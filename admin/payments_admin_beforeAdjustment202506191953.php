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
					// Línea 78-84 — con migración
					db_execute($mysqli,
						"UPDATE payments SET estado='pagado', metodo=?, referencia_pago=?, fecha_pago=?,
						 valor_mora=?, valor_total=?, monto_pagado=?, nota_admin=?,
						 comprobante_url=COALESCE(?,comprobante_url),
						 validado_por=?, fecha_validacion=NOW(), updated_at=NOW() WHERE id=?",
						'',  // ← vacío, inferencia automática
						[$metodo,$ref,$fecha_p,$mora_adj,$total,$total,$nota,$comp_url,$user['id'],$pid]);
				} else {
					// Línea 86-92 — sin migración
					db_execute($mysqli,
						"UPDATE payments SET estado='pagado', metodo=?, referencia_pago=?, fecha_pago=?,
						 valor_mora=?, valor_total=?, nota_admin=?,
						 comprobante_url=COALESCE(?,comprobante_url),
						 validado_por=?, fecha_validacion=NOW(), updated_at=NOW() WHERE id=?",
						'',  // ← vacío
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
                    db_execute($mysqli,
                        "UPDATE payments SET metodo=?, referencia_pago=?, fecha_pago=?,
                         valor_mora=?, valor_total=?, monto_pagado=CASE WHEN estado='pagado' THEN ? ELSE monto_pagado END,
                         nota_admin=?, comprobante_url=COALESCE(?,comprobante_url), updated_at=NOW() WHERE id=?",
                        '',
                        [$metodo,$ref,$fecha_p,$mora_adj,$total,$total,$nota,$comp_url,$pid]);
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
            $pill = 'neutral';
            if ($estado === 'pagado')    $pill = 'ok';
            elseif ($estado === 'validando') $pill = 'warn';
            elseif ($estado === 'moroso')   $pill = 'danger';
            elseif ($estado === 'pendiente') $pill = 'info';

            $dias       = (int)($p['dias_vencido'] ?? 0);
            $saldo_pend = (float)($p['saldo_pendiente'] ?? 0);
            $abonado    = (float)($p['monto_pagado'] ?? 0);
            $saldo_fav  = (float)($p['saldo_favor']  ?? 0);
        ?>
        <tr>
          <td>
            <?= h($p['inquilino']) ?>
            <?php if ($has_migration && $saldo_fav > 0): ?>
            <span class="dpr-pill dpr-pill--ok" style="font-size:10px;margin-left:4px" title="Saldo a favor">+<?= fmt_money($saldo_fav) ?></span>
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
            <span class="dpr-pill dpr-pill--<?= $pill ?>"><?= ucfirst($estado) ?></span>
            <?php if ($has_migration && !empty($p['tiene_abonos'])): ?>
            <span class="dpr-pill dpr-pill--info" style="font-size:10px;margin-left:4px">Parcial</span>
            <?php endif; ?>
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
              onclick="openEditModal(<?= (int)$p['id'] ?>,'<?= h(addslashes($p['inquilino'])) ?>','<?= h($p['metodo'] ?? 'efectivo') ?>','<?= h(addslashes($p['referencia_pago'] ?? '')) ?>','<?= h($p['fecha_pago'] ? substr($p['fecha_pago'], 0, 10) : '') ?>',<?= (float)$p['valor_mora'] ?>,'<?= h(addslashes($p['nota_admin'] ?? '')) ?>')">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M9.5 1.5l3 3-8 8H1.5v-3l8-8z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
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
        Esto corrige los datos del pago tal como quedaron registrados. El cambio queda registrado en el log de auditoría.
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
function openEditModal(id, inq, metodo, ref, fechaPago, mora, nota) {
  document.getElementById('editPayId').value      = id;
  document.getElementById('editMetodo').value     = metodo;
  document.getElementById('editReferencia').value = ref;
  document.getElementById('editFechaPago').value  = fechaPago;
  document.getElementById('editMora').value       = mora;
  document.getElementById('editNota').value       = nota;
  document.getElementById('editInfo').innerHTML =
    '<strong>' + inq + '</strong> · Corrigiendo datos del pago registrado';
  document.getElementById('editModal').classList.add('open');
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