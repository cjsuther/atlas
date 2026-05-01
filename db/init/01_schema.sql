-- =====================================================================
-- ATLAS — Schema de base de datos
-- Administración y Trazabilidad de Licencias, Acuerdos y Servicios
-- MySQL 8 / utf8mb4
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Tabla: estado
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS estado (
  estado_id     INT AUTO_INCREMENT PRIMARY KEY,
  estado_nombre VARCHAR(100) NOT NULL,
  descripcion   TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: tipo_de_contrato
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tipo_de_contrato (
  id_tipo INT AUTO_INCREMENT PRIMARY KEY,
  tipo    VARCHAR(20)  NOT NULL,
  nombre  VARCHAR(200) NOT NULL,
  UNIQUE KEY uq_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: solicitantes
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS solicitantes (
  solicitante_id  INT AUTO_INCREMENT PRIMARY KEY,
  cuil_cuit       VARCHAR(20),
  razon_social    VARCHAR(300) NOT NULL,
  rubro           VARCHAR(200),
  localizacion    VARCHAR(300),
  telefono        VARCHAR(100),
  nombre_contacto VARCHAR(200),
  KEY idx_razon (razon_social)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: sector  (autorreferencia jerárquica)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sector (
  sector_id      INT AUTO_INCREMENT PRIMARY KEY,
  nombre         VARCHAR(200) NOT NULL,
  dependencia_id INT NULL,
  responsable    VARCHAR(200),
  web            VARCHAR(300),
  ubicacion      VARCHAR(200),
  KEY idx_nombre (nombre),
  CONSTRAINT fk_sector_dep
    FOREIGN KEY (dependencia_id) REFERENCES sector(sector_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: utt
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS utt (
  utt_id       INT AUTO_INCREMENT PRIMARY KEY,
  denominacion VARCHAR(50)  NOT NULL,
  nombre       VARCHAR(300) NOT NULL,
  UNIQUE KEY uq_denom (denominacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: uvt
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS uvt (
  uvt_id      INT AUTO_INCREMENT PRIMARY KEY,
  siglas      VARCHAR(50)  NOT NULL,
  nombre      VARCHAR(300) NOT NULL,
  responsable VARCHAR(200),
  UNIQUE KEY uq_siglas (siglas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: personal
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS personal (
  legajo           INT PRIMARY KEY,
  apellido         VARCHAR(100) NOT NULL,
  nombre           VARCHAR(100) NOT NULL,
  interno          VARCHAR(20),
  mail             VARCHAR(200),
  lugar_trabajo_id INT NULL,
  KEY idx_apellido (apellido),
  CONSTRAINT fk_personal_sector
    FOREIGN KEY (lugar_trabajo_id) REFERENCES sector(sector_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: user_roles  (gestión interna de roles, sin contraseña)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_roles (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  username   VARCHAR(200) NOT NULL,
  display_name VARCHAR(200),
  email      VARCHAR(200),
  rol        ENUM('admin','operador','consulta') NOT NULL DEFAULT 'consulta',
  activo     TINYINT(1) DEFAULT 1,
  last_login TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: contratos  (entidad principal)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contratos (
  id_cto                     INT AUTO_INCREMENT PRIMARY KEY,
  nombre_proy                VARCHAR(500) NOT NULL,
  dependencia_contractual_id INT NULL,
  operatoria_id              INT NULL,
  fecha_expediente           DATE,
  estado_id                  INT,
  expediente                 VARCHAR(200),
  solicitud_sector_gde       VARCHAR(300),
  descripcion_objeto         TEXT,
  tipo_de_contrato_id        INT,
  observaciones              TEXT,
  solicitante_id             INT,
  uvt_id                     INT,
  sector_id                  INT,
  gerencia                   VARCHAR(200),
  gerencia_area              VARCHAR(200),
  fecha_firma                DATE,
  fecha_inicio               DATE,
  fecha_vencimiento          DATE,
  fecha_finalizado           DATE,
  duracion_meses             INT,
  atraso_meses               INT,
  prorroga                   TINYINT(1) DEFAULT 0,
  renovacion_automatica      TINYINT(1) DEFAULT 0,
  acta_finalizacion          VARCHAR(500),
  resp1_id                   INT NULL,
  resp2_id                   INT NULL,
  caja_bas                   VARCHAR(200),
  resp_caja                  VARCHAR(200),
  monto_pesos                DECIMAL(18,2),
  monto_usd                  DECIMAL(18,2),
  monto_euros                DECIMAL(18,2),
  monto_otro                 DECIMAL(18,2),
  moneda_otro                VARCHAR(50),
  automatico_ejecucion       TINYINT(1) DEFAULT 0,
  automatico_finalizado      TINYINT(1) DEFAULT 0,
  created_at                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_nombre_proy        (nombre_proy(191)),
  KEY idx_expediente         (expediente),
  KEY idx_fecha_vencimiento  (fecha_vencimiento),
  KEY idx_estado             (estado_id),
  KEY idx_tipo_contrato      (tipo_de_contrato_id),
  KEY idx_sector             (sector_id),
  KEY idx_uvt                (uvt_id),
  KEY idx_solicitante        (solicitante_id),

  CONSTRAINT fk_cto_dep
    FOREIGN KEY (dependencia_contractual_id) REFERENCES contratos(id_cto)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_cto_estado
    FOREIGN KEY (estado_id) REFERENCES estado(estado_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_cto_tipo
    FOREIGN KEY (tipo_de_contrato_id) REFERENCES tipo_de_contrato(id_tipo)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_cto_solic
    FOREIGN KEY (solicitante_id) REFERENCES solicitantes(solicitante_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_cto_uvt
    FOREIGN KEY (uvt_id) REFERENCES uvt(uvt_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_cto_sector
    FOREIGN KEY (sector_id) REFERENCES sector(sector_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_cto_resp1
    FOREIGN KEY (resp1_id) REFERENCES personal(legajo)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_cto_resp2
    FOREIGN KEY (resp2_id) REFERENCES personal(legajo)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tablas de Laravel Sanctum (tokens) y de sesiones/cache (driver file no las necesita,
-- pero personal_access_tokens sí es requerida por Sanctum)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS personal_access_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tokenable_type VARCHAR(255) NOT NULL,
  tokenable_id   BIGINT UNSIGNED NOT NULL,
  name           VARCHAR(255) NOT NULL,
  token          VARCHAR(64)  NOT NULL,
  abilities      TEXT NULL,
  last_used_at   TIMESTAMP NULL,
  expires_at     TIMESTAMP NULL,
  created_at     TIMESTAMP NULL,
  updated_at     TIMESTAMP NULL,
  UNIQUE KEY uq_pat_token (token),
  KEY idx_pat_tokenable (tokenable_type, tokenable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrations (Laravel control table — se mantiene para compatibilidad si en el futuro
-- se ejecutan migraciones adicionales)
CREATE TABLE IF NOT EXISTS migrations (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  migration VARCHAR(255) NOT NULL,
  batch     INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
