"""nginx + Apache por dominio, como el "modo proxy" de Plesk.

Hestia solo permite elegir nginx o nginx+Apache para todo el servidor al instalarlo. Aquí Apache
escucha solo en 127.0.0.1:7080 y cada dominio decide:
  solo nginx    -> nginx ejecuta PHP-FPM directamente (lo normal en este servidor)
  nginx+Apache  -> nginx sirve los archivos estáticos (opcional) y pasa el resto a Apache,
                   que respeta .htaccess y ejecuta PHP con el MISMO pool PHP-FPM del dominio.
"""

import glob
import os
import re

from .common import (TPL_DIR, USERS_DIR, HPError, check_web_domain, domain_dir, hestia, home_of, list_users,
                     load_json, locked, log_action, now, run, save_json, web_domains)
from .jobs import step

PORT = 7080
TPL = "hp-apache"
SITES = "/etc/apache2/hestia-pro"
LOGS = "/var/log/apache2/domains"
STATIC_EXT = "css,js,map,jpg,jpeg,png,gif,webp,avif,svg,ico,bmp,woff,woff2,ttf,otf,eot,mp4,webm,ogg,mp3,wav,pdf,zip,gz,rar,7z,txt,xml,csv"
RE_EXT = re.compile(r"^[a-z0-9]{1,8}$")

MAIN_CONF = f"""# Hestia Pro: Apache detrás de nginx (generado por v-hp, no editar)
Listen 127.0.0.1:{PORT}
ServerTokens Prod
ServerSignature Off
RemoteIPHeader X-Real-IP
RemoteIPInternalProxy 127.0.0.1
SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on
<IfModule mpm_event_module>
	StartServers 2
	MinSpareThreads 10
	MaxSpareThreads 50
	ThreadsPerChild 25
	MaxRequestWorkers 150
</IfModule>
IncludeOptional {SITES}/*.conf
"""


def _path(user):
    return f"{USERS_DIR}/{user}/hp-apache.json"


def load(user):
    return load_json(_path(user), {})


def installed():
    return os.path.exists("/usr/sbin/apache2") and os.path.exists("/etc/apache2/conf-available/hestia-pro.conf")


def status():
    active = run(["systemctl", "is-active", "apache2"], timeout=10).stdout.strip() == "active" if installed() else False
    ver = ""
    if installed():
        m = re.search(r"Apache/([\d.]+)", run(["/usr/sbin/apache2", "-v"], timeout=10).stdout)
        ver = m.group(1) if m else ""
    domains = sum(len(load(u)) for u in list_users())
    return {"installed": installed(), "active": active, "version": ver, "port": PORT, "domains": domains,
            "static_ext": STATIC_EXT}


def install():
    """Instala Apache solo para localhost, con mpm_event + proxy_fcgi (sin mod_php)."""
    env = dict(os.environ, DEBIAN_FRONTEND="noninteractive")
    if not os.path.exists("/usr/sbin/apache2"):
        step("Instalando Apache 2", "running")
        run(["apt-get", "update", "-q"], env=env, timeout=600)
        p = run(["apt-get", "install", "-y", "-q", "--no-install-recommends", "apache2"], env=env, timeout=1800)
        print(p.stdout[-4000:] + p.stderr[-2000:])
        if p.returncode != 0:
            raise HPError("apt-get no pudo instalar Apache")
    step("Configurando Apache en 127.0.0.1:%d" % PORT, "running")
    # Ubuntu trae Listen 80/443: nginx ya los usa, así que Apache solo escucha en local
    with open("/etc/apache2/ports.conf", "w") as fh:
        fh.write("# Hestia Pro: Apache solo escucha en 127.0.0.1 (ver conf-available/hestia-pro.conf)\n")
    os.makedirs(SITES, mode=0o755, exist_ok=True)
    os.makedirs(LOGS, mode=0o750, exist_ok=True)
    with open("/etc/apache2/conf-available/hestia-pro.conf", "w") as fh:
        fh.write(MAIN_CONF)
    for mod in ("php8.3", "php8.4", "php8.5", "mpm_prefork"):
        run(["a2dismod", "-q", mod], timeout=30)
    for mod in ("mpm_event", "proxy", "proxy_fcgi", "setenvif", "rewrite", "headers", "remoteip", "expires", "deflate", "alias"):
        run(["a2enmod", "-q", mod], timeout=30)
    run(["a2dissite", "-q", "000-default"], timeout=30)
    run(["a2enconf", "-q", "hestia-pro"], timeout=30)
    _test_and_reload(restart=True)
    run(["systemctl", "enable", "apache2"], timeout=60)
    step("Apache listo (solo accesible desde nginx)")
    return status()


def _test_and_reload(restart=False):
    p = run(["/usr/sbin/apache2ctl", "configtest"], timeout=30)
    if p.returncode != 0:
        raise HPError("La configuración de Apache no es válida: " + (p.stderr or p.stdout).strip()[-800:])
    run(["systemctl", "restart" if restart else "reload", "apache2"], timeout=90)


def _php_socket(domain):
    """Socket del pool PHP-FPM que Hestia creó para el dominio (cambia con la versión de PHP)."""
    for pool in sorted(glob.glob(f"/etc/php/*/fpm/pool.d/{domain}.conf")):
        with open(pool) as fh:
            m = re.search(r"^\s*listen\s*=\s*(\S+)", fh.read(), re.M)
        if m and m.group(1).startswith("/"):
            return m.group(1)
    return None


def _docroot(user, domain, web):
    return (web.get("CUSTOM_DOCROOT") or f"{domain_dir(user, domain)}/public_html").rstrip("/")


def _vhost(user, domain, web):
    aliases = " ".join(a for a in (web.get("ALIAS") or "").split(",") if a)
    docroot = _docroot(user, domain, web)
    sock = _php_socket(domain)
    php = (f'\t<FilesMatch "\\.(php|phtml)$">\n\t\tSetHandler "proxy:unix:{sock}|fcgi://localhost"\n\t</FilesMatch>\n'
           if sock else "\t# este dominio no tiene PHP\n")
    text = f"""# Hestia Pro: {domain} ({user}) — generado por v-hp, no editar
<VirtualHost 127.0.0.1:{PORT}>
	ServerName {domain}
	{('ServerAlias ' + aliases) if aliases else ''}
	DocumentRoot "{docroot}"
	DirectoryIndex index.php index.html index.htm
	ErrorLog {LOGS}/{domain}.error.log
	CustomLog /dev/null common
	<Directory "{docroot}">
		AllowOverride All
		Options +SymLinksIfOwnerMatch -Indexes
		Require all granted
	</Directory>
	<DirectoryMatch "/\\.(?!well-known)">
		Require all denied
	</DirectoryMatch>
{php}</VirtualHost>
"""
    path = f"{SITES}/{user}--{domain}.conf"
    with open(path, "w") as fh:
        fh.write(text)
    os.chmod(path, 0o644)


def _nginx_conf(user, domain, cfg):
    up = f"http://127.0.0.1:{PORT}"
    headers = """\t\tproxy_set_header Host $host;
\t\tproxy_set_header X-Real-IP $remote_addr;
\t\tproxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
\t\tproxy_set_header X-Forwarded-Proto $scheme;
\t\tproxy_set_header X-Forwarded-Port $server_port;
\t\tproxy_read_timeout 300s;
\t\tproxy_buffers 16 32k;
\t\tproxy_buffer_size 64k;
\t\tproxy_busy_buffers_size 64k;"""
    static = ""
    if cfg.get("static", True):
        exts = "|".join(cfg.get("ext", STATIC_EXT).split(","))
        static = f"""
\t\t# archivos estáticos directamente desde nginx (más rápido); si no existen, los resuelve Apache
\t\tlocation ~* \\.({exts})$ {{
\t\t\ttry_files $uri @hp_apache;
\t\t\texpires 7d;
\t\t\taccess_log off;
\t\t}}
"""
    text = f"""# Hestia Pro: nginx + Apache para {domain} — generado por v-hp, no editar
\tlocation / {{
\t\tproxy_pass {up};
{headers}
{static}\t}}

\tlocation @hp_apache {{
\t\tproxy_pass {up};
{headers}
\t}}
"""
    path = f"{home_of(user)}/conf/web/{domain}/hp-apache.conf"
    with open(path, "w") as fh:
        fh.write(text)
    os.chmod(path, 0o644)


def _clean_ext(ext):
    items = [e.strip().lower().lstrip(".") for e in (ext or STATIC_EXT).split(",") if e.strip()]
    for e in items:
        if not RE_EXT.match(e) or e in ("php", "phtml", "php5", "phar", "htaccess"):
            raise HPError(f"Extensión no permitida para servir como estática: {e}")
    return ",".join(dict.fromkeys(items))


def get(user, domain):
    check_web_domain(user, domain)
    cfg = load(user).get(domain)
    return {"enabled": bool(cfg), "static": (cfg or {}).get("static", True),
            "ext": (cfg or {}).get("ext", STATIC_EXT), "apache": status()}


def enable(user, domain, static=True, ext=None):
    """Pone el dominio en modo nginx + Apache. Se ejecuta como tarea (puede instalar Apache)."""
    web = check_web_domain(user, domain)
    from . import apps
    if domain in apps.load_apps(user):
        raise HPError("Este dominio sirve una aplicación Node.js/Python/Docker: quítala antes de usar Apache")
    ext = _clean_ext(ext)
    if not installed():
        install()
    step(f"Configurando {domain} con nginx + Apache", "running")
    with locked("apache-" + user):
        data = load(user)
        old = data.get(domain, {})
        cfg = {"static": bool(static), "ext": ext, "since": old.get("since") or now(),
               "prev_tpl": old.get("prev_tpl") or (web.get("TPL") if web.get("TPL") not in (TPL, "hp-app") else "default")}
        data[domain] = cfg
        save_json(_path(user), data, 0o660)
    _vhost(user, domain, web)
    _test_and_reload()
    _nginx_conf(user, domain, cfg)
    # nginx debe aceptar la configuración; si no, se deshace todo para no dejarlo sin poder recargar
    p = run(["nginx", "-t"], timeout=30)
    if p.returncode != 0:
        with locked("apache-" + user):
            data = load(user)
            data.pop(domain, None)
            save_json(_path(user), data, 0o660)
        _teardown(user, domain)
        if web.get("TPL") == TPL:
            hestia("v-change-web-domain-tpl", user, domain, cfg["prev_tpl"], "yes", check=False)
        raise HPError("nginx rechazó la configuración y se revirtió: " + p.stderr.strip()[-600:])
    if web.get("TPL") != TPL:
        hestia("v-change-web-domain-tpl", user, domain, TPL, "yes")
    else:
        hestia("v-restart-web", check=False)
    log_action(user, f"Hestia Pro: {domain} ahora usa nginx + Apache (.htaccess)")
    step("Listo: nginx " + ("sirve los estáticos y " if static else "") + "pasa el resto a Apache")
    return get(user, domain)


def disable(user, domain, restore_tpl=True):
    """Vuelve a solo nginx (PHP-FPM directo) con la plantilla que tenía antes."""
    check_web_domain(user, domain)
    with locked("apache-" + user):
        data = load(user)
        cfg = data.pop(domain, None)
        if cfg is None:
            raise HPError("Este dominio ya usa solo nginx")
        save_json(_path(user), data, 0o660)
    _teardown(user, domain)
    if restore_tpl:
        hestia("v-change-web-domain-tpl", user, domain, cfg.get("prev_tpl") or "default", "yes", check=False)
    log_action(user, f"Hestia Pro: {domain} vuelve a usar solo nginx")
    return get(user, domain)


def _teardown(user, domain):
    for p in (f"{SITES}/{user}--{domain}.conf",):
        if os.path.exists(p):
            os.remove(p)
            if installed():
                _test_and_reload()
    try:
        conf = f"{home_of(user)}/conf/web/{domain}/hp-apache.conf"
        if os.path.exists(conf):
            os.remove(conf)
    except KeyError:
        pass


def sync():
    """Regenera los vhost (cambio de versión de PHP, alias o raíz; dominios borrados). Lo llama el cron."""
    changed = False
    seen = set()
    for u in list_users():
        data = load(u)
        if not data:
            continue
        web = web_domains(u)
        for d in list(data):
            if d not in web:
                data.pop(d)
                save_json(_path(u), data, 0o660)
                _teardown(u, d)
                continue
            path = f"{SITES}/{u}--{d}.conf"
            seen.add(path)
            before = open(path).read() if os.path.exists(path) else ""
            _vhost(u, d, web[d])
            if open(path).read() != before:
                changed = True
    if os.path.isdir(SITES):
        for f in glob.glob(f"{SITES}/*.conf"):
            if f not in seen:
                os.remove(f)
                changed = True
    if changed and installed():
        _test_and_reload()
    return {"changed": changed}
