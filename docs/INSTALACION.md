# Guía de instalación

## 1. Requisitos

| | |
|---|---|
| Sistema | Debian 12/13 o Ubuntu 22.04+ (probado en Debian 13 y Ubuntu 26.04) |
| Hestia | **1.10.x** ya instalado y funcionando, con **nginx** (probado con 1.10.5) |
| Acceso | root (o `sudo`) |
| Herramientas | `python3`, `patch`, `git` |
| Opcional | [Tailscale](https://tailscale.com) para el módulo de túneles |

Si aún no tienes Hestia, instálalo con su instalador oficial: <https://hestiacp.com/install.html>.
iaachz-panel no instala Hestia.

> **Antes de empezar**, haz una copia de seguridad. Mínimo:
>
> ```bash
> sudo tar czf ~/hestia-antes-de-iaachz-panel.tgz /usr/local/hestia/conf /usr/local/hestia/web \
>   /usr/local/hestia/bin /usr/local/hestia/data/templates /usr/local/hestia/data/users /etc/nginx
> ```

## 2. Descargar e instalar

```bash
git clone https://github.com/iaachz/iaachz-panel.git
cd iaachz-panel
sudo ./install.sh --check     # comprueba requisitos, no cambia nada
sudo ./install.sh             # instala todos los módulos
```

Para instalar solo una parte: `sudo ./install.sh --only core` (también `subdominio`, `tunnels`,
`dns-proveedor`, `lftp-web`). El módulo `tunnels` necesita `core` y `subdominio` instalados antes;
`lftp-web` (Archivos y transferencias, con papelera) necesita `core`.

El instalador:

1. Copia el código a `/opt/iaachz-panel`, `/opt/iaachz-subdominio`, `/opt/iaachz-tunnels` y
   `/opt/iaachz-dns-proveedor` y `/opt/iaachz-lftp-web` (el motor de archivos corre desde `/opt/lftp-web`).
2. Instala los comandos `v-hp` y `v-tunnel` en `/usr/local/hestia/bin`.
3. Crea las tareas de `cron` (`/etc/cron.d/iaachz-panel`) y los servicios `iaachz-panel-sampler` y
   `iaachz-panel-firewall`.
4. Respalda los archivos de Hestia que modifica y registra ganchos en
   `/etc/hestiacp/hooks/post_install.sh` para reaplicarlos tras cada actualización de Hestia.

## 3. Primer acceso

Entra al panel de Hestia como siempre (normalmente `https://tu-servidor:8083`). El inicio es ahora **`/pro/`**.

Si algo no se ve, vacía la caché del navegador: el panel carga CSS y JS nuevos.

## 4. Ajustes de tu servidor

Lo propio de cada servidor (IP pública, dominios ocultos, destino VPN por defecto…) se configura con
`v-hp settings-set`. Mira [`AJUSTES.md`](AJUSTES.md). En un servidor **privado** (solo accesible por VPN)
activa `pma_same_origin`.

## 5. Completar la pila de Hestia (opcional, recomendado)

Una instalación mínima de Hestia puede no incluir antivirus, antispam, gestor de archivos, webmail ni PHP
8.5. Estos son los pasos, comprobados en Debian 13 con Hestia 1.10.5. Todos usan comandos oficiales de
Hestia o paquetes de la distribución.

### Antivirus (ClamAV) y antispam (SpamAssassin) para el correo

ClamAV necesita unos **1,5 GB de RAM** libres. Para que `apt` no arranque los servicios antes de configurarlos:

```bash
printf '#!/bin/sh\nexit 101\n' | sudo tee /usr/sbin/policy-rc.d >/dev/null && sudo chmod +x /usr/sbin/policy-rc.d
sudo apt-get install -y --no-install-recommends clamav-daemon clamav-freshclam spamd spamassassin
sudo rm -f /usr/sbin/policy-rc.d

# Exim debe poder leer el socket de ClamAV
sudo usermod -aG Debian-exim,mail clamav

# SpamAssassin solo en local
sudo sed -i 's|^OPTIONS=.*|OPTIONS="--create-prefs --max-children 2 --helper-home-dir --listen=127.0.0.1"|' /etc/default/spamd

# Activar ambos en la plantilla de Exim de Hestia
sudo cp -a /etc/exim4/exim4.conf.template /etc/exim4/exim4.conf.template.bak
sudo sed -i -E 's/^#(SPAMASSASSIN = yes|SPAM_SCORE = 50|SPAM_REJECT_SCORE = 100|CLAMD = yes)$/\1/' \
  /etc/exim4/exim4.conf.template
sudo exim4 -C /etc/exim4/exim4.conf.template -bV | head -1     # debe mostrar la versión, sin errores

# Primera descarga de firmas y arranque
sudo freshclam
sudo systemctl enable --now spamd clamav-freshclam clamav-daemon
sudo systemctl restart exim4

# Decírselo a Hestia
sudo /usr/local/hestia/bin/v-change-sys-config-value ANTIVIRUS_SYSTEM clamav-daemon
sudo /usr/local/hestia/bin/v-change-sys-config-value ANTISPAM_SYSTEM spamd
```

### Gestor de archivos, PHP 8.5 y webmail

```bash
sudo /usr/local/hestia/bin/v-add-sys-filemanager
sudo /usr/local/hestia/bin/v-add-web-php 8.5
sudo /usr/local/hestia/bin/v-add-sys-roundcube
sudo /usr/local/hestia/bin/v-change-sys-config-value WEBMAIL_ALIAS webmail
```

### Apache (opcional)

No hay que instalarlo a mano: iaachz-panel lo instala solo la primera vez que activas *nginx + Apache* en un
dominio, y lo deja escuchando únicamente en `127.0.0.1:7080`.

## 6. Actualizar

Desde el panel: **Herramientas y ajustes → Actualizaciones del panel**. El servidor guarda un clon de este
repositorio en `/opt/iaachz-panel/repo`, lo compara con lo instalado (`/opt/iaachz-panel/version.json`) y
muestra la versión nueva y sus cambios. **Actualizar** ejecuta `install.sh --only <módulo>` para los módulos que
ya estén instalados, como tarea con registro; si falla, vuelve a instalar la versión anterior.

- **Qué instalar:** *versiones publicadas* (por defecto) solo cambia el servidor cuando se publica una versión
  (etiqueta `vX.Y.Z`, ver más abajo); *rama main* instala cada cambio fusionado.
- **Actualizar automáticamente:** cada noche a las 3:41 (`v-hp update-cron` en `/etc/cron.d/iaachz-panel`).
  Sin activarlo, la consulta diaria solo avisa en la campana, una vez por versión.
- Es el servidor el que pregunta a GitHub (el repositorio es público), así que funciona detrás de NAT o solo
  con Tailscale. Necesita `git` (`apt install git`).

A mano, desde un clon:

```bash
cd iaachz-panel && git pull
sudo ./install.sh
```

### Publicar una versión

Los servidores con *versiones publicadas* solo se actualizan cuando hay una etiqueta nueva `vX.Y.Z` en `main`
(semver: `v1.2.0`, `v1.2.1`…). Desde GitHub: *Releases → Draft a new release*, etiqueta nueva sobre `main`,
título y notas. O por consola:

```bash
git switch main && git pull
git tag -a v1.2.0 -m "iaachz-panel 1.2.0" && git push origin v1.2.0
```

Prueba antes la versión en un servidor (canal *main* o `sudo ./install.sh` desde el clon) y publica después.

El instalador es idempotente. Tras una actualización de Hestia (`apt upgrade`), los ganchos reaplican los parches solos; si
alguno no encaja con la nueva versión, el instalador lo indica y lo omite sin romper el panel.

## 7. Solución de problemas

| Síntoma | Qué mirar |
|---|---|
| El panel no carga tras instalar | `sudo tail /var/log/hestia/nginx-error.log`; restaura el respaldo de `/opt/iaachz-panel/backup/<versión>/`. |
| Faltan las métricas en vivo | `systemctl status iaachz-panel-sampler`. |
| Un botón (subdominio, DNS) desapareció tras actualizar Hestia | Ejecuta `sudo ./install.sh --only subdominio` o `--only dns-proveedor`. |
| "el parche no encaja con esta versión de Hestia" | Tu versión de Hestia cambió esa plantilla; el módulo sigue funcionando sin el botón. Abre una incidencia con la versión. |
| Los túneles no listan destinos | Instala y conecta Tailscale (`tailscale status`). |
