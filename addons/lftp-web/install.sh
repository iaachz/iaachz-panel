#!/bin/bash
# iaachz-panel · Archivos y transferencias (lftp-web) incrustado en /pro/transfers/.
#   install.sh             instala o actualiza (copia a /opt/iaachz-lftp-web, despliega en /opt/lftp-web)
#   install.sh --reapply   vuelve a aplicar los parches de Hestia tras actualizarlo (gancho post_install)
#   install.sh --quitar    desactiva el módulo y revierte los parches (los datos de los usuarios se conservan)
# Idempotente. Cada usuario de Hestia recibe su propio motor (servicio lftp-web-user-USUARIO) que corre
# con su identidad; la sesión del panel se valida con auth.php a través de una pasarela nginx local.
set -euo pipefail

HESTIA=/usr/local/hestia
HERE="$(cd "$(dirname "$0")" && pwd)"
SRC=/opt/iaachz-lftp-web
APP=/opt/lftp-web
CONF=$HESTIA/nginx/conf/nginx.conf
GWCONF=/etc/lftp-web/hestia-gateway.conf
GW_PORT_DEFAULT=8092
VERSION=$(grep -oP "^VERSION='\K[^']+" $HESTIA/conf/hestia.conf 2>/dev/null || echo unknown)
MODE=${1:-install}

[ "$(id -u)" = 0 ] || { echo "Ejecuta como root"; exit 1; }
[ -d $HESTIA ] || { echo "No se encuentra Hestia en $HESTIA"; exit 1; }

# --- bloque nginx del panel (puerto de Hestia) -------------------------------------------------
quitar_bloque_nginx() {
	grep -q '# nexopanel-transfers:begin' $CONF || return 0
	sed -i '/# nexopanel-transfers:begin/,/# nexopanel-transfers:end/d' $CONF
}

poner_bloque_nginx() { # puerto de la pasarela
	local port=$1 new backup
	new=$(mktemp)
	if grep -q '# nexopanel-transfers:begin' $CONF && grep -q "proxy_pass http://127.0.0.1:$port;" $CONF; then
		rm -f "$new"; return 0
	fi
	backup=$SRC/backup/nginx.conf.$VERSION-$(date +%Y%m%d-%H%M%S)
	mkdir -p $SRC/backup && cp -a $CONF "$backup"
	/usr/bin/python3 - "$CONF" "$new" "$port" <<'PY'
import re, sys
conf, out, port = sys.argv[1:]
text = open(conf).read()
block = f'''# nexopanel-transfers:begin
location = /pro/transfers/auth.php {{ return 404; }}
location ^~ /pro/transfers/service/ {{
    proxy_pass http://127.0.0.1:{port};
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-Proto https;
    client_max_body_size 4g;
    proxy_request_buffering off;
    proxy_buffering off;
    proxy_read_timeout 600s;
}}
# nexopanel-transfers:end'''
text = re.sub(r'\n# nexopanel-transfers:begin.*?# nexopanel-transfers:end\n?', '\n', text, flags=re.S)
found = re.search(r'^[ \t]*root[ \t]+/usr/local/hestia/web;[ \t]*$', text, re.M)
if not found:
    sys.exit('No se encontró el vhost del panel en nginx.conf (línea "root /usr/local/hestia/web;")')
text = text[:found.end()] + '\n\n' + block + '\n' + text[found.end():]
open(out, 'w').write(text)
PY
	cat "$new" > $CONF && rm -f "$new"
	if ! $HESTIA/nginx/sbin/hestia-nginx -t >/dev/null 2>&1; then
		cp -a "$backup" $CONF
		echo "La configuración nginx del panel no es válida con el bloque nuevo; se restauró la original ($backup)."
		exit 1
	fi
	$HESTIA/nginx/sbin/hestia-nginx -s reload
}

# --- v-delete-user: retirar el motor del usuario antes de borrarlo ----------------------------
parchar_delete_user() {
	local f=$HESTIA/bin/v-delete-user
	[ -f $f ] || return 0
	grep -q '# nexopanel-private-files' $f && return 0
	mkdir -p $SRC/backup && [ -f "$SRC/backup/v-delete-user.$VERSION" ] || cp -a $f "$SRC/backup/v-delete-user.$VERSION"
	/usr/bin/python3 - "$f" <<'PY'
import sys
path = sys.argv[1]
text = open(path).read()
anchor = 'check_hestia_demo_mode\n'
if anchor not in text:
    sys.exit('No se encuentra el punto seguro de retirada de usuarios en v-delete-user')
text = text.replace(anchor, anchor + '\n# nexopanel-private-files\n$BIN/v-transfer-engine --retire "$user" || exit 1\n', 1)
open(path, 'w').write(text)
PY
}

quitar_delete_user() {
	local f=$HESTIA/bin/v-delete-user
	[ -f $f ] && sed -i '/# nexopanel-private-files/,/v-transfer-engine --retire/d' $f || true
}

# --- reaplicar tras actualizar Hestia ---------------------------------------------------------
if [ "$MODE" = "--reapply" ]; then
	[ -f /etc/nexopanel/transfers.json ] || exit 0
	PORT=$(grep -oP 'listen 127\.0\.0\.1:\K[0-9]+' $GWCONF 2>/dev/null | head -1 || true)
	poner_bloque_nginx "${PORT:-$GW_PORT_DEFAULT}"
	parchar_delete_user
	exit 0
fi

# --- quitar -----------------------------------------------------------------------------------
if [ "$MODE" = "--quitar" ]; then
	systemctl disable --now lftp-web-gateway >/dev/null 2>&1 || true
	for u in /etc/systemd/system/lftp-web-user-*.service; do
		[ -e "$u" ] && systemctl disable --now "$(basename "$u")" >/dev/null 2>&1 || true
	done
	quitar_bloque_nginx
	quitar_delete_user
	$HESTIA/nginx/sbin/hestia-nginx -t >/dev/null 2>&1 && $HESTIA/nginx/sbin/hestia-nginx -s reload || true
	rm -f /etc/nexopanel/transfers.json /etc/systemd/system/lftp-web-gateway.service $HESTIA/bin/v-transfer-engine
	sed -i '/iaachz-lftp-web/d' /etc/hestiacp/hooks/post_install.sh 2>/dev/null || true
	systemctl daemon-reload
	echo "Archivos y transferencias: desactivado. Se conservan los archivos de los usuarios, /var/lib/lftp-web-users y /etc/lftp-web/users."
	exit 0
fi

# --- instalar ---------------------------------------------------------------------------------
[ -d $HESTIA/web/pro/transfers ] || { echo "Este módulo necesita el módulo 'core' (instálalo antes)."; exit 1; }
[ -S /run/hestia-php.sock ] || echo "  [aviso] no existe /run/hestia-php.sock; la pasarela no podrá validar sesiones hasta que arranque Hestia"
command -v nginx >/dev/null || { echo "Falta nginx"; exit 1; }
/usr/sbin/nginx -V 2>&1 | grep -q auth_request || { echo "El nginx del sistema no incluye http_auth_request_module"; exit 1; }

echo "== Dependencias"
missing=()
command -v lftp >/dev/null || missing+=(lftp)
for m in flask:python3-flask paramiko:python3-paramiko waitress:python3-waitress; do
	python3 -c "import ${m%%:*}" 2>/dev/null || missing+=("${m##*:}")
done
if [ "${#missing[@]}" -gt 0 ]; then
	echo "  instalando: ${missing[*]}"
	DEBIAN_FRONTEND=noninteractive apt-get install -y "${missing[@]}"
fi

if [ "$HERE" != "$SRC" ]; then
	echo "== Copiando a $SRC"
	mkdir -p $SRC && cp -a "$HERE"/. $SRC/
fi
chmod 755 $SRC/install.sh

echo "== Motor en $APP"
mkdir -p $APP
cp -a $SRC/app.py $SRC/hestia_bridge.py $SRC/requirements.txt $SRC/transfer_users.py $APP/
rm -rf $APP/static $APP/templates $APP/bin
cp -a $SRC/static $SRC/templates $SRC/bin $APP/
chown -R root:root $APP/app.py $APP/hestia_bridge.py $APP/requirements.txt $APP/transfer_users.py $APP/static $APP/templates $APP/bin
chmod 644 $APP/app.py $APP/hestia_bridge.py $APP/requirements.txt $APP/transfer_users.py
find $APP/static $APP/templates -type d -exec chmod 755 {} + ; find $APP/static $APP/templates -type f -exec chmod 644 {} +
chmod 755 $APP/bin/*

echo "== Directorios y comando v-transfer-engine"
mkdir -p /etc/lftp-web /var/lib/lftp-web/proxy /var/log/lftp-web /etc/nexopanel
chmod 755 /etc/lftp-web /var/lib/lftp-web /etc/nexopanel
cat > $HESTIA/bin/v-transfer-engine <<EOF
#!/usr/bin/python3 -I
import runpy
runpy.run_path('$APP/transfer_users.py',run_name="__main__")
EOF
chmod 755 $HESTIA/bin/v-transfer-engine

echo "== Pasarela de sesión (nginx local)"
PANEL_PORT=$(grep -oP "^BACKEND_PORT='\K[0-9]+" $HESTIA/conf/hestia.conf 2>/dev/null || echo 8083)
GW_PORT=$(grep -oP 'listen 127\.0\.0\.1:\K[0-9]+' $GWCONF 2>/dev/null | head -1 || true)
GW_PORT=${GW_PORT:-$GW_PORT_DEFAULT}
if ss -ltn 2>/dev/null | grep -q "127.0.0.1:$GW_PORT " && ! systemctl is-active --quiet lftp-web-gateway; then
	echo "El puerto $GW_PORT ya está en uso por otro servicio. Libéralo o instala con GW_PORT distinto editando $0."
	exit 1
fi
cat > $GWCONF <<'EOF'
user hestiaweb;
worker_processes 1;
pid /run/lftp-web-gateway.pid;
error_log /var/log/lftp-web/gateway-error.log;
events { worker_connections 256; }
http {
    access_log /var/log/lftp-web/gateway-access.log;
    client_max_body_size 4g;
    server_tokens off;
    set_real_ip_from 127.0.0.1;
    real_ip_header X-Real-IP;
    server {
        listen 127.0.0.1:@GW_PORT@;
        server_name _;
        location = /_session {
            internal;
            include /etc/nginx/fastcgi_params;
            fastcgi_pass_request_body off;
            fastcgi_param CONTENT_LENGTH "";
            fastcgi_param REQUEST_METHOD GET;
            fastcgi_param SCRIPT_FILENAME /usr/local/hestia/web/pro/transfers/auth.php;
            fastcgi_param SCRIPT_NAME /pro/transfers/auth.php;
            fastcgi_param DOCUMENT_ROOT /usr/local/hestia/web;
            fastcgi_param SERVER_PORT @PANEL_PORT@;
            fastcgi_param REQUEST_SCHEME https;
            fastcgi_param HTTPS on;
            fastcgi_param HTTP_X_NEXOPANEL_METHOD $np_method;
            fastcgi_param HTTP_X_NEXOPANEL_URI $request_uri;
            fastcgi_param HTTP_X_HESTIA_CSRF $http_x_hestia_csrf;
            fastcgi_pass unix:/run/hestia-php.sock;
        }
        location /pro/transfers/service/ {
            set $np_method $request_method;
            auth_request /_session;
            auth_request_set $np_assertion $upstream_http_x_nexopanel_auth;
            auth_request_set $np_backend $upstream_http_x_nexopanel_port;
            proxy_set_header X-NexoPanel-Auth $np_assertion;
            proxy_set_header Host $http_host;
            proxy_set_header X-Forwarded-Proto https;
            proxy_set_header X-Forwarded-For $remote_addr;
            rewrite ^/pro/transfers/service/(.*)$ /$1 break;
            proxy_pass http://127.0.0.1:$np_backend;
            proxy_request_buffering off;
            proxy_buffering off;
            proxy_read_timeout 600s;
        }
        location / { return 404; }
    }
}
EOF
sed -i "s/@GW_PORT@/$GW_PORT/; s/@PANEL_PORT@/$PANEL_PORT/" $GWCONF
chmod 644 $GWCONF
/usr/sbin/nginx -p /var/lib/lftp-web/proxy/ -c $GWCONF -t
cat > /etc/systemd/system/lftp-web-gateway.service <<'EOF'
[Unit]
Description=Hestia internal transfer session gateway
After=hestia.service
[Service]
Type=simple
ExecStart=/usr/sbin/nginx -p /var/lib/lftp-web/proxy/ -c /etc/lftp-web/hestia-gateway.conf -g "daemon off;"
ExecReload=/usr/sbin/nginx -p /var/lib/lftp-web/proxy/ -c /etc/lftp-web/hestia-gateway.conf -s reload
Restart=on-failure
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable lftp-web-gateway >/dev/null 2>&1
systemctl restart lftp-web-gateway

echo "== Activar en el panel"
[ -f /etc/nexopanel/transfers.json ] || printf '{"gateway_port": %s}\n' "$GW_PORT" > /etc/nexopanel/transfers.json
chmod 644 /etc/nexopanel/transfers.json
poner_bloque_nginx "$GW_PORT"
parchar_delete_user
# /fm (gestor nativo) -> /pro/transfers/, con copia previa de nginx.conf como hace el núcleo
PRO=/opt/iaachz-panel
if [ -f $PRO/lib/hp/filemanager_route.py ]; then
	mkdir -p $PRO/backup/$VERSION
	[ -f "$PRO/backup/$VERSION/nginx_filemanager.conf" ] || cp -a $CONF "$PRO/backup/$VERSION/nginx_filemanager.conf"
	/usr/bin/python3 $PRO/lib/hp/filemanager_route.py
fi

# Los motores de los usuarios que ya tienen uno se reinician para cargar la versión nueva
for u in /etc/systemd/system/lftp-web-user-*.service; do
	[ -e "$u" ] && systemctl restart "$(basename "$u")" || true
done

mkdir -p /etc/hestiacp/hooks
HOOK=/etc/hestiacp/hooks/post_install.sh
[ -f $HOOK ] || printf '#!/bin/bash\n' > $HOOK
grep -q "iaachz-lftp-web" $HOOK || echo "[ -x $SRC/install.sh ] && $SRC/install.sh --reapply  # iaachz-lftp-web" >> $HOOK
chmod 755 $HOOK

echo "== Archivos y transferencias instalado: /pro/transfers/ (pasarela en 127.0.0.1:$GW_PORT)"
