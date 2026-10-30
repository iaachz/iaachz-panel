"""Aplicaciones por dominio (como Node.js / Python / Docker en Plesk).

Cada dominio web puede tener UNA aplicación:
  node    -> servicio systemd con la versión de Node elegida
  python  -> venv + gunicorn/uvicorn/comando, servicio systemd
  docker  -> contenedor publicado solo en 127.0.0.1
  proxy   -> nginx reenvía a una URL/puerto ya existente
nginx (plantilla hp-app) reenvía el dominio a 127.0.0.1:<puerto> de la app.
"""

import json
import os
import re
import shutil
import socket
import subprocess

from . import runtimes
from .common import (RUNUSER, APP_TPL, PORT_MAX, PORT_MIN, PRO, RE_ENVKEY, USERS_DIR, HPError, check_user,
                     check_web_domain, domain_dir, ensure_dir, ensure_user_dir, hestia, home_of, list_users, load_json,
                     locked, log_action, now, run, safe_subpath, save_json, web_domains)
from .jobs import step

TYPES = ("node", "python", "docker", "proxy")
UNIT_DIR = "/etc/systemd/system"
ENV_DIR = "/etc/iaachz-panel/env"
LAUNCH_DIR = PRO + "/launch"
RE_IMAGE = re.compile(r"^[a-z0-9][a-z0-9._/:@-]{0,254}$")
RE_CPATH = re.compile(r"^/[A-Za-z0-9._/-]{0,250}$")
RE_SCRIPT = re.compile(r"^[A-Za-z0-9:_.-]{1,64}$")
RE_MODULE = re.compile(r"^[A-Za-z_][A-Za-z0-9_.]*(:[A-Za-z_][A-Za-z0-9_.()]*)?$")


def _apps_path(user):
    return f"{USERS_DIR}/{user}/hp-apps.json"


def load_apps(user):
    return load_json(_apps_path(user), {})


def _save_apps(user, apps):
    save_json(_apps_path(user), apps, 0o660)


def unit_name(user, domain):
    return f"hp-app-{user}-{domain}"


def container_name(user, domain):
    return f"hp-{user}-{domain}"


# --------------------------------------------------------------------- puertos

def _ports_in_use():
    used = set()
    for u in list_users():
        for a in load_apps(u).values():
            if a.get("port"):
                used.add(int(a["port"]))
    p = run(["ss", "-Htln"], timeout=10)
    for line in p.stdout.splitlines():
        f = line.split()
        if len(f) >= 4:
            try:
                used.add(int(f[3].rsplit(":", 1)[1]))
            except (ValueError, IndexError):
                pass
    return used


def _free_port():
    used = _ports_in_use()
    for port in range(PORT_MIN, PORT_MAX):
        if port not in used:
            return port
    raise HPError("No quedan puertos libres para aplicaciones")


# ---------------------------------------------------------------- validación

def _clean_env(env):
    res = {}
    for k, v in (env or {}).items():
        k = str(k).strip()
        v = "" if v is None else str(v)
        if not k:
            continue
        if not RE_ENVKEY.match(k):
            raise HPError(f"Nombre de variable no válido: {k}")
        if "\n" in v or "\r" in v or "\0" in v:
            raise HPError(f"La variable {k} no puede tener saltos de línea")
        res[k] = v
    return res


def _validate(user, domain, cfg, old):
    t = cfg.get("type")
    if t not in TYPES:
        raise HPError("Tipo de aplicación no válido")
    base = domain_dir(user, domain)
    app = {"type": t, "enabled": bool(cfg.get("enabled", True)), "env": _clean_env(cfg.get("env")),
           "created": old.get("created") or now(), "updated": now(),
           "prev_tpl": old.get("prev_tpl")}
    mem = int(cfg.get("memory_mb") or 0)
    if mem < 0 or mem > 1 << 20:
        raise HPError("Límite de memoria no válido")
    app["memory_mb"] = mem

    if t in ("node", "python", "docker"):
        app["port"] = int(old.get("port") or 0) or _free_port()

    if t in ("node", "python"):
        root_rel = (cfg.get("root") or "app").strip().strip("/")
        safe_subpath(base, root_rel)
        app["root"] = root_rel

    if t == "node":
        ver = str(cfg.get("version") or "")
        if ver not in runtimes.node_installed():
            raise HPError(f"Node.js {ver} no está instalado")
        app["version"] = ver
        app["start_mode"] = cfg.get("start_mode") or "file"
        app["mode"] = "development" if cfg.get("mode") == "development" else "production"
        startup = (cfg.get("startup") or "").strip()
        if app["start_mode"] == "file":
            startup = startup or "app.js"
            safe_subpath(safe_subpath(base, app["root"]), startup)
        elif app["start_mode"] == "npm":
            startup = startup or "start"
            if not RE_SCRIPT.match(startup):
                raise HPError("Nombre de script npm no válido")
        elif app["start_mode"] == "command":
            if not startup:
                raise HPError("Indica el comando de inicio")
        else:
            raise HPError("Modo de inicio no válido")
        app["startup"] = startup

    elif t == "python":
        ver = str(cfg.get("version") or "system")
        runtimes.python_path(ver)
        app["version"] = ver
        server = cfg.get("server") or "gunicorn"
        if server not in ("gunicorn", "uvicorn", "command"):
            raise HPError("Servidor Python no válido")
        app["server"] = server
        if server == "command":
            app["command"] = (cfg.get("command") or "").strip()
            if not app["command"]:
                raise HPError("Indica el comando de inicio")
        else:
            module = (cfg.get("module") or ("app:app")).strip()
            if not RE_MODULE.match(module):
                raise HPError("Módulo no válido (ejemplo: app:app o proyecto.wsgi:application)")
            app["module"] = module
        app["workers"] = max(1, min(int(cfg.get("workers") or 2), 32))

    elif t == "docker":
        image = (cfg.get("image") or "").strip().lower()
        if not RE_IMAGE.match(image):
            raise HPError("Imagen Docker no válida")
        app["image"] = image
        cport = int(cfg.get("container_port") or 80)
        if not 1 <= cport <= 65535:
            raise HPError("Puerto del contenedor no válido")
        app["container_port"] = cport
        vols = []
        for v in cfg.get("volumes") or []:
            host = (v.get("host") or "").strip().strip("/")
            cpath = (v.get("container") or "").strip()
            if not host and not cpath:
                continue
            if not host or not RE_CPATH.match(cpath):
                raise HPError("Volumen no válido: usa una carpeta del dominio y una ruta absoluta del contenedor")
            safe_subpath(base, host)
            vols.append({"host": host, "container": cpath})
        app["volumes"] = vols
        cmd = (cfg.get("command") or "").strip()
        app["command"] = cmd

    elif t == "proxy":
        target = (cfg.get("target") or "").strip()
        m = re.match(r"^(https?)://([A-Za-z0-9.-]+|\[[0-9a-fA-F:]+\])(:\d{1,5})?(/[^\s;{}'\"]*)?$", target)
        if not m:
            raise HPError("Destino no válido (ejemplo: http://127.0.0.1:8080)")
        app["target"] = target
    return app


# ------------------------------------------------------------- generación

def _env_file(user, domain, app):
    ensure_dir(ENV_DIR, 0o700)
    env = {"PORT": str(app.get("port", "")), "HOST": "127.0.0.1"}
    if app["type"] == "node":
        env["NODE_ENV"] = app["mode"]
    if app["type"] == "python":
        env["PYTHONUNBUFFERED"] = "1"
    env.update(app["env"])
    path = f"{ENV_DIR}/{unit_name(user, domain)}.env"
    with open(path, "w") as fh:
        for k, v in env.items():
            fh.write(f'{k}="' + v.replace("\\", "\\\\").replace('"', '\\"').replace("$", "\\$") + '"\n')
    os.chmod(path, 0o600)
    return path, env


def _sh(s):
    return "'" + s.replace("'", "'\\''") + "'"


def _launcher(user, domain, app):
    root = safe_subpath(domain_dir(user, domain), app["root"])
    lines = ["#!/bin/bash", f"# iaachz-panel: {domain} ({app['type']}) — generado, no editar", "set -e",
             f"cd {_sh(root)}"]
    if app["type"] == "node":
        nb = runtimes.node_bin(app["version"])
        lines.append(f"export PATH={_sh(nb)}:\"$HOME/.npm-global/bin:./node_modules/.bin:/usr/local/bin:/usr/bin:/bin\"")
        if app["start_mode"] == "file":
            lines.append(f"exec node {_sh(app['startup'])}")
        elif app["start_mode"] == "npm":
            lines.append(f"exec npm run {_sh(app['startup'])}")
        else:
            lines.append(f"exec /bin/bash -c {_sh(app['startup'])}")
    else:
        venv = f"{root}/.venv"
        lines.append(f"export VIRTUAL_ENV={_sh(venv)}")
        lines.append(f"export PATH={_sh(venv + '/bin')}:/usr/local/bin:/usr/bin:/bin")
        if app["server"] == "gunicorn":
            lines.append(f"exec gunicorn --bind 127.0.0.1:$PORT --workers {app['workers']} "
                         f"--access-logfile - {_sh(app['module'])}")
        elif app["server"] == "uvicorn":
            lines.append(f"exec uvicorn --host 127.0.0.1 --port $PORT --workers {app['workers']} "
                         f"--proxy-headers --forwarded-allow-ips 127.0.0.1 {_sh(app['module'])}")
        else:
            lines.append(f"exec /bin/bash -c {_sh(app['command'])}")
    ensure_dir(LAUNCH_DIR, 0o755)
    path = f"{LAUNCH_DIR}/{unit_name(user, domain)}.sh"
    with open(path, "w") as fh:
        fh.write("\n".join(lines) + "\n")
    shutil.chown(path, "root", user)
    os.chmod(path, 0o750)
    return path


def _unit(user, domain, app, launcher, envfile):
    root = safe_subpath(domain_dir(user, domain), app["root"])
    mem = f"MemoryMax={app['memory_mb']}M\n" if app.get("memory_mb") else ""
    text = f"""[Unit]
Description=iaachz-panel: {domain} ({app['type']}) de {user}
After=network.target
StartLimitIntervalSec=120
StartLimitBurst=10

[Service]
Type=simple
User={user}
Group={user}
WorkingDirectory={root}
EnvironmentFile={envfile}
Environment=HOME={home_of(user)}
ExecStart={launcher}
Restart=always
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
SyslogIdentifier=hp-{domain}
{mem}
[Install]
WantedBy=multi-user.target
"""
    path = f"{UNIT_DIR}/{unit_name(user, domain)}.service"
    with open(path, "w") as fh:
        fh.write(text)
    os.chmod(path, 0o644)
    run(["systemctl", "daemon-reload"], timeout=60)


def _nginx_conf(user, domain, app):
    path = f"{home_of(user)}/conf/web/{domain}/hp-app.conf"
    if app["type"] == "proxy":
        target = app["target"]
        host_hdr = "$proxy_host" if not re.match(r"^https?://(127\.0\.0\.1|localhost|\[::1\])", target) else "$host"
    else:
        target = f"http://127.0.0.1:{app['port']}"
        host_hdr = "$host"
    if not app.get("enabled", True):
        body = "\tlocation / {\n\t\treturn 503;\n\t}\n"
    else:
        body = f"""\tlocation / {{
\t\tproxy_pass {target};
\t\tproxy_http_version 1.1;
\t\tproxy_set_header Host {host_hdr};
\t\tproxy_set_header X-Real-IP $remote_addr;
\t\tproxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
\t\tproxy_set_header X-Forwarded-Proto $scheme;
\t\tproxy_set_header X-Forwarded-Host $host;
\t\tproxy_set_header Upgrade $http_upgrade;
\t\tproxy_set_header Connection $http_connection;
\t\tproxy_read_timeout 300s;
\t\tproxy_send_timeout 300s;
\t\tproxy_buffering off;
\t}}
"""
    with open(path, "w") as fh:
        fh.write(f"# iaachz-panel ({app['type']}) — generado por v-hp, no editar\n" + body)
    os.chmod(path, 0o644)


def _set_tpl(user, domain, web, app):
    if web.get("TPL") != APP_TPL:
        app["prev_tpl"] = web.get("TPL") or "default"
        hestia("v-change-web-domain-tpl", user, domain, APP_TPL, "no")
    hestia("v-restart-web", check=False)


def _sample_files(user, domain, app):
    """Si la carpeta de la app está vacía, crea un ejemplo que funcione al instante (como Plesk)."""
    root = safe_subpath(domain_dir(user, domain), app["root"])
    ensure_user_dir(user, root)
    if os.listdir(root):
        return False
    if app["type"] == "node" and app["start_mode"] == "file":
        name = app["startup"]
        code = """const http = require('http');
const port = process.env.PORT || 3000;
http.createServer((req, res) => {
  res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
  res.end(`<h1>Node.js funcionando</h1><p>Aplicación de ejemplo de iaachz-panel (${process.version}).</p>`);
}).listen(port, '127.0.0.1', () => console.log('Escuchando en ' + port));
"""
        files = {name: code, "package.json": json.dumps(
            {"name": domain.replace(".", "-"), "version": "1.0.0", "private": True,
             "scripts": {"start": f"node {name}"}}, indent=2) + "\n"}
    elif app["type"] == "python" and app["server"] in ("gunicorn", "uvicorn"):
        mod = app["module"].split(":")[0].replace(".", "/") + ".py"
        if app["server"] == "gunicorn":
            code = """def app(environ, start_response):
    start_response('200 OK', [('Content-Type', 'text/html; charset=utf-8')])
    return [b'<h1>Python funcionando</h1><p>Aplicacion WSGI de ejemplo de iaachz-panel.</p>']

application = app
"""
            reqs = "gunicorn\n"
        else:
            code = """async def app(scope, receive, send):
    assert scope['type'] == 'http'
    await send({'type': 'http.response.start', 'status': 200,
                'headers': [(b'content-type', b'text/html; charset=utf-8')]})
    await send({'type': 'http.response.body', 'body': b'<h1>Python ASGI funcionando</h1>'})
"""
            reqs = "uvicorn\n"
        files = {mod: code, "requirements.txt": reqs}
    else:
        return False
    for rel, content in files.items():
        full = safe_subpath(root, rel)
        run(["mkdir", "-p", os.path.dirname(full)], user=user)
        run(["tee", full], user=user, input=content, check=True)
    return True


# ------------------------------------------------------------- operaciones

def get(user, domain):
    check_web_domain(user, domain)
    app = load_apps(user).get(domain)
    if app:
        app = dict(app, domain=domain, user=user, **status_of(user, domain, app))
    return app


def status_of(user, domain, app):
    t = app["type"]
    st = {"state": "unknown", "pid": None, "memory": None, "cpu_ns": None, "since": None, "restarts": 0}
    if t in ("node", "python"):
        p = run(["systemctl", "show", unit_name(user, domain) + ".service", "-p",
                 "ActiveState,SubState,MainPID,MemoryCurrent,CPUUsageNSec,ActiveEnterTimestamp,NRestarts"], timeout=10)
        kv = dict(l.split("=", 1) for l in p.stdout.splitlines() if "=" in l)
        state = kv.get("ActiveState", "inactive")
        st["state"] = {"active": "running", "activating": "starting", "failed": "failed"}.get(state, "stopped")
        st["pid"] = int(kv.get("MainPID") or 0) or None
        st["memory"] = int(kv["MemoryCurrent"]) if kv.get("MemoryCurrent", "").isdigit() else None
        st["cpu_ns"] = int(kv["CPUUsageNSec"]) if kv.get("CPUUsageNSec", "").isdigit() else None
        st["since"] = kv.get("ActiveEnterTimestamp") or None
        st["restarts"] = int(kv.get("NRestarts") or 0)
    elif t == "docker":
        exe = runtimes.docker_path()
        if exe:
            p = run([exe, "inspect", "--format", "{{.State.Status}}|{{.State.Pid}}|{{.State.StartedAt}}|{{.RestartCount}}",
                     container_name(user, domain)], timeout=15)
            if p.returncode == 0:
                s, pid, since, rc = p.stdout.strip().split("|")
                st.update(state="running" if s == "running" else ("failed" if s in ("dead", "exited") else s),
                          pid=int(pid) or None, since=since, restarts=int(rc or 0))
            else:
                st["state"] = "missing"
    elif t == "proxy":
        st["state"] = "running" if app.get("enabled", True) else "stopped"
    if not app.get("enabled", True) and st["state"] == "stopped":
        st["state"] = "disabled"
    return st


def list_all(users=None):
    res = []
    for u in users or list_users():
        apps = load_apps(u)
        web = web_domains(u) if apps else {}
        for d, a in apps.items():
            if d not in web:
                continue
            res.append(dict(a, domain=d, user=u, **status_of(u, d, a)))
    return res


def in_use():
    node, py = {}, {}
    for a in list_all():
        if a["type"] == "node":
            node.setdefault(a["version"], []).append(a["domain"])
        elif a["type"] == "python":
            py.setdefault(a["version"], []).append(a["domain"])
    return node, py


def save(user, domain, cfg):
    """Crea o actualiza la app del dominio y la aplica (servicio + nginx)."""
    web = check_web_domain(user, domain)
    from . import apache
    ap = apache.load(user).get(domain)
    if ap:  # una app reemplaza al modo nginx + Apache; recuerda la plantilla original
        apache.disable(user, domain, restore_tpl=False)
        web = dict(web, TPL=ap.get("prev_tpl") or "default")
    with locked("apps-" + user):
        apps = load_apps(user)
        old = apps.get(domain, {})
        if old and old.get("type") != cfg.get("type"):
            _teardown(user, domain, old, restore_tpl=False)
            old = {"prev_tpl": old.get("prev_tpl"), "created": old.get("created")}
        app = _validate(user, domain, cfg, old)
        _set_tpl(user, domain, web, app)
        apps[domain] = app
        _save_apps(user, apps)
    sample = False
    if app["type"] in ("node", "python"):
        sample = _sample_files(user, domain, app)
        envfile, _ = _env_file(user, domain, app)
        _unit(user, domain, app, _launcher(user, domain, app), envfile)
    elif app["type"] == "docker":
        _env_file(user, domain, app)
    _nginx_conf(user, domain, app)
    hestia("v-restart-web", check=False)
    log_action(user, f"iaachz-panel: aplicación {app['type']} configurada en {domain}")
    return {"app": app, "sample_created": sample}


def apply_runtime(user, domain):
    """Tras guardar: instala dependencias (si hace falta) y arranca. Pensado para ejecutarse como tarea."""
    app = load_apps(user).get(domain)
    if not app:
        raise HPError("La aplicación no existe")
    if app["type"] == "python":
        install_deps(user, domain, quiet_if_missing=True)
    elif app["type"] == "node":
        root = safe_subpath(domain_dir(user, domain), app["root"])
        if os.path.exists(f"{root}/package.json") and not os.path.isdir(f"{root}/node_modules"):
            pkg = load_json(f"{root}/package.json", {})
            if pkg.get("dependencies") or pkg.get("devDependencies"):
                install_deps(user, domain)
    elif app["type"] == "docker":
        _docker_run(user, domain, app, pull=True)
    if app.get("enabled", True):
        action(user, domain, "restart")
    else:
        action(user, domain, "stop")
    return get(user, domain)


def _docker_run(user, domain, app, pull=False):
    exe = runtimes.docker_path()
    if not exe:
        raise HPError("Docker no está instalado (instálalo desde Docker → Instalar)")
    name = container_name(user, domain)
    if pull:
        step(f"Descargando imagen {app['image']}", "running")
        p = subprocess.run([exe, "pull", app["image"]], text=True, capture_output=True, timeout=1800)
        print(p.stdout[-4000:] + p.stderr[-2000:])
        if p.returncode != 0:
            raise HPError("No se pudo descargar la imagen " + app["image"])
        step(f"Imagen {app['image']} lista")
    run([exe, "rm", "-f", name], timeout=120)
    envfile = f"{ENV_DIR}/{unit_name(user, domain)}.env"
    _env_file(user, domain, app)
    # docker --env-file no admite comillas: genera una copia simple
    plain = envfile + ".docker"
    env = {"PORT": str(app["container_port"])}
    env.update(app["env"])
    with open(plain, "w") as fh:
        for k, v in env.items():
            fh.write(f"{k}={v}\n")
    os.chmod(plain, 0o600)
    cmd = [exe, "run", "-d", "--name", name, "--restart", "unless-stopped",
           "-p", f"127.0.0.1:{app['port']}:{app['container_port']}", "--env-file", plain,
           "--label", f"iaachz-panel.user={user}", "--label", f"iaachz-panel.domain={domain}",
           "--log-opt", "max-size=10m", "--log-opt", "max-file=3"]
    if app.get("memory_mb"):
        cmd += ["--memory", f"{app['memory_mb']}m"]
    base = domain_dir(user, domain)
    for v in app.get("volumes", []):
        host = ensure_user_dir(user, safe_subpath(base, v["host"]))
        cmd += ["-v", f"{host}:{v['container']}"]
    cmd.append(app["image"])
    if app.get("command"):
        cmd += ["/bin/sh", "-c", app["command"]]
    step("Creando contenedor " + name, "running")
    p = run(cmd, timeout=300)
    if p.returncode != 0:
        raise HPError("docker run falló: " + p.stderr.strip()[-1500:])
    step("Contenedor en marcha")


def action(user, domain, what):
    check_web_domain(user, domain)
    app = load_apps(user).get(domain)
    if not app:
        raise HPError("Este dominio no tiene aplicación")
    if what in ("enable", "disable"):
        with locked("apps-" + user):
            apps = load_apps(user)
            apps[domain]["enabled"] = what == "enable"
            _save_apps(user, apps)
            app = apps[domain]
        _nginx_conf(user, domain, app)
        hestia("v-restart-web", check=False)
        what = "restart" if what == "enable" else "stop"
    t = app["type"]
    if t in ("node", "python"):
        unit = unit_name(user, domain) + ".service"
        if what == "stop":
            run(["systemctl", "disable", "--now", unit], timeout=60)
        elif what in ("start", "restart"):
            run(["systemctl", "reset-failed", unit], timeout=30)
            run(["systemctl", "enable", unit], timeout=60)
            p = run(["systemctl", "restart", unit], timeout=90)
            if p.returncode != 0:
                raise HPError(p.stderr.strip() or "No se pudo iniciar")
    elif t == "docker":
        exe = runtimes.docker_path()
        if not exe:
            raise HPError("Docker no está instalado")
        name = container_name(user, domain)
        if run([exe, "inspect", name], timeout=15).returncode != 0:
            if what == "stop":
                return status_of(user, domain, app)
            _docker_run(user, domain, app)
        p = run([exe, {"start": "start", "restart": "restart", "stop": "stop"}[what], name], timeout=120)
        if p.returncode != 0:
            raise HPError(p.stderr.strip())
    log_action(user, f"iaachz-panel: {what} de la aplicación de {domain}")
    return status_of(user, domain, app)


def _teardown(user, domain, app, restore_tpl=True):
    t = app.get("type")
    unit = unit_name(user, domain)
    if t in ("node", "python"):
        run(["systemctl", "disable", "--now", unit + ".service"], timeout=60)
        for p in (f"{UNIT_DIR}/{unit}.service", f"{LAUNCH_DIR}/{unit}.sh"):
            if os.path.exists(p):
                os.remove(p)
        run(["systemctl", "daemon-reload"], timeout=60)
    elif t == "docker" and runtimes.docker_path():
        run([runtimes.docker_path(), "rm", "-f", container_name(user, domain)], timeout=120)
    for p in (f"{ENV_DIR}/{unit}.env", f"{ENV_DIR}/{unit}.env.docker"):
        if os.path.exists(p):
            os.remove(p)
    conf = f"{home_of(user)}/conf/web/{domain}/hp-app.conf"
    if os.path.exists(conf):
        os.remove(conf)
    if restore_tpl:
        hestia("v-change-web-domain-tpl", user, domain, app.get("prev_tpl") or "default", "yes", check=False)


def delete(user, domain):
    check_user(user)
    with locked("apps-" + user):
        apps = load_apps(user)
        app = apps.pop(domain, None)
        if not app:
            raise HPError("Este dominio no tiene aplicación")
        _teardown(user, domain, app)
        _save_apps(user, apps)
    log_action(user, f"iaachz-panel: aplicación eliminada de {domain} (los archivos se conservan)")
    return {"deleted": domain}


def cleanup_domain(user, domain):
    """Para el hook de borrado de dominio: elimina la app si existía."""
    apps = load_apps(user)
    if domain in apps:
        _teardown(user, domain, apps[domain], restore_tpl=False)
        apps.pop(domain)
        _save_apps(user, apps)


def logs(user, domain, lines=200):
    check_web_domain(user, domain)
    app = load_apps(user).get(domain)
    if not app:
        raise HPError("Este dominio no tiene aplicación")
    lines = max(10, min(int(lines), 2000))
    if app["type"] in ("node", "python"):
        p = run(["journalctl", "-u", unit_name(user, domain) + ".service", "-n", str(lines), "--no-pager",
                 "-o", "short-iso"], timeout=30)
        return {"log": p.stdout}
    if app["type"] == "docker" and runtimes.docker_path():
        p = run([runtimes.docker_path(), "logs", "--tail", str(lines), "--timestamps",
                 container_name(user, domain)], timeout=30)
        return {"log": p.stdout + p.stderr}
    p = run(["tail", "-n", str(lines), f"/var/log/nginx/domains/{domain}.error.log"], timeout=10)
    return {"log": p.stdout}


def _user_env(user, extra_path=""):
    home = home_of(user)
    return {"HOME": home, "USER": user, "LANG": "C.UTF-8",
            "PATH": (extra_path + ":" if extra_path else "") + "/usr/local/bin:/usr/bin:/bin"}


def _stream(cmd, user, cwd, env, timeout=1800):
    """Ejecuta como el usuario mostrando la salida en el log de la tarea."""
    p = subprocess.run([RUNUSER, "-u", user, "--"] + cmd, cwd=cwd, env=env, text=True,
                       stdout=None, stderr=subprocess.STDOUT, timeout=timeout)
    if p.returncode != 0:
        raise HPError(f"{' '.join(cmd[:3])} terminó con código {p.returncode}")


def install_deps(user, domain, quiet_if_missing=False):
    app = load_apps(user).get(domain)
    if not app or app["type"] not in ("node", "python"):
        raise HPError("Solo para aplicaciones Node.js o Python")
    root = safe_subpath(domain_dir(user, domain), app["root"])
    if app["type"] == "node":
        if not os.path.exists(f"{root}/package.json"):
            raise HPError("No hay package.json en " + root)
        nb = runtimes.node_bin(app["version"])
        env = _user_env(user, nb)
        has_lock = os.path.exists(f"{root}/package-lock.json")
        step("npm ci" if has_lock else "npm install", "running")
        _stream([f"{nb}/npm", "ci" if has_lock else "install", "--no-audit", "--no-fund"], user, root, env)
        step("Dependencias de Node.js instaladas")
    else:
        uv = runtimes._ensure_uv()
        py = runtimes.python_path(app["version"])
        env = _user_env(user)
        env["UV_PYTHON_INSTALL_DIR"] = runtimes.PY_DIR
        venv = f"{root}/.venv"
        if not os.path.exists(f"{venv}/bin/python"):
            step("Creando entorno virtual (.venv)", "running")
            _stream([uv, "venv", "--seed", "--python", py, venv], user, root, env)
        pkgs = []
        if os.path.exists(f"{root}/requirements.txt"):
            step("Instalando requirements.txt", "running")
            _stream([uv, "pip", "install", "--python", f"{venv}/bin/python", "-r", "requirements.txt"], user, root, env)
        elif not quiet_if_missing:
            step("No hay requirements.txt", "warning")
        if app["server"] in ("gunicorn", "uvicorn"):
            pkgs.append(app["server"])
        if pkgs:
            step("Asegurando " + ", ".join(pkgs), "running")
            _stream([uv, "pip", "install", "--python", f"{venv}/bin/python"] + pkgs, user, root, env)
        step("Entorno de Python listo")
    return {"ok": True}


def scripts(user, domain):
    app = load_apps(user).get(domain)
    if not app or app["type"] != "node":
        return {"scripts": {}}
    root = safe_subpath(domain_dir(user, domain), app["root"])
    return {"scripts": load_json(f"{root}/package.json", {}).get("scripts", {})}


def run_script(user, domain, script):
    app = load_apps(user).get(domain)
    if not app or app["type"] != "node":
        raise HPError("Solo para aplicaciones Node.js")
    if not RE_SCRIPT.match(script):
        raise HPError("Nombre de script no válido")
    root = safe_subpath(domain_dir(user, domain), app["root"])
    nb = runtimes.node_bin(app["version"])
    step(f"npm run {script}", "running")
    _stream([f"{nb}/npm", "run", script], user, root, _user_env(user, nb), timeout=3600)
    step("Script terminado")
    return {"ok": True}
