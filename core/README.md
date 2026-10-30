# iaachz-panel

Capa estilo **Plesk** para Hestia Control Panel (probado en Hestia 1.10.5 / Ubuntu 26.04, servidor *test*).
Se instala junto a Hestia sin reemplazar su motor: usa sus comandos `v-*` y añade los que faltaban.

## Qué añade

| Plesk | iaachz-panel | Dónde |
|---|---|---|
| Panel lateral, Inicio con estado del servidor | Barra lateral, medidores en vivo (CPU, RAM, disco, carga, red, E/S), servicios con reinicio, procesos | `/pro/` |
| Websites & Domains | Lista de dominios con tipo de hosting, SSL (días restantes), DNS, correo, Git y herramientas por dominio | `/pro/sites/` |
| Panel del dominio | Pestañas Hosting y PHP, Aplicación, SSL/TLS, Git, WordPress, Suspender/eliminar | `/pro/site/?domain=…` |
| Add Domain / Add Subscription | Asistente: cliente + plan + dominio + DNS + correo + BD + SSL Let's Encrypt + hosting, en un paso | `/pro/new/` |
| Subscriptions / Customers | Suscripciones con plan, consumo de disco y tráfico, dominios y apps | `/pro/subscriptions/` |
| Node.js Toolkit | Versiones 20/22/24/26 (nodejs.org, SHA256), servicio systemd por app, npm install, scripts de package.json, variables, registros | Aplicaciones / dominio → Aplicación |
| Python | Sistema + 3.10–3.14 (uv), venv por app, Gunicorn / Uvicorn / comando, pip install | idem |
| Docker | Instalación en un clic, contenedores, imágenes, pilas Compose, contenedor publicado en un dominio con SSL | `/pro/docker/` |
| Apache & nginx Settings | Por dominio: **solo nginx** (PHP-FPM directo) o **nginx + Apache** (respeta `.htaccess`; nginx sirve los estáticos, lista de extensiones configurable). Apache escucha solo en `127.0.0.1:7080` y usa el mismo pool PHP-FPM del dominio | dominio → Hosting y PHP, asistente |
| Databases → Import / Export Dump | Importar volcados `.sql`, `.gz`, `.zip`, `.bz2`, `.xz`, `.zst` subidos desde el navegador (por partes, hasta 8 GB) o desde un archivo del servidor; opción de vaciar antes; exportar `.sql.gz`; ver tablas. La importación usa un usuario temporal limitado a esa base de datos (Hestia por consola usa la cuenta administradora) y reasigna vistas/triggers/rutinas a su usuario | `/pro/databases/` |
| Databases → phpMyAdmin | Botón **phpMyAdmin** en cada base de datos que entra ya autenticado y solo a esa base: ticket aleatorio de un solo uso (60 s, atado al navegador con una cookie, no a la IP), usuario MySQL temporal limitado a esa base que se borra a las 3 h (`hp-sso.php` + `conf.d/90-iaachz-panel-sso.php`). No necesita la API de Hestia | `/pro/databases/` |
| Git | Clonar/actualizar rama, acciones posteriores, reinicio de app, llave de despliegue, webhook | dominio → Git |
| WP Toolkit (básico) | Instalación de WordPress en el asistente, estado de núcleo/plugins/temas, actualizar todo | dominio → WordPress |
| Monitoring | Historial 1 h–1 año (1 muestra/min, SQLite), alertas por CPU/RAM/disco/carga/servicios/apps/SSL a la campana y por correo | `/pro/monitor/`, Herramientas → Alertas |
| Monitoring → Tráfico | Peticiones/s, conexiones nuevas/s y abiertas (nginx `stub_status`), TCP establecidas, consultas MySQL/s; respuestas 2xx-5xx por minuto; dominios, páginas y URLs con error (404/5xx) más frecuentes; % de bots; IPs con más peticiones, IPs sospechosas (escaneos) y conectados ahora. Se leen solo las líneas nuevas de los registros de nginx cada minuto (detalle 8 días, por horas 1 año). También en la ficha de cada dominio. Modo **En vivo** (muestra cada ~2 s) y **Exportar**: informe HTML de un solo archivo con gráficos interactivos (tooltip, leyenda que oculta series, modo claro/oscuro, sin internet), el mismo informe en PDF vía impresión del navegador, y datos CSV | `/pro/monitor/#traffic` |
| Uptime (disponibilidad) | Cada minuto sondea cada sitio desde el servidor: puerto de la aplicación y petición real a nginx con el Host del dominio; también la conexión a internet del servidor. Muestra cuánto lleva en línea cada sitio, % de disponibilidad (hoy / 7 / 30 días), tiempo de respuesta y un **registro** de caídas, recuperaciones, cortes de internet y huecos en que el servidor estuvo parado (90 días de estadísticas). Caída = 2 fallos seguidos; avisa en la campana y por correo | `/pro/uptime/` |
| Advisor | Puntuación y recomendaciones (SSL, HTTPS, cortafuegos, actualizaciones, reinicio pendiente, disco, apps caídas, PHP sin soporte) | `/pro/advisor/` |
| Tools & Settings | Todo lo del servidor agrupado, tareas en segundo plano, terminal web en un clic | `/pro/tools/` |

También: estilo nuevo para la pantalla de acceso y las páginas nativas de Hestia, tema claro y oscuro, móvil.

## Cómo funciona

- `bin/v-hp` + `lib/hp/*.py`: comando root (vía el `sudo` que Hestia ya permite para `/usr/local/hestia/bin/*`). Responde JSON. Lo lento (instalar, npm, pip, docker pull, Git, Let's Encrypt, asistente) corre como **tarea en segundo plano** con pasos y registro (`v-hp job-status ID`).
- Cada app Node.js/Python es un servicio `hp-app-<usuario>-<dominio>` que corre **como el usuario dueño**, escucha en `127.0.0.1:<puerto 30000+>` y nginx (plantilla `hp-app`) le reenvía el dominio con WebSockets. Las variables de entorno se guardan en `/etc/iaachz-panel/env` (solo root).
- Métricas: el servicio `iaachz-panel-sampler` mide cada 2 s y guarda los últimos 5 minutos (`data/hp/live.json`); el modo En vivo y la gráfica de Inicio arrancan ya llenos. Cada minuto el cron guarda el promedio y, con esas muestras de 2 s, el **pico real** de CPU y de peticiones/s (`cpu_pk`, `req_pk`); al agrupar en horas los picos se conservan (MAX) y el resto se promedia. Consume ~1,5 % de un núcleo y ~16 MB.
- nginx + Apache: vhost por dominio en `/etc/apache2/iaachz-panel/`, reglas nginx en `conf/web/<dominio>/hp-apache.conf` (plantilla `hp-apache`). El cron (`alerts-check`) regenera los vhost si cambian la versión de PHP, los alias o la raíz; si nginx rechaza la configuración se revierte sola.
- Los contenedores Docker de dominio se publican solo en `127.0.0.1`. Docker y Compose son solo para el administrador.
- Datos: `/usr/local/hestia/data/users/<usuario>/hp-apps.json` y `hp-git.json`; métricas y tareas en `/usr/local/hestia/data/hp`.
- Interfaz: `/usr/local/hestia/web/pro` (PHP + Alpine.js + Chart.js que ya trae Hestia). Usa la sesión y el token CSRF de Hestia; el cliente solo actúa sobre lo suyo.

## Archivos de Hestia que se tocan

Todo lo demás son archivos nuevos. Los cambios llevan la marca `iaachz-panel` y el original queda en `/opt/iaachz-panel/backup/<versión de Hestia>/`:

- `web/templates/includes/panel.php` → barra lateral.
- `web/index.php`, `web/list/index.php`, `web/login/index.php` → el inicio pasa a `/pro/`.
- `web/inc/prevent_csrf.php` → `/pro/` y `/pro/sites/` admitidos como páginas de entrada (marcadores).
- `web/js/custom_scripts/iaachz-panel.php` (mecanismo oficial de Hestia) → carga CSS/JS.

Las actualizaciones de Hestia (`apt upgrade`) sobrescriben esos archivos; el hook `/etc/hestiacp/hooks/post_install.sh` vuelve a aplicar los cambios automáticamente.

## Instalar / actualizar

```bash
sudo bash install.sh
```

Copia el código a `/opt/iaachz-panel/src` y desde ahí se reaplica (`install.sh --reapply`). Cron: `/etc/cron.d/iaachz-panel` (métricas cada minuto, alertas y limpieza cada 5 min).

## Desinstalar

```bash
V=$(grep -oP "^VERSION='\K[^']+" /usr/local/hestia/conf/hestia.conf)
cd /opt/iaachz-panel/backup/$V
cp panel.php /usr/local/hestia/web/templates/includes/panel.php
cp index.php /usr/local/hestia/web/index.php; cp list_index.php /usr/local/hestia/web/list/index.php
cp login_index.php /usr/local/hestia/web/login/index.php; cp inc_prevent_csrf.php /usr/local/hestia/web/inc/prevent_csrf.php
rm -rf /usr/local/hestia/web/pro /usr/local/hestia/web/js/custom_scripts/iaachz-panel.php /etc/cron.d/iaachz-panel
sed -i '/iaachz-panel/d' /etc/hestiacp/hooks/post_install.sh
```

Quita antes las aplicaciones (cada dominio vuelve a su plantilla anterior) para que no queden servicios `hp-app-*` ni contenedores `hp-*`.
