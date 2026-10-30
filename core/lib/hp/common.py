"""Utilidades compartidas de iaachz-panel (corre como root vía sudo desde el panel)."""

import base64
import fcntl
import json
import os
import pwd
import re
import shlex
import subprocess
import sys
import time
from contextlib import contextmanager

HESTIA = "/usr/local/hestia"
BIN = HESTIA + "/bin"
USERS_DIR = HESTIA + "/data/users"
DATA = HESTIA + "/data/hp"  # estado global de iaachz-panel (root:root 700)
PRO = "/opt/iaachz-panel"
RUNTIMES = PRO + "/runtimes"
TPL_DIR = HESTIA + "/data/templates/web/nginx/php-fpm"
APP_TPL = "hp-app"
RUNUSER = "/usr/sbin/runuser" if os.path.exists("/usr/sbin/runuser") else "/sbin/runuser"
PORT_MIN, PORT_MAX = 30000, 39999

RE_USER = re.compile(r"^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,31}$")
RE_DOMAIN = re.compile(r"^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$")
RE_ENVKEY = re.compile(r"^[A-Za-z_][A-Za-z0-9_]{0,63}$")


class HPError(Exception):
    pass


def out(obj):
    sys.stdout.write(json.dumps(obj, ensure_ascii=False, default=str))
    sys.stdout.write("\n")


def fail(msg, code=1):
    out({"error": str(msg)})
    sys.exit(code)


def b64json(s):
    try:
        return json.loads(base64.b64decode(s).decode("utf-8"))
    except Exception:
        raise HPError("Parámetros inválidos (JSON/base64)")


def ensure_dir(path, mode=0o700):
    os.makedirs(path, mode=mode, exist_ok=True)
    return path


# ---------------------------------------------------------------- Hestia conf

_PAIR = re.compile(r"([A-Z0-9_]+)='((?:[^'\\]|\\.)*)'")


def parse_conf_line(line):
    return {k: v for k, v in _PAIR.findall(line)}


def read_conf(path):
    """Archivo estilo Hestia (una línea KEY='v' ... por objeto) -> lista de dicts."""
    try:
        with open(path) as fh:
            return [parse_conf_line(l) for l in fh if l.strip()]
    except FileNotFoundError:
        return []


def read_kv(path):
    """Archivo KEY='value' (una clave por línea) -> dict."""
    data = {}
    try:
        with open(path) as fh:
            for line in fh:
                data.update(parse_conf_line(line))
    except FileNotFoundError:
        pass
    return data


def hestia_conf():
    return read_kv(HESTIA + "/conf/hestia.conf")


def list_users():
    try:
        return sorted(u for u in os.listdir(USERS_DIR) if os.path.isfile(f"{USERS_DIR}/{u}/user.conf"))
    except FileNotFoundError:
        return []


def user_conf(user):
    return read_kv(f"{USERS_DIR}/{user}/user.conf")


def web_domains(user):
    return {d["DOMAIN"]: d for d in read_conf(f"{USERS_DIR}/{user}/web.conf") if "DOMAIN" in d}


def check_user(user):
    if not user or not RE_USER.match(user) or not os.path.isfile(f"{USERS_DIR}/{user}/user.conf"):
        raise HPError(f"Usuario no válido: {user}")
    return user


def check_domain_fmt(domain):
    domain = (domain or "").strip().lower().rstrip(".")
    if not RE_DOMAIN.match(domain):
        raise HPError(f"Dominio no válido: {domain}")
    return domain


def check_web_domain(user, domain):
    check_user(user)
    domain = check_domain_fmt(domain)
    web = web_domains(user)
    if domain not in web:
        raise HPError(f"El dominio {domain} no pertenece a {user}")
    return web[domain]


def home_of(user):
    return pwd.getpwnam(user).pw_dir


def domain_dir(user, domain):
    return f"{home_of(user)}/web/{domain}"


def safe_subpath(base, rel):
    """Ruta relativa dentro de base (sin escapar con .. ni symlinks fuera)."""
    rel = (rel or "").strip().strip("/")
    full = os.path.normpath(os.path.join(base, rel)) if rel else base
    if full != base and not full.startswith(base + "/"):
        raise HPError("La ruta debe quedar dentro de la carpeta del dominio")
    real = os.path.realpath(full) if os.path.exists(full) else full
    real_base = os.path.realpath(base)
    if real != real_base and not real.startswith(real_base + "/"):
        raise HPError("La ruta apunta fuera de la carpeta del dominio")
    return full


def ensure_user_dir(user, path):
    """Crea path (y padres que falten) con dueño user. Hace falta como root porque Hestia
    deja la carpeta del dominio sin permiso de escritura para su dueño."""
    missing = []
    p = path
    while not os.path.exists(p):
        missing.append(p)
        p = os.path.dirname(p)
    pw = pwd.getpwnam(user)
    for d in reversed(missing):
        os.mkdir(d, 0o751)
        os.lchown(d, pw.pw_uid, pw.pw_gid)
    return path


# ------------------------------------------------------------------- procesos

def run(cmd, user=None, cwd=None, env=None, timeout=120, check=False, input=None):
    """Ejecuta cmd (lista). Con user corre como ese usuario (runuser)."""
    if user:
        cmd = [RUNUSER, "-u", user, "--"] + list(cmd)
    p = subprocess.run(cmd, cwd=cwd, env=env, capture_output=True, text=True,
                       timeout=timeout, input=input)
    if check and p.returncode != 0:
        raise HPError((p.stderr or p.stdout or f"{cmd[0]} falló").strip()[-2000:])
    return p


def hestia(cmd, *args, check=True, timeout=300):
    p = run([f"{BIN}/{cmd}"] + [str(a) for a in args], timeout=timeout)
    if check and p.returncode != 0:
        raise HPError(f"{cmd}: " + (p.stdout + p.stderr).strip()[-1500:])
    return p


def hestia_json(cmd, *args):
    p = hestia(cmd, *args, "json")
    try:
        return json.loads(p.stdout or "{}")
    except ValueError:
        return {}


def log_action(user, message, level="Info", category="Web"):
    try:
        run([f"{BIN}/v-log-action", user, level, category, message], timeout=10)
    except Exception:
        pass


def notify(user, topic, notice):
    try:
        run([f"{BIN}/v-add-user-notification", user, topic, notice], timeout=10)
    except Exception:
        pass


def which(name):
    for d in os.environ.get("PATH", "/usr/sbin:/usr/bin:/sbin:/bin").split(":") + ["/usr/local/bin"]:
        p = os.path.join(d, name)
        if os.access(p, os.X_OK):
            return p
    return None


# ---------------------------------------------------------- JSON con candado

@contextmanager
def locked(name):
    ensure_dir(DATA)
    with open(f"{DATA}/.{name}.lock", "w") as fh:
        fcntl.flock(fh, fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(fh, fcntl.LOCK_UN)


def load_json(path, default):
    try:
        with open(path) as fh:
            return json.load(fh)
    except (FileNotFoundError, ValueError):
        return default


def save_json(path, data, mode=0o600):
    tmp = f"{path}.tmp{os.getpid()}"
    with open(tmp, "w") as fh:
        json.dump(data, fh, indent=1, ensure_ascii=False)
    os.chmod(tmp, mode)
    os.replace(tmp, path)


def settings():
    base = {
        "alert_cpu": 90, "alert_mem": 90, "alert_disk": 85, "alert_load": 0,
        "alert_ssl_days": 14, "alert_services": True, "alert_apps": True,
        "alert_email": "", "metrics_enabled": True,
        # Ajustes propios de cada servidor (el código es el mismo en todos)
        "public_ip": "",               # IP pública de este servidor (vacío = detectar)
        "hidden_domains": "",          # dominios que no se listan en /pro/sites (separados por coma)
        "standalone_suffixes": "",     # sufijos que forman su propio grupo, p. ej. .vps.example.net
        "tunnel_default_target": "",   # destino por defecto de un túnel nuevo
        "tunnel_default_port": "8080",
        "target_labels": "",           # nombres para IPs VPN: 100.64.0.1=servidor-casa,...
        "pma_same_origin": False,      # True: phpMyAdmin se abre en el mismo origen que el panel
    }
    base.update(load_json(f"{DATA}/settings.json", {}))
    return base


def now():
    return int(time.time())


def quote(args):
    return " ".join(shlex.quote(str(a)) for a in args)
