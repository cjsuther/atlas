-- =====================================================================
-- ATLAS — Datos semilla (estados, tipos de contrato, UTTs, UVTs)
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Estados
-- ---------------------------------------------------------------------
INSERT INTO estado (estado_id, estado_nombre, descripcion) VALUES
  (1, 'En Tramitación', 'El expediente electrónico ya se encuentra en tramitación'),
  (2, 'En Ejecución',   'Existe un contrato firmado por todas las partes'),
  (3, 'Finalizado',     'Con el acuerdo de las partes se dio por finalizado el contrato'),
  (4, 'Sin efecto',     'No se llegó a ejecutar el proyecto')
ON DUPLICATE KEY UPDATE estado_nombre = VALUES(estado_nombre), descripcion = VALUES(descripcion);

-- ---------------------------------------------------------------------
-- Tipos de contrato
-- ---------------------------------------------------------------------
INSERT INTO tipo_de_contrato (tipo, nombre) VALUES
  ('AP',  'Acuerdo Particular'),
  ('SAT', 'Solicitud de Asistencia Tecnológica'),
  ('AM',  'Acuerdo Marco'),
  ('CAT', 'Convenio de Asistencia Tecnológica'),
  ('CP',  'Contrato Particular'),
  ('AE',  'Acuerdo Específico'),
  ('CIT', 'Contrato de Innovación Tecnológica'),
  ('ADD', 'Adenda'),
  ('PR',  'Prórroga')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- ---------------------------------------------------------------------
-- UTTs
-- ---------------------------------------------------------------------
INSERT INTO utt (denominacion, nombre) VALUES
  ('CAE',       'Centro Atómico Ezeiza'),
  ('CAC',       'Centro Atómico Constituyentes'),
  ('CAB',       'Centro Atómico Bariloche'),
  ('UPESN',     'Unidad de Proyectos Especiales de Suministros Nucleares'),
  ('CNEA-NASA', 'Convenio CNEA-NA S.A.'),
  ('PNGRR',     'Programa Nacional de Gestión de Residuos Radioactivos'),
  ('PIECA II',  'Proyecto Ingeniería de Elementos Combustibles para Atucha II'),
  ('RRR ANSTO', 'RRR ANSTO')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- ---------------------------------------------------------------------
-- UVTs
-- ---------------------------------------------------------------------
INSERT INTO uvt (siglas, nombre) VALUES
  ('FB',    'Fundación Balseiro'),
  ('ACDEF', 'Asociación Cooperadora del Departamento de Física')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);
