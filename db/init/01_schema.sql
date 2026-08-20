-- =====================================================================
-- ATLAS v2 — Schema de base de datos
-- Administración y Trazabilidad de Licencias, Acuerdos y Servicios
-- MySQL 8 / utf8mb4
--
-- Cambios v2:
--   * Se separa la tabla `contratos` en `contratos_principal` y `contratos_ejecucion`.
--   * Se separan tipos y estados en tablas distintas para principal y ejecución.
--   * Se incorpora `historial_cambios` (auditoría obligatoria).
--   * Toda baja es lógica (`deleted_at`).
--
-- Cambios v3 (CAMBIOS SOLICITADOS AL SISTEMA ATLAS):
--   * La estructura organizativa es la tabla `sector`: los sectores sin
--     dependencia son las Gerencias de Área y reemplazan a la gestión de
--     contratos principales.
--   * `contratos_ejecucion.sector_id` es obligatorio (jerarquía completa
--     Gerencia de Área -> Subsector -> Contrato -> Movimiento).
--   * `ejecucion_movimientos` admite acciones además de facturas
--     (transferencias entre contratos, incentivos y MCH) y contraparte
--     flexible (cliente, proveedor, contrato o rubro).
--   * El contrato guarda `saldo_inicial`; su saldo es ese monto más los
--     ingresos ejecutados menos los gastos ejecutados.
--   * `user_roles` pasa a los roles admin_sistema / admin_gerencia /
--     operador_gerencia, con alcance por Gerencia de Área (`sector_id`) y
--     preferencia de agrupación de saldos.
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
--
-- Es la estructura organizativa del sistema:
--   * Un sector sin dependencia es una Gerencia de Área. Es el nivel al que se
--     asocian los administradores y operadores de gerencia, y el límite de
--     confidencialidad: la información no sale de la Gerencia de Área.
--   * Los sectores dependientes son sus subsectores.
--
--   Gerencia de Área -> Subsector -> Contrato -> Movimiento de ejecución
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
--
-- Roles y alcance:
--   admin_sistema     : todas las Gerencias de Área y sus contratos.
--   admin_gerencia    : su Gerencia de Área (ABM de contratos y de operadores).
--   operador_gerencia : su Gerencia de Área (ABM de contratos, sin usuarios).
--   sin_acceso        : puede autenticarse y nada más. Es el rol con el que se
--                       dan de alta los usuarios que llegan por LDAP, hasta que
--                       un administrador les asigne rol y Gerencia de Área.
-- `sector_id` apunta a la Gerencia de Área (un sector sin dependencia) y es
-- obligatorio para los roles acotados.
-- `saldos_agrupacion` es la configuración con la que el usuario ve los saldos
-- del panel (por Gerencia de Área, por Subsector o por Contrato).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_roles (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  username          VARCHAR(200) NOT NULL,
  display_name      VARCHAR(200),
  email             VARCHAR(200),
  password          VARCHAR(255) NULL,
  auth_source       ENUM('local','ldap') NOT NULL DEFAULT 'ldap',
  rol               ENUM('admin_sistema','admin_gerencia','operador_gerencia','sin_acceso') NOT NULL DEFAULT 'sin_acceso',
  sector_id         INT NULL,
  saldos_agrupacion ENUM('gerencia_area','subsector','contrato') NOT NULL DEFAULT 'gerencia_area',
  activo            TINYINT(1) DEFAULT 1,
  last_login        TIMESTAMP NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_username (username),
  KEY idx_ur_sector (sector_id),
  CONSTRAINT fk_ur_sector
    FOREIGN KEY (sector_id) REFERENCES sector(sector_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
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
  CONSTRAINT fk_cp_resp1
    FOREIGN KEY (resp1_id) REFERENCES personal(legajo)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_cp_resp2
    FOREIGN KEY (resp2_id) REFERENCES personal(legajo)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabla: contratos_ejecucion
-- Contrato concreto. Tipos: CP, CIT, CE.
-- Cuelga SIEMPRE de un sector; la Gerencia de Área es el ancestro raíz de ese
-- sector. El vínculo con contratos_principal se conserva sólo por trazabilidad
-- histórica: la gestión de contratos principales fue reemplazada por la
-- estructura Gerencia de Área -> Subsector.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contratos_ejecucion (
  id                          INT AUTO_INCREMENT PRIMARY KEY,
  nro_expediente              VARCHAR(100) NOT NULL,
  fecha_apertura_expediente   DATE         NULL,
  tipo_contrato_id            INT          NOT NULL,
  nombre_proyecto             VARCHAR(500) NOT NULL,
  descripcion_objeto          TEXT         NULL,
  contrato_principal_id       INT          NULL,
  sector_id                   INT          NOT NULL,
  solicitante_id              INT          NULL,
  resp1_id                    INT          NULL,
  resp2_id                    INT          NULL,
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
  saldo_inicial               DECIMAL(18,2) NULL,    -- saldo con el que arranca el contrato
  deleted_at                  TIMESTAMP    NULL,
  created_at                  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_ce_expediente       (nro_expediente),
  KEY idx_ce_nombre           (nombre_proyecto(191)),
  KEY idx_ce_estado           (estado_id),
  KEY idx_ce_tipo             (tipo_contrato_id),
  KEY idx_ce_principal        (contrato_principal_id),
  KEY idx_ce_sector           (sector_id),
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
  CONSTRAINT fk_ce_sector
    FOREIGN KEY (sector_id) REFERENCES sector(sector_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_ce_solic
    FOREIGN KEY (solicitante_id) REFERENCES solicitantes(solicitante_id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_ce_uvt
    FOREIGN KEY (uvt_id) REFERENCES uvt(uvt_id)
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
--
-- `accion` distingue el origen del movimiento:
--   factura       : solicitud / recepción de factura (cliente o proveedor)
--   transferencia : movimiento de fondos hacia/desde otro contrato
--   incentivo     : pago de incentivos
--   mch           : pago de Mayor Carga Horaria
--
-- No siempre hay cliente o proveedor: en una transferencia la contraparte es
-- otro contrato, y en incentivos / MCH suele ser sólo un rubro. `contraparte_tipo`
-- indica cuál de los cuatro campos de contraparte es el que aplica.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ejecucion_movimientos (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  contrato_ejecucion_id    INT          NOT NULL,
  tipo                     ENUM('ingreso','gasto') NOT NULL,
  accion                   ENUM('factura','transferencia','incentivo','mch') NOT NULL DEFAULT 'factura',
  nro_expediente           VARCHAR(100) NOT NULL,
  contraparte_tipo         ENUM('cliente','proveedor','contrato','rubro') NULL,
  proveedor                VARCHAR(300) NULL,          -- gastos por factura
  cliente                  VARCHAR(300) NULL,          -- ingresos por factura
  contrato_contraparte_id  INT          NULL,          -- transferencias
  rubro                    VARCHAR(200) NULL,          -- incentivos / MCH / sin contraparte
  movimiento_espejo_id     INT          NULL,          -- contrapartida de una transferencia
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
  KEY idx_em_accion        (accion),
  KEY idx_em_contraparte   (contrato_contraparte_id),
  KEY idx_em_espejo        (movimiento_espejo_id),
  KEY idx_em_deleted       (deleted_at),

  CONSTRAINT fk_em_contrato
    FOREIGN KEY (contrato_ejecucion_id) REFERENCES contratos_ejecucion(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_em_contraparte
    FOREIGN KEY (contrato_contraparte_id) REFERENCES contratos_ejecucion(id)
    ON DELETE SET NULL ON UPDATE CASCADE
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
