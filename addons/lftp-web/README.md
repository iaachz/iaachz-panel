# lftp-web (gestor de archivos integrado)

Motor del gestor de archivos que el panel muestra en `/pro/transfers/`. Se despliega en `/opt/lftp-web`.

- `app.py`, `static/`, `templates/`, `bin/`, `hestia_bridge.py`: contenido de `/opt/lftp-web`.
- `transfer_users.py`: aprovisiona la carpeta de cada usuario (incluye `.trash`). En los servidores actuales vive en el paquete heredado `/opt/nexopanel/features/0.1.0/nexopanel/` (el nombre "nexopanel" es histórico).

## Cambio reciente: papelera
"Eliminar" mueve los elementos a `~/.trash/<fecha>-<id>/items/` con un `.trash-info.json` (ruta original, nombre, fecha) en vez de borrarlos. No se puede eliminar la raíz ni el contenido de `.trash`, ni una papelera que sea enlace simbólico.

## Pendiente
El encabezado `X-NexoPanel-Auth` y las rutas `/opt/nexopanel` conservan el nombre antiguo; renombrarlos exige cambiar a la vez `core/web/pro/transfers/auth.php` y los servidores.
