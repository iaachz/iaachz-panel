#!/bin/bash
# iaachz-panel — instalador / reaplicador.
#   install.sh            instala o actualiza desde esta carpeta
#   install.sh --reapply  vuelve a aplicar los parches tras actualizar Hestia (lo llama el hook post_install)
# Todo lo propio vive en /opt/iaachz-panel; sobre Hestia solo se tocan archivos nuevos y,
# con marcas "iaachz-panel", panel.php y las redirecciones de inicio (se respaldan antes).
set -euo pipefail

HESTIA=/usr/local/hestia
PRO=/opt/iaachz-panel
SRC="$(cd "$(dirname "$0")" && pwd)"
WEB=$HESTIA/web
TPL=$HESTIA/data/templates/web/nginx/php-fpm
VERSION=$(grep -oP "^VERSION='\K[^']+" $HESTIA/conf/hestia.conf || echo unknown)

[ "$(id -u)" = 0 ] || { echo "Ejecuta como root"; exit 1; }

if [ "${1:-}" != "--reapply" ]; then
	echo "== Copiando iaachz-panel a $PRO"
	mkdir -p $PRO/src $PRO/lib $PRO/runtimes/node $PRO/runtimes/python $PRO/launch $PRO/compose $PRO/bin $PRO/backup
	chmod 755 $PRO $PRO/runtimes $PRO/runtimes/node $PRO/runtimes/python $PRO/launch $PRO/bin
	chmod 700 $PRO/compose $PRO/backup $PRO/src
	if [ "$SRC" != "$PRO/src" ]; then
		rm -rf $PRO/src.new && cp -a "$SRC" $PRO/src.new && rm -rf $PRO/src && mv $PRO/src.new $PRO/src
		chmod 700 $PRO/src
	fi
fi
S=$PRO/src

echo "== Biblioteca y comando v-hp"
rm -rf $PRO/lib/hp && cp -a $S/lib/hp $PRO/lib/hp
find $PRO/lib -type d -exec chmod 755 {} + ; find $PRO/lib -type f -exec chmod 644 {} +
chown -R root:root $PRO/lib
install -o root -g root -m 755 $S/bin/v-hp $HESTIA/bin/v-hp

echo "== Plantillas nginx hp-app"
install -o root -g root -m 644 $S/templates/hp-app.tpl $TPL/hp-app.tpl
install -o root -g root -m 644 $S/templates/hp-app.stpl $TPL/hp-app.stpl
install -o root -g root -m 644 $S/templates/hp-apache.tpl $TPL/hp-apache.tpl
install -o root -g root -m 644 $S/templates/hp-apache.stpl $TPL/hp-apache.stpl

echo "== Interfaz web (/pro)"
rm -rf $WEB/pro.new && cp -a $S/web/pro $WEB/pro.new
rm -rf $WEB/pro && mv $WEB/pro.new $WEB/pro
find $WEB/pro -type d -exec chmod 755 {} + ; find $WEB/pro -type f -exec chmod 644 {} +
chown -R root:root $WEB/pro
install -o root -g root -m 644 $S/web/custom_scripts/iaachz-panel.php $WEB/js/custom_scripts/iaachz-panel.php

# panel.php: respaldo del original de esta versión de Hestia y reemplazo por el lateral estilo Plesk
mkdir -p $PRO/backup/$VERSION
if ! grep -q "iaachz-panel" $WEB/templates/includes/panel.php; then
	cp -a $WEB/templates/includes/panel.php $PRO/backup/$VERSION/panel.php
fi
install -o root -g root -m 644 $S/web/panel.php $WEB/templates/includes/panel.php

# Inicio -> /pro/ (panel de inicio estilo Plesk) en vez de la lista de usuarios
patch_home() {
	local f=$1
	[ -f "$f" ] || return 0
	grep -q "iaachz-panel" "$f" && return 0
	[ -f "$PRO/backup/$VERSION/$(echo "${f#$WEB/}" | tr / _)" ] || cp -a "$f" "$PRO/backup/$VERSION/$(echo "${f#$WEB/}" | tr / _)"
	sed -i 's#"list/user"#"pro" /* iaachz-panel */#; s#Location: /list/user/");#Location: /pro/"); // iaachz-panel#' "$f"
}
patch_home $WEB/index.php
patch_home $WEB/list/index.php
patch_home $WEB/login/index.php

# El filtro CSRF de Hestia solo deja entrar por GET sin "Referer" a ciertas páginas de inicio
# (marcadores, URL escrita a mano, redirección desde "/"): se suman Inicio y Sitios de iaachz-panel.
CSRF=$WEB/inc/prevent_csrf.php
if [ -f $CSRF ] && ! grep -q "iaachz-panel" $CSRF; then
	cp -a $CSRF "$PRO/backup/$VERSION/inc_prevent_csrf.php"
	sed -i 's#^\(\s*\)"/list/user/index.php",#\1"/list/user/index.php",\n\1"/pro/index.php", // iaachz-panel\n\1"/pro/sites/index.php", // iaachz-panel#' $CSRF
fi

echo "== phpMyAdmin: acceso directo por base de datos"
PMA=/usr/share/phpmyadmin
if [ -d $PMA ] && [ -d /etc/phpmyadmin/conf.d ]; then
	install -o root -g hestiamail -m 640 $S/pma/hp-sso.php $PMA/hp-sso.php
	install -o root -g hestiamail -m 640 $S/pma/90-iaachz-panel-sso.php /etc/phpmyadmin/conf.d/90-iaachz-panel-sso.php
	mkdir -p /var/lib/iaachz-panel/pma
	chown hestiamail:hestiamail /var/lib/iaachz-panel/pma && chmod 700 /var/lib/iaachz-panel/pma
fi
# Hestia programa con "at" la limpieza de usuarios temporales de MySQL y de volcados exportados
if command -v atd >/dev/null 2>&1 || [ -x /usr/sbin/atd ]; then
	systemctl enable --now atd >/dev/null 2>&1 || true
fi

echo "== Datos, tareas cron y hook de actualización"
mkdir -p $HESTIA/data/hp/jobs /etc/iaachz-panel/env /var/lib/iaachz-panel/uploads
# subidas de volcados: las escribe el panel (hestiaweb) y v-hp las mueve a una zona solo-root antes de usarlas
chown root:root /var/lib/iaachz-panel && chmod 755 /var/lib/iaachz-panel
chown hestiaweb:hestiaweb /var/lib/iaachz-panel/uploads && chmod 700 /var/lib/iaachz-panel/uploads
chmod 700 $HESTIA/data/hp $HESTIA/data/hp/jobs /etc/iaachz-panel /etc/iaachz-panel/env
cat > /etc/cron.d/iaachz-panel <<'EOF'
# iaachz-panel: métricas y disponibilidad de los sitios cada minuto, alertas/limpieza cada 5 minutos
* * * * * root /usr/local/hestia/bin/v-hp metrics-collect >/dev/null 2>&1
*/5 * * * * root /usr/local/hestia/bin/v-hp alerts-check >/dev/null 2>&1
* * * * * root /usr/local/hestia/bin/v-hp uptime-check >/dev/null 2>&1
EOF
chmod 644 /etc/cron.d/iaachz-panel

# Muestreo continuo cada 2 s para el modo "En vivo" del Monitor (últimos 5 minutos siempre listos)
cat > /etc/systemd/system/iaachz-panel-sampler.service <<'EOF2'
[Unit]
Description=iaachz-panel: muestreo de métricas en vivo (cada 2 s)
After=network.target nginx.service

[Service]
Type=simple
ExecStart=/usr/bin/python3 -I /usr/local/hestia/bin/v-hp sampler
Restart=always
RestartSec=5
Nice=10
IOSchedulingClass=idle

[Install]
WantedBy=multi-user.target
EOF2
systemctl daemon-reload
systemctl enable iaachz-panel-sampler >/dev/null 2>&1 || true
systemctl restart iaachz-panel-sampler || true

# Acceso por país e IP: base de países, listas por país y reglas propias de iptables (HP_GEO / HP_ADMIN)
mkdir -p /var/lib/iaachz-panel/geo
chmod 755 /var/lib/iaachz-panel/geo
cat > /etc/systemd/system/iaachz-panel-firewall.service <<'EOF3'
[Unit]
Description=iaachz-panel: bloqueos por país/IP y lista blanca de administración
After=network-online.target docker.service tailscaled.service
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/bin/python3 -I /usr/local/hestia/bin/v-hp fw-boot

[Install]
WantedBy=multi-user.target
EOF3
systemctl daemon-reload
systemctl enable iaachz-panel-firewall >/dev/null 2>&1 || true
grep -q "uptime-check" /etc/cron.d/iaachz-panel || echo '* * * * * root /usr/local/hestia/bin/v-hp uptime-check >/dev/null 2>&1' >> /etc/cron.d/iaachz-panel
grep -q "geo-update-cron" /etc/cron.d/iaachz-panel || echo '17 4 4 * * root /usr/local/hestia/bin/v-hp geo-update-cron >/dev/null 2>&1' >> /etc/cron.d/iaachz-panel
# Si se activa el cortafuegos de Hestia, este vacía INPUT: su gancho custom.sh vuelve a poner nuestras reglas
FWD=$HESTIA/data/firewall
if [ -d $FWD ] && [ -n "$(ls -A $FWD 2>/dev/null)" ] && ! grep -qs "iaachz-panel" $FWD/custom.sh; then
	[ -f $FWD/custom.sh ] || printf '#!/bin/bash\n' > $FWD/custom.sh
	echo '/usr/local/hestia/bin/v-hp fw-boot >/dev/null 2>&1  # iaachz-panel' >> $FWD/custom.sh
	chmod 755 $FWD/custom.sh
fi

mkdir -p /etc/hestiacp/hooks
HOOK=/etc/hestiacp/hooks/post_install.sh
if [ ! -f $HOOK ]; then
	printf '#!/bin/bash\n' > $HOOK
fi
if ! grep -q "iaachz-panel" $HOOK; then
	echo '[ -x /opt/iaachz-panel/src/install.sh ] && /opt/iaachz-panel/src/install.sh --reapply  # iaachz-panel' >> $HOOK
fi
chmod 755 $HOOK
chmod 700 $PRO/src/install.sh 2>/dev/null || true

/usr/local/hestia/bin/v-hp metrics-collect >/dev/null 2>&1 || true
/usr/local/hestia/bin/v-hp traffic-backfill >/dev/null 2>&1 || true  # visitantes por dominio (solo la primera vez)
echo "== iaachz-panel instalado (Hestia $VERSION)"
