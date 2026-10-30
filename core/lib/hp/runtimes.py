"""Versiones de Node.js y Python (como el Node.js Toolkit y Python de Plesk) y el motor Docker."""

import glob
import hashlib
import json
import os
import platform
import re
import shutil
import tarfile
import tempfile
import urllib.request

from .common import PRO, RUNTIMES, HPError, ensure_dir, load_json, run, which
from .jobs import step

NODE_MAJORS = ["20", "22", "24", "26"]
PY_VERSIONS = ["3.10", "3.11", "3.12", "3.13", "3.14"]
UV = PRO + "/bin/uv"
PY_DIR = RUNTIMES + "/python"


def _arch():
    m = platform.machine()
    return {"x86_64": "x64", "aarch64": "arm64"}.get(m, m)


def _fetch(url, timeout=60):
    req = urllib.request.Request(url, headers={"User-Agent": "iaachz-panel"})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read()


def _download(url, dest, timeout=600):
    req = urllib.request.Request(url, headers={"User-Agent": "iaachz-panel"})
    h = hashlib.sha256()
    with urllib.request.urlopen(req, timeout=timeout) as r, open(dest, "wb") as fh:
        while True:
            chunk = r.read(1 << 20)
            if not chunk:
                break
            h.update(chunk)
            fh.write(chunk)
    return h.hexdigest()


# ------------------------------------------------------------------- Node.js

def node_bin(major):
    return f"{RUNTIMES}/node/{major}/bin"


def node_installed():
    res = {}
    for d in sorted(glob.glob(f"{RUNTIMES}/node/*")):
        major = os.path.basename(d)
        v = load_json(f"{d}/.hp-version.json", {})
        if os.path.exists(f"{d}/bin/node"):
            res[major] = v.get("version", "?")
    return res


def node_install(major):
    if major not in NODE_MAJORS:
        raise HPError(f"Versión de Node.js no soportada: {major}")
    step(f"Buscando la última versión de Node.js {major}", "running")
    index = json.loads(_fetch("https://nodejs.org/dist/index.json"))
    rel = next((r for r in index if r["version"].startswith(f"v{major}.")), None)
    if not rel:
        raise HPError(f"No se encontró Node.js {major} en nodejs.org")
    ver = rel["version"]
    fname = f"node-{ver}-linux-{_arch()}.tar.xz"
    step(f"Descargando {fname}", "running")
    sums = _fetch(f"https://nodejs.org/dist/{ver}/SHASUMS256.txt").decode()
    expected = next((l.split()[0] for l in sums.splitlines() if l.endswith(" " + fname)), None)
    if not expected:
        raise HPError("No se encontró la suma SHA256 oficial")
    ensure_dir(f"{RUNTIMES}/node", 0o755)
    # temporal en el mismo disco que el destino: os.rename no cruza sistemas de archivos
    with tempfile.TemporaryDirectory(dir=f"{RUNTIMES}/node", prefix=".tmp-") as tmp:
        got = _download(f"https://nodejs.org/dist/{ver}/{fname}", f"{tmp}/{fname}")
        if got != expected:
            raise HPError("La suma SHA256 no coincide: descarga corrupta")
        step("Suma SHA256 verificada")
        dest = f"{RUNTIMES}/node/{major}"
        stage = f"{dest}.new"
        shutil.rmtree(stage, ignore_errors=True)
        ensure_dir(stage, 0o755)
        with tarfile.open(f"{tmp}/{fname}") as tar:
            top = tar.getnames()[0].split("/")[0]
            tar.extractall(tmp, filter="data")
        os.rename(f"{tmp}/{top}", f"{stage}/x")
        for item in os.listdir(f"{stage}/x"):
            os.rename(f"{stage}/x/{item}", f"{stage}/{item}")
        os.rmdir(f"{stage}/x")
        with open(f"{stage}/.hp-version.json", "w") as fh:
            json.dump({"version": ver, "lts": rel.get("lts")}, fh)
        run(["chmod", "-R", "a+rX", stage])
        old = f"{dest}.old"
        if os.path.exists(dest):
            shutil.rmtree(old, ignore_errors=True)
            os.rename(dest, old)
        os.rename(stage, dest)
        shutil.rmtree(old, ignore_errors=True)
    step(f"Node.js {ver} instalado en {dest}")
    return {"major": major, "version": ver}


def node_remove(major, in_use):
    if major in in_use:
        raise HPError(f"Node.js {major} lo usan: {', '.join(in_use[major])}")
    path = f"{RUNTIMES}/node/{major}"
    if not os.path.isdir(path):
        raise HPError("Esa versión no está instalada")
    shutil.rmtree(path)
    return {"removed": major}


# -------------------------------------------------------------------- Python

def _ensure_uv():
    if os.path.exists(UV):
        return UV
    step("Instalando uv (gestor de Python)", "running")
    arch = {"x86_64": "x86_64", "aarch64": "aarch64"}.get(platform.machine(), platform.machine())
    name = f"uv-{arch}-unknown-linux-gnu.tar.gz"
    base = "https://github.com/astral-sh/uv/releases/latest/download/"
    expected = _fetch(base + name + ".sha256").decode().split()[0]
    with tempfile.TemporaryDirectory() as tmp:
        got = _download(base + name, f"{tmp}/{name}")
        if got != expected:
            raise HPError("La suma SHA256 de uv no coincide")
        with tarfile.open(f"{tmp}/{name}") as tar:
            tar.extractall(tmp, filter="data")
        src = glob.glob(f"{tmp}/*/uv")[0]
        ensure_dir(PRO + "/bin", 0o755)
        shutil.copy(src, UV)
        os.chmod(UV, 0o755)
    step("uv instalado")
    return UV


def _uv_env():
    return {"PATH": "/usr/local/bin:/usr/bin:/bin", "UV_PYTHON_INSTALL_DIR": PY_DIR,
            "UV_NO_CONFIG": "1", "HOME": "/root"}


def python_system():
    exe = which("python3")
    if not exe:
        return None
    v = run([exe, "-c", "import sys;print('%d.%d.%d'%sys.version_info[:3])"]).stdout.strip()
    return {"version": v, "path": exe}


def python_installed():
    res = {}
    sysp = python_system()
    if sysp:
        res["system"] = sysp
    for d in sorted(glob.glob(f"{PY_DIR}/cpython-*")):
        m = re.match(r"cpython-(\d+\.\d+)\.(\d+)", os.path.basename(d))
        exe = f"{d}/bin/python3"
        if m and os.path.exists(exe):
            res[m.group(1)] = {"version": f"{m.group(1)}.{m.group(2)}", "path": exe}
    return res


def python_path(version):
    inst = python_installed()
    if version in inst:
        return inst[version]["path"]
    raise HPError(f"Python {version} no está instalado (instálalo en Aplicaciones → Entornos)")


def python_install(version):
    if version not in PY_VERSIONS:
        raise HPError(f"Versión de Python no soportada: {version}")
    uv = _ensure_uv()
    ensure_dir(PY_DIR, 0o755)
    step(f"Descargando Python {version}", "running")
    p = run([uv, "python", "install", version], env=_uv_env(), timeout=900)
    print(p.stdout + p.stderr)
    if p.returncode != 0:
        raise HPError("uv no pudo instalar Python " + version)
    run(["chmod", "-R", "a+rX", PY_DIR])
    step(f"Python {version} instalado")
    return {"version": version}


def python_remove(version, in_use):
    if version == "system":
        raise HPError("El Python del sistema no se puede quitar desde aquí")
    if version in in_use:
        raise HPError(f"Python {version} lo usan: {', '.join(in_use[version])}")
    uv = _ensure_uv()
    p = run([uv, "python", "uninstall", version], env=_uv_env(), timeout=300)
    if p.returncode != 0:
        raise HPError(p.stderr.strip() or "No se pudo desinstalar")
    return {"removed": version}


# -------------------------------------------------------------------- Docker

def docker_path():
    return which("docker")


def docker_status():
    exe = docker_path()
    if not exe:
        return {"installed": False}
    active = run(["systemctl", "is-active", "docker"], timeout=10).stdout.strip() == "active"
    ver = run([exe, "version", "--format", "{{.Server.Version}}"], timeout=15).stdout.strip() if active else ""
    compose = run([exe, "compose", "version", "--short"], timeout=15).stdout.strip() if active else ""
    return {"installed": True, "active": active, "version": ver, "compose": compose}


def docker_install():
    env = dict(os.environ, DEBIAN_FRONTEND="noninteractive")
    step("Actualizando índices de paquetes", "running")
    p = run(["apt-get", "update", "-q"], env=env, timeout=600)
    print(p.stdout[-3000:])
    step("Instalando docker.io y docker compose", "running")
    p = run(["apt-get", "install", "-y", "-q", "docker.io", "docker-compose-v2"], env=env, timeout=1800)
    print(p.stdout[-6000:] + p.stderr[-3000:])
    if p.returncode != 0:
        raise HPError("apt-get no pudo instalar Docker")
    ensure_dir("/etc/docker", 0o755)
    daemon = "/etc/docker/daemon.json"
    cfg = load_json(daemon, {})
    cfg.setdefault("log-driver", "json-file")
    cfg.setdefault("log-opts", {"max-size": "10m", "max-file": "3"})
    with open(daemon, "w") as fh:
        json.dump(cfg, fh, indent=2)
    run(["systemctl", "enable", "--now", "docker"], timeout=120)
    run(["systemctl", "restart", "docker"], timeout=120)
    step("Docker instalado y en marcha")
    return docker_status()


def summary(in_use_node=None, in_use_py=None):
    return {
        "node": {"installed": node_installed(), "available": NODE_MAJORS, "in_use": in_use_node or {}},
        "python": {"installed": python_installed(), "available": PY_VERSIONS, "in_use": in_use_py or {},
                   "uv": os.path.exists(UV)},
        "docker": docker_status(),
        "terminal": os.path.exists("/usr/local/hestia/web-terminal") or os.path.exists("/etc/systemd/system/hestia-web-terminal.service"),
    }
