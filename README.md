# ATLAS — Administración y Trazabilidad de Licencias, Acuerdos y Servicios

Sistema web para la gestión de contratos tecnológicos. Reemplaza la planilla Excel histórica del organismo por una aplicación con backend Laravel 11, base MySQL 8, autenticación LDAP/Active Directory institucional y frontend SPA en JavaScript Vanilla. Todo orquestado con Docker Compose y servido detrás de un único gateway NGINX.

---

## 1. Características

- **Login institucional** mediante LDAP/Active Directory (sin gestión propia de contraseñas).
- **Tres roles** internos asignables sobre los usuarios del AD: `admin`, `operador`, `consulta`.
- **Gestión completa de contratos** (alta, edición, detalle, baja lógica, búsqueda, filtros, ordenamiento y paginación servidor).
- **ABM de catálogos**: estados, tipos de contrato, solicitantes, sectores (jerárquicos), UTTs, UVTs y personal.
- **Dashboard** con KPIs por estado y vencimientos a 30 / 60 / 90 días.
- **Exportación a Excel** del listado filtrado de contratos.
- **API REST** documentada vía rutas Laravel, autenticación con tokens Sanctum.
- **Datos semilla automáticos**: estados, tipos de contrato, UTTs, UVTs y administrador inicial.

---

## 2. Arquitectura

```
atlas/
├── docker-compose.yml         # Orquestación de los 4 servicios
├── .env / .env.example
├── nginx/                     # Gateway: / → frontend, /api/ → backend
├── backend/                   # PHP 8.2 + Laravel 11 (FPM)
├── frontend/                  # SPA Vanilla JS modular (sin build tool)
└── db/                        # MySQL 8 + schema.sql + seeds.sql
```

| Servicio   | Imagen base       | Puertos          | Notas                                              |
|------------|-------------------|------------------|----------------------------------------------------|
| `nginx`    | nginx:1.27-alpine | `80:80`          | Único punto de entrada                              |
| `frontend` | nginx:1.27-alpine | interno          | Sirve los archivos estáticos                        |
| `backend`  | php:8.2-fpm-alpine| `9000` interno   | Laravel 11 + extensiones LDAP, GD, intl, pdo_mysql  |
| `db`       | mysql:8           | interno          | Volumen persistente `mysql_data`                    |

---

## 3. Requisitos del servidor (a confirmar por el organismo)

Antes de iniciar la puesta en producción, **el organismo debe confirmar** las características del servidor que proveerá. La aplicación está pensada para correr sobre Docker, por lo que se solicita:

| Recurso                | Mínimo recomendado                                  |
|------------------------|-----------------------------------------------------|
| Sistema operativo      | Linux (Ubuntu 22.04 LTS / RHEL 9 / Debian 12)       |
| CPU                    | 2 vCPU                                              |
| RAM                    | 4 GB                                                |
| Almacenamiento         | 30 GB (datos + logs + imágenes Docker)              |
| Red                    | Salida TCP/389 o 636 al servidor LDAP/AD            |
| Software base          | Docker Engine ≥ 24, Docker Compose plugin ≥ 2.20    |
| Cuenta de servicio LDAP| DN y password con permisos de lectura del directorio|
| Atributo de usuario AD | Definir cuál se usará para identificar (default: `sAMAccountName`) |
| Acceso del usuario     | Cuenta administradora inicial (username del AD) para configurar `FIRST_ADMIN_USERNAME` |

> El organismo también debe confirmar las **políticas de backup** del volumen `mysql_data` y la **trazabilidad de logs** (rotación, destino) según el procedimiento normativo de pase a producción.

---

## 4. Configuración

### 4.1. Crear archivo `.env`
Copiar `.env.example` a `.env` y completar los valores reales:

```bash
cp .env.example .env
```

Variables clave:

| Variable                | Descripción                                                    |
|-------------------------|----------------------------------------------------------------|
| `APP_KEY`               | Clave de aplicación. Si se deja con el placeholder, se genera al primer arranque. |
| `DB_*`                  | Credenciales de la base MySQL.                                 |
| `LDAP_HOST`             | Hostname o IP del controlador de dominio.                      |
| `LDAP_PORT`             | 389 (sin SSL) o 636 (SSL/LDAPS).                               |
| `LDAP_BASE_DN`          | DN base de búsqueda, p.ej. `dc=organismo,dc=gob,dc=ar`.        |
| `LDAP_USERNAME` / `LDAP_PASSWORD` | DN y password de la cuenta de servicio LDAP.        |
| `LDAP_USE_SSL` / `LDAP_USE_TLS`   | true/false según despliegue.                        |
| `LDAP_USER_ATTRIBUTE`   | Atributo de identificación del usuario (`sAMAccountName` en AD, `uid` en OpenLDAP). |
| `FIRST_ADMIN_USERNAME`  | Username del AD que se cargará con rol `admin` en el primer arranque. |

### 4.2. Generar `APP_KEY` (opcional)
La aplicación genera la clave automáticamente al primer arranque si detecta el placeholder. Para generarla previamente:

```bash
docker compose run --rm backend php artisan key:generate --show
```

Y pegar el valor en `.env` como `APP_KEY=...`.

---

## 5. Puesta en marcha

Desde la raíz del proyecto:

```bash
docker compose up --build -d
```

Esto:
1. Construye las imágenes `nginx`, `frontend`, `backend`, `db`.
2. Arranca MySQL y ejecuta `db/init/01_schema.sql` y `db/init/02_seeds.sql` (idempotentes).
3. El backend espera a que MySQL esté listo, asegura `APP_KEY` y crea/actualiza el primer admin (`FIRST_ADMIN_USERNAME`).
4. NGINX queda escuchando en el puerto `${HTTP_PORT}` (default `80`).

Acceder en el navegador:

```
http://localhost
```

### Logs

```bash
docker compose logs -f backend
docker compose logs -f nginx
docker compose logs -f db
```

### Detener / reiniciar

```bash
docker compose down            # detener (mantiene volumen)
docker compose down -v         # detener y borrar la base (CUIDADO)
docker compose restart backend
```

---

## 6. Roles y permisos

| Rol         | Capacidades                                                                    |
|-------------|--------------------------------------------------------------------------------|
| `consulta`  | Solo lectura: ver dashboard, listar y consultar contratos y catálogos.         |
| `operador`  | Lo de `consulta` + crear y editar contratos. **No** ABM de catálogos.          |
| `admin`     | Acceso total: ABM de contratos y catálogos, gestión de roles de usuario.       |

- El primer login de un usuario del AD lo registra automáticamente con rol `consulta`.
- Solo un `admin` puede ascender a otros usuarios.
- Hay un guard que **impide degradar al último administrador activo**.

---

## 7. Endpoints principales

```
POST   /api/auth/login                    Bind LDAP + token Sanctum
POST   /api/auth/logout                   Revoca el token actual
GET    /api/auth/me                       Datos + rol del usuario logueado

GET    /api/dashboard/kpis                KPIs por estado y vencimientos
GET    /api/dashboard/vencimientos        10 contratos próximos a vencer

GET    /api/contratos                     Listado paginado con filtros
GET    /api/contratos/{id}                Detalle
POST   /api/contratos                     Crear (admin/operador)
PUT    /api/contratos/{id}                Editar (admin/operador)
DELETE /api/contratos/{id}                Baja lógica → estado "Sin efecto" (admin)
GET    /api/contratos/export/excel        Exporta XLSX según filtros

# Entidades maestras (mismo patrón):
# /api/estados, /api/tipos-contrato, /api/solicitantes,
# /api/sectores, /api/utt, /api/uvt, /api/personal
GET    /api/{entidad}                     Listado paginado
GET    /api/{entidad}/{id}                Detalle
POST   /api/{entidad}                     Crear (admin)
PUT    /api/{entidad}/{id}                Editar (admin)
DELETE /api/{entidad}/{id}                Eliminar (admin, valida dependencias)

GET    /api/usuarios                      Listado de usuarios con roles (admin)
PUT    /api/usuarios/{username}           Cambiar rol / activo (admin)
```

**Filtros de `/api/contratos`:** `estado_id`, `tipo_de_contrato_id`, `sector_id`, `solicitante_id`, `uvt_id`, `fecha_inicio_desde`, `fecha_inicio_hasta`, `fecha_vencimiento_desde`, `fecha_vencimiento_hasta`, `search`, `order_by`, `order_dir`, `page`, `per_page`.

---

## 8. Tareas administrativas

```bash
# Forzar a un usuario AD a tener rol admin (útil si se rota el FIRST_ADMIN)
docker compose exec backend php artisan atlas:ensure-admin nombre.usuario

# Limpiar caches de Laravel
docker compose exec backend php artisan config:clear
docker compose exec backend php artisan route:clear
docker compose exec backend php artisan cache:clear

# Acceder al MySQL
docker compose exec db mysql -u atlas_user -p atlas_db
```

---

## 9. Estructura de carpetas (resumen)

```
backend/
├── app/
│   ├── Console/Commands/EnsureAdminCommand.php
│   ├── Exports/ContratosExport.php
│   ├── Http/
│   │   ├── Controllers/   (Auth, Contrato, Dashboard, Crud..., UserRole)
│   │   └── Middleware/CheckRole.php
│   ├── Models/            (UserRole, Contrato, Estado, ...)
│   ├── Providers/AppServiceProvider.php
│   └── Services/          (LdapAuthService, ContratoService, BaseCrudService, ...)
├── bootstrap/app.php
├── config/                (app, auth, sanctum, ldap, cors, ...)
├── public/index.php
├── routes/api.php
├── composer.json
├── Dockerfile
└── docker-entrypoint.sh

frontend/src/
├── index.html
├── main.js
├── styles/main.css
├── assets/atlas-icon.svg
├── components/   (layout, modal, pager, logo, icons)
├── services/     (api, format, router, toast)
└── modules/
    ├── login/
    ├── dashboard/
    ├── contratos/   (listado, formulario, detalle)
    ├── entidades/   (ABM genérico para los 7 catálogos)
    └── roles/

db/
├── Dockerfile
├── my.cnf
└── init/
    ├── 01_schema.sql
    └── 02_seeds.sql

nginx/
├── Dockerfile
└── nginx.conf
```

---

## 10. Procedimiento normativo de pase a producción

El sistema fue desarrollado bajo la modalidad **Desarrollo Externo con Soporte Interno**:

- Backend en PHP 8.2 + Laravel 11 (mantenible internamente por el organismo).
- Sin dependencias propietarias.
- Datos en MySQL 8 con script de inicialización idempotente.
- Despliegue 100% Docker.

Antes del pase definitivo, el organismo debe:

1. Confirmar y entregar las características del servidor (sección 3).
2. Proveer las credenciales LDAP de servicio y atributo de identificación.
3. Definir el `FIRST_ADMIN_USERNAME`.
4. Validar política de backup del volumen `mysql_data`.
5. Validar política de logs (rotación y destino).
6. Realizar la prueba de bind LDAP desde el servidor.
7. Ejecutar las pruebas funcionales contra los datos semilla.

---

## 11. Soporte y mantenimiento

- Logs de la aplicación: `docker compose logs backend` y archivo `storage/logs/atlas.log` dentro del contenedor.
- Para integrar nuevos campos al esquema, agregar la columna en `db/init/01_schema.sql` y/o crear una migración Laravel y ejecutar `php artisan migrate`.
- Al rotar la cuenta de servicio LDAP, basta con actualizar `LDAP_USERNAME` / `LDAP_PASSWORD` en `.env` y reiniciar `backend`.

---

*ATLAS · PHP 8.2 + Laravel 11 + LDAP + MySQL 8 + Docker*
