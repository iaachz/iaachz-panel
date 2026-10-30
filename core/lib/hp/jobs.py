"""Tareas en segundo plano (instalaciones, npm install, despliegues...) con log consultable.

El panel lanza la tarea y luego consulta su estado; así ninguna petición web queda
colgada esperando a apt, npm o Let's Encrypt.
"""

import json
import os
import secrets
import subprocess
import sys
import time

from .common import DATA, HPError, ensure_dir, load_json, now, save_json

JOBS = DATA + "/jobs"
KEEP = 150


def _meta_path(jid):
    if not jid or not jid.isalnum():
        raise HPError("Tarea no válida")
    return f"{JOBS}/{jid}.json"


def start(owner, title, argv):
    """Lanza `v-hp <argv...>` desacoplado. Devuelve el id de la tarea."""
    ensure_dir(JOBS)
    jid = time.strftime("%Y%m%d%H%M%S") + secrets.token_hex(3)
    meta = {"id": jid, "owner": owner, "title": title, "status": "running",
            "started": now(), "ended": None, "argv": argv[:1]}
    save_json(_meta_path(jid), meta)
    log = open(f"{JOBS}/{jid}.log", "w")
    os.chmod(f"{JOBS}/{jid}.log", 0o600)
    subprocess.Popen([sys.executable, "-I", "/usr/local/hestia/bin/v-hp", "_job-run", jid] + list(argv),
                     stdin=subprocess.DEVNULL, stdout=log, stderr=subprocess.STDOUT,
                     start_new_session=True, close_fds=True,
                     env={"PATH": "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
                          "HOME": "/root", "LANG": "C.UTF-8", "HP_JOB": jid})
    _prune()
    return jid


def finish(jid, ok, error=None):
    meta = load_json(_meta_path(jid), {})
    meta.update({"status": "ok" if ok else "error", "ended": now()})
    if error:
        meta["error"] = str(error)[-2000:]
    save_json(_meta_path(jid), meta)


def step(name, status="ok", detail=""):
    """Marca un paso con progreso estructurado dentro del log de la tarea."""
    print("::step::" + json.dumps({"name": name, "status": status, "detail": detail}, ensure_ascii=False), flush=True)


def status(jid, owner=None, offset=0):
    meta = load_json(_meta_path(jid), None)
    if not meta:
        raise HPError("La tarea no existe")
    if owner and meta.get("owner") != owner:
        raise HPError("La tarea no existe")
    steps, lines = [], []
    try:
        with open(f"{JOBS}/{jid}.log", errors="replace") as fh:
            for line in fh.read().splitlines():
                if line.startswith("::step::"):
                    try:
                        steps.append(json.loads(line[8:]))
                    except ValueError:
                        pass
                else:
                    lines.append(line)
    except FileNotFoundError:
        pass
    meta["steps"] = steps
    meta["log"] = "\n".join(lines[-400:])
    return meta


def listing(owner=None, limit=30):
    ensure_dir(JOBS)
    items = []
    for name in sorted(os.listdir(JOBS), reverse=True):
        if not name.endswith(".json"):
            continue
        meta = load_json(f"{JOBS}/{name}", None)
        if meta and (owner is None or meta.get("owner") == owner):
            items.append(meta)
        if len(items) >= limit:
            break
    return items


def _prune():
    files = sorted(f for f in os.listdir(JOBS) if f.endswith(".json"))
    for name in files[:-KEEP]:
        for ext in (".json", ".log"):
            try:
                os.remove(f"{JOBS}/{name[:-5]}{ext}")
            except FileNotFoundError:
                pass
