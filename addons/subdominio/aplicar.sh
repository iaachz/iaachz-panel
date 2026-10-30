#!/bin/bash
# Aplica (o quita con --quitar) el boton "Agregar subdominio" en Hestia.
# Idempotente. Se reejecuta solo tras cada apt/dpkg (/etc/apt/apt.conf.d/99iaachz-subdominio).
set -e
SRC=/opt/iaachz-subdominio
W=/usr/local/hestia/web
LIST=$W/templates/pages/list_web.php
BAK=$SRC/backup
VER=$(dpkg-query -W -f='${Version}' hestia 2>/dev/null || echo desconocida)
START='<!-- subdominio-btn:inicio -->'
END='<!-- subdominio-btn:fin -->'

[ -f "$LIST" ] || { echo "No existe $LIST"; exit 1; }
mkdir -p "$BAK"

quitar_boton() {
	sed -i "/$START/,/$END/d" "$LIST"
}

if [ "$1" = "--quitar" ]; then
	quitar_boton
	rm -rf "$W/add/subdomain" "$W/templates/pages/add_subdomain.php"
	echo "Quitado."
	exit 0
fi

# Respaldo del original (sin boton) una vez por version de Hestia
if [ ! -f "$BAK/list_web.php.$VER" ]; then
	cp -a "$LIST" "$BAK/list_web.php.$VER"
	sed -i "/$START/,/$END/d" "$BAK/list_web.php.$VER"
fi

# Controlador y plantilla
install -d -m 755 "$W/add/subdomain"
install -m 644 "$SRC/controller.php" "$W/add/subdomain/index.php"
install -m 644 "$SRC/template.php" "$W/templates/pages/add_subdomain.php"

# Boton en la lista de dominios web
if ! grep -q "$START" "$LIST"; then
	perl -0pi -e 's#(<a href="/add/web/" class="button button-secondary js-button-create">.*?</a>)#$1\n\t\t\t\t<!-- subdominio-btn:inicio -->\n\t\t\t\t<a href="/add/subdomain/" class="button button-secondary js-button-create">\n\t\t\t\t\t<i class="fas fa-circle-plus icon-green"></i><?= tohtml(detect_user_language() === "es" ? "Agregar subdominio" : "Add Subdomain") ?>\n\t\t\t\t</a>\n\t\t\t\t<!-- subdominio-btn:fin -->#s' "$LIST"
fi

# Validar sintaxis; si algo falla, revertir
for f in "$LIST" "$W/add/subdomain/index.php" "$W/templates/pages/add_subdomain.php"; do
	if ! php -l "$f" >/dev/null 2>&1; then
		echo "Error de sintaxis en $f; revirtiendo"
		cp -a "$BAK/list_web.php.$VER" "$LIST"
		rm -rf "$W/add/subdomain" "$W/templates/pages/add_subdomain.php"
		exit 1
	fi
done
grep -q "$START" "$LIST" || { echo "No se pudo insertar el boton (cambio la plantilla de Hestia)"; exit 1; }
echo "Aplicado (Hestia $VER)."
