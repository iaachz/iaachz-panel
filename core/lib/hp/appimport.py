"""Importar una aplicación desde un archivo (.zip, .tar.gz…) subido desde el panel.

El archivo se descomprime COMO EL USUARIO en una carpeta temporal del dominio, se detecta qué es
(Node.js, Python, Docker o web PHP/estática) y se mueve a su carpeta definitiva. Lo que hubiera
allí se conserva como <carpeta>.bak-AAAAMMDD-HHMMSS. Opcionalmente crea y arranca la aplicación.
"""

import json
import os
import pwd
import re
import secrets
import shutil
import subprocess
import tarfile
import time
import zipfile

from . import apps, runtimes
from .common import (RUNUSER, HPError, check_web_domain, domain_dir, ensure_user_dir, home_of, load_json,
                     log_action, safe_subpath)
from .jobs import step

UPLOADS = "/var/lib/iaachz-panel/uploads"
RE_UPLOAD = re.compile(r"^[a-f0-9]{32}$")
MAX_BYTES = 30 * 1024 ** 3  # descomprimido
MAX_FILES = 400_000
MODES = ("auto", "node", "python", "docker", "static")

# Se ejecuta como el usuario: zipfile limpia rutas con .. o absolutas y tarfile usa el filtro "data".
EXTRACT = r"""
import os, sys, tarfile, zipfile
src, dst = sys.argv[1], sys.argv[2]
os.umask(0o022)
if zipfile.is_zipfile(src):
    with zipfile.ZipFile(src) as z:
        for info in z.infolist():
            path = z.extract(info, dst)
            mode = (info.external_attr >> 16) & 0o777
            if info.create_system == 3 and mode and not info.is_dir():
                os.chmod(path, (mode & 0o755) | 0o644)
else:
    with tarfile.open(src) as t:
        t.extractall(dst, filter="data")
"""


# ------------------------------------------------------------------ archivo

def _upload_path(source):
    if not source.startswith("upload:") or not RE_UPLOAD.match(source[7:]):
        raise HPError("Origen no válido: sube el archivo desde el panel")
    path = f"{UPLOADS}/{source[7:]}.part"
    if not os.path.isfile(path) or os.path.islink(path):
        raise HPError("No se encontró el archivo subido (vuelve a subirlo)")
    return path


def check_source(opts):
    _upload_path(str(opts.get("source") or ""))


def _scan(path):
    """Cuenta archivos y tamaño descomprimido sin extraer nada (lo hace root, solo lee el índice)."""
    if zipfile.is_zipfile(path):
        with zipfile.ZipFile(path) as z:
            items = z.infolist()
            return "zip", len(items), sum(i.file_size for i in items)
    try:
        with tarfile.open(path) as t:
            n = size = 0
            for m in t:
                n += 1
                size += m.size
                if n > MAX_FILES:
                    break
            return "tar", n, size
    except tarfile.TarError:
        raise HPError("El archivo no es un .zip ni un .tar(.gz/.bz2/.xz) válido")


def _stage(user, src):
    """Copia la subida a ~/tmp con nombre aleatorio y dueño el usuario (sin seguir enlaces)."""
    tmp = f"{home_of(user)}/tmp"
    if not os.path.isdir(tmp) or os.path.islink(tmp):
        raise HPError(f"No existe la carpeta temporal del usuario ({tmp})")
    dst = f"{tmp}/hp-import-{secrets.token_hex(8)}"
    fd = os.open(dst, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, "wb") as out, open(src, "rb") as fh:
        shutil.copyfileobj(fh, out, 8 * 1024 * 1024)
        uid, gid = _ids(user)
        os.fchown(out.fileno(), uid, gid)
    os.remove(src)
    meta = src[:-5] + ".json"
    if os.path.exists(meta):
        os.remove(meta)
    return dst


def _ids(user):
    pw = pwd.getpwnam(user)
    return pw.pw_uid, pw.pw_gid


def _content_root(path):
    """Si el archivo trae una única carpeta (proyecto-main/…), su contenido es la app."""
    for junk in ("__MACOSX", ".DS_Store"):
        p = os.path.join(path, junk)
        if os.path.lexists(p):
            shutil.rmtree(p) if os.path.isdir(p) and not os.path.islink(p) else os.remove(p)
    entries = os.listdir(path)
    if len(entries) == 1:
        inner = os.path.join(path, entries[0])
        if os.path.isdir(inner) and not os.path.islink(inner):
            return inner
    return path


# ---------------------------------------------------------------- detección

def _read(path, limit=200_000):
    try:
        if os.path.islink(path) or not os.path.isfile(path):
            return ""
        with open(path, "rb") as fh:
            return fh.read(limit).decode("utf-8", "replace")
    except OSError:
        return ""


def _has(root, *names):
    return any(os.path.isfile(os.path.join(root, n)) and not os.path.islink(os.path.join(root, n)) for n in names)


def _pick_node(want, at_least=False):
    installed = sorted(runtimes.node_installed(), key=int)
    if not installed:
        return "", "No hay ninguna versión de Node.js instalada (Aplicaciones → Entornos)"
    if at_least and want.isdigit() and int(installed[-1]) >= int(want):
        return installed[-1], ""
    if want and want in installed:
        return want, ""
    if want and want.isdigit():
        newer = [v for v in installed if int(v) >= int(want)]
        if newer:
            return newer[0], f"Pide Node.js {want}; se usará {newer[0]}"
        return installed[-1], f"Pide Node.js {want}, que no está instalado; se usará {installed[-1]}"
    return installed[-1], ""


def _node(root):
    pkg = load_json(os.path.join(root, "package.json"), {})
    if not isinstance(pkg, dict):
        pkg = {}
    deps = dict(pkg.get("devDependencies") or {}, **(pkg.get("dependencies") or {}))
    scripts = pkg.get("scripts") or {}
    framework = next((name for key, name in (
        ("next", "Next.js"), ("nuxt", "Nuxt"), ("@nestjs/core", "NestJS"), ("@sveltejs/kit", "SvelteKit"),
        ("@remix-run/node", "Remix"), ("astro", "Astro"), ("express", "Express"), ("fastify", "Fastify"),
        ("koa", "Koa"), ("@hapi/hapi", "hapi"), ("socket.io", "Socket.IO"), ("vite", "Vite"))
        if key in deps), "")
    want, at_least = "", False
    for f in (".nvmrc", ".node-version"):
        m = re.search(r"(\d+)", _read(os.path.join(root, f), 200))
        if m:
            want = m.group(1)
            break
    if not want:
        spec = str((pkg.get("engines") or {}).get("node") or "")
        m = re.search(r"(\d+)", spec)
        want = m.group(1) if m else ""
        at_least = spec.strip().startswith(">") and "<" not in spec
    version, note = _pick_node(want, at_least)
    notes = [note] if note else []

    cfg = {"type": "node", "version": version, "mode": "production"}
    main = str(pkg.get("main") or "")
    entry = next((f for f in (main, "server.js", "app.js", "index.js", "main.js", "server.mjs", "index.mjs",
                              "src/server.js", "src/index.js") if f and _has(root, f)), "")
    if framework == "NestJS" and "start:prod" in scripts:
        cfg.update(start_mode="npm", startup="start:prod")
    elif "start" in scripts:
        cfg.update(start_mode="npm", startup="start")
    elif entry:
        cfg.update(start_mode="file", startup=entry)
    elif framework == "Vite":
        cfg.update(start_mode="command", startup="npx vite preview --host 127.0.0.1 --port $PORT --strictPort")
    else:
        cfg.update(start_mode="file", startup="app.js")
        notes.append("No se encontró cómo arrancarla (script start ni archivo de inicio): revisa el archivo de inicio")
    build = "build" in scripts and not any(os.path.isdir(os.path.join(root, d)) for d in (".next", ".output", "dist", "build"))
    if build:
        notes.append("Se ejecutará npm run build antes de arrancar")
    if not deps:
        notes.append("package.json no declara dependencias")
    return cfg, framework, build, notes


RE_PYAPP = re.compile(r"^(\w+)\s*=\s*(FastAPI|Starlette|Quart|Flask|Sanic|Litestar)\s*\(", re.M)
RE_FACTORY = re.compile(r"^def\s+(create_app)\s*\(", re.M)


def _python(root):
    notes = []
    reqs = (_read(os.path.join(root, "requirements.txt")) + _read(os.path.join(root, "pyproject.toml"))).lower()
    want = ""
    m = re.search(r"(3\.\d+)", _read(os.path.join(root, ".python-version"), 200) or _read(os.path.join(root, "runtime.txt"), 200))
    if m:
        want = m.group(1)
    installed = runtimes.python_installed() if want else {}
    version = want if want in installed else "system"
    if want and version == "system" and not str(installed.get("system", {}).get("version", "")).startswith(want + "."):
        notes.append(f"Pide Python {want}, que no está instalado; se usará el del sistema")
    cfg = {"type": "python", "version": version, "workers": 2}
    framework = ""
    if _has(root, "manage.py"):
        framework = "Django"
        wsgi = ""
        for d in sorted(os.listdir(root)):
            p = os.path.join(root, d)
            if os.path.isdir(p) and not os.path.islink(p) and _has(p, "wsgi.py") and "django" in _read(os.path.join(p, "wsgi.py")).lower():
                wsgi = d
                break
        cfg.update(server="gunicorn", module=f"{wsgi or 'config'}.wsgi:application")
        notes.append("Django: ejecuta migrate y collectstatic si hace falta, y añade el dominio a ALLOWED_HOSTS")
        return cfg, framework, notes
    files = [f for f in ("main.py", "app.py", "application.py", "wsgi.py", "asgi.py", "server.py", "run.py", "api.py")
             if _has(root, f)]
    files += sorted(f for f in os.listdir(root) if f.endswith(".py") and f not in files and _has(root, f))
    for f in files[:40]:
        code = _read(os.path.join(root, f))
        m = RE_PYAPP.search(code)
        mod = f[:-3]
        if m:
            framework = m.group(2)
            asgi = framework in ("FastAPI", "Starlette", "Quart", "Litestar")
            cfg.update(server="uvicorn" if asgi else "gunicorn", module=f"{mod}:{m.group(1)}")
            if framework == "Sanic":
                cfg.update(server="command", command=f"sanic {mod}:{m.group(1)} --host 127.0.0.1 --port $PORT")
            return cfg, framework, notes
        if RE_FACTORY.search(code) and "flask" in code.lower():
            framework = "Flask"
            cfg.update(server="gunicorn", module=f"{mod}:create_app()")
            return cfg, framework, notes
    if "fastapi" in reqs:
        framework = "FastAPI"
        cfg.update(server="uvicorn", module="main:app")
    elif "flask" in reqs:
        framework = "Flask"
        cfg.update(server="gunicorn", module="app:app")
    else:
        cfg.update(server="gunicorn", module="app:app")
    notes.append("No se encontró el objeto de la aplicación: revisa el módulo (" + cfg["module"] + ")")
    return cfg, framework, notes


def _docker(root):
    name = next(f for f in ("Dockerfile", "dockerfile", "Containerfile") if _has(root, f))
    m = re.search(r"^\s*EXPOSE\s+(\d+)", _read(os.path.join(root, name)), re.M | re.I)
    port = int(m.group(1)) if m else 80
    notes = [] if m else ["El Dockerfile no declara EXPOSE: se asume el puerto 80"]
    return {"type": "docker", "build": True, "container_port": port, "image": ""}, notes


def detect(root, mode="auto"):
    """Qué hay en root. Devuelve {type, framework, config, build, notes, alternatives}."""
    has_pkg = _has(root, "package.json")
    has_py = _has(root, "requirements.txt", "pyproject.toml", "Pipfile", "manage.py", "setup.py")
    has_docker = _has(root, "Dockerfile", "dockerfile", "Containerfile")
    has_compose = _has(root, "docker-compose.yml", "docker-compose.yaml", "compose.yml", "compose.yaml")
    scripts = (load_json(os.path.join(root, "package.json"), {}) or {}).get("scripts", {}) if has_pkg else {}
    is_php = _has(root, "artisan", "index.php", "wp-config.php", "wp-load.php") or (
        _has(root, "composer.json") and "start" not in (scripts if isinstance(scripts, dict) else {}))
    if mode == "auto":
        if is_php:
            mode = "static"
        elif has_pkg:
            mode = "node"
        elif has_py or any(f.endswith(".py") for f in os.listdir(root)):
            mode = "python"
        elif has_docker:
            mode = "docker"
        elif _has(root, "index.html", "index.htm"):
            mode = "static"
        else:
            mode = "unknown"
    res = {"type": mode, "framework": "", "config": {}, "build": False, "notes": [], "alternatives": []}
    if mode == "node":
        if not has_pkg:
            raise HPError("Elegiste Node.js pero el archivo no tiene package.json")
        res["config"], res["framework"], res["build"], res["notes"] = _node(root)
    elif mode == "python":
        res["config"], res["framework"], res["notes"] = _python(root)
    elif mode == "docker":
        if not has_docker:
            raise HPError("Elegiste Docker pero el archivo no tiene Dockerfile")
        res["config"], res["notes"] = _docker(root)
    elif mode == "static":
        php = _has(root, "index.php") or _has(root, "artisan") or _has(root, "composer.json")
        res["framework"] = "Laravel" if _has(root, "artisan") else ("WordPress" if _has(root, "wp-load.php") else ("PHP" if php else "HTML"))
        if res["framework"] == "Laravel":
            res["notes"].append("Laravel: cambia la plantilla nginx a «laravel» (raíz en public/) y configura .env")
        if _has(root, "composer.json") and not os.path.isdir(os.path.join(root, "vendor")):
            res["notes"].append("Falta la carpeta vendor/: ejecuta composer install")
    else:
        res["notes"].append("No se reconoció el tipo de proyecto (no hay package.json, archivos .py, Dockerfile ni index)")
    if has_docker and mode != "docker":
        res["alternatives"].append("docker")
    if has_compose and not has_docker:
        res["notes"].append("Trae docker-compose: para pilas de varios contenedores usa Docker → Compose")
    return res


# -------------------------------------------------------------- importación

def _set_like(path, ref, default_mode=0o751):
    """Dueño/permisos de la carpeta nueva iguales a la que reemplaza (public_html es usuario:www-data)."""
    if ref:
        os.lchown(path, ref.st_uid, ref.st_gid)
        os.chmod(path, ref.st_mode & 0o7777)
    else:
        os.chmod(path, default_mode)


def import_archive(user, domain, opts):
    check_web_domain(user, domain)
    src = _upload_path(str(opts.get("source") or ""))
    mode = opts.get("mode") if opts.get("mode") in MODES else "auto"
    is_admin = bool(opts.get("admin"))
    base = domain_dir(user, domain)
    name = os.path.basename(str(opts.get("name") or "archivo"))

    kind, count, size = _scan(src)
    if count > MAX_FILES:
        raise HPError(f"El archivo tiene demasiados elementos (más de {MAX_FILES:,})")
    free = shutil.disk_usage(base).free
    if size > MAX_BYTES or size > free * 0.8:
        raise HPError(f"Descomprimido ocupa {size / 1024 ** 3:.1f} GB: no hay espacio suficiente")
    step(f"{name}: {kind}, {count:,} elementos, {size / 1048576:.1f} MB descomprimido".replace(",", "."))

    archive = _stage(user, src)
    work = f"{base}/.hp-import-{secrets.token_hex(6)}"
    uid, gid = _ids(user)
    try:
        os.mkdir(work, 0o751)
        os.lchown(work, uid, gid)
        step("Descomprimiendo", "running")
        p = subprocess.run([RUNUSER, "-u", user, "--", "/usr/bin/python3", "-I", "-c", EXTRACT, archive, work],
                           capture_output=True, text=True, timeout=3600)
        if p.returncode != 0:
            print(p.stderr[-3000:])
            raise HPError("No se pudo descomprimir: " + (p.stderr.strip().splitlines() or ["error"])[-1][:300])
        step("Archivo descomprimido")

        root = _content_root(work)
        det = detect(root, mode)
        label = {"node": "Node.js", "python": "Python", "docker": "Docker", "static": "Web PHP/estática"}.get(det["type"], "desconocido")
        step(f"Detectado: {label}" + (f" · {det['framework']}" if det["framework"] else ""), "ok" if det["type"] != "unknown" else "warning")
        for n in det["notes"]:
            step(n, "warning")
        if det["type"] == "unknown" and mode == "auto":
            raise HPError("No se reconoció el tipo de aplicación. Elige el tipo a mano y vuelve a importar.")

        if det["type"] == "static":
            rel = "public_html"
        else:
            rel = (str(opts.get("root") or "app")).strip().strip("/") or "app"
            if rel.split("/")[0] in ("public_html", "public_shtml", "private", "logs", "stats", "cgi-bin",
                                     "document_errors", "conf") or rel.startswith("."):
                raise HPError("Elige otra carpeta para la aplicación (no puede ser " + rel + ")")
        dest = safe_subpath(base, rel)
        if os.path.islink(dest):
            raise HPError("La carpeta de destino es un enlace simbólico")
        ensure_user_dir(user, os.path.dirname(dest))

        ref = os.lstat(dest) if os.path.isdir(dest) else None
        backup = None
        if os.path.lexists(dest):
            if os.path.isdir(dest) and not os.listdir(dest):
                os.rmdir(dest)
            else:
                backup = f"{dest}.bak-{time.strftime('%Y%m%d-%H%M%S')}"
                os.rename(dest, backup)
                step(f"Contenido anterior guardado en {os.path.relpath(backup, base)}")
        os.rename(root, dest)
        _set_like(dest, ref)
        step(f"Archivos en ~/web/{domain}/{rel}")
    finally:
        if os.path.lexists(work):
            shutil.rmtree(work, ignore_errors=True)
        if os.path.exists(archive):
            os.remove(archive)

    res = {"detected": det, "root": rel, "backup": os.path.relpath(backup, base) if backup else None, "app": None}
    log_action(user, f"iaachz-panel: importado {name} en {domain} ({det['type']})")
    current = apps.load_apps(user).get(domain)
    if det["type"] == "static":
        if current:
            res["notes"] = [f"El dominio tiene una aplicación {current['type']}: quítala para servir public_html"]
        return res
    if not opts.get("create"):
        return res
    if det["type"] == "docker" and not is_admin:
        step("Docker solo lo puede configurar el administrador: revisa y guarda la configuración", "warning")
        return res
    if det["type"] == "node" and not det["config"].get("version"):
        raise HPError("Instala una versión de Node.js en Aplicaciones → Entornos y vuelve a intentarlo")

    cfg = dict(det["config"], root=rel, enabled=True)
    if current:  # conserva variables y límite de memoria de la app anterior
        cfg["env"] = current.get("env") or {}
        cfg["memory_mb"] = current.get("memory_mb") or 0
    step("Configurando la aplicación", "running")
    apps.save(user, domain, cfg)
    step("Aplicación configurada")
    if det["type"] == "node" and det["build"]:
        app_root = safe_subpath(base, rel)
        if not os.path.isdir(f"{app_root}/node_modules"):
            apps.install_deps(user, domain)
        apps.run_script(user, domain, "build")
    app = apps.apply_runtime(user, domain)
    if app and app.get("type") in ("node", "python"):
        time.sleep(4)
        app = apps.get(user, domain)
        if app.get("state") != "running":
            step("La aplicación no quedó en marcha: revisa sus registros y la configuración", "warning")
            print(apps.logs(user, domain, 40).get("log", ""))
    res["app"] = app
    return res
