<?php
// =============================================================
//  PropertyRent — Admin: Gestión de Pagos (con pagos parciales)
// =============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/functions.php';
dpr_require_role(ROL_ADMIN);

$user = dpr_current_user();
$msg  = $type = '';

// ------------------------------------------------------------------
// Detectar si las columnas nuevas ya existen (migracion aplicada?)
// ------------------------------------------------------------------
$has_migration = false;
$col_check = $mysqli->query("SHOW COLUMNS FROM payments LIKE 'monto_pagado'");
if ($col_check && $col_check->num_rows > 0) {
    $has_migration = true;
}

// ------------------------------------------------------------------
// AJAX: listar movimientos (abonos / pago total) de un pago
// ------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'movimientos') {
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int)($_GET['payment_id'] ?? 0);
    $out = ['success' => false, 'movimientos' => []];
    if ($pid && $has_migration) {
        $movs = db_query($mysqli,
            "SELECT id, tipo, monto, metodo, referencia, comprobante_url, nota, created_at
             FROM payment_partials WHERE payment_id=? ORDER BY created_at DESC", 'i', [$pid]);
        $out['success'] = true;
        $out['movimientos'] = array_map(function($m) {
            return [
                'id'         => (int)$m['id'],
                'tipo'       => $m['tipo'],
                'monto'      => (float)$m['monto'],
                'monto_fmt'  => fmt_money((float)$m['monto']),
                'metodo'     => $m['metodo'],
                'referencia' => $m['referencia'],
                'tiene_comprobante' => !empty($m['comprobante_url']),
                'nota'       => $m['nota'],
                'fecha'      => $m['created_at'] ? date('d M Y', strtotime($m['created_at'])) : '',
            ];
        }, $movs);
    }
    echo json_encode($out);
    exit;
}

// ------------------------------------------------------------------
// POST
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'validate') {
        $pid  = (int)$_POST['payment_id'];
        $nota = trim($_POST['nota_admin'] ?? '');
        db_execute($mysqli,
            "UPDATE payments SET estado='pagado', validado_por=?, fecha_validacion=NOW(),
             nota_admin=?, fecha_pago=NOW(), updated_at=NOW() WHERE id=?",
            'isi', [$user['id'], $nota, $pid]);
        dpr_audit_log($mysqli, $user['id'], 'VALIDATE', 'payments', $pid, 'Aprobó pago');
        $msg = 'Pago aprobado.'; $type = 'success';
    }

    elseif ($act === 'reject') {
        $pid  = (int)$_POST['payment_id'];
        $nota = trim($_POST['nota_admin'] ?? 'Comprobante rechazado.');
        db_execute($mysqli,
            "UPDATE payments SET estado='pendiente', nota_admin=?, comprobante_url=NULL, updated_at=NOW() WHERE id=?",
            'si', [$nota, $pid]);
        dpr_audit_log($mysqli, $user['id'], 'REJECT', 'payments', $pid, "Rechazó: $nota");
        $msg = 'Comprobante rechazado.'; $type = 'warn';
    }

    elseif ($act === 'register') {
        $pid      = (int)$_POST['payment_id'];
        $metodo   = $_POST['metodo']          ?? 'efectivo';
        $ref      = trim($_POST['referencia_pago'] ?? '');
        $nota     = trim($_POST['nota_admin'] ?? '');
        $fecha_p  = $_POST['fecha_pago']      ?? date('Y-m-d');
        $mora_adj = (float)($_POST['valor_mora'] ?? 0);
        $comp_url = null;
        $upload_ok = true;

        if (!empty($_FILES['comprobante']['name'])) {
            $up = dpr_upload_image($_FILES['comprobante'], '/comprobantes');
            if (!$up['success']) {
                $msg = $up['error']; $type = 'danger'; $upload_ok = false;
            } else {
                $comp_url = $up['url'];
            }
        }

        if ($upload_ok) {
            $pay_row = db_query($mysqli, "SELECT * FROM payments WHERE id=?", 'i', [$pid]);
            if (!$pay_row) {
                $msg = 'Pago no encontrado.'; $type = 'danger';
            } else {
                $p     = $pay_row[0];
                $total = (float)$p['valor_arriendo'] + $mora_adj;

                if ($has_migration) {
                    db_execute($mysqli,
                        "UPDATE payments SET estado='pagado', metodo=?, referencia_pago=?, fecha_pago=?,
                         valor_mora=?, valor_total=?, monto_pagado=?, tiene_abonos=tiene_abonos, nota_admin=?,
                         comprobante_url=COALESCE(?,comprobante_url),
                         validado_por=?, fecha_validacion=NOW(), updated_at=NOW() WHERE id=?",
                        '',
                        [$metodo,$ref,$fecha_p,$mora_adj,$total,$total,$nota,$comp_url,$user['id'],$pid]);

                    // Registrar el pago total como movimiento individual y reversible.
                    db_execute($mysqli,
                        "INSERT INTO payment_partials
                            (payment_id, lease_id, tipo, monto, metodo, referencia, comprobante_url, nota, subido_por, rol_subido)
                         VALUES (?, ?, 'pago_total', ?, ?, ?, ?, ?, ?, 'admin')",
                        '',
                        [$pid, (int)$p['lease_id'], $total, $metodo, $ref, $comp_url, $nota, $user['id']]);
                } else {
                    db_execute($mysqli,
                        "UPDATE payments SET estado='pagado', metodo=?, referencia_pago=?, fecha_pago=?,
                         valor_mora=?, valor_total=?, nota_admin=?,
                         comprobante_url=COALESCE(?,comprobante_url),
                         validado_por=?, fecha_validacion=NOW(), updated_at=NOW() WHERE id=?",
                        '',
                        [$metodo,$ref,$fecha_p,$mora_adj,$total,$nota,$comp_url,$user['id'],$pid]);
                }
                dpr_audit_log($mysqli, $user['id'], 'REGISTER_PAYMENT', 'payments', $pid, "Pago manual: $metodo $ref");
                $msg = 'Pago registrado.'; $type = 'success';
            }
        }
    }

    elseif ($act === 'abono' && $has_migration) {
        $pid    = (int)$_POST['payment_id'];
        $lid    = (int)$_POST['lease_id'];
        $monto  = (float)str_replace(['.', '$', ','], ['', '', ''], $_POST['monto_abono'] ?? '0');
        $metodo = $_POST['metodo_abono'] ?? 'efectivo';
        $ref    = trim($_POST['ref_abono']   ?? '');
        $nota   = trim($_POST['nota_abono']  ?? '');
        $comp_url = null;

        if (!empty($_FILES['comp_abono']['name'])) {
            $up = dpr_upload_image($_FILES['comp_abono'], '/comprobantes');
            if ($up['success']) $comp_url = $up['url'];
        }

        if ($monto <= 0) {
            $msg = 'El monto del abono debe ser mayor a 0.'; $type = 'danger';
        } else {
            $r = dpr_registrar_abono($mysqli, $pid, $lid, $monto, $metodo, $ref, $comp_url, $nota, $user['id'], 'admin');
            if (!$r['success']) {
                $msg = $r['error']; $type = 'danger';
            } else {
                $msg = 'Abono registrado.';
                if (!empty($r['saldo_a_favor']) && $r['saldo_a_favor'] > 0) {
                    $msg .= ' Saldo a favor generado: ' . fmt_money($r['saldo_a_favor']) . '.';
                }
                $type = 'success';
                dpr_audit_log($mysqli, $user['id'], 'ABONO', 'payments', $pid, 'Abono $' . number_format($monto));
            }
        }
    }

    elseif ($act === 'aplicar_saldo' && $has_migration) {
        $pid = (int)$_POST['payment_id'];
        $lid = (int)$_POST['lease_id'];
        $r   = dpr_aplicar_saldo_favor($mysqli, $lid, $pid, $user['id']);
        $msg  = $r['success'] ? 'Saldo a favor aplicado: ' . fmt_money($r['aplicado'] ?? 0) : $r['error'];
        $type = $r['success'] ? 'success' : 'danger';
    }

    elseif ($act === 'edit') {
        $pid      = (int)$_POST['payment_id'];
        $metodo   = $_POST['metodo']          ?? 'efectivo';
        $ref      = trim($_POST['referencia_pago'] ?? '');
        $nota     = trim($_POST['nota_admin'] ?? '');
        $fecha_p  = $_POST['fecha_pago']      ?: null;
        $mora_adj = (float)str_replace(['.', '$', ','], ['', '', ''], $_POST['valor_mora'] ?? '0');
        $monto_in = array_key_exists('monto_pagado', $_POST)
            ? (float)str_replace(['.', '$', ','], ['', '', ''], $_POST['monto_pagado'])
            : null;
        $upload_ok = true;
        $comp_url  = null;

        if (!empty($_FILES['comprobante']['name'])) {
            $up = dpr_upload_image($_FILES['comprobante'], '/comprobantes');
            if (!$up['success']) {
                $msg = $up['error']; $type = 'danger'; $upload_ok = false;
            } else {
                $comp_url = $up['url'];
            }
        }

        if ($upload_ok) {
            $pay_row = db_query($mysqli, "SELECT * FROM payments WHERE id=?", 'i', [$pid]);
            if (!$pay_row) {
                $msg = 'Pago no encontrado.'; $type = 'danger';
            } else {
                $p     = $pay_row[0];
                $total = (float)$p['valor_arriendo'] + $mora_adj;

                // Construir detalle de cambios para auditoría
                $cambios = [];
                if ($metodo !== $p['metodo'])              $cambios[] = "método: {$p['metodo']} → $metodo";
                if ($ref !== $p['referencia_pago'])         $cambios[] = "referencia: '{$p['referencia_pago']}' → '$ref'";
                if ($fecha_p !== substr((string)$p['fecha_pago'], 0, 10)) $cambios[] = "fecha_pago: " . substr((string)$p['fecha_pago'], 0, 10) . " → $fecha_p";
                if ($mora_adj != (float)$p['valor_mora'])   $cambios[] = "mora: " . fmt_money((float)$p['valor_mora']) . " → " . fmt_money($mora_adj);
                if ($total != (float)$p['valor_total'])     $cambios[] = "total: " . fmt_money((float)$p['valor_total']) . " → " . fmt_money($total);
                if ($comp_url)                              $cambios[] = "comprobante reemplazado";

                if ($has_migration) {
                    // El monto pagado, acotado entre 0 y el total, define el nuevo estado:
                    // 0 = pendiente, parcial entre 0 y total, >= total = pagado.
                    $monto_pagado = $monto_in === null ? (float)$p['monto_pagado'] : max(0, min($monto_in, $total));
                    if ($monto_pagado <= 0) {
                        $nuevo_estado = 'pendiente';
                        $tiene_abonos = 0;
                    } elseif ($monto_pagado < $total) {
                        $nuevo_estado = in_array($p['estado'], ['validando']) ? $p['estado'] : 'pendiente';
                        $tiene_abonos = 1;
                    } else {
                        $nuevo_estado = 'pagado';
                        $tiene_abonos = (int)$p['tiene_abonos'];
                    }

                    if ($monto_pagado != (float)$p['monto_pagado']) {
                        $cambios[] = "monto pagado: " . fmt_money((float)$p['monto_pagado']) . " → " . fmt_money($monto_pagado);
                    }
                    if ($nuevo_estado !== $p['estado']) {
                        $cambios[] = "estado: {$p['estado']} → $nuevo_estado";
                    }

                    $fecha_pago_sql = ($nuevo_estado === 'pagado') ? ($fecha_p ?: date('Y-m-d')) : $fecha_p;

                    db_execute($mysqli,
                        "UPDATE payments SET metodo=?, referencia_pago=?, fecha_pago=?,
                         valor_mora=?, valor_total=?, monto_pagado=?, tiene_abonos=?, estado=?,
                         nota_admin=?, comprobante_url=COALESCE(?,comprobante_url),
                         validado_por=CASE WHEN ?='pagado' THEN ? ELSE validado_por END,
                         fecha_validacion=CASE WHEN ?='pagado' THEN NOW() ELSE fecha_validacion END,
                         updated_at=NOW() WHERE id=?",
                        '',
                        [$metodo,$ref,$fecha_pago_sql,$mora_adj,$total,$monto_pagado,$tiene_abonos,$nuevo_estado,
                         $nota,$comp_url,$nuevo_estado,$user['id'],$nuevo_estado,$pid]);
                } else {
                    db_execute($mysqli,
                        "UPDATE payments SET metodo=?, referencia_pago=?, fecha_pago=?,
                         valor_mora=?, valor_total=?, nota_admin=?,
                         comprobante_url=COALESCE(?,comprobante_url), updated_at=NOW() WHERE id=?",
                        '',
                        [$metodo,$ref,$fecha_p,$mora_adj,$total,$nota,$comp_url,$pid]);
                }

                $detalle = $cambios ? implode('; ', $cambios) : 'Sin cambios detectados';
                dpr_audit_log($mysqli, $user['id'], 'EDIT_PAYMENT', 'payments', $pid, $detalle);
                $msg = 'Pago actualizado.'; $type = 'success';
            }
        }
    }

    elseif ($act === 'delete_payment' && $has_migration) {
        $pid       = (int)$_POST['payment_id'];
        $partialid = (int)($_POST['partial_id'] ?? 0);
        $motivo    = trim($_POST['motivo_delete'] ?? '');

        $pay_row = db_query($mysqli, "SELECT * FROM payments WHERE id=?", 'i', [$pid]);
        if (!$pay_row) {
            $msg = 'Pago no encontrado.'; $type = 'danger';
        } else {
            $p   = $pay_row[0];
            $lid = (int)$p['lease_id'];

            $mov_row = db_query($mysqli, "SELECT * FROM payment_partials WHERE id=? AND payment_id=?", 'ii', [$partialid, $pid]);
            if (!$mov_row) {
                $msg = 'El movimiento de pago no existe o ya fue eliminado.'; $type = 'danger';
            } else {
                $mov = $mov_row[0];
                $total = (float)$p['valor_total']; // valor_arriendo + valor_mora: intacto

                // 0) Si este movimiento generó o consumió saldo a favor, revertirlo en leases.saldo_favor
                //    ANTES de borrarlo. Para saberlo con precisión, reconstruimos la suma cronológica
                //    de abonos/pagos anteriores a este (el orden importa: el excedente solo lo genera
                //    el movimiento que hace cruzar el total, sea cual sea su posición en la lista).
                $saldo_ajuste = 0.0; $saldo_nota = '';

                if ($mov['tipo'] === 'saldo_favor_aplicado') {
                    // Este movimiento CONSUMIÓ saldo a favor: al borrarlo, se lo devolvemos al inquilino.
                    $saldo_ajuste = (float)$mov['monto'];
                    db_execute($mysqli, "UPDATE leases SET saldo_favor = saldo_favor + ? WHERE id=?", 'di', [$saldo_ajuste, $lid]);
                    $saldo_nota = "Devolvió " . fmt_money($saldo_ajuste) . " a saldo a favor (era saldo aplicado a este pago).";
                } elseif (in_array($mov['tipo'], ['abono','pago_total'], true)) {
                    $previos = db_query($mysqli,
                        "SELECT COALESCE(SUM(monto),0) AS suma FROM payment_partials
                         WHERE payment_id=? AND tipo IN ('abono','pago_total')
                           AND (created_at < ? OR (created_at = ? AND id < ?))",
                        'issi', [$pid, $mov['created_at'], $mov['created_at'], $partialid]);
                    $monto_antes = (float)($previos[0]['suma'] ?? 0);
                    $monto_con   = $monto_antes + (float)$mov['monto'];

                    $excedente_generado = 0;
                    if ($monto_antes < $total && $monto_con > $total) {
                        $excedente_generado = $monto_con - $total;
                    } elseif ($monto_antes >= $total) {
                        // El período ya estaba cubierto antes de este movimiento: todo él fue excedente.
                        $excedente_generado = (float)$mov['monto'];
                    }
                    if ($excedente_generado > 0) {
                        // No dejar el saldo_favor negativo si parte de ese excedente ya fue usado en otro pago.
                        $lease_now = db_query($mysqli, "SELECT saldo_favor FROM leases WHERE id=?", 'i', [$lid]);
                        $saldo_actual = (float)($lease_now[0]['saldo_favor'] ?? 0);
                        $saldo_ajuste = min($excedente_generado, $saldo_actual);
                        if ($saldo_ajuste > 0) {
                            db_execute($mysqli, "UPDATE leases SET saldo_favor = saldo_favor - ? WHERE id=?", 'di', [$saldo_ajuste, $lid]);
                            $saldo_nota = "Retiró " . fmt_money($saldo_ajuste) . " de saldo a favor (excedente que había generado este pago).";
                        }
                    }
                }

                // 1) Borrar SOLO ese movimiento (la acción de pago), nunca la obligación.
                db_execute($mysqli, "DELETE FROM payment_partials WHERE id=?", 'i', [$partialid]);

                // 2) Borrar su comprobante físico del servidor, si tenía.
                $borrado_archivo = false;
                if (!empty($mov['comprobante_url'])) {
                    $rel  = ltrim(str_replace(UPLOAD_URL, '', $mov['comprobante_url']), '/');
                    $path = UPLOAD_PATH . $rel;
                    if (is_file($path) && @unlink($path)) $borrado_archivo = true;
                }
                // Si el comprobante del movimiento era el mismo que quedó guardado en payments.comprobante_url, límpialo también.
                if (!empty($p['comprobante_url']) && $p['comprobante_url'] === $mov['comprobante_url']) {
                    db_execute($mysqli, "UPDATE payments SET comprobante_url=NULL WHERE id=?", 'i', [$pid]);
                }

                // 3) Recalcular lo pagado a partir de los movimientos que quedan (la obligación NO se toca).
                //    Solo 'abono' y 'pago_total' representan dinero recibido; 'saldo_favor_aplicado' también
                //    cuenta como pagado porque cubrió parte de la obligación con saldo previo.
                $restantes = db_query($mysqli,
                    "SELECT COALESCE(SUM(monto),0) AS suma, COUNT(*) AS n
                     FROM payment_partials WHERE payment_id=? AND tipo IN ('abono','pago_total','saldo_favor_aplicado')",
                    'i', [$pid]);
                $nuevo_pagado = (float)($restantes[0]['suma'] ?? 0);
                $n_restantes  = (int)($restantes[0]['n'] ?? 0);

                if ($nuevo_pagado <= 0) {
                    $nuevo_estado = 'pendiente';
                    $tiene_abonos = 0;
                } elseif ($nuevo_pagado < $total) {
                    $nuevo_estado = 'pendiente';
                    $tiene_abonos = 1;
                } else {
                    $nuevo_estado = 'pagado';
                    $tiene_abonos = $n_restantes > 1 ? 1 : 0;
                }

                $fecha_pago_sql = ($nuevo_estado === 'pagado') ? $p['fecha_pago'] : null;

                db_execute($mysqli,
                    "UPDATE payments SET monto_pagado=?, tiene_abonos=?, estado=?,
                     fecha_pago=?,
                     validado_por=CASE WHEN ?='pagado' THEN validado_por ELSE NULL END,
                     fecha_validacion=CASE WHEN ?='pagado' THEN fecha_validacion ELSE NULL END,
                     updated_at=NOW() WHERE id=?",
                    '',
                    [$nuevo_pagado,$tiene_abonos,$nuevo_estado,$fecha_pago_sql,$nuevo_estado,$nuevo_estado,$pid]);

                $tipo_mov = $mov['tipo'] === 'pago_total' ? 'pago total' : ($mov['tipo'] === 'saldo_favor_aplicado' ? 'aplicación de saldo a favor' : 'abono');
                $detalle = "Eliminó $tipo_mov de " . fmt_money((float)$mov['monto'])
                    . " (periodo {$p['periodo']}, registrado el " . substr($mov['created_at'],0,10) . ")"
                    . ($borrado_archivo ? ', comprobante borrado del servidor' : '')
                    . ". Obligación intacta: arriendo " . fmt_money((float)$p['valor_arriendo'])
                    . " + mora " . fmt_money((float)$p['valor_mora'])
                    . ". Nuevo saldo pagado: " . fmt_money($nuevo_pagado) . " de " . fmt_money($total)
                    . ($saldo_nota ? ". $saldo_nota" : '')
                    . ($motivo ? ". Motivo: $motivo" : '');
                dpr_audit_log($mysqli, $user['id'], 'DELETE', 'payments', $pid, $detalle);
                $msg = 'Pago eliminado. La obligación del periodo se conservó intacta.'
                    . ($saldo_nota ? ' Se ajustó el saldo a favor del inquilino.' : ''); $type = 'success';
            }
        }
    }
}

// ------------------------------------------------------------------
// Filtros
// ------------------------------------------------------------------
$f_estado  = $_GET['estado']           ?? '';
$f_prop    = (int)($_GET['property_id'] ?? 0);
$f_periodo = $_GET['periodo']           ?? date('Y-m');

$where_parts = ['1=1'];
if ($f_estado)  $where_parts[] = "p.estado = '" . $mysqli->real_escape_string($f_estado) . "'";
if ($f_prop)    $where_parts[] = "pr.id = $f_prop";
if ($f_periodo) $where_parts[] = "p.periodo = '" . $mysqli->real_escape_string($f_periodo) . "'";
$where = implode(' AND ', $where_parts);

// Construir SELECT según si la migración está aplicada
if ($has_migration) {
    $select_extra = "GREATEST(0, p.valor_total - p.monto_pagado) AS saldo_pendiente,
                     p.monto_pagado, p.tiene_abonos,
                     l.id AS lease_id, l.saldo_favor,";
} else {
    $select_extra = "0 AS saldo_pendiente, 0 AS monto_pagado, 0 AS tiene_abonos,
                     l.id AS lease_id, 0 AS saldo_favor,";
}

$payments = db_query($mysqli,
    "SELECT p.*,
            $select_extra
            CONCAT(u.nombre,' ',u.apellido) AS inquilino,
            un.nombre AS unidad, pr.nombre AS inmueble,
            DATEDIFF(CURDATE(), p.fecha_vencimiento) AS dias_vencido,
            CONCAT(adm.nombre,' ',adm.apellido) AS validado_por_nombre
     FROM payments p
     JOIN leases l   ON l.id = p.lease_id
     JOIN users u    ON u.id = l.tenant_id
     JOIN units un   ON un.id = l.unit_id
     JOIN properties pr ON pr.id = un.property_id
     LEFT JOIN users adm ON adm.id = p.validado_por
     WHERE $where
     ORDER BY p.fecha_vencimiento ASC, pr.nombre");

$all_props = db_query($mysqli, "SELECT id, nombre FROM properties WHERE estado='activo' ORDER BY nombre");

$page_title  = 'Pagos';
$active_menu = 'payments';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
<div class="dpr-alert dpr-alert--<?= h($type) ?>"><?= h($msg) ?></div>
<?php endif; ?>

<?php if (!$has_migration): ?>
<div class="dpr-alert dpr-alert--warn">
  <strong>Migración pendiente:</strong> Ejecuta <code>2_migration_produccion.sql</code> en tu base de datos para habilitar pagos parciales y saldo a favor.
</div>
<?php endif; ?>

<div class="dpr-page-header">
  <div>
    <div class="dpr-page-title">Gestión de Pagos</div>
    <div class="dpr-page-sub"><?= count($payments) ?> registros · Periodo: <?= h($f_periodo) ?></div>
  </div>
</div>

<form method="GET" class="dpr-card" style="padding:14px 18px">
  <div class="dpr-form-grid dpr-form-grid--3" style="margin-bottom:0">
    <div class="dpr-form-group">
      <label class="dpr-label">Periodo</label>
      <input type="month" name="periodo" class="dpr-input" value="<?= h($f_periodo) ?>">
    </div>
    <div class="dpr-form-group">
      <label class="dpr-label">Inmueble</label>
      <select name="property_id" class="dpr-select">
        <option value="">Todos</option>
        <?php foreach ($all_props as $prop): ?>
        <option value="<?= $prop['id'] ?>" <?= $f_prop == $prop['id'] ? 'selected' : '' ?>><?= h($prop['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="dpr-form-group">
      <label class="dpr-label">Estado</label>
      <select name="estado" class="dpr-select">
        <option value="">Todos</option>
        <?php foreach (['pendiente','validando','pagado','moroso','rechazado','condonado'] as $e): ?>
        <option value="<?= $e ?>" <?= $f_estado === $e ? 'selected' : '' ?>><?= ucfirst($e) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div style="margin-top:10px;text-align:right">
    <button class="dpr-btn dpr-btn--primary">Filtrar</button>
    <a href="?" class="dpr-btn dpr-btn--secondary">Limpiar</a>
  </div>
</form>

<div class="dpr-card">
  <div class="dpr-table-wrap">
    <table class="dpr-table">
      <thead>
        <tr>
          <th>Inquilino</th><th>Inmueble / Unidad</th><th>Periodo</th>
          <th>Arriendo</th><th>Mora</th><th>Total</th>
          <?php if ($has_migration): ?><th>Abonado</th><th>Pendiente</th><?php endif; ?>
          <th>Vcto.</th><th>Estado</th><th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$payments): ?>
        <tr><td colspan="<?= $has_migration ? 11 : 9 ?>" class="dpr-text-muted" style="text-align:center;padding:24px">Sin registros para los filtros aplicados.</td></tr>
        <?php endif; ?>
        <?php foreach ($payments as $p):
            $estado = $p['estado'];
            $dias       = (int)($p['dias_vencido'] ?? 0);
            $saldo_pend = (float)($p['saldo_pendiente'] ?? 0);
            $abonado    = (float)($p['monto_pagado'] ?? 0);
            $saldo_fav  = (float)($p['saldo_favor']  ?? 0);
            $es_parcial = $has_migration && !empty($p['tiene_abonos']) && $estado !== 'pagado';

            // Estado visual único y mutuamente excluyente: Pendiente / Parcial / Pagado
            // (más los estados de flujo: validando, moroso, rechazado, condonado)
            $estado_label = ucfirst($estado);
            $pill = 'neutral';
            if ($estado === 'pagado')        { $pill = 'ok';    $estado_label = 'Pagado'; }
            elseif ($estado === 'validando') { $pill = 'warn';  $estado_label = 'Validando'; }
            elseif ($estado === 'moroso')    { $pill = 'danger';$estado_label = 'Moroso'; }
            elseif ($es_parcial)             { $pill = 'info';  $estado_label = 'Parcial'; }
            elseif ($estado === 'pendiente') { $pill = 'info';  $estado_label = 'Pendiente'; }
        ?>
        <tr>
          <td>
            <?= h($p['inquilino']) ?>
            <?php if ($has_migration && $saldo_fav > 0): ?>
            <span class="dpr-pill dpr-pill--ok" style="font-size:10px;margin-left:4px" title="Saldo a favor de <?= h($p['inquilino']) ?>: se aplicará automáticamente al próximo periodo pendiente.">Saldo a favor: <?= fmt_money($saldo_fav) ?></span>
            <?php endif; ?>
          </td>
          <td><?= h($p['inmueble'] . ' / ' . $p['unidad']) ?></td>
          <td><?= h($p['periodo']) ?></td>
          <td><?= fmt_money((float)$p['valor_arriendo']) ?></td>
          <td><?= (float)$p['valor_mora'] > 0 ? '<span style="color:#dc2626">' . fmt_money((float)$p['valor_mora']) . '</span>' : '—' ?></td>
          <td><strong><?= fmt_money((float)$p['valor_total']) ?></strong></td>
          <?php if ($has_migration): ?>
          <td><?= $abonado > 0 ? '<span style="color:#059669">' . fmt_money($abonado) . '</span>' : '—' ?></td>
          <td><?= $saldo_pend > 0 ? '<span style="color:#d97706">' . fmt_money($saldo_pend) . '</span>' : '<span style="color:#059669">✓</span>' ?></td>
          <?php endif; ?>
          <td>
            <?= fmt_date($p['fecha_vencimiento']) ?>
            <?php if ($dias > 0 && !in_array($estado, ['pagado','condonado'])): ?>
            <span class="dpr-pill dpr-pill--danger" style="margin-left:4px"><?= $dias ?>d</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="dpr-pill dpr-pill--<?= $pill ?>"><?= h($estado_label) ?></span>
          </td>
          <td class="dpr-flex">
            <?php if ($estado === 'validando'): ?>
              <?php if ($p['comprobante_url']): ?>
              <button type="button" class="dpr-btn dpr-btn--sm dpr-btn--secondary"
                onclick="openComprobanteModal('<?= h(addslashes($p['comprobante_url'])) ?>')">Ver comp.</button>
              <?php endif; ?>
              <button class="dpr-btn dpr-btn--sm dpr-btn--primary"
                onclick="openValidateModal(<?= (int)$p['id'] ?>,'<?= h(addslashes($p['inquilino'])) ?>','<?= fmt_money((float)$p['valor_total']) ?>','<?= h($p['periodo']) ?>')">
                Validar
              </button>
            <?php endif; ?>

            <?php if ($has_migration && (in_array($estado, ['pendiente','moroso','validando']) || $saldo_pend > 0)): ?>
              <button class="dpr-btn dpr-btn--sm dpr-btn--secondary"
                onclick="openAbonoModal(<?= (int)$p['id'] ?>,<?= (int)$p['lease_id'] ?>,'<?= h(addslashes($p['inquilino'])) ?>','<?= fmt_money($saldo_pend) ?>')">
                + Abono
              </button>
            <?php endif; ?>

            <?php if (in_array($estado, ['pendiente','moroso'])): ?>
              <button class="dpr-btn dpr-btn--sm dpr-btn--secondary"
                onclick="openRegisterModal(<?= (int)$p['id'] ?>,'<?= h(addslashes($p['inquilino'])) ?>','<?= fmt_money((float)$p['valor_arriendo']) ?>',<?= (float)$p['valor_mora'] ?>)">
                Pago total
              </button>
            <?php endif; ?>

            <?php if ($has_migration && $saldo_fav > 0 && $saldo_pend > 0): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action"     value="aplicar_saldo">
                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="lease_id"   value="<?= (int)$p['lease_id'] ?>">
                <button type="submit" class="dpr-btn dpr-btn--sm dpr-btn--secondary">Saldo favor</button>
              </form>
            <?php endif; ?>

            <?php if ($p['comprobante_url'] && $estado === 'pagado'): ?>
              <button type="button" class="dpr-btn dpr-btn--sm dpr-btn--icon" title="Comprobante"
                onclick="openComprobanteModal('<?= h(addslashes($p['comprobante_url'])) ?>')">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 13h10M7 1v8M4 6l3 3 3-3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </button>
            <?php endif; ?>

            <button class="dpr-btn dpr-btn--sm dpr-btn--icon" title="Editar pago"
              onclick="openEditModal(<?= (int)$p['id'] ?>,'<?= h(addslashes($p['inquilino'])) ?>','<?= h($p['metodo'] ?? 'efectivo') ?>','<?= h(addslashes($p['referencia_pago'] ?? '')) ?>','<?= h($p['fecha_pago'] ? substr($p['fecha_pago'], 0, 10) : '') ?>',<?= (float)$p['valor_mora'] ?>,'<?= h(addslashes($p['nota_admin'] ?? '')) ?>',<?= (float)$p['valor_arriendo'] ?>,<?= (float)$abonado ?>,'<?= h($estado) ?>')">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M9.5 1.5l3 3-8 8H1.5v-3l8-8z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>

            <?php if ($has_migration): ?>
            <button type="button" class="dpr-btn dpr-btn--sm dpr-btn--icon" title="Eliminar un pago registrado"
              onclick="openDeleteModal(<?= (int)$p['id'] ?>,'<?= h(addslashes($p['inquilino'])) ?>','<?= h($p['periodo']) ?>')">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="#dc2626"><path d="M2 3.5h10M5 3.5V2a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v1.5M5.5 6.5v4M8.5 6.5v4M2.8 3.5l.5 8a1 1 0 0 0 1 .9h5.4a1 1 0 0 0 1-.9l.5-8" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Validar comprobante -->
<div class="dpr-modal-backdrop" id="validateModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeModal('validateModal')">&times;</button>
    <div class="dpr-modal__title">Validar comprobante de pago</div>
    <div id="validateInfo" style="background:#f8fafc;border-radius:8px;padding:14px;margin-bottom:14px;font-size:13px"></div>
    <form method="POST">
      <input type="hidden" name="action"     value="validate">
      <input type="hidden" name="payment_id" id="validatePayId">
      <div class="dpr-form-group" style="margin-bottom:14px">
        <label class="dpr-label">Nota de aprobación (opcional)</label>
        <textarea name="nota_admin" class="dpr-textarea" style="height:60px" placeholder="Ej: Verificado en extracto bancario"></textarea>
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeModal('validateModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Aprobar pago</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Abono parcial -->
<?php if ($has_migration): ?>
<div class="dpr-modal-backdrop" id="abonoModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeModal('abonoModal')">&times;</button>
    <div class="dpr-modal__title">Registrar abono / pago parcial</div>
    <div id="abonoInfo" style="background:#f8fafc;border-radius:8px;padding:14px;margin-bottom:14px;font-size:13px"></div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action"     value="abono">
      <input type="hidden" name="payment_id" id="abonoPayId">
      <input type="hidden" name="lease_id"   id="abonoLeaseId">
      <div class="dpr-form-grid">
        <div class="dpr-form-group">
          <label class="dpr-label">Monto del abono ($)</label>
          <input type="number" name="monto_abono" class="dpr-input" min="1" step="1" required placeholder="Ej: 500000">
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Método</label>
          <select name="metodo_abono" class="dpr-select">
            <?php foreach (['efectivo','transferencia','pse','tarjeta','nequi','daviplata','otro'] as $m): ?>
            <option value="<?= $m ?>"><?= ucfirst($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Referencia</label>
          <input type="text" name="ref_abono" class="dpr-input" placeholder="No. comprobante, transferencia…">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Adjuntar comprobante (opcional)</label>
          <input type="file" name="comp_abono" class="dpr-input" accept=".jpg,.jpeg,.png,.pdf,.webp">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Nota</label>
          <textarea name="nota_abono" class="dpr-textarea" style="height:55px"></textarea>
        </div>
      </div>
      <div class="dpr-alert dpr-alert--info" style="font-size:12px;margin-top:12px">
        Si el abono supera el total adeudado, el excedente se registra como <strong>saldo a favor</strong> y se aplica automáticamente al próximo periodo.
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeModal('abonoModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Registrar abono</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Modal: Pago total manual -->
<div class="dpr-modal-backdrop" id="registerModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeModal('registerModal')">&times;</button>
    <div class="dpr-modal__title">Registrar pago total manual</div>
    <div id="registerInfo" style="background:#f8fafc;border-radius:8px;padding:14px;margin-bottom:14px;font-size:13px"></div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action"     value="register">
      <input type="hidden" name="payment_id" id="registerPayId">
      <div class="dpr-form-grid">
        <div class="dpr-form-group">
          <label class="dpr-label">Método</label>
          <select name="metodo" class="dpr-select">
            <?php foreach (['efectivo','transferencia','pse','tarjeta','nequi','daviplata','otro'] as $m): ?>
            <option value="<?= $m ?>"><?= ucfirst($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Fecha de pago</label>
          <input type="date" name="fecha_pago" class="dpr-input" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Mora aplicada ($)</label>
          <input type="number" name="valor_mora" id="registerMora" class="dpr-input" min="0" step="1000" value="0">
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Referencia / No. comprobante</label>
          <input type="text" name="referencia_pago" class="dpr-input" placeholder="TRF123456">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Adjuntar comprobante (opcional)</label>
          <input type="file" name="comprobante" class="dpr-input" accept=".jpg,.jpeg,.png,.pdf,.webp">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Nota</label>
          <textarea name="nota_admin" class="dpr-textarea" style="height:55px"></textarea>
        </div>
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeModal('registerModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Registrar pago</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Editar pago -->
<div class="dpr-modal-backdrop" id="editModal">
  <div class="dpr-modal">
    <button class="dpr-modal__close" onclick="closeModal('editModal')">&times;</button>
    <div class="dpr-modal__title">Editar pago</div>
    <div id="editInfo" style="background:#f8fafc;border-radius:8px;padding:14px;margin-bottom:14px;font-size:13px"></div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action"     value="edit">
      <input type="hidden" name="payment_id" id="editPayId">
      <div class="dpr-form-grid">
        <?php if ($has_migration): ?>
        <div class="dpr-form-group dpr-form-group--full" id="editMontoWrap">
          <label class="dpr-label">Monto pagado ($)</label>
          <input type="number" name="monto_pagado" id="editMontoPagado" class="dpr-input" min="0" step="1" placeholder="Ej: 500000">
          <div id="editMontoHint" style="font-size:11px;color:#64748b;margin-top:4px"></div>
        </div>
        <?php endif; ?>
        <div class="dpr-form-group">
          <label class="dpr-label">Método</label>
          <select name="metodo" id="editMetodo" class="dpr-select">
            <?php foreach (['efectivo','transferencia','pse','tarjeta','nequi','daviplata','otro'] as $m): ?>
            <option value="<?= $m ?>"><?= ucfirst($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Fecha de pago</label>
          <input type="date" name="fecha_pago" id="editFechaPago" class="dpr-input">
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Mora aplicada ($)</label>
          <input type="number" name="valor_mora" id="editMora" class="dpr-input" min="0" step="1000">
        </div>
        <div class="dpr-form-group">
          <label class="dpr-label">Referencia / No. comprobante</label>
          <input type="text" name="referencia_pago" id="editReferencia" class="dpr-input" placeholder="TRF123456">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Reemplazar comprobante (opcional)</label>
          <input type="file" name="comprobante" class="dpr-input" accept=".jpg,.jpeg,.png,.pdf,.webp">
        </div>
        <div class="dpr-form-group dpr-form-group--full">
          <label class="dpr-label">Nota</label>
          <textarea name="nota_admin" id="editNota" class="dpr-textarea" style="height:55px"></textarea>
        </div>
      </div>
      <div class="dpr-alert dpr-alert--info" style="font-size:12px;margin-top:12px">
        El <strong>monto pagado</strong> define el estado: si es <strong>0</strong> queda <strong>Pendiente</strong>, si es menor al total queda <strong>Parcial</strong>, y si cubre el total queda <strong>Pagado</strong>. Usa este campo para corregir un pago marcado por error como total o parcial. El cambio queda registrado en el log de auditoría.
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeModal('editModal')">Cancelar</button>
        <button type="submit" class="dpr-btn dpr-btn--primary">Guardar cambios</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Vista previa de comprobante -->
<div class="dpr-modal-backdrop" id="comprobanteModal">
  <div class="dpr-modal" style="max-width:560px">
    <button class="dpr-modal__close" onclick="closeModal('comprobanteModal')">&times;</button>
    <div class="dpr-modal__title">Comprobante de pago</div>
    <div id="comprobanteBody" style="display:flex;align-items:center;justify-content:center;background:#f8fafc;border-radius:8px;min-height:200px;max-height:65vh;overflow:auto;padding:10px">
      <!-- contenido inyectado por JS -->
    </div>
    <div class="dpr-form-actions" style="margin-top:14px">
      <a id="comprobanteOpenLink" href="#" target="_blank" class="dpr-btn dpr-btn--secondary">Abrir en pestaña nueva</a>
      <button type="button" class="dpr-btn dpr-btn--primary" onclick="closeModal('comprobanteModal')">Cerrar</button>
    </div>
  </div>
</div>

<!-- Modal: Eliminar un pago/abono puntual (no toca la obligación) -->
<div class="dpr-modal-backdrop" id="deleteModal">
  <div class="dpr-modal" style="max-width:520px">
    <button class="dpr-modal__close" onclick="closeModal('deleteModal')">&times;</button>
    <div class="dpr-modal__title">Eliminar un pago registrado</div>
    <div id="deleteInfo" style="background:#f8fafc;border-radius:8px;padding:14px;margin-bottom:14px;font-size:13px"></div>
    <div class="dpr-alert dpr-alert--info" style="font-size:12px;margin-bottom:14px">
      Esto elimina <strong>solo la acción de pago</strong> (el dinero registrado y su comprobante). La obligación del periodo —arriendo, mora y fecha de vencimiento— <strong>no se modifica</strong>. El saldo pendiente se recalcula automáticamente, y si ese pago había generado o usado saldo a favor del inquilino, también se ajusta.
    </div>

    <div id="deleteMovsLoading" style="text-align:center;padding:16px;color:#64748b;font-size:13px">Cargando movimientos…</div>
    <div id="deleteMovsEmpty" style="display:none;text-align:center;padding:16px;color:#64748b;font-size:13px">Este periodo no tiene pagos ni abonos registrados.</div>
    <div id="deleteMovsList" style="display:none"></div>

    <!-- Paso 2: confirmar eliminación de un movimiento concreto -->
    <form method="POST" id="deleteMovForm" style="display:none;margin-top:14px;border-top:1px solid #e5e7eb;padding-top:14px">
      <input type="hidden" name="action"     value="delete_payment">
      <input type="hidden" name="payment_id" id="deletePayId">
      <input type="hidden" name="partial_id" id="deletePartialId">
      <div id="deleteMovSummary" style="font-size:13px;margin-bottom:10px"></div>
      <div class="dpr-form-group" style="margin-bottom:14px">
        <label class="dpr-label">Motivo (queda en el log de auditoría)</label>
        <textarea name="motivo_delete" class="dpr-textarea" style="height:50px" placeholder="Ej: Se registró por error como pago total"></textarea>
      </div>
      <div class="dpr-form-actions">
        <button type="button" class="dpr-btn dpr-btn--secondary" onclick="dprCancelDeleteMov()">Volver a la lista</button>
        <button type="submit" class="dpr-btn dpr-btn--danger">Sí, eliminar este pago</button>
      </div>
    </form>

    <div class="dpr-form-actions" id="deleteModalFooter" style="margin-top:14px">
      <button type="button" class="dpr-btn dpr-btn--secondary" onclick="closeModal('deleteModal')">Cerrar</button>
    </div>
  </div>
</div>

<script>
function openComprobanteModal(url) {
  var body = document.getElementById('comprobanteBody');
  var isPdf = /\.pdf(\?|$)/i.test(url);
  body.innerHTML = isPdf
    ? '<iframe src="' + url + '" style="width:100%;height:60vh;border:0"></iframe>'
    : '<img src="' + url + '" style="max-width:100%;max-height:60vh;object-fit:contain;border-radius:6px">';
  document.getElementById('comprobanteOpenLink').href = url;
  document.getElementById('comprobanteModal').classList.add('open');
}
function openValidateModal(id, inq, monto, periodo) {
  document.getElementById('validatePayId').value = id;
  document.getElementById('validateInfo').innerHTML =
    '<strong>' + inq + '</strong> · Periodo: ' + periodo + ' · Monto: <strong>' + monto + '</strong>';
  document.getElementById('validateModal').classList.add('open');
}
function openAbonoModal(id, lid, inq, pendiente) {
  document.getElementById('abonoPayId').value   = id;
  document.getElementById('abonoLeaseId').value = lid;
  document.getElementById('abonoInfo').innerHTML =
    '<strong>' + inq + '</strong> · Saldo pendiente: <strong>' + pendiente + '</strong>';
  document.getElementById('abonoModal').classList.add('open');
}
function openRegisterModal(id, inq, arriendo, mora) {
  document.getElementById('registerPayId').value = id;
  document.getElementById('registerMora').value  = mora;
  document.getElementById('registerInfo').innerHTML =
    '<strong>' + inq + '</strong> · Arriendo: <strong>' + arriendo + '</strong>';
  document.getElementById('registerModal').classList.add('open');
}
function dprMontoHint(input) {
  var totalAttr = parseFloat(input.dataset.total || '0');
  var val = parseFloat(input.value);
  if (isNaN(val)) val = 0;
  var hint = document.getElementById('editMontoHint');
  var nuevoEstado;
  if (val <= 0)            nuevoEstado = 'Pendiente';
  else if (val < totalAttr) nuevoEstado = 'Parcial';
  else                       nuevoEstado = 'Pagado';
  hint.textContent = 'Total del periodo: $' + totalAttr.toLocaleString('es-CO') + ' → con este monto el estado quedará: ' + nuevoEstado;
}
function openEditModal(id, inq, metodo, ref, fechaPago, mora, nota, arriendo, abonado, estado) {
  document.getElementById('editPayId').value      = id;
  document.getElementById('editMetodo').value     = metodo;
  document.getElementById('editReferencia').value = ref;
  document.getElementById('editFechaPago').value  = fechaPago;
  document.getElementById('editMora').value       = mora;
  document.getElementById('editNota').value       = nota;

  var montoInput = document.getElementById('editMontoPagado');
  if (montoInput) {
    var total = (arriendo || 0) + (mora || 0);
    // Si el pago ya está 'pagado', el monto pagado por convención es el total; si no, es lo abonado.
    montoInput.value = estado === 'pagado' ? total : (abonado || 0);
    montoInput.dataset.total = total;
    dprMontoHint(montoInput);
  }

  document.getElementById('editInfo').innerHTML =
    '<strong>' + inq + '</strong> · Corrigiendo datos del pago registrado';
  document.getElementById('editModal').classList.add('open');
}
var editMontoPagadoEl = document.getElementById('editMontoPagado');
if (editMontoPagadoEl) {
  editMontoPagadoEl.addEventListener('input', function() {
    dprMontoHint(this);
  });
}
function dprEscHtml(str) {
  var d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}
function openDeleteModal(id, inq, periodo) {
  document.getElementById('deletePayId').value = id;
  document.getElementById('deleteInfo').innerHTML =
    '<strong>' + dprEscHtml(inq) + '</strong> · Periodo: ' + dprEscHtml(periodo);

  document.getElementById('deleteMovForm').style.display   = 'none';
  document.getElementById('deleteModalFooter').style.display = 'flex';
  document.getElementById('deleteMovsEmpty').style.display = 'none';
  document.getElementById('deleteMovsList').style.display  = 'none';
  document.getElementById('deleteMovsList').innerHTML      = '';
  document.getElementById('deleteMovsLoading').style.display = 'block';

  document.getElementById('deleteModal').classList.add('open');

  fetch('?ajax=movimientos&payment_id=' + id)
    .then(function(r) { return r.json(); })
    .then(function(data) {
      document.getElementById('deleteMovsLoading').style.display = 'none';
      var movs = (data && data.movimientos) || [];
      if (!movs.length) {
        document.getElementById('deleteMovsEmpty').style.display = 'block';
        return;
      }
      var html = '';
      movs.forEach(function(m) {
        var tipoLabel = m.tipo === 'pago_total' ? 'Pago total' : (m.tipo === 'saldo_favor_aplicado' ? 'Saldo a favor aplicado' : 'Abono');
        html += '<div style="display:flex;justify-content:space-between;align-items:center;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-bottom:8px">'
          + '<div>'
          +   '<div style="font-size:13px"><strong>' + dprEscHtml(m.monto_fmt) + '</strong> · ' + dprEscHtml(tipoLabel) + '</div>'
          +   '<div style="font-size:11px;color:#64748b">' + dprEscHtml(m.fecha) + ' · ' + dprEscHtml(m.metodo || '') + (m.referencia ? ' · ' + dprEscHtml(m.referencia) : '') + (m.tiene_comprobante ? ' · con comprobante' : '') + '</div>'
          + '</div>'
          + '<button type="button" class="dpr-btn dpr-btn--sm dpr-btn--danger" onclick="dprSelectMovToDelete(' + m.id + ',\'' + dprEscHtml(m.monto_fmt) + '\',\'' + dprEscHtml(tipoLabel) + '\',\'' + dprEscHtml(m.fecha) + '\')">Eliminar</button>'
          + '</div>';
      });
      document.getElementById('deleteMovsList').innerHTML = html;
      document.getElementById('deleteMovsList').style.display = 'block';
    })
    .catch(function() {
      document.getElementById('deleteMovsLoading').style.display = 'none';
      document.getElementById('deleteMovsEmpty').textContent = 'No se pudo cargar la lista de pagos. Intenta de nuevo.';
      document.getElementById('deleteMovsEmpty').style.display = 'block';
    });
}
function dprSelectMovToDelete(partialId, montoFmt, tipoLabel, fecha) {
  document.getElementById('deletePartialId').value = partialId;
  document.getElementById('deleteMovSummary').innerHTML =
    'Vas a eliminar: <strong>' + montoFmt + '</strong> · ' + tipoLabel + ' · ' + fecha;
  document.getElementById('deleteMovsList').style.display    = 'none';
  document.getElementById('deleteModalFooter').style.display = 'none';
  document.getElementById('deleteMovForm').style.display     = 'block';
}
function dprCancelDeleteMov() {
  document.getElementById('deleteMovForm').style.display     = 'none';
  document.getElementById('deleteMovsList').style.display    = 'block';
  document.getElementById('deleteModalFooter').style.display = 'flex';
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}
document.querySelectorAll('.dpr-modal-backdrop').forEach(function(el) {
  el.addEventListener('click', function(e) {
    if (e.target === el) el.classList.remove('open');
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>