SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS usuarios_sistema (
  id_usuario BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(160) NOT NULL,
  apellidos VARCHAR(160) NULL,
  correo VARCHAR(190) NOT NULL,
  foto_perfil VARCHAR(255) NULL,
  password_hash VARCHAR(255) NOT NULL,
  rol ENUM('superadministrador','administrador','asesor','consulta') NOT NULL DEFAULT 'administrador',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_usuario),
  UNIQUE KEY uq_usuario_correo (correo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO usuarios_sistema (nombre, correo, password_hash, rol, activo)
SELECT 'Administrador', 'admin@aidora.com', '$2y$12$c4lfXuzWDe27ufsNOlhGvOBjqOmFcBEt02Dz0/ThpGE2d6if9GfhO', 'superadministrador', 1
WHERE NOT EXISTS (SELECT 1 FROM usuarios_sistema WHERE correo='admin@aidora.com');


CREATE TABLE IF NOT EXISTS usuario_permisos (
  id_usuario BIGINT UNSIGNED NOT NULL,
  modulo VARCHAR(60) NOT NULL,
  puede_ver TINYINT(1) NOT NULL DEFAULT 0,
  puede_editar TINYINT(1) NOT NULL DEFAULT 0,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_usuario, modulo),
  KEY idx_perm_modulo (modulo),
  CONSTRAINT fk_perm_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios_sistema(id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contactos (
  id_contacto BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  whatsapp VARCHAR(25) NOT NULL,
  nombre_completo VARCHAR(180) NULL,
  correo VARCHAR(190) NULL,
  empresa VARCHAR(190) NULL,
  tipo_contacto ENUM('prospecto','prospecto_recurrente','cliente','cliente_recurrente') NOT NULL DEFAULT 'prospecto',
  origen VARCHAR(120) NOT NULL DEFAULT 'whatsapp',
  primera_interaccion_en DATETIME NULL,
  ultima_interaccion_en DATETIME NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  notas_generales TEXT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_contacto),
  UNIQUE KEY uq_contacto_whatsapp (whatsapp),
  KEY idx_contactos_tipo (tipo_contacto),
  KEY idx_contactos_ultima (ultima_interaccion_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversaciones (
  id_conversacion BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contacto BIGINT UNSIGNED NOT NULL,
  canal VARCHAR(30) NOT NULL DEFAULT 'whatsapp',
  estado ENUM('abierta','finalizada') NOT NULL DEFAULT 'abierta',
  iniciada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finalizada_en DATETIME NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_conversacion),
  KEY idx_conv_contacto (id_contacto),
  KEY idx_conv_estado (estado),
  CONSTRAINT fk_conv_contacto FOREIGN KEY (id_contacto) REFERENCES contactos(id_contacto) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS interacciones (
  id_interaccion BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contacto BIGINT UNSIGNED NOT NULL,
  id_conversacion BIGINT UNSIGNED NULL,
  direccion ENUM('entrante','saliente') NOT NULL,
  tipo_mensaje VARCHAR(40) NOT NULL DEFAULT 'texto',
  meta_message_id VARCHAR(191) NULL,
  paso_flujo VARCHAR(100) NULL,
  contenido TEXT NULL,
  payload_json JSON NULL,
  estado_envio VARCHAR(40) NULL,
  detalle_error TEXT NULL,
  registrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_interaccion),
  KEY idx_inter_contacto (id_contacto),
  KEY idx_inter_conv (id_conversacion),
  KEY idx_inter_fecha (registrado_en),
  CONSTRAINT fk_inter_contacto FOREIGN KEY (id_contacto) REFERENCES contactos(id_contacto) ON DELETE CASCADE,
  CONSTRAINT fk_inter_conv FOREIGN KEY (id_conversacion) REFERENCES conversaciones(id_conversacion) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_sesiones (
  whatsapp VARCHAR(25) NOT NULL,
  id_contacto BIGINT UNSIGNED NULL,
  id_conversacion BIGINT UNSIGNED NULL,
  paso VARCHAR(100) NOT NULL DEFAULT 'menu',
  datos_json LONGTEXT NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (whatsapp),
  KEY idx_sesion_contacto (id_contacto),
  CONSTRAINT fk_sesion_contacto FOREIGN KEY (id_contacto) REFERENCES contactos(id_contacto) ON DELETE CASCADE,
  CONSTRAINT fk_sesion_conv FOREIGN KEY (id_conversacion) REFERENCES conversaciones(id_conversacion) ON DELETE SET NULL
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
  KEY idx_aidor_fecha (creado_en),
  CONSTRAINT fk_aidor_contacto FOREIGN KEY (id_contacto) REFERENCES contactos(id_contacto) ON DELETE CASCADE,
  CONSTRAINT fk_aidor_conv FOREIGN KEY (id_conversacion) REFERENCES conversaciones(id_conversacion) ON DELETE SET NULL
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
  KEY idx_asesor_estado (estado),
  CONSTRAINT fk_asesor_contacto FOREIGN KEY (id_contacto) REFERENCES contactos(id_contacto) ON DELETE CASCADE,
  CONSTRAINT fk_asesor_conv FOREIGN KEY (id_conversacion) REFERENCES conversaciones(id_conversacion) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mensajes_campanas (
  id_campana BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(180) NULL,
  mensaje TEXT NOT NULL,
  segmento VARCHAR(100) NULL,
  total_destinatarios INT UNSIGNED NOT NULL DEFAULT 0,
  creado_por BIGINT UNSIGNED NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_campana),
  KEY idx_campana_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mensajes_envios (
  id_envio BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_campana BIGINT UNSIGNED NULL,
  id_contacto BIGINT UNSIGNED NOT NULL,
  whatsapp VARCHAR(25) NOT NULL,
  mensaje TEXT NOT NULL,
  estado ENUM('pendiente','aceptado_meta','enviado','entregado','leido','error') NOT NULL DEFAULT 'pendiente',
  tipo_envio ENUM('template') NOT NULL DEFAULT 'template',
  template_nombre VARCHAR(191) NULL,
  intentos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  meta_message_id VARCHAR(191) NULL,
  detalle_error TEXT NULL,
  procesado_en DATETIME NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_envio),
  KEY idx_envio_contacto (id_contacto),
  KEY idx_envio_estado (estado),
  KEY idx_envio_fecha (creado_en),
  CONSTRAINT fk_envio_contacto FOREIGN KEY (id_contacto) REFERENCES contactos(id_contacto) ON DELETE CASCADE,
  CONSTRAINT fk_envio_campana FOREIGN KEY (id_campana) REFERENCES mensajes_campanas(id_campana) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configuracion_bot (
  clave VARCHAR(120) NOT NULL,
  valor TEXT NULL,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracion_bot (clave, valor) VALUES
('whatsapp_template_marketing','aidora_plantilla'),
('whatsapp_template_language','es_MX')
ON DUPLICATE KEY UPDATE valor=VALUES(valor);
