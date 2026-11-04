# Control de compras - La Labranza

Aplicación de un solo usuario para registrar boletas/facturas de compras de cocina y seguir la evolución de precios.
Destino: HostGator (hosting compartido, Apache, cPanel) en `https://compras.lalabranza.cl`
(alternativa: `https://lalabranza.cl/compras/`, ver "Alternativa en subcarpeta").

- `web/`: frontend (Vite + React). `api/`: backend PHP (sin Composer en producción). `deploy/`: empaquetado y smoke test.
- **Versión de PHP: 8.1 a 8.3.** El cálculo de vista previa en el navegador replica el `round()` de PHP 8.1; otras versiones podrían diferir en redondeos.

## Estructura del paquete (`dist/`)

```
dist/docroot/   -> raíz web del subdominio (p. ej. ~/public_html/compras/)
  index.html assets/ robots.txt .htaccess
  api/index.php api/.htaccess api/app-root.php
dist/kt-app/    -> ~/kt-app/  (FUERA de public_html)
  bootstrap.php src/ migrations/ bin/ config.example.php
```

## Checklist cPanel (una sola vez)

1. **MultiPHP Manager**: asignar PHP 8.1, 8.2 u 8.3 al subdominio. En *MultiPHP INI Editor* verificar que `pdo_mysql` esté habilitado (Select PHP Versión / extensiones).
2. **MySQL Databases**: crear la base de datos y un usuario, y asignarlo con todos los privilegios. Anotar el nombre completo (con prefijo, p. ej. `lalabran_kt`).
3. **Domains / Subdomains**: crear `compras.lalabranza.cl` con raíz `public_html/compras`.
4. **SSL/TLS Status**: ejecutar **AutoSSL** para el subdominio y confirmar que `https://compras.lalabranza.cl` carga con candado (el `.htaccess` fuerza HTTPS).
5. **Cron Jobs**: se crea después de la primera instalación (ver "Respaldos").
6. Confirmar que el usuario cPanel es el correcto (`/home/USUARIO`) para usarlo en `--app-root`.

## Primer despliegue

En tu computador (necesita Node 20+ y npm):

```bash
deploy/build.sh --app-root=/home/USUARIO/kt-app
```

(`USUARIO` = usuario cPanel. Genera `dist/`.)

1. Subir (File Manager o FTP/SFTP) el contenido de `dist/docroot/` a `~/public_html/compras/` (incluir los archivos ocultos `.htaccess` y `api/.htaccess`).
2. Subir `dist/kt-app/` a `~/kt-app/` (fuera de `public_html`). Incluye la carpeta vacía `~/kt-app/sessions/` (permisos 700): ahí se guardan las sesiones, para que la limpieza de sesiones compartida del hosting (a menudo 24 minutos) no corte la sesión de 12 horas. Si el File Manager no sube carpetas vacías, la app la crea sola; también se puede cambiar con `session_dir` en `config.php`.
3. En `~/kt-app/`, copiar `config.example.php` a `config.php` y editar:
   - `db_dsn` => `mysql:host=localhost;dbname=NOMBRE_COMPLETO_DB`, `db_user`, `db_pass`
   - `cookie_secure` => `true`
   - `backup_dir` => `/home/USUARIO/kt-backups` (fuera de `public_html`)
   - `cron_token` => una cadena aleatoria de 32+ caracteres (`openssl rand -hex 24`). Mientras sea `CHANGE-ME` la URL de cron responde 403.
4. Con SSH o el Terminal de cPanel:

   ```bash
   cd ~/kt-app
   php bin/migrate.php
   php bin/set-password.php
   ```

   `migrate.php` crea las tablas; `set-password.php` define la clave de acceso.
5. Abrir `https://compras.lalabranza.cl`, iniciar sesión y, desde tu computador, ejecutar:

   ```bash
   deploy/smoke.sh https://compras.lalabranza.cl
   ```

   Debe terminar con `SMOKE PASSED`.

**Sin acceso a shell:** abrir phpMyAdmin, seleccionar la base de datos e importar (pestaña *Importar*) cada archivo de `~/kt-app/migrations/*.sql` en orden numérico (`001_...`, `002_...`, `003_...`). Luego definir la clave sin shell, con un cron de una sola vez:

1. En File Manager crear `/home/USUARIO/clave.txt` con dos líneas iguales: la clave (mínimo 10 caracteres) repetida.
2. En Cron Jobs agregar (ejecución en el próximo minuto): `/usr/local/bin/ea-php81 /home/USUARIO/kt-app/bin/set-password.php < /home/USUARIO/clave.txt` (ajustar la ruta de PHP según MultiPHP Manager, ver "Respaldos").
3. Cuando haya corrido (iniciar sesión en la app para comprobar), **borrar `clave.txt` y el cron job**. La clave queda guardada solo como hash.

Alternativa: pedir a soporte de HostGator que habilite SSH, o usar el Terminal de cPanel si la cuenta lo incluye.

## Actualizar

1. `deploy/build.sh --app-root=/home/USUARIO/kt-app`
2. Subir `dist/docroot/` y `dist/kt-app/` encima de lo existente. **No sobrescribir ni borrar `~/kt-app/config.php`** (el paquete no lo incluye) ni el contenido de `~/kt-app/sessions/`.
3. Si hay migraciones nuevas: `cd ~/kt-app && php bin/migrate.php` (es idempotente).
4. `deploy/smoke.sh https://compras.lalabranza.cl`

## Respaldos

El respaldo genera un archivo `.sql` en `backup_dir` y borra los de más de 30 días.

Cron (cPanel > Cron Jobs), todos los días a las 04:00:

```
0 4 * * * /usr/local/bin/ea-php81 ~/kt-app/bin/backup.php
```

Usar el binario de PHP explícito: el `php` por defecto del cron puede ser otra versión. Revisar la ruta real en cPanel > MultiPHP Manager (p. ej. `/usr/local/bin/ea-php81`, `ea-php82` o `ea-php83`).

Si el cron de cPanel no está disponible, usar un programador externo (p. ej. cron-job.org) llamando una vez al dia a:

```
GET https://compras.lalabranza.cl/api/cron/backup?token=TU_CRON_TOKEN
```

(el `cron_token` de `config.php`). También se puede descargar un respaldo manual en la app: Ajustes > Respaldo. Copiar los respaldos fuera del servidor de vez en cuando.

## Restaurar un respaldo

**Probar este procedimiento una vez antes de usar la app en serio.**

- phpMyAdmin: seleccionar la base de datos (vacía o borrar las tablas antes) > *Importar* > elegir el archivo `.sql` > *Continuar*.
- Con shell:

  ```bash
  mysql -u USUARIO_DB -p NOMBRE_DB < ~/kt-backups/ARCHIVO.sql
  ```

Después de restaurar, entrar a la app y verificar que las boletas recientes están.

## Alternativa en subcarpeta (`lalabranza.cl/compras/`)

Si no se puede usar el subdominio:

```bash
deploy/build.sh --base=/compras/ --app-root=/home/USUARIO/kt-app
```

Subir `dist/docroot/` a `~/public_html/compras/` y `dist/kt-app/` a `~/kt-app/`; el resto es igual. Probar con `deploy/smoke.sh https://lalabranza.cl/compras`.

**Advertencia:** el despliegue del sitio principal (lalabranza.cl) **no debe borrar la carpeta `compras/`** (por ejemplo, si sincroniza o limpia `public_html`). Excluirla del borrado/sincronización.

## Prueba local en PHP 8.1 + Apache (Docker)

```bash
deploy/build.sh --app-root=/kt-app
docker build -t kt-php81 deploy
# MariaDB de prueba, config.php en dist/kt-app (solo local), luego:
docker run -d -p 8081:80 -v $PWD/dist/docroot:/var/www/html -v $PWD/dist/kt-app:/kt-app kt-php81
deploy/smoke.sh http://localhost:8081
```

## Desarrollo

```bash
cd api && composer install && vendor/bin/phpunit   # requiere MySQL/MariaDB con bases kt_dev y kt_test (usuario kt/kt)
cd web && npm ci && npm test && npm run dev
```
