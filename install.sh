#!/bin/bash
# iaachz-panel · instalador completo.
#
#   sudo ./install.sh                  instala todo: núcleo, subdominios, túneles y DNS del proveedor
#   sudo ./install.sh --check          solo comprueba los requisitos, no cambia nada
#   sudo ./install.sh --only MÓDULO    instala un módulo: core | subdominio | tunnels | dns-proveedor
#
# Cada módulo es idempotente: puedes volver a ejecutarlo para actualizar. Los ganchos de
# /etc/hestiacp/hooks/post_install.sh reaplican los cambios cuando Hestia se actualiza.
set -euo pipefail

HESTIA=/usr/local/hestia
REPO="$(cd "$(dirname "$0")" && pwd)"
ONLY=""
CHECK=0

while [ $# -gt 0 ]; do
	case "$1" in
		--check) CHECK=1 ;;
		--only) ONLY="${2:-}"; shift ;;
		-h|--help) sed -n '2,10p' "$0"; exit 0 ;;
		*) echo "Opción desconocida: $1"; exit 2 ;;
	esac
	shift
done

case "$ONLY" in ""|core|subdominio|tunnels|dns-proveedor) ;; *) echo "Módulo desconocido: $ONLY"; exit 2 ;; esac
quiere() { [ -z "$ONLY" ] || [ "$ONLY" = "$1" ]; }

ok()   { printf '  [ok]   %s\n' "$1"; }
warn() { printf '  [aviso] %s\n' "$1"; }
fail() { printf '  [FALLO] %s\n' "$1"; FALLOS=$((FALLOS + 1)); }
FALLOS=0

echo "== Requisitos"
[ "$(id -u)" = 0 ] && ok "ejecutando como root" || fail "ejecuta como root (sudo)"
if [ -f $HESTIA/conf/hestia.conf ]; then
	VERSION=$(grep -oP "^VERSION='\K[^']+" $HESTIA/conf/hestia.conf || echo desconocida)
	ok "Hestia $VERSION"
	case "$VERSION" in 1.10.*) ;; *) warn "probado con Hestia 1.10.5; con $VERSION los parches pueden no encajar" ;; esac
else
	fail "no se encontró Hestia en $HESTIA (instálalo primero: https://hestiacp.com)"
fi
command -v python3 >/dev/null && ok "python3" || fail "falta python3"
[ -x $HESTIA/php/bin/php ] && ok "PHP de Hestia" || fail "falta $HESTIA/php/bin/php"
command -v patch >/dev/null && ok "patch" || fail "falta patch (apt install patch)"
command -v nginx >/dev/null && ok "nginx" || fail "Hestia Pro necesita Hestia con nginx"
command -v tailscale >/dev/null && ok "tailscale (túneles VPN)" || warn "tailscale no está instalado: el módulo de túneles no tendrá destinos VPN"
systemctl is-active --quiet hestia && ok "servicio hestia activo" || warn "el servicio hestia no está activo"

if [ "$FALLOS" -gt 0 ]; then
	echo "Hay $FALLOS requisito(s) sin cumplir."
	exit 1
fi
[ "$CHECK" = 1 ] && { echo "Requisitos correctos."; exit 0; }

if quiere core; then
	echo "== Núcleo (Hestia Pro)"
	bash "$REPO/core/install.sh"
fi

if quiere subdominio; then
	echo "== Subdominios"
	mkdir -p /opt/hestia-subdominio
	cp -a "$REPO/addons/subdominio/." /opt/hestia-subdominio/
	chmod 755 /opt/hestia-subdominio/aplicar.sh
	install -m 644 /opt/hestia-subdominio/99hestia-subdominio /etc/apt/apt.conf.d/99hestia-subdominio
	/opt/hestia-subdominio/aplicar.sh
fi

if quiere tunnels; then
	echo "== Túneles VPN y vista agrupada de dominios"
	[ -f $HESTIA/web/add/subdomain/index.php ] || { echo "Los túneles necesitan el módulo 'subdominio' (instálalo antes)."; exit 1; }
	[ -d $HESTIA/web/pro ] || { echo "Los túneles necesitan el módulo 'core' (instálalo antes)."; exit 1; }
	mkdir -p /opt/hestia-tunnels /etc/hestia-tunnels
	cp -a "$REPO/addons/tunnels/." /opt/hestia-tunnels/
	install -o root -g root -m 755 "$REPO/bin/v-tunnel" $HESTIA/bin/v-tunnel
	/usr/bin/python3 /opt/hestia-tunnels/install.py
	/usr/bin/python3 /opt/hestia-tunnels/install-grouped-sites.py
fi

if quiere dns-proveedor; then
	echo "== DNS del proveedor"
	bash "$REPO/addons/dns-proveedor/install.sh"
fi

echo
echo "Listo. Abre el panel de Hestia: el inicio es ahora /pro/."
echo "Ajustes propios de este servidor: ver docs/AJUSTES.md"
