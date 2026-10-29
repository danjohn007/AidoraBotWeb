SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS bot_sesiones (
  whatsapp VARCHAR(25) NOT NULL,
  id_contacto BIGINT UNSIGNED NULL,
  id_conversacion BIGINT UNSIGNED NULL,
  paso VARCHAR(100) NOT NULL DEFAULT 'menu',
  datos_json LONGTEXT NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (whatsapp),
  KEY idx_sesion_contacto (id_contacto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS solicitudes_aidores (
  id_solicitud BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contacto BIGINT UNSIGNED NOT NULL,
  id_conversacion BIGINT UNSIGNED NULL,
  perfil_interes VARCHAR(80) NULL,
  nombre_completo VARCHAR(180) NOT NULL,
  edad SMALLINT UNSIGNED NULL,
  telefono_contacto VARCHAR(40) NULL,
  whatsapp VARCHAR(25) NOT NULL,
  correo VARCHAR(190) NULL,
  ciudad VARCHAR(160) NULL,
  zona_servicio VARCHAR(160) NULL,
  nivel_estudios VARCHAR(120) NULL,
  formacion_cuidado VARCHAR(160) NULL,
  experiencia_anios VARCHAR(80) NULL,
  actividades_experiencia TEXT NULL,
  tipos_servicio TEXT NULL,
  dias_disponibles TEXT NULL,
  movilidad VARCHAR(80) NULL,
  distancia_traslado VARCHAR(120) NULL,
  motivacion TEXT NULL,
  significado_cuidar TEXT NULL,
  estado ENUM('nueva','en_revision','contactado','aprobado','no_aprobado') NOT NULL DEFAULT 'nueva',
  notas TEXT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_solicitud),
  KEY idx_aidor_contacto (id_contacto),
  KEY idx_aidor_estado (estado),
  KEY idx_aidor_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS solicitudes_asesor (
  id_solicitud BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contacto BIGINT UNSIGNED NOT NULL,
  id_conversacion BIGINT UNSIGNED NULL,
  whatsapp VARCHAR(25) NOT NULL,
  origen_solicitud VARCHAR(120) NOT NULL DEFAULT 'menu_principal',
  estado ENUM('nueva','contactado','cerrada') NOT NULL DEFAULT 'nueva',
  notas TEXT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_solicitud),
  KEY idx_asesor_contacto (id_contacto),
  KEY idx_asesor_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracion_bot (clave, valor) VALUES
('whatsapp_template_marketing','aidora_plantilla'),
('whatsapp_template_language','es_MX')
ON DUPLICATE KEY UPDATE valor=VALUES(valor);
