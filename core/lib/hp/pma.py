"""Acceso directo a phpMyAdmin por base de datos (como el botón de Plesk), sin pedir contraseña.

1. El panel pide un ticket para una base de datos del usuario.
2. Se crea un usuario MySQL temporal con permisos SOLO sobre esa base (caduca en 3 horas).
3. El ticket (aleatorio, de un solo uso, válido 60 s y atado al navegador con una cookie) se deja en
   /var/lib/iaachz-panel/pma, legible solo por el usuario de PHP de phpMyAdmin (hestiamail).
4. hp-sso.php en phpMyAdmin canjea el ticket, lo borra y abre la sesión "signon" de phpMyAdmin.
No usa la API de Hestia (su SSO propio obliga a restringir la API a 127.0.0.1).
"""

import json
import os
import pwd
import re
import secrets
import time

from .common import DATA, HPError, ensure_dir, hestia, load_json, locked, log_action, save_json
from .db import _check_db

TICKETS = "/var/lib/iaachz-panel/pma"
USERS = DATA + "/pma-users.json"
PMA_RUNAS = "hestiamail"
TICKET_TTL = 60
USER_TTL = 3 * 3600


def ticket(user, db, bind="-"):
    """bind: sha256 de la cookie que el panel dejó en el navegador ("-" si el panel se abrió por IP)."""
    if not re.match(r"^([a-f0-9]{64}|-)$", bind or ""):
        raise HPError("Vínculo de navegador no válido")
    info = _check_db(user, db)
    if info.get("TYPE", "mysql") != "mysql":
        raise HPError("El acceso directo es para MySQL/MariaDB")
    if info.get("SUSPENDED") == "yes":
        raise HPError("La base de datos está suspendida")
    if not os.path.isdir(TICKETS):
        raise HPError("Falta la carpeta de tickets: vuelve a ejecutar install.sh de iaachz-panel")
    p = hestia("v-add-database-temp-user", user, db, "mysql", "", "0")
    try:
        login = json.loads(p.stdout)["login"]
    except (ValueError, KeyError):
        raise HPError("No se pudo crear el acceso temporal a la base de datos")
    now = int(time.time())
    with locked("pma"):
        users = load_json(USERS, {})
        users[login["user"]] = {"user": user, "db": db, "exp": now + USER_TTL}
        ensure_dir(DATA)
        save_json(USERS, users)
    tid = secrets.token_hex(32)
    path = f"{TICKETS}/{tid}.json"
    with open(path, "w") as fh:
        json.dump({"dbuser": login["user"], "dbpass": login["password"], "db": db,
                   "bind": None if bind == "-" else bind, "exp": now + TICKET_TTL}, fh)
    pw = pwd.getpwnam(PMA_RUNAS)
    os.chown(path, pw.pw_uid, pw.pw_gid)
    os.chmod(path, 0o600)
    log_action(user, f"iaachz-panel: acceso a phpMyAdmin para la base de datos {db}", category="Databases")
    return {"ticket": tid}


def cleanup():
    """Borra usuarios temporales caducados y tickets sin usar (el cron lo llama cada 5 minutos)."""
    now = int(time.time())
    removed = 0
    with locked("pma"):
        users = load_json(USERS, {})
        for dbuser, u in list(users.items()):
            if u.get("exp", 0) <= now:
                hestia("v-delete-database-temp-user", u["user"], u["db"], dbuser, "mysql", check=False)
                users.pop(dbuser)
                removed += 1
        save_json(USERS, users)
    if os.path.isdir(TICKETS):
        for f in os.listdir(TICKETS):
            p = os.path.join(TICKETS, f)
            try:
                if now - os.path.getmtime(p) > 300:
                    os.remove(p)
            except OSError:
                pass
    return {"removed": removed}
