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
