#!/bin/bash
# Hestia Pro — instalador / reaplicador.
#   install.sh            instala o actualiza desde esta carpeta
#   install.sh --reapply  vuelve a aplicar los parches tras actualizar Hestia (lo llama el hook post_install)
# Todo lo propio vive en /opt/hestia-pro; sobre Hestia solo se tocan archivos nuevos y,
# con marcas "hestia-pro", panel.php y las redirecciones de inicio (se respaldan antes).
set -euo pipefail

HESTIA=/usr/local/hestia
PRO=/opt/hestia-pro
SRC="$(cd "$(dirname "$0")" && pwd)"
WEB=$HESTIA/web
TPL=$HESTIA/data/templates/web/nginx/php-fpm
VERSION=$(grep -oP "^VERSION='\K[^']+" $HESTIA/conf/hestia.conf || echo unknown)

[ "$(id -u)" = 0 ] || { echo "Ejecuta como root"; exit 1; }

if [ "${1:-}" != "--reapply" ]; then
	echo "== Copiando Hestia Pro a $PRO"
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
install -o root -g root -m 644 $S/web/custom_scripts/hestia-pro.php $WEB/js/custom_scripts/hestia-pro.php

# panel.php: respaldo del original de esta versión de Hestia y reemplazo por el lateral estilo Plesk
mkdir -p $PRO/backup/$VERSION
if ! grep -q "hestia-pro" $WEB/templates/includes/panel.php; then
	cp -a $WEB/templates/includes/panel.php $PRO/backup/$VERSION/panel.php
fi
install -o root -g root -m 644 $S/web/panel.php $WEB/templates/includes/panel.php

# Inicio -> /pro/ (panel de inicio estilo Plesk) en vez de la lista de usuarios
patch_home() {
	local f=$1
	[ -f "$f" ] || return 0
	grep -q "hestia-pro" "$f" && return 0
	[ -f "$PRO/backup/$VERSION/$(echo "${f#$WEB/}" | tr / _)" ] || cp -a "$f" "$PRO/backup/$VERSION/$(echo "${f#$WEB/}" | tr / _)"
	sed -i 's#"list/user"#"pro" /* hestia-pro */#; s#Location: /list/user/");#Location: /pro/"); // hestia-pro#' "$f"
}
patch_home $WEB/index.php
patch_home $WEB/list/index.php
patch_home $WEB/login/index.php

# El filtro CSRF de Hestia solo deja entrar por GET sin "Referer" a ciertas páginas de inicio
# (marcadores, URL escrita a mano, redirección desde "/"): se suman Inicio y Sitios de Hestia Pro.
CSRF=$WEB/inc/prevent_csrf.php
if [ -f $CSRF ] && ! grep -q "hestia-pro" $CSRF; then
	cp -a $CSRF "$PRO/backup/$VERSION/inc_prevent_csrf.php"
	sed -i 's#^\(\s*\)"/list/user/index.php",#\1"/list/user/index.php",\n\1"/pro/index.php", // hestia-pro\n\1"/pro/sites/index.php", // hestia-pro#' $CSRF
fi

echo "== phpMyAdmin: acceso directo por base de datos"
PMA=/usr/share/phpmyadmin
if [ -d $PMA ] && [ -d /etc/phpmyadmin/conf.d ]; then
	install -o root -g hestiamail -m 640 $S/pma/hp-sso.php $PMA/hp-sso.php
	install -o root -g hestiamail -m 640 $S/pma/90-hestia-pro-sso.php /etc/phpmyadmin/conf.d/90-hestia-pro-sso.php
	mkdir -p /var/lib/hestia-pro/pma
	chown hestiamail:hestiamail /var/lib/hestia-pro/pma && chmod 700 /var/lib/hestia-pro/pma
fi
# Hestia programa con "at" la limpieza de usuarios temporales de MySQL y de volcados exportados
if command -v atd >/dev/null 2>&1 || [ -x /usr/sbin/atd ]; then
	systemctl enable --now atd >/dev/null 2>&1 || true
fi

echo "== Datos, tareas cron y hook de actualización"
mkdir -p $HESTIA/data/hp/jobs /etc/hestia-pro/env /var/lib/hestia-pro/uploads
# subidas de volcados: las escribe el panel (hestiaweb) y v-hp las mueve a una zona solo-root antes de usarlas
chown root:root /var/lib/hestia-pro && chmod 755 /var/lib/hestia-pro
chown hestiaweb:hestiaweb /var/lib/hestia-pro/uploads && chmod 700 /var/lib/hestia-pro/uploads
chmod 700 $HESTIA/data/hp $HESTIA/data/hp/jobs /etc/hestia-pro /etc/hestia-pro/env
cat > /etc/cron.d/hestia-pro <<'EOF'
# Hestia Pro: métricas cada minuto y alertas/limpieza cada 5 minutos
* * * * * root /usr/local/hestia/bin/v-hp metrics-collect >/dev/null 2>&1
*/5 * * * * root /usr/local/hestia/bin/v-hp alerts-check >/dev/null 2>&1
EOF
chmod 644 /etc/cron.d/hestia-pro

# Muestreo continuo cada 2 s para el modo "En vivo" del Monitor (últimos 5 minutos siempre listos)
cat > /etc/systemd/system/hestia-pro-sampler.service <<'EOF2'
[Unit]
Description=Hestia Pro: muestreo de métricas en vivo (cada 2 s)
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
systemctl enable hestia-pro-sampler >/dev/null 2>&1 || true
systemctl restart hestia-pro-sampler || true

# Acceso por país e IP: base de países, listas por país y reglas propias de iptables (HP_GEO / HP_ADMIN)
mkdir -p /var/lib/hestia-pro/geo
chmod 755 /var/lib/hestia-pro/geo
cat > /etc/systemd/system/hestia-pro-firewall.service <<'EOF3'
[Unit]
Description=Hestia Pro: bloqueos por país/IP y lista blanca de administración
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
systemctl enable hestia-pro-firewall >/dev/null 2>&1 || true
grep -q "geo-update-cron" /etc/cron.d/hestia-pro || echo '17 4 4 * * root /usr/local/hestia/bin/v-hp geo-update-cron >/dev/null 2>&1' >> /etc/cron.d/hestia-pro
# Si se activa el cortafuegos de Hestia, este vacía INPUT: su gancho custom.sh vuelve a poner nuestras reglas
FWD=$HESTIA/data/firewall
if [ -d $FWD ] && [ -n "$(ls -A $FWD 2>/dev/null)" ] && ! grep -qs "hestia-pro" $FWD/custom.sh; then
	[ -f $FWD/custom.sh ] || printf '#!/bin/bash\n' > $FWD/custom.sh
	echo '/usr/local/hestia/bin/v-hp fw-boot >/dev/null 2>&1  # hestia-pro' >> $FWD/custom.sh
	chmod 755 $FWD/custom.sh
fi

mkdir -p /etc/hestiacp/hooks
HOOK=/etc/hestiacp/hooks/post_install.sh
if [ ! -f $HOOK ]; then
	printf '#!/bin/bash\n' > $HOOK
fi
if ! grep -q "hestia-pro" $HOOK; then
	echo '[ -x /opt/hestia-pro/src/install.sh ] && /opt/hestia-pro/src/install.sh --reapply  # hestia-pro' >> $HOOK
fi
chmod 755 $HOOK
chmod 700 $PRO/src/install.sh 2>/dev/null || true

/usr/local/hestia/bin/v-hp metrics-collect >/dev/null 2>&1 || true
/usr/local/hestia/bin/v-hp traffic-backfill >/dev/null 2>&1 || true  # visitantes por dominio (solo la primera vez)
echo "== Hestia Pro instalado (Hestia $VERSION)"
