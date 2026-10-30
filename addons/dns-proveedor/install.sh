#!/bin/bash
# Hestia Pro · DNS del proveedor: botón "Copiar al proveedor" y "Registros DNS" en la lista de dominios.
#   install.sh             instala (copia a /opt/hestia-dns-proveedor y aplica los parches)
#   install.sh --reapply   vuelve a aplicar tras actualizar Hestia (lo llama el gancho post_install)
#   install.sh --quitar    revierte los parches y borra los archivos añadidos
# Idempotente: si un parche ya está aplicado, lo omite. Si la sintaxis PHP falla, restaura el original.
set -euo pipefail

HESTIA=/usr/local/hestia
W=$HESTIA/web
PAGES=$W/templates/pages
SRC=/opt/hestia-dns-proveedor
HERE="$(cd "$(dirname "$0")" && pwd)"
PHP=$HESTIA/php/bin/php
VERSION=$(grep -oP "^VERSION='\K[^']+" $HESTIA/conf/hestia.conf || echo unknown)

[ "$(id -u)" = 0 ] || { echo "Ejecuta como root"; exit 1; }
[ -d $HESTIA ] || { echo "No se encuentra Hestia en $HESTIA"; exit 1; }

quitar_parche() { # archivo marca
	local f=$PAGES/$1
	if grep -q "$2" "$f" 2>/dev/null; then
		if [ -f "$SRC/backup/$VERSION/$1" ]; then
			cp -a "$SRC/backup/$VERSION/$1" "$f"
		else
			echo "   $1: no hay respaldo del original; quita a mano las líneas marcadas con $2"
		fi
	fi
}

if [ "${1:-}" = "--quitar" ]; then
	quitar_parche list_web.php 'hestia-custom:web-dns'
	quitar_parche list_dns_rec.php 'hestia-custom:dns-proveedor'
	rm -rf $W/list/dns-proveedor $W/inc/dns_proveedor.php $PAGES/list_dns_provider.php
	sed -i '/hestia-dns-proveedor/d' /etc/hestiacp/hooks/post_install.sh 2>/dev/null || true
	echo "DNS del proveedor: quitado (el original de list_web.php/list_dns_rec.php se restauró si había respaldo)."
	exit 0
fi

if [ "${1:-}" != "--reapply" ] && [ "$HERE" != "$SRC" ]; then
	echo "== Copiando a $SRC"
	mkdir -p $SRC && cp -a "$HERE"/. $SRC/
fi

echo "== Archivos nuevos"
install -d -m 755 $W/list/dns-proveedor
install -m 644 $SRC/dns_proveedor.php $W/inc/dns_proveedor.php
install -m 644 $SRC/list_dns_proveedor_index.php $W/list/dns-proveedor/index.php
install -m 644 $SRC/list_dns_provider.php $PAGES/list_dns_provider.php

mkdir -p "$SRC/backup/$VERSION"
aplicar() { # archivo marca
	local f=$PAGES/$1
	[ -f "$f" ] || { echo "No existe $f"; return 1; }
	if grep -q "$2" "$f"; then
		echo "   $1: ya aplicado"
		return 0
	fi
	[ -f "$SRC/backup/$VERSION/$1" ] || cp -a "$f" "$SRC/backup/$VERSION/$1"
	if ! (cd $HESTIA && patch -p1 --forward --dry-run --silent < "$SRC/$1.patch" >/dev/null 2>&1); then
		echo "   $1: el parche no encaja con esta versión de Hestia ($VERSION); se omite"
		return 0
	fi
	(cd $HESTIA && patch -p1 --forward --silent < "$SRC/$1.patch")
	if ! $PHP -l "$f" >/dev/null 2>&1; then
		echo "   $1: error de sintaxis tras el parche; restaurando"
		cp -a "$SRC/backup/$VERSION/$1" "$f"
		return 1
	fi
	echo "   $1: aplicado"
}
echo "== Parches"
aplicar list_web.php 'hestia-custom:web-dns'
aplicar list_dns_rec.php 'hestia-custom:dns-proveedor'

for f in $W/inc/dns_proveedor.php $W/list/dns-proveedor/index.php $PAGES/list_dns_provider.php; do
	$PHP -l "$f" >/dev/null
done

mkdir -p /etc/hestiacp/hooks
HOOK=/etc/hestiacp/hooks/post_install.sh
[ -f $HOOK ] || printf '#!/bin/bash\n' > $HOOK
grep -q "hestia-dns-proveedor" $HOOK || echo '[ -x /opt/hestia-dns-proveedor/install.sh ] && /opt/hestia-dns-proveedor/install.sh --reapply  # hestia-dns-proveedor' >> $HOOK
chmod 755 $HOOK /opt/hestia-dns-proveedor/install.sh 2>/dev/null || true
echo "== DNS del proveedor listo (Hestia $VERSION)"
