"""Pasos largos de los instaladores rápidos de Hestia (Instalación rápida de aplicación).

El instalador PHP de Hestia descarga el código, crea la base de datos y deja la configuración;
lo que tarda o necesita argumentos con espacios y símbolos (crear las tablas de Moodle, el
instalador de consola de LimeSurvey o phpBB) corre aquí como tarea en segundo plano, así la
petición web no se corta. Las contraseñas no viajan en la línea de comandos de la tarea: se
guardan en un archivo solo-root que la tarea lee y borra.
"""

import json
import os
import pwd
import re
import secrets
import shutil
import subprocess

from . import jobs
from .common import (DATA, RUNUSER, HPError, b64json, check_web_domain, domain_dir, ensure_dir, hestia,
                     hestia_json, home_of, log_action)
from .jobs import step

SECRETS = DATA + "/secrets"
RE_SID = re.compile(r"^[a-f0-9]{32}$")
RE_PHP = re.compile(r"^8\.\d$")
RE_LANG = re.compile(r"^[a-z]{2,3}(_[a-z]{2,4})?$")
RE_ADMIN = re.compile(r"^[A-Za-z0-9_.@-]{1,100}$")


def _put_secret(data):
    ensure_dir(SECRETS)
    sid = secrets.token_hex(16)
    fd = os.open(f"{SECRETS}/{sid}.json", os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "w") as fh:
        json.dump(data, fh)
    return sid


def _take_secret(sid):
    if not RE_SID.match(sid or ""):
        raise HPError("Parámetros no válidos")
    path = f"{SECRETS}/{sid}.json"
    try:
        with open(path) as fh:
            data = json.load(fh)
    except (OSError, ValueError):
        raise HPError("Los datos de la instalación ya no existen; vuelve a lanzarla")
    os.remove(path)
    return data


def _docroot(user, domain, *needed):
    """public_html del dominio, comprobando que existen los archivos indicados (sin salirse con enlaces)."""
    base = domain_dir(user, domain)
    root = f"{base}/public_html"
    for rel in needed:
        p = f"{root}/{rel}"
        if not os.path.isfile(p) or not os.path.realpath(p).startswith(os.path.realpath(base) + "/"):
            raise HPError(f"Falta {rel} en public_html: la descarga no terminó bien")
    return base, root


def _as_user(user, cmd, cwd, secret="", timeout=3600):
    """Ejecuta como el usuario, copia la salida al registro de la tarea (sin la contraseña)."""
    env = {"HOME": home_of(user), "USER": user, "LANG": "C.UTF-8", "PATH": "/usr/local/bin:/usr/bin:/bin"}
    p = subprocess.run([RUNUSER, "-u", user, "--"] + cmd, cwd=cwd, env=env, text=True,
                       stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=timeout)
    out = p.stdout or ""
    if secret:
        out = out.replace(secret, "********")
    print(out[-20000:], flush=True)
    return p.returncode, out


def _fail_from(out, what):
    last = [l for l in out.strip().splitlines() if l.strip()][-3:]
    raise HPError(f"La instalación de {what} falló: " + " ".join(last)[-600:])


def _common_checks(opts):
    php = str(opts.get("php") or "")
    if not RE_PHP.match(php) or not os.path.exists(f"/usr/bin/php{php}"):
        raise HPError(f"PHP {php} no está instalado")
    if not RE_ADMIN.match(str(opts.get("adminuser") or "")):
        raise HPError("Usuario administrador no válido")
    for k in ("adminpass", "adminemail"):
        v = str(opts.get(k) or "")
        if not v or "\n" in v or "\0" in v or len(v) > 255:
            raise HPError(f"Falta o no es válido: {k}")


# ---------------------------------------------------------------------- Moodle

def _moodle_check(user, domain, opts):
    _docroot(user, domain, "config.php", "admin/cli/install_database.php")
    if not RE_LANG.match(str(opts.get("lang") or "en")):
        raise HPError("Idioma no válido")
    for k in ("fullname", "shortname"):
        if not str(opts.get(k) or "").strip() or "\n" in str(opts.get(k)):
            raise HPError(f"Falta o no es válido: {k}")


def _moodle_run(user, domain, opts):
    base, root = _docroot(user, domain, "config.php", "admin/cli/install_database.php")
    php = f"/usr/bin/php{opts['php']}"
    step("Creando las tablas de Moodle e instalando el idioma (tarda unos minutos)", "running")
    rc, out = _as_user(user, [php, "-d", "max_input_vars=5000", "-d", "memory_limit=1024M",
                              f"{root}/admin/cli/install_database.php",
                              "--lang=" + str(opts.get("lang") or "en"),
                              "--adminuser=" + opts["adminuser"],
                              "--adminpass=" + opts["adminpass"],
                              "--adminemail=" + opts["adminemail"],
                              "--fullname=" + opts["fullname"],
                              "--shortname=" + opts["shortname"],
                              "--agree-license"], root, opts["adminpass"])
    if rc != 0:
        _fail_from(out, "Moodle")
    step("Base de datos de Moodle instalada")
    maint = f"{base}/private/moodledata/climaintenance.html"
    if os.path.lexists(maint):
        os.remove(maint)
    _cron(user, f"{php} {root}/admin/cli/cron.php >/dev/null 2>&1", f"{root}/admin/cli/cron.php", "Moodle")


def _cron(user, cmd, marker, name):
    try:
        current = hestia_json("v-list-cron-jobs", user) or {}
    except HPError:
        current = {}
    if any(marker in (j.get("CMD") or "") for j in current.values()):
        step(f"El cron de {name} ya estaba programado")
        return
    hestia("v-add-cron-job", user, "*", "*", "*", "*", "*", cmd)
    step(f"Cron de {name} programado cada minuto")


# ------------------------------------------------------------------ LimeSurvey

def _limesurvey_check(user, domain, opts):
    _docroot(user, domain, "application/config/config.php", "application/commands/console.php")
    if not str(opts.get("fullname") or "").strip():
        raise HPError("Falta el nombre del administrador")


def _limesurvey_run(user, domain, opts):
    base, root = _docroot(user, domain, "application/config/config.php", "application/commands/console.php")
    step("Creando las tablas de LimeSurvey y el administrador", "running")
    rc, out = _as_user(user, [f"/usr/bin/php{opts['php']}", "application/commands/console.php", "install",
                              opts["adminuser"], opts["adminpass"], opts["fullname"], opts["adminemail"], "verbose"],
                       root, opts["adminpass"], timeout=1800)
    if rc != 0 or "All done" not in out:
        _fail_from(out, "LimeSurvey")
    step("LimeSurvey instalado")


# ----------------------------------------------------------------------- phpBB

def _yaml_str(s):
    return json.dumps(str(s), ensure_ascii=False)  # una cadena JSON es YAML válido


def _phpbb_check(user, domain, opts):
    _docroot(user, domain, "install/phpbbcli.php")
    for k in ("board_name", "dbname", "dbuser", "dbpass"):
        if not str(opts.get(k) or "").strip() or "\n" in str(opts.get(k)):
            raise HPError(f"Falta o no es válido: {k}")


def _phpbb_run(user, domain, opts):
    base, root = _docroot(user, domain, "install/phpbbcli.php")
    https = bool(opts.get("https"))
    conf = f"""installer:
    admin:
        name: {_yaml_str(opts['adminuser'])}
        password: {_yaml_str(opts['adminpass'])}
        email: {_yaml_str(opts['adminemail'])}
    board:
        lang: en
        name: {_yaml_str(opts['board_name'])}
        description: {_yaml_str(opts.get('board_description') or '')}
    database:
        dbms: mysqli
        dbhost: {_yaml_str(opts.get('dbhost') or 'localhost')}
        dbport: ~
        dbuser: {_yaml_str(opts['dbuser'])}
        dbpasswd: {_yaml_str(opts['dbpass'])}
        dbname: {_yaml_str(opts['dbname'])}
        table_prefix: phpbb_
    email:
        enabled: false
        smtp_delivery: ~
        smtp_host: ~
        smtp_port: ~
        smtp_auth: ~
        smtp_user: ~
        smtp_pass: ~
    server:
        cookie_secure: {'true' if https else 'false'}
        server_protocol: {'https://' if https else 'http://'}
        force_server_vars: false
        server_name: {_yaml_str(domain)}
        server_port: {443 if https else 80}
        script_path: /
"""
    tmp = f"{home_of(user)}/tmp"
    path = f"{tmp}/hp-phpbb-{secrets.token_hex(8)}.yml"
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, "w") as fh:
        fh.write(conf)
        os.fchown(fh.fileno(), *_ids(user))
    try:
        step("Instalando phpBB (tablas, administrador y foro de ejemplo)", "running")
        rc, out = _as_user(user, [f"/usr/bin/php{opts['php']}", "install/phpbbcli.php", "install", path], root,
                           opts["adminpass"], timeout=1800)
    finally:
        os.remove(path)
    if rc != 0 or ("error" in out.lower() and "successfully" not in out.lower()):
        _fail_from(out, "phpBB")
    inst = f"{root}/install"
    if os.path.isdir(inst) and not os.path.islink(inst):
        shutil.rmtree(inst)
    step("phpBB instalado (se eliminó la carpeta install/)")


def _ids(user):
    pw = pwd.getpwnam(user)
    return pw.pw_uid, pw.pw_gid


# --------------------------------------------------------------------- general

APPS = {
    "moodle": ("Moodle", _moodle_check, _moodle_run),
    "limesurvey": ("LimeSurvey", _limesurvey_check, _limesurvey_run),
    "phpbb": ("phpBB", _phpbb_check, _phpbb_run),
}


def start(app, user, domain, b64):
    """Valida, guarda los datos (con contraseñas) en un archivo solo-root y lanza la tarea."""
    if app not in APPS:
        raise HPError("Aplicación no válida")
    check_web_domain(user, domain)
    opts = b64json(b64)
    _common_checks(opts)
    APPS[app][1](user, domain, opts)
    sid = _put_secret(opts)
    jid = jobs.start(user, f"Instalar {APPS[app][0]} en {domain}", ["webapp-run", app, user, domain, sid])
    return {"job": jid}


def run(app, user, domain, sid):
    if app not in APPS:
        raise HPError("Aplicación no válida")
    check_web_domain(user, domain)
    opts = _take_secret(sid)
    APPS[app][2](user, domain, opts)
    log_action(user, f"iaachz-panel: {APPS[app][0]} instalado en {domain}")
    return {"ok": True, "app": app, "domain": domain, "admin": opts.get("adminuser")}
