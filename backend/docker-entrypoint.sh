#!/bin/sh
set -e

cd /var/www/html

echo "[atlas] Esperando a que MySQL esté disponible..."
until php -r "try { new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); echo 'OK'; } catch (\Throwable \$e) { exit(1); }" >/dev/null 2>&1; do
  printf '.'
  sleep 2
done
echo " MySQL OK."

# Generar APP_KEY si está en valor placeholder o vacío
if [ -z "$APP_KEY" ] || echo "$APP_KEY" | grep -q "CAMBIAR\|not-a-real-key"; then
  echo "[atlas] Generando APP_KEY..."
  php artisan key:generate --force --no-interaction || true
fi

# Cargar admin inicial desde FIRST_ADMIN_USERNAME (idempotente)
if [ -n "$FIRST_ADMIN_USERNAME" ]; then
  echo "[atlas] Asegurando admin inicial: $FIRST_ADMIN_USERNAME"
  php artisan atlas:ensure-admin "$FIRST_ADMIN_USERNAME" || echo "[atlas] WARN: no se pudo crear admin inicial"
fi

# Cache de configuración
php artisan config:clear || true
php artisan route:clear || true
php artisan cache:clear || true

# Permisos
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

echo "[atlas] Backend listo."
exec "$@"
