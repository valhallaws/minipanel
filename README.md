# Freyja

Control del VPS para operar aplicaciones web en Ubuntu. Incluye login local, inventario de sitios, cola auditable y ejecución controlada de provisión, despliegue y SSL.

## Arranque local

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan minipanel:admin "Tu nombre" tu@correo.com
npm install && npm run build
php artisan serve
```

En producción, usa `QUEUE_CONNECTION=database` y mantén activo un worker para **este** panel:

```bash
php artisan queue:work --tries=1 --timeout=900
```

## Instalación Ubuntu (VPS)

El instalador incluido prepara el host sin ejecutarse por accidente: primero muestra el plan; sólo modifica el VPS cuando se añade `--apply`.

```bash
cd /var/www/minipanel
sudo ./ops-agent/install-minipanel --app-path /var/www/minipanel --app-user www-data --php-version 8.3 --panel-domain panel.ejemplo.com --with-firewall
sudo ./ops-agent/install-minipanel --apply --app-path /var/www/minipanel --app-user www-data --php-version 8.3 --panel-domain panel.ejemplo.com --with-firewall
php artisan minipanel:doctor
```

El instalador es idempotente y prepara Nginx, PHP, Composer, Node/NPM, Redis, Certbot, cliente MariaDB, UFW (si se solicita), Fail2ban, el agente limitado y el worker systemd. Revisa la salida del primer comando y configura primero un `.env` de producción con `APP_ENV=production`, `APP_DEBUG=false`, una `APP_KEY` real y una base de datos exclusiva para el panel. Cuando el DNS del panel apunte al VPS, emite su certificado con `sudo certbot --nginx -d panel.ejemplo.com`.

`php artisan minipanel:doctor` sólo inspecciona dependencias; no cambia el servidor. Mantén `MINIPANEL_EXECUTION_ENABLED=false` hasta revisar el agente, el sudoers y el resultado del diagnóstico.

Por seguridad, la ejecución viene apagada. Puedes iniciar el worker en tu laptop: las operaciones quedarán marcadas como `blocked` y no invocarán el agente ni comandos del sistema. Sólo en el VPS objetivo, después de instalar el agente, configura `MINIPANEL_EXECUTION_ENABLED=true` y limpia la caché con `php artisan optimize:clear`.

Puedes instalar [ops-agent/minipanel-worker.service](ops-agent/minipanel-worker.service) en `/etc/systemd/system/`, ajustando `WorkingDirectory` y `User`, y luego ejecutar `systemctl daemon-reload && systemctl enable --now minipanel-worker`.

## Passkeys

En **Seguridad** puedes registrar varias passkeys (Touch ID, Face ID, Windows Hello o un gestor compatible) y revocarlas por dispositivo. Antes de modificar esa lista el panel pide confirmar la contraseña actual. WebAuthn exige HTTPS —excepto `localhost`— y una `APP_URL` estable. Antes de registrar la primera, configura `APP_URL=https://panel.tu-dominio.com` y genera un valor aleatorio permanente para `PASSKEYS_USER_HANDLE_SECRET`; cambiar el dominio o ese secreto invalida las passkeys existentes. La contraseña y TOTP siguen disponibles como recuperación.

## Modelo de seguridad

El panel **no ofrece terminal ni comandos libres**. Cada clic registra una acción (`provision`, `deploy` o `issue-ssl`) en `deployments`; el worker procesa exclusivamente esas acciones mediante el binario de lista permitida `ops-agent/minipanel-agent`. El binario vuelve a validar todos sus argumentos y no usa `eval` ni construye comandos shell desde entradas web.

Antes de conectar el agente real:

1. Publica el panel detrás de HTTPS, con una cuenta administrativa de contraseña única y fuerte.
2. Instala `nginx`, `git`, `composer`, `certbot`, `python3-certbot-nginx` y las versiones PHP-FPM que permitirás (8.2–8.4).
3. Instala `ops-agent/minipanel-agent` como `root:root` con permiso `0750` y `ops-agent/sudoers-minipanel` mediante `visudo`; ajusta el usuario PHP-FPM. Nunca habilites `sudo` genérico.
4. Publica el DNS del dominio hacia el VPS antes de pedir SSL. El agente crea el vhost HTTP primero y Certbot convierte el sitio a HTTPS.
5. Ejecuta el worker del propio MiniPanel como servicio systemd y conserva sus logs. Añade backup externo antes de activar `migrate --force` en despliegues.

Al crear un sitio, el panel encola `provision`; después configura el repositorio y presiona **Desplegar**. El deploy clona/actualiza la rama indicada, instala dependencias de producción, genera la app key si falta, ejecuta migraciones y optimiza Laravel. Para repos privados HTTPS, configura credenciales Git en el servidor antes de usar el panel.

## Backups

La acción de backup archiva los archivos del sitio. En proyectos Laravel con conexión `mysql` o `mariadb`, además crea un dump consistente de esa base dentro del mismo archivo. El agente obtiene las credenciales desde la configuración de Laravel y usa un archivo temporal con permiso `0600`; la contraseña no aparece en la línea de comandos, logs ni en el panel. Instala `mariadb-dump` o `mysqldump` en el VPS.

## Configuración de MariaDB del servidor

Para crear o restaurar bases desde el panel, configura un usuario **de aprovisionamiento** en el VPS; no uses `root`. Ejecuta una vez [ops-agent/mariadb-provisioner.sql](ops-agent/mariadb-provisioner.sql) como DBA, cambiando la contraseña. Después instala `ops-agent/setup-mariadb-provisioner` como root y ejecútalo: guarda host, puerto, usuario y contraseña en `/etc/minipanel/mariadb-provisioner.cnf`, propiedad de root y con permiso `0600`.

El panel cifra la contraseña para que el instalador pueda entregarla al agente una sola vez, pero nunca vuelve a mostrarla. En el VPS objetivo, con `MINIPANEL_EXECUTION_ENABLED=true`, aplica la configuración capturada desde el panel con `php artisan minipanel:apply-server-settings`. El agente la almacena como configuración root-only y la podrá usar sólo para las acciones permitidas de creación/restauración de bases.
