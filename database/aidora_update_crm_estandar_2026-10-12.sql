-- Aidora CRM · Customer Journey + Mi perfil + Usuarios y permisos
-- Ejecutar UNA VEZ sobre la base existente crmcamar_aidora.

SET NAMES utf8mb4;

ALTER TABLE usuarios_sistema
  ADD COLUMN IF NOT EXISTS apellidos VARCHAR(160) NULL AFTER nombre,
  ADD COLUMN IF NOT EXISTS foto_perfil VARCHAR(255) NULL AFTER correo;

CREATE TABLE IF NOT EXISTS usuario_permisos (
  id_usuario BIGINT UNSIGNED NOT NULL,
  modulo VARCHAR(60) NOT NULL,
  puede_ver TINYINT(1) NOT NULL DEFAULT 0,
  puede_editar TINYINT(1) NOT NULL DEFAULT 0,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_usuario, modulo),
  KEY idx_perm_modulo (modulo),
  CONSTRAINT fk_perm_usuario FOREIGN KEY (id_usuario)
    REFERENCES usuarios_sistema(id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A los administradores existentes se les conserva acceso completo.
INSERT INTO usuario_permisos (id_usuario, modulo, puede_ver, puede_editar)
SELECT u.id_usuario, m.modulo, 1, 1
FROM usuarios_sistema u
JOIN (
  SELECT 'dashboard' modulo UNION ALL
  SELECT 'solicitudes' UNION ALL
  SELECT 'asesor' UNION ALL
  SELECT 'contactos' UNION ALL
  SELECT 'conversaciones' UNION ALL
  SELECT 'mensajes' UNION ALL
  SELECT 'configuracion' UNION ALL
  SELECT 'usuarios'
) m
WHERE u.rol='administrador'
ON DUPLICATE KEY UPDATE puede_ver=VALUES(puede_ver), puede_editar=VALUES(puede_editar);

-- Sincronización inicial del Customer Journey.
-- Aidora adapta la regla estándar así:
-- 1-2 consultas sin registro aprobado = prospecto
-- 3+ consultas sin registro aprobado = prospecto recurrente
-- 1-3 registros aprobados = cliente
-- 4+ registros aprobados = cliente recurrente
UPDATE contactos c SET tipo_contacto = CASE
  WHEN (SELECT COUNT(*) FROM solicitudes_aidores s WHERE s.id_contacto=c.id_contacto AND s.estado='aprobado') >= 4 THEN 'cliente_recurrente'
  WHEN (SELECT COUNT(*) FROM solicitudes_aidores s WHERE s.id_contacto=c.id_contacto AND s.estado='aprobado') >= 1 THEN 'cliente'
  WHEN (SELECT COUNT(*) FROM conversaciones cv WHERE cv.id_contacto=c.id_contacto) >= 3 THEN 'prospecto_recurrente'
  ELSE 'prospecto'
END;
