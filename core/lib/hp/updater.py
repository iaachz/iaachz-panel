"""Actualizaciones de iaachz-panel desde GitHub.

Cada servidor guarda un clon del repositorio en /opt/iaachz-panel/repo y lo compara con lo instalado
(/opt/iaachz-panel/version.json, que escribe install.sh). Por defecto sigue las versiones publicadas
(etiquetas vX.Y.Z): fusionar en main no cambia ningún servidor hasta que se publica una versión. El canal
"main" sigue la rama principal. El servidor pregunta a GitHub (no al revés), así que funciona también
detrás de Tailscale o NAT.

Actualizar = poner el clon en la versión nueva y ejecutar su install.sh solo para los módulos que ya
estén instalados. Si falla, vuelve a instalar la versión anterior.
"""

import os
import re
import shutil
import subprocess

from . import jobs
from .common import (DATA, PRO, HPError, ensure_dir, load_json, locked, log_action, notify, now, run, save_json,
                     settings, which)
from .jobs import step

REPO_URL = "https://github.com/iaachz/iaachz-panel.git"
CLONE = PRO + "/repo"
VERSION_FILE = PRO + "/version.json"
STATE = DATA + "/update.json"
PREV_SRC = PRO + "/backup/pre-update-src"
RE_TAG = re.compile(r"^v\d+(\.\d+){1,2}$")
# módulo de install.sh -> carpeta que indica que está instalado
MODULES = (("core", PRO + "/lib"), ("subdominio", "/opt/iaachz-subdominio"), ("tunnels", "/opt/iaachz-tunnels"),
           ("dns-proveedor", "/opt/iaachz-dns-proveedor"))


def _git(*args, timeout=300):
    return run(["git", "-c", "safe.directory=*", "-C", CLONE, *args], timeout=timeout)


def _channel():
    return "main" if settings().get("update_channel") == "main" else "releases"


def _fetch():
    if not which("git"):
        raise HPError("Falta git en el servidor: instálalo con apt install git")
    if not os.path.isdir(CLONE + "/.git"):
        if os.path.lexists(CLONE):
            shutil.rmtree(CLONE)
        p = run(["git", "clone", "--quiet", REPO_URL, CLONE], timeout=600)
        if p.returncode != 0:
            raise HPError("No se pudo descargar el repositorio de GitHub: " + (p.stderr.strip()[-300:] or "sin respuesta"))
        os.chmod(CLONE, 0o700)
    p = _git("fetch", "--quiet", "--tags", "--prune", "--prune-tags", "--force", "origin")
    if p.returncode != 0:
        raise HPError("No se pudo consultar GitHub: " + (p.stderr.strip()[-300:] or "sin respuesta"))


def installed():
    return load_json(VERSION_FILE, {}) or {"version": "", "commit": ""}


def _latest(channel):
    if channel == "main":
        commit = _git("rev-parse", "origin/main").stdout.strip()
        if not commit:
            return None
        return {"version": "main@" + commit[:7], "commit": commit,
                "date": int(_git("log", "-1", "--format=%ct", commit).stdout.strip() or 0)}
    tags = [t for t in _git("tag", "-l", "v*", "--sort=-v:refname").stdout.split() if RE_TAG.match(t)]
    if not tags:
        return None
    commit = _git("rev-list", "-n1", tags[0]).stdout.strip()
    return {"version": tags[0], "commit": commit,
            "date": int(_git("log", "-1", "--format=%ct", commit).stdout.strip() or 0)}


def _changes(old, new):
    """Títulos de los commits entre lo instalado y lo nuevo (si se conoce lo instalado)."""
    if not old or _git("merge-base", "--is-ancestor", old, new).returncode != 0:
        return []
    out = _git("log", "--no-merges", "--format=%s", "-n", "30", f"{old}..{new}").stdout
    return [l for l in out.splitlines() if l.strip()]


def status():
    st = load_json(STATE, {})
    s = settings()
    return {"installed": installed(), "latest": st.get("latest"), "available": bool(st.get("available")),
            "changes": st.get("changes", []), "checked": st.get("checked"), "last": st.get("last"),
            "channel": _channel(), "auto": bool(s.get("update_auto")), "error": st.get("error"),
            "git": bool(which("git"))}


def check():
    """Consulta GitHub y guarda si hay una versión nueva para el canal elegido."""
    channel = _channel()
    state = load_json(STATE, {})
    try:
        with locked("update"):
            _fetch()
            latest = _latest(channel)
    except HPError as e:
        state.update(checked=now(), error=str(e))
        save_json(STATE, state)
        raise
    cur = installed()
    # nueva = distinta de lo instalado y no contenida ya en ello (no "actualizar" hacia atrás)
    available = bool(latest) and latest["commit"] != cur.get("commit") and not (
        cur.get("commit") and _git("merge-base", "--is-ancestor", latest["commit"], cur["commit"]).returncode == 0)
    state.update(latest=latest, channel=channel, checked=now(), available=available, error=None,
                 changes=_changes(cur.get("commit"), latest["commit"]) if available else [])
    save_json(STATE, state)
    return status()


def _install(modules):
    for m in modules:
        step(f"Instalando el módulo {m}", "running")
        p = subprocess.run(["bash", CLONE + "/install.sh", "--only", m], text=True, stdout=subprocess.PIPE,
                           stderr=subprocess.STDOUT, timeout=1800, cwd="/")
        print(p.stdout[-15000:], flush=True)
        if p.returncode != 0:
            raise HPError(f"install.sh --only {m} terminó con código {p.returncode}")
        step(f"Módulo {m} instalado")


def apply():
    """Tarea: instala la última versión del canal. Si falla, vuelve a la anterior."""
    with locked("update-apply"):
        check()
        st = load_json(STATE, {})
        latest = st.get("latest")
        if not st.get("available") or not latest:
            step("iaachz-panel ya está en la última versión")
            return status()
        prev = installed()
        modules = [m for m, path in MODULES if os.path.isdir(path)] or ["core"]
        step(f"Actualizando de {prev.get('version') or 'versión desconocida'} a {latest['version']}")
        # copia del código instalado del núcleo: sirve para volver atrás aunque no se sepa su commit
        if os.path.isdir(PRO + "/src"):
            shutil.rmtree(PREV_SRC, ignore_errors=True)
            ensure_dir(os.path.dirname(PREV_SRC))
            shutil.copytree(PRO + "/src", PREV_SRC, symlinks=True)
        p = _git("checkout", "--quiet", "--force", latest["commit"])
        if p.returncode != 0:
            raise HPError("No se pudo preparar la versión nueva: " + p.stderr.strip()[-300:])
        try:
            _install(modules)
        except Exception as e:
            step("La actualización falló: volviendo a la versión anterior", "warning")
            try:
                if prev.get("commit") and _git("checkout", "--quiet", "--force", prev["commit"]).returncode == 0:
                    _install(modules)
                    step("Versión anterior restaurada")
                elif os.path.isfile(PREV_SRC + "/install.sh"):
                    rb = subprocess.run(["bash", PREV_SRC + "/install.sh"], text=True, stdout=subprocess.PIPE,
                                        stderr=subprocess.STDOUT, timeout=1800, cwd="/")
                    print(rb.stdout[-8000:], flush=True)
                    step("Núcleo anterior restaurado", "ok" if rb.returncode == 0 else "error")
                else:
                    step("No había copia de la versión anterior para restaurar", "error")
            except Exception as rb_err:
                step(f"No se pudo restaurar la versión anterior: {rb_err}", "error")
            _record(False, prev, latest, str(e))
            notify("admin", "⚠ iaachz-panel: la actualización falló",
                   f"No se pudo instalar {latest['version']}; se restauró la versión anterior. Detalle en Herramientas → Tareas.")
            raise
        _record(True, prev, latest)
        check()
        log_action("admin", f"iaachz-panel: actualizado a {latest['version']}")
        notify("admin", f"✔ iaachz-panel actualizado a {latest['version']}", "")
        step(f"iaachz-panel actualizado a {latest['version']}")
        return status()


def _record(ok, prev, latest, error=None):
    state = load_json(STATE, {})
    state["last"] = {"ok": ok, "at": now(), "from": prev.get("version"), "to": latest["version"], "error": error}
    if latest.get("version") and ok:
        state["notified"] = latest["version"]
    save_json(STATE, state)


def start_apply():
    if not which("git"):
        raise HPError("Falta git en el servidor: instálalo con apt install git")
    return {"job": jobs.start("admin", "Actualizar iaachz-panel", ["update-apply"])}


def cron():
    """Una vez al día: busca versión nueva; con actualización automática la instala, si no, avisa (una vez)."""
    try:
        st = check()
    except HPError:
        return {"checked": False}
    if not st["available"]:
        return {"available": False}
    if st["auto"]:
        return start_apply()
    state = load_json(STATE, {})
    version = st["latest"]["version"]
    if state.get("notified") != version:
        notify("admin", f"Hay una actualización de iaachz-panel: {version}",
               "Instálala desde Herramientas y ajustes → Actualizaciones del panel.")
        state["notified"] = version
        save_json(STATE, state)
    return {"available": True, "version": version}
