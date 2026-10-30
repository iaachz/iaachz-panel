"""Gestión de Docker (como la extensión Docker de Plesk): contenedores, imágenes y pilas Compose."""

import json
import os
import re
import shutil
import subprocess

from .common import PRO, HPError, ensure_dir, run
from .jobs import step
from .runtimes import docker_path

COMPOSE_DIR = PRO + "/compose"
RE_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$")
RE_STACK = re.compile(r"^[a-z0-9][a-z0-9_-]{0,47}$")
RE_IMAGE = re.compile(r"^[a-z0-9][a-z0-9._/:@-]{0,254}$")


def _exe():
    exe = docker_path()
    if not exe:
        raise HPError("Docker no está instalado")
    return exe


def _lines_json(text):
    res = []
    for line in text.splitlines():
        line = line.strip()
        if line:
            try:
                res.append(json.loads(line))
            except ValueError:
                pass
    return res


def containers():
    exe = _exe()
    items = _lines_json(run([exe, "ps", "-a", "--no-trunc", "--format", "{{json .}}"], timeout=30).stdout)
    stats = {s.get("ID", "")[:12]: s for s in _lines_json(
        run([exe, "stats", "--no-stream", "--format", "{{json .}}"], timeout=60).stdout)}
    for c in items:
        s = stats.get(c.get("ID", "")[:12], {})
        c["CPUPerc"] = s.get("CPUPerc", "")
        c["MemUsage"] = s.get("MemUsage", "")
        c["MemPerc"] = s.get("MemPerc", "")
        c["NetIO"] = s.get("NetIO", "")
        labels = c.get("Labels", "")
        c["hp_domain"] = (re.search(r"iaachz-panel\.domain=([^,]+)", labels) or [None, None])[1]
        c["hp_user"] = (re.search(r"iaachz-panel\.user=([^,]+)", labels) or [None, None])[1]
        c["compose"] = (re.search(r"com\.docker\.compose\.project=([^,]+)", labels) or [None, None])[1]
    return items


def images():
    return _lines_json(run([_exe(), "images", "--format", "{{json .}}"], timeout=30).stdout)


def info():
    exe = _exe()
    p = run([exe, "system", "df", "--format", "{{json .}}"], timeout=60)
    return {"df": _lines_json(p.stdout)}


def container_action(cid, what):
    if not RE_ID.match(cid or ""):
        raise HPError("Contenedor no válido")
    if what not in ("start", "stop", "restart", "remove", "pause", "unpause"):
        raise HPError("Acción no válida")
    args = ["rm", "-f", cid] if what == "remove" else [what, cid]
    p = run([_exe()] + args, timeout=180)
    if p.returncode != 0:
        raise HPError(p.stderr.strip())
    return {"ok": True}


def container_logs(cid, lines=300):
    if not RE_ID.match(cid or ""):
        raise HPError("Contenedor no válido")
    p = run([_exe(), "logs", "--tail", str(max(10, min(int(lines), 5000))), "--timestamps", cid], timeout=30)
    return {"log": p.stdout + p.stderr}


def container_inspect(cid):
    if not RE_ID.match(cid or ""):
        raise HPError("Contenedor no válido")
    p = run([_exe(), "inspect", cid], timeout=30)
    if p.returncode != 0:
        raise HPError(p.stderr.strip())
    return json.loads(p.stdout)[0]


def image_remove(iid):
    if not (RE_ID.match(iid or "") or RE_IMAGE.match(iid or "")):
        raise HPError("Imagen no válida")
    p = run([_exe(), "rmi", iid], timeout=120)
    if p.returncode != 0:
        raise HPError(p.stderr.strip())
    return {"ok": True}


def pull(image):
    image = image.strip().lower()
    if not RE_IMAGE.match(image):
        raise HPError("Imagen no válida")
    step(f"docker pull {image}", "running")
    p = subprocess.run([_exe(), "pull", image], text=True, stdout=None, stderr=subprocess.STDOUT, timeout=3600)
    if p.returncode != 0:
        raise HPError("No se pudo descargar " + image)
    step(f"Imagen {image} descargada")
    return {"ok": True}


def run_container(cfg):
    """Contenedor independiente (sin dominio). Puertos publicados por defecto solo en 127.0.0.1."""
    name = (cfg.get("name") or "").strip()
    image = (cfg.get("image") or "").strip().lower()
    if not RE_ID.match(name):
        raise HPError("Nombre de contenedor no válido")
    if not RE_IMAGE.match(image):
        raise HPError("Imagen no válida")
    cmd = [_exe(), "run", "-d", "--name", name, "--restart", cfg.get("restart") or "unless-stopped",
           "--log-opt", "max-size=10m", "--log-opt", "max-file=3"]
    if cmd[6] not in ("no", "always", "unless-stopped", "on-failure"):
        raise HPError("Política de reinicio no válida")
    for p in cfg.get("ports") or []:
        host, cont = int(p.get("host") or 0), int(p.get("container") or 0)
        if not (1 <= host <= 65535 and 1 <= cont <= 65535):
            continue
        bind = "0.0.0.0" if p.get("public") else "127.0.0.1"
        cmd += ["-p", f"{bind}:{host}:{cont}"]
    for k, v in (cfg.get("env") or {}).items():
        if not re.match(r"^[A-Za-z_][A-Za-z0-9_]*$", k):
            raise HPError(f"Variable no válida: {k}")
        cmd += ["-e", f"{k}={v}"]
    for v in cfg.get("volumes") or []:
        host, cont = (v.get("host") or "").strip(), (v.get("container") or "").strip()
        if not host or not cont:
            continue
        if not re.match(r"^(/[A-Za-z0-9._/-]+|[a-z0-9][a-z0-9_.-]*)$", host) or not cont.startswith("/"):
            raise HPError("Volumen no válido")
        if host.startswith("/") and (host in ("/", "/etc", "/root", "/usr", "/var", "/boot", "/proc", "/sys", "/dev")):
            raise HPError("No se permite montar " + host)
        cmd += ["-v", f"{host}:{cont}"]
    if cfg.get("memory_mb"):
        cmd += ["--memory", f"{int(cfg['memory_mb'])}m"]
    cmd.append(image)
    step("Descargando imagen (si hace falta) y creando contenedor", "running")
    p = subprocess.run(cmd, text=True, capture_output=True, timeout=3600)
    print(p.stdout + p.stderr)
    if p.returncode != 0:
        raise HPError("docker run falló: " + p.stderr.strip()[-1500:])
    step(f"Contenedor {name} en marcha")
    return {"ok": True}


# -------------------------------------------------------------------- Compose

def _stack_dir(name):
    if not RE_STACK.match(name or ""):
        raise HPError("Nombre de pila no válido (minúsculas, números, - y _)")
    return f"{COMPOSE_DIR}/{name}"


def stacks():
    ensure_dir(COMPOSE_DIR, 0o700)
    res = []
    states = {}
    try:
        for s in json.loads(run([_exe(), "compose", "ls", "-a", "--format", "json"], timeout=30).stdout or "[]"):
            states[s.get("Name")] = s.get("Status")
    except (ValueError, HPError):
        pass
    for name in sorted(os.listdir(COMPOSE_DIR)):
        if os.path.exists(f"{COMPOSE_DIR}/{name}/compose.yaml"):
            res.append({"name": name, "status": states.get(name, "sin desplegar"),
                        "mtime": int(os.path.getmtime(f"{COMPOSE_DIR}/{name}/compose.yaml"))})
    return res


def stack_get(name):
    d = _stack_dir(name)
    try:
        with open(f"{d}/compose.yaml") as fh:
            yaml = fh.read()
    except FileNotFoundError:
        raise HPError("La pila no existe")
    env = ""
    if os.path.exists(f"{d}/.env"):
        with open(f"{d}/.env") as fh:
            env = fh.read()
    return {"name": name, "yaml": yaml, "env": env}


def stack_save(name, yaml, env=""):
    d = _stack_dir(name)
    if len(yaml) > 200_000 or "services:" not in yaml:
        raise HPError("El archivo compose debe tener una sección services:")
    ensure_dir(d, 0o700)
    with open(f"{d}/compose.yaml", "w") as fh:
        fh.write(yaml)
    with open(f"{d}/.env", "w") as fh:
        fh.write(env or "")
    os.chmod(f"{d}/.env", 0o600)
    p = run([_exe(), "compose", "-f", f"{d}/compose.yaml", "config", "-q"], cwd=d, timeout=60)
    if p.returncode != 0:
        return {"saved": True, "warning": p.stderr.strip()[-1500:]}
    return {"saved": True}


def stack_action(name, what):
    d = _stack_dir(name)
    if not os.path.exists(f"{d}/compose.yaml"):
        raise HPError("La pila no existe")
    args = {"up": ["up", "-d", "--remove-orphans"], "down": ["down"], "restart": ["restart"],
            "pull": ["pull"], "stop": ["stop"]}.get(what)
    if not args:
        raise HPError("Acción no válida")
    step(f"docker compose {' '.join(args)}", "running")
    p = subprocess.run([_exe(), "compose", "-p", name, "-f", f"{d}/compose.yaml"] + args, cwd=d, text=True,
                       stdout=None, stderr=subprocess.STDOUT, timeout=3600)
    if p.returncode != 0:
        raise HPError(f"docker compose {what} falló")
    step("Hecho")
    return {"ok": True}


def stack_delete(name):
    d = _stack_dir(name)
    if os.path.exists(f"{d}/compose.yaml"):
        run([_exe(), "compose", "-p", name, "-f", f"{d}/compose.yaml", "down"], cwd=d, timeout=600)
    shutil.rmtree(d, ignore_errors=True)
    return {"deleted": name}
