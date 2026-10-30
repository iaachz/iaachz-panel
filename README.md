# iaachz-panel

Una capa estilo **Plesk** para [Hestia Control Panel](https://hestiacp.com). Se instala junto a Hestia sin
reemplazar su motor: usa sus comandos `v-*` y añade los que faltaban.

> Prefijo interno: el comando del módulo principal es `v-hp` y sus datos están en `data/hp`; `hp` es solo
> una abreviatura corta usada en el código.

> **Estado:** probado con Hestia **1.10.5** en **Ubuntu 26.04** y **Debian 13**. La instalación completa
> (`install.sh`) se verificó desde un clon de este repositorio sobre un Debian 13 limpio con Hestia recién
> instalado: todas las páginas del panel responden sin errores de PHP, los ajustes y el gancho de actualización
> funcionan y volver a ejecutar el instalador es seguro. Aun así, haz copia de seguridad y pruébalo primero
> en un servidor de pruebas.

## Qué incluye

| Módulo | Qué añade |
|---|---|
| **core** (`core/`) | Panel de inicio con métricas en vivo, lista de sitios y dominios, asistente "Agregar dominio" en un paso, apps Node.js / Python / Docker, Git, importar/exportar bases de datos, phpMyAdmin con acceso directo por base, monitorización y tráfico, alertas, Advisor, bloqueo por país/IP, Apache opcional detrás de nginx. Detalle completo en [`core/README.md`](core/README.md). |
| **subdominio** (`addons/subdominio/`) | Botón **Agregar subdominio** en la lista de dominios web. Se reaplica solo tras cada `apt`/`dpkg`. |
| **tunnels** (`addons/tunnels/`) | Publica una app que corre en otra máquina de tu red Tailscale bajo un dominio propio con HTTPS (Let's Encrypt), y agrupa los dominios por zona. |
| **dns-proveedor** (`addons/dns-proveedor/`) | Botón **Copiar al proveedor**: muestra los registros DNS de una zona (o los recomendados para un sitio) listos para pegar en el panel de tu proveedor, y como archivo de zona BIND. |

## Instalación rápida

```bash
git clone https://github.com/iaachz/iaachz-panel.git
cd iaachz-panel
sudo ./install.sh --check      # solo comprueba requisitos
sudo ./install.sh              # instala todo
```

Guía paso a paso (incluye cómo completar la pila de Hestia con antivirus, antispam, gestor de archivos,
PHP 8.5 y webmail): [`docs/INSTALACION.md`](docs/INSTALACION.md).

## Idiomas

La interfaz está en español, inglés, portugués y francés; se elige en *Herramientas y ajustes → Idioma de la interfaz* (o «automático» según el perfil de cada usuario). Añadir otro idioma es rellenar un archivo JSON: ver [docs/IDIOMAS.md](docs/IDIOMAS.md).

## Un mismo código en todos tus servidores

Nada de lo específico de un servidor está escrito en el código. IP pública, dominios ocultos, destino VPN por
defecto, etc. son **ajustes** (`v-hp settings-set`), así que puedes desplegar exactamente los mismos archivos
en un VPS público y en un servidor privado. Ver [`docs/AJUSTES.md`](docs/AJUSTES.md).

## Qué toca en Hestia

Todo lo demás son archivos nuevos. Los archivos de Hestia que se modifican llevan marcas (`iaachz-panel`,
`iaachz-custom`, `subdominio-btn`, `tunnel-btn`), se respaldan antes y se reaplican cuando Hestia se actualiza:

`web/templates/includes/panel.php`, `web/index.php`, `web/list/index.php`, `web/login/index.php`,
`web/inc/prevent_csrf.php`, `web/templates/pages/list_web.php`, `web/templates/pages/list_dns_rec.php`
y `bin/v-delete-user`.

## Desinstalar

- Núcleo: ver la sección *Desinstalar* de [`core/README.md`](core/README.md).
- Subdominios: `sudo /opt/iaachz-subdominio/aplicar.sh --quitar`
- DNS del proveedor: `sudo /opt/iaachz-dns-proveedor/install.sh --quitar`
- Túneles: no hay desinstalador automático; cada instalación guarda sus respaldos en
  `/opt/iaachz-tunnels/backups/`. Elimina antes los túneles desde el panel.

## Limitaciones

- Solo Hestia con **nginx**; no admite Apache como servidor principal de Hestia.
- Los parches de plantillas de Hestia (`list_web.php`, `list_dns_rec.php`, `panel.php`…) están hechos para
  la serie 1.10; con otra versión el instalador avisa y omite el parche que no encaje.
- El módulo de túneles usa [Tailscale](https://tailscale.com) para ofrecer destinos VPN; sin él se instala,
  pero la lista de destinos queda vacía.
- Si instalas Hestia **sin DNS (BIND)**, la página `/list/dns/` del propio Hestia puede dar error; es un
  comportamiento de Hestia, no de este proyecto. El botón de DNS del proveedor sigue funcionando para los sitios web.
- Este proyecto no está afiliado a Hestia Control Panel.

## Licencia

[GPL-3.0](LICENSE), la misma que Hestia, porque incluye archivos derivados de él.
