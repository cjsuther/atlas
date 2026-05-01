# PROMPT PARA CLAUDE CODE — SISTEMA ATLAS (PHP + LDAP)

---

## Contexto General

Construir un sistema web completo llamado **ATLAS** (**A**dministración y **T**razabilidad de **L**icencias, **A**cuerdos y **S**ervicios) para la gestión de contratos tecnológicos de un organismo público.

El sistema reemplaza una planilla Excel y debe desarrollarse bajo la modalidad **Desarrollo Externo con Soporte Interno**, lo que implica:
- Backend en **PHP 8.2 + Laravel 11** (el organismo puede darle mantenimiento internamente)
- Autenticación vía **LDAP / Active Directory** institucional (sin usuarios propios del sistema)
- Cumplimiento del **procedimiento normativo de gestión de desarrollos** del organismo para el pase a producción

**Logo:** SVG inline — esfera atómica estilizada (3 órbitas elípticas) con un ícono de documento superpuesto en el centro. Colores `#1a2e4a` (azul oscuro) y `#00b4d8` (celeste). El nombre "ATLAS" en bold sans-serif a la derecha o debajo.

---

## Arquitectura del Sistema

Seguir estrictamente esta estructura de microservicios con Docker:

```
atlas/
├── docker-compose.yml
├── .env
├── nginx/
│   ├── Dockerfile
│   └── nginx.conf               # Gateway único: / → frontend, /api/ → backend
├── backend/                     # PHP 8.2 + Laravel 11
│   ├── Dockerfile
│   ├── composer.json
│   └── app/
│       ├── Http/
│       │   ├── Controllers/     # Un controller por entidad
│       │   └── Middleware/      # Auth LDAP, roles
│       ├── Models/              # Eloquent ORM
│       ├── Services/            # Lógica de negocio (no en controllers)
│       └── Providers/
├── frontend/                    # Vanilla JS modular o Vue 3 (sin build tool, CDN)
│   ├── Dockerfile
│   └── src/
│       ├── modules/
│       │   ├── contratos/
│       │   ├── entidades/
│       │   └── dashboard/
│       ├── components/
│       └── services/            # Fetch wrapper para la API
└── db/
    ├── Dockerfile               # MySQL 8
    └── init/
        └── 01_schema.sql
```

### Reglas de Arquitectura

1. **Backend:** PHP 8.2 con Laravel 11. Un controller por entidad. Lógica en Services, no en Controllers. Respuestas JSON estructuradas.
2. **Autenticación:** LDAP/Active Directory. El sistema **no gestiona contraseñas**. El login valida contra el AD usando las credenciales institucionales. Se usa Laravel Sanctum para la sesión posterior al login LDAP. Los roles (admin / operador / consulta) se asignan dentro del sistema en una tabla `user_roles`.
3. **Frontend:** Vanilla JS con módulos ES6 o Vue 3 CDN. Organizado por módulos. Sin frameworks de build.
4. **Base de datos:** MySQL 8 en contenedor dedicado.
5. **Gateway:** NGINX como único punto de entrada.
6. **Docker Compose:** Todos los servicios con healthchecks, networks internas, volumen persistente para MySQL.
7. **Variables de entorno:** Todo via `.env`. Nunca hardcodeado.

---

## Base de Datos — Schema Completo

### Entidades Maestras

#### `estado`
```sql
CREATE TABLE estado (
  estado_id   INT AUTO_INCREMENT PRIMARY KEY,
  estado_nombre VARCHAR(100) NOT NULL,
  descripcion TEXT
);
```
**Seed:**
- 1, En Tramitación, El expediente electrónico ya se encuentra en tramitación
- 2, En Ejecución, Existe un contrato firmado por todas las partes
- 3, Finalizado, Con el acuerdo de las partes se dio por finalizado el contrato
- 4, Sin efecto, No se llegó a ejecutar el proyecto

#### `tipo_de_contrato`
```sql
CREATE TABLE tipo_de_contrato (
  id_tipo  INT AUTO_INCREMENT PRIMARY KEY,
  tipo     VARCHAR(20) NOT NULL,
  nombre   VARCHAR(200) NOT NULL
);
```
**Seed:** AP/Acuerdo Particular, SAT/Solicitud de Asistencia Tecnológica, AM/Acuerdo Marco, CAT/Convenio de Asistencia Tecnológica, CP/Contrato Particular, AE/Acuerdo Específico, CIT/Contrato de Innovación Tecnológica, ADD/Adenda, PR/Prórroga

#### `solicitantes`
```sql
CREATE TABLE solicitantes (
  solicitante_id  INT AUTO_INCREMENT PRIMARY KEY,
  cuil_cuit       VARCHAR(20),
  razon_social    VARCHAR(300) NOT NULL,
  rubro           VARCHAR(200),
  localizacion    VARCHAR(300),
  telefono        VARCHAR(100),
  nombre_contacto VARCHAR(200)
);
```

#### `sector`
```sql
CREATE TABLE sector (
  sector_id      INT AUTO_INCREMENT PRIMARY KEY,
  nombre         VARCHAR(200) NOT NULL,
  dependencia_id INT NULL,  -- FK a sector (autorreferencia jerárquica)
  responsable    VARCHAR(200),
  web            VARCHAR(300),
  ubicacion      VARCHAR(200),
  FOREIGN KEY (dependencia_id) REFERENCES sector(sector_id)
);
```

#### `utt`
```sql
CREATE TABLE utt (
  utt_id       INT AUTO_INCREMENT PRIMARY KEY,
  denominacion VARCHAR(50) NOT NULL,
  nombre       VARCHAR(300) NOT NULL
);
```
**Seed:** CAE/Centro Atómico Ezeiza, CAC/Centro Atómico Constituyentes, CAB/Centro Atómico Bariloche, UPESN/Unidad de Proyectos Especiales de Suministros Nucleares, CNEA-NASA/Convenio CNEA-NA S.A., PNGRR/Programa Nacional de Gestión de Residuos Radioactivos, PIECA II/Proyecto Ingeniería de Elementos Combustibles para Atucha II, RRR ANSTO/RRR ANSTO

#### `uvt`
```sql
CREATE TABLE uvt (
  uvt_id      INT AUTO_INCREMENT PRIMARY KEY,
  siglas      VARCHAR(50) NOT NULL,
  nombre      VARCHAR(300) NOT NULL,
  responsable VARCHAR(200)
);
```
**Seed:** FB/Fundación Balseiro, ACDEF/Asociación Cooperadora del Departamento de Física

#### `personal`
```sql
CREATE TABLE personal (
  legajo          INT PRIMARY KEY,
  apellido        VARCHAR(100) NOT NULL,
  nombre          VARCHAR(100) NOT NULL,
  interno         VARCHAR(20),
  mail            VARCHAR(200),
  lugar_trabajo_id INT NULL,
  FOREIGN KEY (lugar_trabajo_id) REFERENCES sector(sector_id)
);
```

#### `user_roles` (gestión interna de roles)
```sql
CREATE TABLE user_roles (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  username   VARCHAR(200) NOT NULL UNIQUE,  -- username del AD
  rol        ENUM('admin','operador','consulta') NOT NULL DEFAULT 'consulta',
  activo     TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

### Entidad Principal: `contratos`

```sql
CREATE TABLE contratos (
  id_cto                    INT AUTO_INCREMENT PRIMARY KEY,
  nombre_proy               VARCHAR(500) NOT NULL,
  dependencia_contractual_id INT NULL,         -- FK autorreferencia (contrato padre)
  operatoria_id             INT NULL,
  fecha_expediente          DATE,
  estado_id                 INT,
  expediente                VARCHAR(200),
  solicitud_sector_gde      VARCHAR(300),
  descripcion_objeto        TEXT,
  tipo_de_contrato_id       INT,
  observaciones             TEXT,
  solicitante_id            INT,
  uvt_id                    INT,
  sector_id                 INT,
  gerencia                  VARCHAR(200),
  gerencia_area             VARCHAR(200),
  fecha_firma               DATE,
  fecha_inicio              DATE,
  fecha_vencimiento         DATE,
  fecha_finalizado          DATE,
  duracion_meses            INT,
  atraso_meses              INT,
  prorroga                  TINYINT(1) DEFAULT 0,
  renovacion_automatica     TINYINT(1) DEFAULT 0,
  acta_finalizacion         VARCHAR(500),
  resp1_id                  INT NULL,          -- FK a personal(legajo)
  resp2_id                  INT NULL,          -- FK a personal(legajo)
  caja_bas                  VARCHAR(200),
  resp_caja                 VARCHAR(200),
  monto_pesos               DECIMAL(18,2),
  monto_usd                 DECIMAL(18,2),
  monto_euros               DECIMAL(18,2),
  monto_otro                DECIMAL(18,2),
  moneda_otro               VARCHAR(50),
  automatico_ejecucion      TINYINT(1) DEFAULT 0,
  automatico_finalizado     TINYINT(1) DEFAULT 0,
  created_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  FOREIGN KEY (dependencia_contractual_id) REFERENCES contratos(id_cto),
  FOREIGN KEY (estado_id)                 REFERENCES estado(estado_id),
  FOREIGN KEY (tipo_de_contrato_id)       REFERENCES tipo_de_contrato(id_tipo),
  FOREIGN KEY (solicitante_id)            REFERENCES solicitantes(solicitante_id),
  FOREIGN KEY (uvt_id)                    REFERENCES uvt(uvt_id),
  FOREIGN KEY (sector_id)                 REFERENCES sector(sector_id),
  FOREIGN KEY (resp1_id)                  REFERENCES personal(legajo),
  FOREIGN KEY (resp2_id)                  REFERENCES personal(legajo)
);
```

---

## Módulo de Autenticación LDAP

### Flujo de login
1. El usuario ingresa su **usuario y contraseña institucional** en el formulario de login de ATLAS.
2. Laravel intenta hacer un **bind LDAP** contra el Active Directory con esas credenciales.
3. Si el bind es exitoso: se crea/actualiza el registro en `user_roles` (si no existe, se crea con rol `consulta` por defecto) y se genera un token Sanctum.
4. Si falla: se devuelve error 401.
5. El token Sanctum se usa en todas las llamadas posteriores a la API.

### Paquete recomendado
Usar `directorytree/ldaprecord-laravel` para la integración LDAP.

### Configuración LDAP en `.env`
```
LDAP_HOST=ldap.organismo.gob.ar
LDAP_PORT=389
LDAP_BASE_DN=dc=organismo,dc=gob,dc=ar
LDAP_USERNAME=cn=service_account,dc=organismo,dc=gob,dc=ar
LDAP_PASSWORD=service_account_password
LDAP_USE_SSL=false
LDAP_USE_TLS=false
```
> Estos valores son placeholder. El organismo proveerá los valores reales al momento de la integración.

### Middleware de roles
Crear middleware `CheckRole` que verifique el campo `rol` de `user_roles`:
- `consulta`: solo GET
- `operador`: GET + POST + PUT en contratos; GET en entidades maestras
- `admin`: acceso total incluyendo ABM de entidades y gestión de roles

---

## Endpoints de la API (Laravel)

### Autenticación
```
POST   /api/auth/login          → bind LDAP + devuelve token Sanctum
POST   /api/auth/logout         → invalida token
GET    /api/auth/me             → datos del usuario actual + rol
```

### Contratos
```
GET    /api/contratos                    → listado paginado (filtros por query params)
GET    /api/contratos/{id}               → detalle completo
POST   /api/contratos                    → crear
PUT    /api/contratos/{id}               → editar
DELETE /api/contratos/{id}               → baja lógica (estado → Sin efecto)
GET    /api/contratos/export/excel       → exportar listado filtrado a .xlsx
```

**Filtros disponibles en GET /api/contratos:**
`estado_id`, `tipo_de_contrato_id`, `sector_id`, `solicitante_id`, `uvt_id`, `fecha_inicio_desde`, `fecha_inicio_hasta`, `fecha_vencimiento_desde`, `fecha_vencimiento_hasta`, `search` (busca en nombre_proy, expediente, descripcion_objeto)

### Entidades maestras (patrón repetido)
Rutas para: `/api/estados`, `/api/tipos-contrato`, `/api/solicitantes`, `/api/sectores`, `/api/utt`, `/api/uvt`, `/api/personal`
```
GET    /api/{entidad}           → listado paginado con búsqueda
GET    /api/{entidad}/{id}      → detalle
POST   /api/{entidad}           → crear   [admin]
PUT    /api/{entidad}/{id}      → editar  [admin]
DELETE /api/{entidad}/{id}      → eliminar con validación de dependencias [admin]
```

### Roles de usuario
```
GET    /api/usuarios            → listado de usuarios con roles [admin]
PUT    /api/usuarios/{username} → asignar/cambiar rol           [admin]
```

### Dashboard
```
GET    /api/dashboard/kpis         → totales por estado
GET    /api/dashboard/vencimientos → contratos que vencen en 30/60/90 días
```

---

## Módulos del Frontend

### Módulo 1: Login
- Formulario usuario + contraseña institucional
- Logo ATLAS centrado
- Mensaje de error si falla el bind LDAP
- Al autenticar, redirige al dashboard

### Módulo 2: Dashboard
- KPIs: total contratos, en ejecución, en tramitación, finalizados, próximos a vencer (30/60/90 días)
- Tabla resumen con los 10 contratos más próximos a vencer
- Badges de color por estado: En Tramitación (azul), En Ejecución (verde), Finalizado (gris), Sin efecto (rojo)

### Módulo 3: Gestión de Contratos
- **Listado** con columnas ordenables, paginación servidor, filtros laterales persistentes
- **Búsqueda** en tiempo real (debounce)
- **Formulario alta/edición:** todos los campos. Los FK son `<select>` que cargan datos de los endpoints de entidades maestras. Datepicker en campos de fecha. Campos de monto con separador de miles.
- **Vista detalle** solo lectura con todos los campos agrupados por sección
- **Baja lógica** con confirmación
- **Botón exportar Excel** respetando los filtros activos

### Módulo 4: ABM Entidades Maestras
Un submódulo por cada entidad (Estados, Tipos de Contrato, Solicitantes, Sectores, UTT, UVT, Personal):
- Listado paginado con búsqueda
- Modal de alta/edición
- Confirmación de eliminación con advertencia si tiene contratos asociados
- Solo visible/editable para rol `admin`

### Módulo 5: Gestión de Roles
- Listado de usuarios que alguna vez iniciaron sesión
- Dropdown para cambiar rol por usuario
- Solo visible para rol `admin`

---

## Docker Compose

```yaml
version: '3.9'

services:
  db:
    image: mysql:8
    environment:
      MYSQL_DATABASE: ${DB_NAME}
      MYSQL_USER: ${DB_USER}
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - mysql_data:/var/lib/mysql
      - ./db/init:/docker-entrypoint-initdb.d
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5

  backend:
    build: ./backend
    environment:
      APP_ENV: production
      APP_KEY: ${APP_KEY}
      DB_HOST: db
      DB_DATABASE: ${DB_NAME}
      DB_USERNAME: ${DB_USER}
      DB_PASSWORD: ${DB_PASSWORD}
      LDAP_HOST: ${LDAP_HOST}
      LDAP_PORT: ${LDAP_PORT}
      LDAP_BASE_DN: ${LDAP_BASE_DN}
      LDAP_USERNAME: ${LDAP_USERNAME}
      LDAP_PASSWORD: ${LDAP_PASSWORD}
    depends_on:
      db:
        condition: service_healthy

  frontend:
    build: ./frontend
    depends_on:
      - backend

  nginx:
    build: ./nginx
    ports:
      - "80:80"
    depends_on:
      - frontend
      - backend

volumes:
  mysql_data:
```

---

## Variables de Entorno (`.env`)

```
# App
APP_KEY=base64:GENERAR_CON_php_artisan_key_generate
APP_ENV=production
APP_DEBUG=false

# Base de datos
DB_NAME=atlas_db
DB_USER=atlas_user
DB_PASSWORD=atlas_secure_pass_2024
DB_ROOT_PASSWORD=atlas_root_pass_2024

# LDAP / Active Directory
LDAP_HOST=ldap.organismo.gob.ar
LDAP_PORT=389
LDAP_BASE_DN=dc=organismo,dc=gob,dc=ar
LDAP_USERNAME=cn=service_account,dc=organismo,dc=gob,dc=ar
LDAP_PASSWORD=service_account_password
LDAP_USE_SSL=false
LDAP_USE_TLS=false

# Primer admin (se carga en user_roles al iniciar)
FIRST_ADMIN_USERNAME=admin.username
```

---

## Diseño Visual

- **Paleta:**
  - Primario: `#1a2e4a` (azul oscuro)
  - Acento: `#00b4d8` (celeste)
  - Fondo: `#f4f6f9`
  - Superficie: `#ffffff`
  - Texto: `#2d3748`
  - Éxito/En Ejecución: `#38a169`
  - Info/En Tramitación: `#3182ce`
  - Neutro/Finalizado: `#718096`
  - Peligro/Sin efecto: `#e53e3e`
- **Tipografía:** Inter o Roboto (Google Fonts CDN)
- **Layout:** Sidebar izquierdo colapsable con íconos, header con nombre de usuario y botón de logout
- **Tablas:** columnas ordenables, paginación, filas alternadas
- **Badges de estado** con color por valor

---

## Datos Semilla

Al iniciar por primera vez, cargar automáticamente:
1. Los 4 estados (ver schema arriba)
2. Los 9 tipos de contrato (ver schema arriba)
3. Las 8 UTTs (ver schema arriba)
4. Las 2 UVTs (ver schema arriba)
5. El usuario admin inicial desde `FIRST_ADMIN_USERNAME` en `.env`, con rol `admin`

Implementar como Laravel Seeders ejecutados en el `docker-compose up` inicial.

---

## Requisitos de Calidad

1. **Validaciones:** Todos los campos requeridos validados en frontend (antes de enviar) y en backend (Laravel Form Requests).
2. **Manejo de errores:** Respuestas estructuradas `{"error": "...", "message": "..."}`. Mensajes amigables en UI.
3. **Paginación:** Todos los listados paginados (default 20 ítems/página).
4. **Filtros persistentes:** Los filtros del listado de contratos se mantienen al volver desde el detalle (sessionStorage o query params en URL).
5. **Fechas:** Formato DD/MM/YYYY en la UI. ISO 8601 en la API.
6. **Montos:** Separador de miles y 2 decimales en la UI. `DECIMAL(18,2)` en BD.
7. **Export Excel:** Usar `maatwebsite/excel` (Laravel Excel) para la exportación.
8. **CORS:** Configurado en Laravel para el dominio del frontend.
9. **README.md** en la raíz con instrucciones de instalación. El sistema debe quedar operativo con un único `docker compose up --build`.
10. **Servidor requerido por el organismo:** Antes de iniciar el proyecto, el organismo debe confirmar las características del servidor que proveerá (SO, CPU, RAM, almacenamiento, acceso LDAP con cuenta de servicio). Documentar este requerimiento en el README.

---

## Orden de Implementación Sugerido

1. Estructura de carpetas y `docker-compose.yml` base
2. DB: schema SQL completo + seeders
3. Backend: modelos Eloquent + migraciones
4. Backend: autenticación LDAP + middleware de roles + endpoint `/api/auth/*`
5. Backend: Form Requests + Controllers + Services para entidades maestras
6. Backend: Controller + Service de contratos con filtros y export Excel
7. Backend: Controller de dashboard/KPIs
8. Frontend: layout base (sidebar, header, router SPA)
9. Frontend: módulo login
10. Frontend: módulo dashboard
11. Frontend: ABM entidades maestras
12. Frontend: módulo contratos (listado + formulario + detalle)
13. Frontend: módulo gestión de roles
14. NGINX config final
15. README.md con instrucciones completas

---

*Sistema ATLAS — Gestión de Contratos Tecnológicos — PHP 8.2 + Laravel 11 + LDAP + MySQL 8 + Docker*
