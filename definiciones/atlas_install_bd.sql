-- =====================================================================
-- ATLAS v2 — Script de instalación de base de datos (servidor externo)
--
-- Destinatario: DBA de la institución.
-- Motor:        MySQL 8.x (utf8mb4 / utf8mb4_unicode_ci)
-- Uso típico:
--   mysql -h <host> -P <port> -u <admin_user> -p < atlas_install_bd.sql
--
-- El script es IDEMPOTENTE: usa CREATE TABLE IF NOT EXISTS y ON DUPLICATE
-- KEY UPDATE en los INSERT de catálogos. Puede ejecutarse varias veces
-- sin riesgo sobre instalaciones existentes.
--
-- Pasos cubiertos:
--   1) Creación de la base de datos `atlas` con charset utf8mb4.
--   2) Creación del usuario aplicativo (BLOQUE COMENTADO — ajustar antes).
--   3) Creación del esquema completo (15 tablas).
--   4) Carga de catálogos iniciales (estados, tipos, UTTs, UVTs).
--   5) (Opcional) Designación del primer administrador (BLOQUE COMENTADO).
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) Base de datos
-- ---------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS atlas
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE atlas;

-- ---------------------------------------------------------------------
-- 2) Usuario aplicativo
-- ---------------------------------------------------------------------
-- IMPORTANTE: descomentar y AJUSTAR la contraseña antes de ejecutar.
-- Restringir el host al rango de IPs de los servidores de aplicación.
--
-- CREATE USER IF NOT EXISTS 'atlas_app'@'10.0.0.%' IDENTIFIED BY 'CAMBIAR_EN_PRODUCCION';
-- GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE
--   ON atlas.* TO 'atlas_app'@'10.0.0.%';
-- -- Si el comando atlas:* (artisan) se ejecutará desde el server de app,
-- -- también se necesitan permisos de DDL para futuras migraciones:
-- GRANT CREATE, ALTER, DROP, INDEX, REFERENCES ON atlas.* TO 'atlas_app'@'10.0.0.%';
-- FLUSH PRIVILEGES;

-- ---------------------------------------------------------------------
-- 3) Esquema
-- ---------------------------------------------------------------------
-- =====================================================================
-- ATLAS v2 — Schema de base de datos
-- Administración y Trazabilidad de Licencias, Acuerdos y Servicios
-- MySQL 8 / utf8mb4
--
-- Cambios v2:
--   * Se separa la tabla `contratos` en `contratos_principal` y `contratos_ejecucion`.
--   * Se separan tipos y estados en tablas distintas para principal y ejecución.
--   * Se incorpora `historial_cambios` (auditoría obligatoria).
--   * Se agrega `regimen` a `utt`.
--   * Toda baja es lógica (`deleted_at`).
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Catálogos: tipo de contrato (principal y ejecución, separados)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tipo_contrato_principal (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  sigla  VARCHAR(20)  NOT NULL,
  nombre VARCHAR(200) NOT NULL,
  UNIQUE KEY uq_tcp_sigla (sigla)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tipo_contrato_ejecucion (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  sigla  VARCHAR(20)  NOT NULL,
  nombre VARCHAR(200) NOT NULL,
  UNIQUE KEY uq_tce_sigla (sigla)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Catálogos: estados (principal y ejecución, separados)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS estado_principal (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  UNIQUE KEY uq_ep_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS estado_ejecucion (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nombre      VARCHAR(100) NOT NULL,
  descripcion TEXT,
  UNIQUE KEY uq_ee_nombre (nombre)
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
-- Tabla: utt (con régimen 160/317/ambos)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS utt (
  utt_id       INT AUTO_INCREMENT PRIMARY KEY,
  denominacion VARCHAR(50)  NOT NULL,
  nombre       VARCHAR(300) NOT NULL,
  regimen      VARCHAR(20)  NULL,    -- '160', '317', 'ambos'
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
-- Tabla: user_roles  (gestión interna de roles, opcionalmente con password)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_roles (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  username     VARCHAR(200) NOT NULL,
  display_name VARCHAR(200),
  email        VARCHAR(200),
  password     VARCHAR(255) NULL,
  rol          ENUM('admin','operador','consulta') NOT NULL DEFAULT 'consulta',
  activo       TINYINT(1) DEFAULT 1,
  last_login   TIMESTAMP NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: contratos_principal
-- Acuerdo marco. Tipos: CATP, CLIT, AP. Régimen: 160 ó 317.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contratos_principal (
  id                          INT AUTO_INCREMENT PRIMARY KEY,
  nro_expediente              VARCHAR(100) NOT NULL,
  fecha_apertura_expediente   DATE         NULL,
  regimen                     ENUM('160','317') NOT NULL,
  tipo_contrato_id            INT          NOT NULL,
  nombre_proyecto             VARCHAR(500) NOT NULL,
  descripcion_objeto          TEXT         NULL,
  gerencia_area               VARCHAR(200) NULL,
  gerencia                    VARCHAR(200) NULL,
  solicitante_id              INT          NULL,
  resp1_id                    INT          NULL,
  resp2_id                    INT          NULL,
  utt_id                      INT          NULL,
  estado_id                   INT          NOT NULL,
  observaciones               TEXT         NULL,
  uvt_id                      INT          NULL,
  cliente                     VARCHAR(300) NULL,
  fecha_inicio                DATE         NULL,
  fecha_vencimiento           DATE         NULL,
  fecha_finalizacion          DATE         NULL,
  acta_finalizacion           VARCHAR(500) NULL,
  prorroga                    TINYINT(1)   NOT NULL DEFAULT 0,
  renovacion_automatica       TINYINT(1)   NOT NULL DEFAULT 0,
  caja_bas                    VARCHAR(200) NULL,
  moneda                      ENUM('Peso','Dólar','Euro','Otro') NOT NULL DEFAULT 'Peso',
  cotizacion                  DECIMAL(18,4) NULL,
  deleted_at                  TIMESTAMP    NULL,
  created_at                  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_cp_expediente       (nro_expediente),
  KEY idx_cp_nombre           (nombre_proyecto(191)),
  KEY idx_cp_estado           (estado_id),
  KEY idx_cp_tipo             (tipo_contrato_id),
  KEY idx_cp_uvt              (uvt_id),
  KEY idx_cp_solicitante      (solicitante_id),
  KEY idx_cp_vencimiento      (fecha_vencimiento),
  KEY idx_cp_deleted          (deleted_at),

  CONSTRAINT fk_cp_tipo
    FOREIGN KEY (tipo_contrato_id) REFERENCES tipo_contrato_principal(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_cp_estado
    FOREIGN KEY (estado_id) REFERENCES estado_principal(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_cp_solic
    FOREIGN KEY (solicitante_id) REFERENCES solicitantes(solicitante_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_cp_uvt
    FOREIGN KEY (uvt_id) REFERENCES uvt(uvt_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_cp_utt
    FOREIGN KEY (utt_id) REFERENCES utt(utt_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_cp_resp1
    FOREIGN KEY (resp1_id) REFERENCES personal(legajo)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_cp_resp2
    FOREIGN KEY (resp2_id) REFERENCES personal(legajo)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: contratos_ejecucion
-- Contrato concreto. Tipos: CP, CIT, CE. Vinculado a un principal salvo AP.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contratos_ejecucion (
  id                          INT AUTO_INCREMENT PRIMARY KEY,
  nro_expediente              VARCHAR(100) NOT NULL,
  fecha_apertura_expediente   DATE         NULL,
  tipo_contrato_id            INT          NOT NULL,
  nombre_proyecto             VARCHAR(500) NOT NULL,
  descripcion_objeto          TEXT         NULL,
  contrato_principal_id       INT          NULL,
  gerencia_area               VARCHAR(200) NULL,
  gerencia                    VARCHAR(200) NULL,
  solicitante_id              INT          NULL,
  resp1_id                    INT          NULL,
  resp2_id                    INT          NULL,
  utt_id                      INT          NULL,
  estado_id                   INT          NOT NULL,
  observaciones               TEXT         NULL,
  uvt_id                      INT          NULL,
  cliente                     VARCHAR(300) NULL,
  fecha_inicio                DATE         NULL,
  fecha_vencimiento           DATE         NULL,
  fecha_finalizacion          DATE         NULL,
  acta_finalizacion           VARCHAR(500) NULL,
  prorroga                    TINYINT(1)   NOT NULL DEFAULT 0,
  renovacion_automatica       TINYINT(1)   NOT NULL DEFAULT 0,
  caja_bas                    VARCHAR(200) NULL,
  moneda                      ENUM('Peso','Dólar','Euro','Otro') NOT NULL DEFAULT 'Peso',
  cotizacion                  DECIMAL(18,4) NULL,
  monto_presupuestado_ingresos DECIMAL(18,2) NULL,
  monto_presupuestado_gastos   DECIMAL(18,2) NULL,
  deleted_at                  TIMESTAMP    NULL,
  created_at                  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_ce_expediente       (nro_expediente),
  KEY idx_ce_nombre           (nombre_proyecto(191)),
  KEY idx_ce_estado           (estado_id),
  KEY idx_ce_tipo             (tipo_contrato_id),
  KEY idx_ce_principal        (contrato_principal_id),
  KEY idx_ce_uvt              (uvt_id),
  KEY idx_ce_solicitante      (solicitante_id),
  KEY idx_ce_vencimiento      (fecha_vencimiento),
  KEY idx_ce_deleted          (deleted_at),

  CONSTRAINT fk_ce_tipo
    FOREIGN KEY (tipo_contrato_id) REFERENCES tipo_contrato_ejecucion(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ce_estado
    FOREIGN KEY (estado_id) REFERENCES estado_ejecucion(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ce_principal
    FOREIGN KEY (contrato_principal_id) REFERENCES contratos_principal(id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ce_solic
    FOREIGN KEY (solicitante_id) REFERENCES solicitantes(solicitante_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ce_uvt
    FOREIGN KEY (uvt_id) REFERENCES uvt(uvt_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ce_utt
    FOREIGN KEY (utt_id) REFERENCES utt(utt_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ce_resp1
    FOREIGN KEY (resp1_id) REFERENCES personal(legajo)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ce_resp2
    FOREIGN KEY (resp2_id) REFERENCES personal(legajo)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: ejecucion_movimientos (gastos / ingresos imputados a una
-- ejecución concreta). Reemplaza a los campos monto_ejecutado_* del
-- contrato de ejecución: los montos ejecutados son ahora la suma de
-- estos movimientos por tipo.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ejecucion_movimientos (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  contrato_ejecucion_id    INT          NOT NULL,
  tipo                     ENUM('ingreso','gasto') NOT NULL,
  nro_expediente           VARCHAR(100) NOT NULL,
  proveedor                VARCHAR(300) NULL,          -- gastos
  cliente                  VARCHAR(300) NULL,          -- ingresos
  moneda                   ENUM('Peso','Dólar') NOT NULL DEFAULT 'Peso',
  monto                    DECIMAL(18,2) NOT NULL,     -- en pesos siempre (calculado si moneda='Dólar')
  monto_dolares            DECIMAL(18,2) NULL,         -- monto original si moneda='Dólar'
  cotizacion               DECIMAL(18,4) NULL,         -- cotización aplicada si moneda='Dólar'
  objeto                   TEXT         NOT NULL,
  factura_path             VARCHAR(500) NULL,          -- ingresos: ruta relativa al disk local
  factura_original_name    VARCHAR(255) NULL,
  factura_mime             VARCHAR(100) NULL,
  deleted_at               TIMESTAMP    NULL,
  created_at               TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_em_contrato      (contrato_ejecucion_id),
  KEY idx_em_tipo          (tipo),
  KEY idx_em_deleted       (deleted_at),

  CONSTRAINT fk_em_contrato
    FOREIGN KEY (contrato_ejecucion_id) REFERENCES contratos_ejecucion(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: historial_cambios (auditoría obligatoria)
-- Cada creación / modificación / baja de contratos genera filas aquí.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS historial_cambios (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  tabla            VARCHAR(100) NOT NULL,
  registro_id      INT          NOT NULL,
  tipo_cambio      ENUM('creacion','modificacion','baja') NOT NULL,
  campo_modificado VARCHAR(100) NULL,
  valor_anterior   TEXT         NULL,
  valor_nuevo      TEXT         NULL,
  usuario          VARCHAR(200) NOT NULL,
  fecha            TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hist_target (tabla, registro_id),
  KEY idx_hist_fecha  (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Laravel Sanctum + migrations (compatibilidad)
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

CREATE TABLE IF NOT EXISTS migrations (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  migration VARCHAR(255) NOT NULL,
  batch     INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 4) Datos iniciales (catálogos)
-- ---------------------------------------------------------------------
-- =====================================================================
-- ATLAS v2 — Datos semilla (catálogos)
-- Estados, tipos de contrato, UTTs, UVTs.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Tipos de contrato — PRINCIPAL: CATP / CLIT / AP
-- ---------------------------------------------------------------------
INSERT INTO tipo_contrato_principal (id, sigla, nombre) VALUES
  (1, 'CATP', 'Contrato de Asistencia Tecnológica Principal'),
  (2, 'CLIT', 'Contrato de Licencia y Transferencia'),
  (3, 'AP',   'Acuerdo Particular')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- ---------------------------------------------------------------------
-- Tipos de contrato — EJECUCIÓN: CP / CIT / CE
-- ---------------------------------------------------------------------
INSERT INTO tipo_contrato_ejecucion (id, sigla, nombre) VALUES
  (1, 'CP',  'Contrato Particular'),
  (2, 'CIT', 'Contrato de Innovación Tecnológica'),
  (3, 'CE',  'Convenio Específico')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- ---------------------------------------------------------------------
-- Estados — PRINCIPAL: 3 estados
-- ---------------------------------------------------------------------
INSERT INTO estado_principal (id, nombre) VALUES
  (1, 'En firma'),
  (2, 'En ejecución'),
  (3, 'Finalizado')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- ---------------------------------------------------------------------
-- Estados — EJECUCIÓN: 8 estados granulares
-- ---------------------------------------------------------------------
INSERT INTO estado_ejecucion (id, nombre, descripcion) VALUES
  (1, 'Borrador',         'Contrato en preparación, aún no iniciado'),
  (2, 'En revisión GVT',  'En revisión por la Gerencia de Vinculación Tecnológica'),
  (3, 'En jurídicos',     'En análisis por el área legal'),
  (4, 'En firma UV',      'Pendiente de firma de la Unidad Vinculada'),
  (5, 'En firma CNEA',    'Pendiente de firma del organismo'),
  (6, 'Firmado',          'Contrato firmado por todas las partes'),
  (7, 'En ejecución',     'Contrato en curso de ejecución'),
  (8, 'Finalizado',       'Contrato concluido')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion);

-- ---------------------------------------------------------------------
-- UTTs (con régimen)
-- ---------------------------------------------------------------------
INSERT INTO utt (denominacion, nombre, regimen) VALUES
  ('CAE',       'Centro Atómico Ezeiza',                                            'ambos'),
  ('CAC',       'Centro Atómico Constituyentes',                                    'ambos'),
  ('CAB',       'Centro Atómico Bariloche',                                         'ambos'),
  ('UPESN',     'Unidad de Proyectos Especiales de Suministros Nucleares',          '160'),
  ('CNEA-NASA', 'Convenio CNEA-NA S.A.',                                            '317'),
  ('PNGRR',     'Programa Nacional de Gestión de Residuos Radioactivos',            '160'),
  ('PIECA II',  'Proyecto Ingeniería de Elementos Combustibles para Atucha II',     '160'),
  ('RRR ANSTO', 'RRR ANSTO',                                                        '317')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), regimen = VALUES(regimen);

-- ---------------------------------------------------------------------
-- UVTs
-- ---------------------------------------------------------------------
INSERT INTO uvt (siglas, nombre) VALUES
  ('FB',    'Fundación Balseiro'),
  ('ACDEF', 'Asociación Cooperadora del Departamento de Física')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- ---------------------------------------------------------------------
-- 5) (Opcional) Designación del primer administrador
-- ---------------------------------------------------------------------
-- Esta sección es opcional. Lo recomendado es usar el mecanismo
-- FIRST_ADMIN_USERNAME del backend (ver manual de implementación).
-- Si se prefiere hacerlo a nivel SQL, descomentar y ajustar:
--
-- INSERT INTO user_roles (username, display_name, email, rol, activo)
-- VALUES ('<usuario_ad>', '<Nombre Apellido>', '<email@institucion.gob.ar>', 'admin', 1)
-- ON DUPLICATE KEY UPDATE rol='admin', activo=1;
--
-- El usuario quedará habilitado para autenticarse contra LDAP/API
-- y, al loguearse, mantendrá su rol admin.

-- =====================================================================
-- Fin del script
-- =====================================================================
