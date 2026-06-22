-- =============================================================
--  PropertyRent — Migración 4: Ledger de Pagos (rediseño)
--  Introduce: obligations, payments_received, payment_applications
--  NO elimina payments / payment_partials (se mantienen para
--  módulos no migrados todavía: dashboard.php, reports.php,
--  tenant/payments.php). payments_admin2.php opera 100% sobre
--  las tablas nuevas. Coexistencia intencional durante transición.
-- =============================================================

START TRANSACTION;

-- -------------------------------------------------------------
-- 1) OBLIGATIONS — toda deuda del inquilino, por concepto
-- -------------------------------------------------------------
CREATE TABLE `obligations` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `lease_id` int(10) UNSIGNED NOT NULL,
  `concepto` enum('alquiler','agua','energia','gas','aseo','multa','otro') NOT NULL,
  `periodo` varchar(7) DEFAULT NULL COMMENT 'YYYY-MM — referencia del mes/año, NULL si no aplica (ej: multa puntual)',
  `valor_calculado` decimal(12,2) DEFAULT NULL COMMENT 'Valor sugerido por el sistema (ej: lectura de medidor). NULL si la obligación nació manual.',
  `valor_ajustado` decimal(12,2) DEFAULT NULL COMMENT 'Si no es NULL, este valor manda sobre valor_calculado (ajuste manual del admin).',
  `valor_efectivo` decimal(12,2) GENERATED ALWAYS AS (COALESCE(`valor_ajustado`, `valor_calculado`, 0)) STORED COMMENT 'Valor real de la obligación — siempre usar esta columna para sumas.',
  `fecha_limite` date DEFAULT NULL,
  `nota` varchar(500) DEFAULT NULL,
  `origen` enum('sistema','manual') NOT NULL DEFAULT 'manual' COMMENT 'sistema = generada por cron/medidores; manual = creada vía botón Nueva Obligación',
  `meter_reading_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'FK opcional a meter_readings cuando origen=sistema y concepto es un servicio',
  `estado` enum('activa','anulada') NOT NULL DEFAULT 'activa' COMMENT 'Borrado lógico — nunca DELETE físico de una obligación con historial de pagos',
  `nota_anulacion` varchar(500) DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_obl_lease` (`lease_id`),
  KEY `idx_obl_concepto` (`concepto`),
  KEY `idx_obl_periodo` (`periodo`),
  KEY `idx_obl_estado` (`estado`),
  KEY `idx_obl_lease_estado` (`lease_id`,`estado`),
  KEY `fk_obl_meter` (`meter_reading_id`),
  KEY `fk_obl_creator` (`created_by`),
  CONSTRAINT `fk_obl_lease` FOREIGN KEY (`lease_id`) REFERENCES `leases` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_obl_meter` FOREIGN KEY (`meter_reading_id`) REFERENCES `meter_readings` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_obl_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Obligaciones (deudas) del inquilino por concepto — reemplaza el rol de payments';

-- -------------------------------------------------------------
-- 2) PAYMENTS_RECEIVED — el dinero que entra (una transacción)
-- -------------------------------------------------------------
CREATE TABLE `payments_received` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `lease_id` int(10) UNSIGNED NOT NULL,
  `monto_total` decimal(12,2) NOT NULL COMMENT 'Monto total recibido en esta transacción, antes de repartir por concepto',
  `metodo` enum('pse','tarjeta','transferencia','efectivo','nequi','daviplata','otro') NOT NULL DEFAULT 'efectivo',
  `referencia` varchar(100) DEFAULT NULL,
  `comprobante_url` varchar(255) DEFAULT NULL COMMENT 'Un solo comprobante por pago, aunque se reparta en varios conceptos',
  `nota` text DEFAULT NULL,
  `fecha_pago` date NOT NULL,
  `recibido_por` int(10) UNSIGNED NOT NULL COMMENT 'user_id de quien registra el pago (admin) o lo sube (tenant)',
  `rol_origen` enum('admin','tenant') NOT NULL DEFAULT 'admin',
  `estado` enum('validando','aprobado','rechazado') NOT NULL DEFAULT 'aprobado' COMMENT 'tenant siempre entra en validando; admin entra directo en aprobado',
  `nota_admin` varchar(500) DEFAULT NULL COMMENT 'Motivo de rechazo o comentario de aprobación',
  `validado_por` int(10) UNSIGNED DEFAULT NULL,
  `fecha_validacion` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pr_lease` (`lease_id`),
  KEY `idx_pr_estado` (`estado`),
  KEY `idx_pr_fecha` (`fecha_pago`),
  KEY `fk_pr_recibido` (`recibido_por`),
  KEY `fk_pr_validado` (`validado_por`),
  CONSTRAINT `fk_pr_lease` FOREIGN KEY (`lease_id`) REFERENCES `leases` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_pr_recibido` FOREIGN KEY (`recibido_por`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_pr_validado` FOREIGN KEY (`validado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pagos recibidos — la transacción de dinero, separada de cómo se aplica';

-- -------------------------------------------------------------
-- 3) PAYMENT_APPLICATIONS — el ledger: cómo se reparte cada pago
-- -------------------------------------------------------------
CREATE TABLE `payment_applications` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id` int(10) UNSIGNED NOT NULL,
  `obligation_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'NULL = excedente sin asignar todavía (concepto=otro, reasignable después)',
  `concepto` enum('alquiler','agua','energia','gas','aseo','multa','otro') NOT NULL COMMENT 'Redundante con obligations.concepto pero necesario: puede no haber obligation_id',
  `monto` decimal(12,2) NOT NULL,
  `nota` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pa_payment` (`payment_id`),
  KEY `idx_pa_obligation` (`obligation_id`),
  KEY `idx_pa_concepto` (`concepto`),
  KEY `fk_pa_creator` (`created_by`),
  CONSTRAINT `fk_pa_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments_received` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pa_obligation` FOREIGN KEY (`obligation_id`) REFERENCES `obligations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pa_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cómo se reparte cada pago entre obligaciones/conceptos — el ledger real';

-- -------------------------------------------------------------
-- 4) Vista: saldo por lease (obligaciones activas - pagos aprobados aplicados)
-- -------------------------------------------------------------
DROP VIEW IF EXISTS `v_lease_saldo`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_lease_saldo` AS
SELECT
    l.id AS lease_id,
    CONCAT(u.nombre,' ',u.apellido) AS inquilino,
    un.nombre AS unidad,
    pr.nombre AS inmueble,
    COALESCE(obl.total_obligado, 0) AS total_obligado,
    COALESCE(pag.total_pagado, 0) AS total_pagado,
    COALESCE(obl.total_obligado, 0) - COALESCE(pag.total_pagado, 0) AS saldo_pendiente
FROM leases l
JOIN users u ON u.id = l.tenant_id
JOIN units un ON un.id = l.unit_id
JOIN properties pr ON pr.id = un.property_id
LEFT JOIN (
    SELECT lease_id, SUM(valor_efectivo) AS total_obligado
    FROM obligations
    WHERE estado = 'activa'
    GROUP BY lease_id
) obl ON obl.lease_id = l.id
LEFT JOIN (
    SELECT pr2.lease_id, SUM(pa.monto) AS total_pagado
    FROM payment_applications pa
    JOIN payments_received pr2 ON pr2.id = pa.payment_id
    WHERE pr2.estado = 'aprobado'
    GROUP BY pr2.lease_id
) pag ON pag.lease_id = l.id;

-- -------------------------------------------------------------
-- 5) Vista: movimientos combinados (obligaciones + pagos) para
--    la tabla de consecutivo con paginación en payments_admin2.php
-- -------------------------------------------------------------
DROP VIEW IF EXISTS `v_ledger_movimientos`;
CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `v_ledger_movimientos` AS
SELECT
    CONCAT('obl_', o.id) AS row_key,
    'obligacion' AS tipo_movimiento,
    o.id AS obligation_id,
    NULL AS payment_id,
    o.lease_id,
    o.concepto,
    o.periodo,
    o.valor_efectivo AS monto,
    o.fecha_limite,
    o.estado AS estado_detalle,
    o.origen,
    o.created_by,
    o.created_at
FROM obligations o
UNION ALL
SELECT
    CONCAT('app_', pa.id) AS row_key,
    'pago' AS tipo_movimiento,
    pa.obligation_id,
    pa.payment_id,
    pr2.lease_id,
    pa.concepto,
    NULL AS periodo,
    pa.monto,
    NULL AS fecha_limite,
    pr2.estado AS estado_detalle,
    NULL AS origen,
    pa.created_by,
    pa.created_at
FROM payment_applications pa
JOIN payments_received pr2 ON pr2.id = pa.payment_id;

COMMIT;

-- -------------------------------------------------------------
-- NOTA DE USO — v_ledger_movimientos
-- -------------------------------------------------------------
-- row_key NO es numérico (mezcla 'obl_N' / 'app_N' de dos tablas con
-- IDs independientes) — no sirve para ORDER BY. Para el consecutivo
-- con "lo más reciente al principio" en payments_admin2.php, ordenar
-- SIEMPRE por created_at DESC, y como desempate usar row_key DESC
-- (estable porque created_at tiene resolución de segundo y dos
-- movimientos del mismo segundo deben mantener un orden consistente
-- entre cargas de página, aunque no sea "el más nuevo exacto"):
--   ORDER BY created_at DESC, row_key DESC
-- Para paginación real (LIMIT/OFFSET) sobre el UNION, ejecutar este
-- ORDER BY en la query externa, no dentro de la vista (las vistas con
-- UNION no garantizan orden persistente sin ORDER BY explícito fuera).

