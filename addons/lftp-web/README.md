# lftp-web (gestor de archivos integrado)

Motor del gestor de archivos que el panel muestra en `/pro/transfers/`. Se despliega en `/opt/lftp-web`.

- `app.py`, `static/`, `templates/`, `bin/`, `hestia_bridge.py`: contenido de `/opt/lftp-web`.
- `transfer_users.py`: aprovisiona el motor y la carpeta de cada usuario (incluye `.trash`); se despliega en `/opt/lftp-web/transfer_users.py` y lo invoca el comando `v-transfer-engine`. (Los nombres `nexopanel` internos son históricos.)

## Instalar
```bash
sudo ./install.sh --only lftp-web      # desde la raíz del repo; necesita el módulo core
```
Instala `lftp`, `python3-flask`, `python3-paramiko` y `python3-waitress` si faltan, despliega el motor en
`/opt/lftp-web`, crea la pasarela de sesión (`lftp-web-gateway`, nginx en `127.0.0.1:8092`), añade el bloque
`# nexopanel-transfers` al nginx de Hestia, activa `/etc/nexopanel/transfers.json` (lo que enciende
«Archivos y transferencias» en el panel) y parcha `v-delete-user` para retirar el motor de un usuario al
borrarlo. Cada usuario recibe su propio servicio `lftp-web-user-USUARIO` la primera vez que abre la página.
Idempotente; tras actualizar Hestia se reaplica solo (gancho `post_install`).

Quitar: `sudo /opt/iaachz-lftp-web/install.sh --quitar` (conserva los archivos de los usuarios).

## Cambio reciente: papelera
"Eliminar" mueve los elementos a `~/.trash/<fecha>-<id>/items/` con un `.trash-info.json` (ruta original, nombre, fecha) en vez de borrarlos. No se puede eliminar la raíz ni el contenido de `.trash`, ni una papelera que sea enlace simbólico.

## Pendiente
El encabezado `X-NexoPanel-Auth`, el bloque `nexopanel-transfers` y `/etc/nexopanel/transfers.json` conservan el nombre antiguo; renombrarlos exige cambiar a la vez `core/` y los servidores ya instalados.
