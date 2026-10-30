"""Sitios web y dominios al estilo Plesk: listado enriquecido, asistente de creación y WordPress."""

import json
import os
import re
import secrets
import socket
import ssl
import string
import time

from . import apache, apps, git
from .common import (USERS_DIR, HPError, check_domain_fmt, check_user, check_web_domain, domain_dir,
                     hestia, hestia_conf, hestia_json, home_of, list_users, log_action, read_conf, run,
                     user_conf, web_domains)
from .jobs import step

RE_PKG = re.compile(r"^[A-Za-z0-9_.-]{1,64}$")
RE_DBNAME = re.compile(r"^[A-Za-z0-9_]{1,40}$")
RE_PHP = re.compile(r"^(PHP-\d+_\d+|default|no-php|socket)$")


def _cert_expiry(user, domain):
    path = f"{USERS_DIR}/{user}/ssl/{domain}.crt"
    if not os.path.exists(path):
        return None
    try:
        info = ssl._ssl._test_decode_cert(path)
        exp = ssl.cert_time_to_seconds(info["notAfter"])
        issuer = dict(x[0] for x in info.get("issuer", ()))
        return {"expires": int(exp), "days": int((exp - time.time()) // 86400),
                "issuer": issuer.get("organizationName") or issuer.get("commonName", "")}
    except Exception:
        return None


def _detect_cms(user, domain, web):
    docroot = web.get("CUSTOM_DOCROOT") or f"{domain_dir(user, domain)}/public_html"
    base = (web.get("CUSTOM_PHPROOT") or docroot).rstrip("/")
    checks = [("wp-config.php", "WordPress"), ("wp-includes/version.php", "WordPress"),
              ("lib/moodlelib.php", "Moodle"), ("../lib/moodlelib.php", "Moodle"),
              ("libraries/src/Version.php", "Joomla"), ("core/lib/Drupal.php", "Drupal"),
              ("artisan", "Laravel"), ("../artisan", "Laravel"), ("bin/console", "Symfony"),
              ("app/etc/env.php", "Magento"), ("config/settings.inc.php", "PrestaShop")]
    for rel, name in checks:
        if os.path.exists(os.path.join(base, rel)):
            return name
    return None


def _wp_version(user, domain, web):
    docroot = web.get("CUSTOM_DOCROOT") or f"{domain_dir(user, domain)}/public_html"
    try:
        with open(os.path.join(docroot, "wp-includes/version.php")) as fh:
            m = re.search(r"\$wp_version\s*=\s*'([^']+)'", fh.read())
            return m.group(1) if m else None
    except OSError:
        return None


def _php_label(backend):
    if not backend or backend == "default":
        return "PHP (predeterminado)"
    m = re.match(r"PHP-(\d+)_(\d+)", backend)
    if m:
        return f"PHP {m.group(1)}.{m.group(2)}"
    return "Sin PHP" if backend == "no-php" else backend


def list_sites(users):
    res = []
    for u in users:
        uconf = user_conf(u)
        dns = {d["DOMAIN"]: d for d in read_conf(f"{USERS_DIR}/{u}/dns.conf") if "DOMAIN" in d}
        mail = {d["DOMAIN"]: d for d in read_conf(f"{USERS_DIR}/{u}/mail.conf") if "DOMAIN" in d}
        uapps = apps.load_apps(u)
        ugit = git.load(u)
        uapache = apache.load(u)
        web = web_domains(u)
        for d, w in web.items():
            app = uapps.get(d)
            item = {
                "domain": d, "user": u, "package": uconf.get("PACKAGE"), "ip": w.get("IP"),
                "aliases": [a for a in (w.get("ALIAS") or "").split(",") if a],
                "tpl": w.get("TPL"), "backend": w.get("BACKEND"), "php": _php_label(w.get("BACKEND")),
                "ssl": w.get("SSL") == "yes", "letsencrypt": w.get("LETSENCRYPT") == "yes",
                "force_ssl": w.get("SSL_FORCE") == "yes", "hsts": w.get("SSL_HSTS") == "yes",
                "cert": _cert_expiry(u, d) if w.get("SSL") == "yes" else None,
                "disk": int(w.get("U_DISK") or 0), "bandwidth": int(w.get("U_BANDWIDTH") or 0),
                "suspended": w.get("SUSPENDED") == "yes", "created": f"{w.get('DATE', '')} {w.get('TIME', '')}",
                "stats": w.get("STATS") or "", "docroot": w.get("CUSTOM_DOCROOT") or "",
                "dns": bool(dns.get(d)), "dns_records": int((dns.get(d) or {}).get("RECORDS") or 0),
                "mail": bool(mail.get(d)), "mail_accounts": int((mail.get(d) or {}).get("ACCOUNTS") or 0),
                "app": None, "git": bool(ugit.get(d)), "cms": None,
                "webserver": "apache" if d in uapache else "nginx",
                "apache_static": (uapache.get(d) or {}).get("static", True),
            }
            if app:
                item["app"] = dict({k: app.get(k) for k in ("type", "version", "port", "enabled", "image", "target")},
                                   **apps.status_of(u, d, app))
                item["hosting"] = app["type"]
            elif w.get("BACKEND") == "no-php" or w.get("TPL") == "no-php":
                item["hosting"] = "static"
            else:
                item["hosting"] = "php"
                item["cms"] = _detect_cms(u, d, w)
                if item["cms"] == "WordPress":
                    item["wp_version"] = _wp_version(u, d, w)
            res.append(item)
        # dominios solo DNS / solo correo (sin web) también aparecen, como en Plesk
        for d in sorted(set(dns) | set(mail)):
            if d not in web:
                res.append({"domain": d, "user": u, "package": uconf.get("PACKAGE"), "hosting": "none",
                            "dns": d in dns, "dns_records": int((dns.get(d) or {}).get("RECORDS") or 0),
                            "mail": d in mail, "mail_accounts": int((mail.get(d) or {}).get("ACCOUNTS") or 0),
                            "aliases": [], "ssl": False, "suspended": (dns.get(d) or mail.get(d) or {}).get("SUSPENDED") == "yes",
                            "disk": 0, "bandwidth": 0, "app": None, "git": False})
    return res


def server_ips():
    data = hestia_json("v-list-sys-ips")
    ips = set(data.keys())
    ips |= {v.get("NAT") for v in data.values() if v.get("NAT")}
    return ips


def resolve(domain):
    try:
        return sorted({ai[4][0] for ai in socket.getaddrinfo(domain, None, socket.AF_INET)})
    except socket.gaierror:
        return []


def dns_check(domain):
    domain = check_domain_fmt(domain)
    ips = server_ips()
    a = resolve(domain)
    www = resolve("www." + domain)
    return {"domain": domain, "a": a, "www": www, "server_ips": sorted(ips),
            "points_here": bool(a) and all(x in ips for x in a),
            "www_points_here": bool(www) and all(x in ips for x in www)}


def _pick_ip(user):
    data = hestia_json("v-list-sys-ips")
    for ip, v in data.items():
        if v.get("STATUS") == "shared" or v.get("OWNER") == user:
            return ip
    if data:
        return next(iter(data))
    raise HPError("El servidor no tiene IPs configuradas en Hestia")


def _password(n=16):
    alphabet = string.ascii_letters + string.digits
    while True:
        pw = "".join(secrets.choice(alphabet) for _ in range(n))
        if any(c.islower() for c in pw) and any(c.isupper() for c in pw) and any(c.isdigit() for c in pw):
            return pw


def domain_owner(domain):
    """Usuario que ya tiene el dominio (web, DNS o correo), o None."""
    for u in list_users():
        for kind in ("web", "dns", "mail"):
            if any(d.get("DOMAIN") == domain for d in read_conf(f"{USERS_DIR}/{u}/{kind}.conf")):
                return u
    return None


def precheck(params):
    """Valida el asistente antes de crear nada (así un error no deja una suscripción a medias)."""
    domain = check_domain_fmt(params.get("domain"))
    owner = domain_owner(domain)
    if owner:
        raise HPError(f"El dominio {domain} ya existe en la suscripción {owner}")
    nu = params.get("new_user")
    target = (nu or {}).get("username") or params.get("user")
    if not nu:
        check_user(target)
    elif os.path.exists(f"{USERS_DIR}/{nu.get('username')}"):
        raise HPError(f"El usuario {nu.get('username')} ya existe")
    if hestia_conf().get("ENFORCE_SUBDOMAIN_OWNERSHIP", "yes") == "yes":
        parts = domain.split(".")
        for i in range(1, len(parts) - 1):
            parent = ".".join(parts[i:])
            p_owner = domain_owner(parent)
            if p_owner and p_owner != target:
                raise HPError(f"{domain} es un subdominio de {parent}, que pertenece a la suscripción {p_owner}. "
                              f"Créalo dentro de esa suscripción (o desactiva ENFORCE_SUBDOMAIN_OWNERSHIP).")
    return domain


def create(params):
    """Asistente: (suscripción nueva) + dominio + DNS + correo + hosting + BD + SSL. Corre como tarea."""
    precheck(params)
    summary = {"warnings": [], "created": []}
    nu = params.get("new_user")
    if nu:
        username = (nu.get("username") or "").strip()
        if not re.match(r"^[a-z][a-z0-9_-]{1,27}$", username):
            raise HPError("Nombre de usuario no válido (minúsculas, números, - y _; empieza por letra)")
        if os.path.exists(f"{USERS_DIR}/{username}"):
            raise HPError(f"El usuario {username} ya existe")
        pkg = nu.get("package") or "default"
        if not RE_PKG.match(pkg):
            raise HPError("Plan no válido")
        email = (nu.get("email") or "").strip()
        if not re.match(r"^[^@\s]+@[^@\s]+\.[^@\s]+$", email):
            raise HPError("Correo de contacto no válido")
        pw = nu.get("password") or _password()
        step(f"Creando suscripción (usuario {username}, plan {pkg})", "running")
        hestia("v-add-user", username, pw, email, pkg, nu.get("name") or username)
        summary["created"].append(f"usuario {username}")
        summary["user_password"] = pw if not nu.get("password") else None
        step(f"Usuario {username} creado")
        user = username
    else:
        user = check_user(params.get("user"))

    domain = check_domain_fmt(params.get("domain"))
    hosting = params.get("hosting") or "php"
    if hosting not in ("php", "static", "wordpress", "node", "python", "docker", "proxy", "none"):
        raise HPError("Tipo de hosting no válido")
    ip = _pick_ip(user)
    aliases = []
    if params.get("www", True):
        aliases.append("www." + domain)
    for a in re.split(r"[\s,]+", params.get("aliases") or ""):
        if a:
            aliases.append(check_domain_fmt(a))

    if hosting != "none":
        step(f"Creando sitio web {domain}", "running")
        hestia("v-add-web-domain", user, domain, ip, "no", ",".join(aliases) if aliases else "none")
        summary["created"].append("sitio web")
        step(f"Sitio web {domain} creado")
        php = params.get("php") or ""
        if hosting == "static":
            hestia("v-change-web-domain-backend-tpl", user, domain, "no-php", "no", check=False)
            hestia("v-change-web-domain-tpl", user, domain, "no-php", "no", check=False)
        elif php and RE_PHP.match(php) and php != "default":
            step(f"Configurando {php.replace('PHP-', 'PHP ').replace('_', '.')}", "running")
            hestia("v-change-web-domain-backend-tpl", user, domain, php, "no")
            step("Versión de PHP configurada")

    if params.get("dns"):
        step("Creando zona DNS", "running")
        p = hestia("v-add-dns-domain", user, domain, ip, check=False)
        if p.returncode == 0:
            summary["created"].append("zona DNS")
            step("Zona DNS creada")
        else:
            step("Zona DNS", "warning", (p.stdout + p.stderr).strip()[-300:])
            summary["warnings"].append("No se creó la zona DNS")

    if params.get("mail"):
        step("Activando correo para el dominio", "running")
        p = hestia("v-add-mail-domain", user, domain, check=False)
        if p.returncode == 0:
            summary["created"].append("correo")
            step("Dominio de correo creado (con DKIM)")
        else:
            step("Correo", "warning", (p.stdout + p.stderr).strip()[-300:])

    db = params.get("db") or None
    if hosting == "wordpress" and not db:
        db = {"name": "wp" + secrets.token_hex(2), "user": "wp" + secrets.token_hex(2)}
    if db:
        dbname = (db.get("name") or "").strip()
        dbuser = (db.get("user") or dbname).strip()
        if not RE_DBNAME.match(dbname) or not RE_DBNAME.match(dbuser):
            raise HPError("Nombre de base de datos/usuario no válido (letras, números y _)")
        dbpass = db.get("password") or _password(20)
        step(f"Creando base de datos {user}_{dbname}", "running")
        hestia("v-add-database", user, dbname, dbuser, dbpass, "mysql")
        db = {"name": f"{user}_{dbname}", "user": f"{user}_{dbuser}", "password": dbpass}
        summary["database"] = {"name": db["name"], "user": db["user"],
                               "password": dbpass if not params.get("db", {}).get("password") else None}
        step("Base de datos creada")

    if hosting in ("node", "python", "docker", "proxy"):
        cfg = dict(params.get("app") or {}, type=hosting)
        step(f"Configurando aplicación {hosting}", "running")
        apps.save(user, domain, cfg)
        apps.apply_runtime(user, domain)
        step("Aplicación configurada y en marcha")
        summary["created"].append(f"aplicación {hosting}")

    if hosting == "wordpress":
        wp = params.get("wordpress") or {}
        wp_install(user, domain, db, wp, summary)

    # recargar antes del SSL: Let's Encrypt valida contra el nginx y PHP-FPM ya activos
    step("Aplicando la configuración (nginx, PHP-FPM, DNS)", "running")
    hestia("v-restart-web-backend", check=False)
    hestia("v-restart-web", check=False)
    if params.get("dns"):
        hestia("v-restart-dns", check=False)
    step("Servicios recargados")

    if params.get("webserver") == "apache" and hosting in ("php", "wordpress", "static"):
        apache.enable(user, domain, static=params.get("apache_static", True) is not False)
        summary["created"].append("nginx + Apache")

    if params.get("ssl") and hosting != "none":
        chk = dns_check(domain)
        if not chk["points_here"]:
            where = f"apunta a {', '.join(chk['a'])}" if chk["a"] else "todavía no tiene registro A"
            msg = (f"SSL pendiente: {domain} {where} y debe apuntar a {', '.join(chk['server_ips'])}. "
                   "Cuando el DNS esté listo, emítelo desde la pestaña SSL/TLS del dominio.")
            step("Certificado SSL (Let's Encrypt)", "warning", msg)
            summary["warnings"].append(msg)
        else:
            step("Solicitando certificado Let's Encrypt", "running")
            ips = chk["server_ips"]
            le_aliases = [a for a in aliases if resolve(a) and all(x in ips for x in resolve(a))]
            p = hestia("v-add-letsencrypt-domain", user, domain, ",".join(le_aliases), check=False, timeout=600)
            if p.returncode == 0:
                step("Certificado SSL emitido")
                if params.get("force_ssl", True):
                    hestia("v-add-web-domain-ssl-force", user, domain, "yes", check=False)
                    hestia("v-add-web-domain-ssl-hsts", user, domain, "yes", check=False)
                summary["created"].append("SSL Let's Encrypt")
            else:
                msg = "Let's Encrypt falló: " + (p.stdout + p.stderr).strip()[-400:]
                step("Certificado SSL (Let's Encrypt)", "warning", msg)
                summary["warnings"].append(msg)

    log_action(user, f"iaachz-panel: dominio {domain} creado con el asistente ({hosting})")
    summary.update({"user": user, "domain": domain, "hosting": hosting})
    return summary


# ------------------------------------------------------------------- WordPress

def _php_bin(user, domain):
    web = web_domains(user).get(domain, {})
    m = re.match(r"PHP-(\d+)_(\d+)", web.get("BACKEND") or "")
    if m and os.path.exists(f"/usr/bin/php{m.group(1)}.{m.group(2)}"):
        return f"/usr/bin/php{m.group(1)}.{m.group(2)}"
    return "/usr/bin/php"


def _wp(user, domain, args, timeout=900):
    wpbin = f"{home_of(user)}/.wp-cli/wp"
    if not os.path.exists(wpbin):
        hestia("v-add-user-wp-cli", user, check=False)
    if not os.path.exists(wpbin):
        raise HPError("No se pudo instalar wp-cli")
    web = web_domains(user)[domain]
    docroot = web.get("CUSTOM_DOCROOT") or f"{domain_dir(user, domain)}/public_html"
    env = {"HOME": home_of(user), "PATH": "/usr/local/bin:/usr/bin:/bin", "LANG": "C.UTF-8"}
    p = run([_php_bin(user, domain), "-d", "memory_limit=512M", wpbin, f"--path={docroot}"] + args,
            user=user, env=env, timeout=timeout)
    if os.environ.get("HP_JOB"):  # en tareas, la salida va al registro; en consultas ensuciaría el JSON
        print((p.stdout + p.stderr)[-3000:])
    if p.returncode != 0:
        raise HPError("wp " + args[0] + " falló: " + (p.stderr or p.stdout).strip()[-500:])
    return p.stdout


def wp_install(user, domain, db, wp, summary):
    web = web_domains(user)[domain]
    docroot = web.get("CUSTOM_DOCROOT") or f"{domain_dir(user, domain)}/public_html"
    step("Descargando WordPress", "running")
    for f in ("index.html", "robots.txt"):
        if os.path.exists(f"{docroot}/{f}"):
            os.remove(f"{docroot}/{f}")
    locale = wp.get("locale") or "es_ES"
    try:
        _wp(user, domain, ["core", "download", f"--locale={locale}", "--force"])
        locale_later = None
    except HPError:
        # la traducción de la versión más reciente puede tardar unos días en publicarse
        step(f"Paquete {locale} aún no disponible: se descarga en inglés y se traduce después", "warning")
        _wp(user, domain, ["core", "download", "--force"])
        locale_later = locale if locale != "en_US" else None
    step("Configurando wp-config.php", "running")
    _wp(user, domain, ["config", "create", f"--dbname={db['name']}", f"--dbuser={db['user']}",
                       f"--dbpass={db['password']}", "--dbhost=localhost", "--force"])
    admin_user = re.sub(r"[^A-Za-z0-9_.@-]", "", wp.get("admin_user") or "admin") or "admin"
    admin_pass = wp.get("admin_password") or _password(16)
    admin_mail = wp.get("admin_email") or user_conf(user).get("CONTACT") or f"admin@{domain}"
    scheme = "https" if params_ssl(user, domain) else "http"
    step("Instalando WordPress", "running")
    _wp(user, domain, ["core", "install", f"--url={scheme}://{domain}", f"--title={wp.get('title') or domain}",
                       f"--admin_user={admin_user}", f"--admin_password={admin_pass}",
                       f"--admin_email={admin_mail}", "--skip-email"])
    if locale_later:
        try:
            _wp(user, domain, ["language", "core", "install", locale_later, "--activate"])
        except HPError:
            step(f"No se pudo instalar el idioma {locale_later}; puedes cambiarlo en Ajustes → Generales", "warning")
    hestia("v-change-web-domain-tpl", user, domain, "wordpress", "no", check=False)
    step("WordPress instalado")
    summary["wordpress"] = {"url": f"{scheme}://{domain}/wp-admin/", "user": admin_user,
                            "password": admin_pass if not wp.get("admin_password") else None}


def params_ssl(user, domain):
    return web_domains(user).get(domain, {}).get("SSL") == "yes"


def wp_info(user, domain):
    web = check_web_domain(user, domain)
    if not _wp_version(user, domain, web):
        raise HPError("No se detectó WordPress en este dominio")
    info = {"version": _wp_version(user, domain, web)}
    try:
        info["core_update"] = json.loads(_wp(user, domain, ["core", "check-update", "--format=json"], 120) or "[]")
        info["plugins"] = json.loads(_wp(user, domain, ["plugin", "list", "--format=json"], 120) or "[]")
        info["themes"] = json.loads(_wp(user, domain, ["theme", "list", "--format=json"], 120) or "[]")
    except (ValueError, HPError) as e:
        info["error"] = str(e)
    return info


def wp_update(user, domain):
    check_web_domain(user, domain)
    step("Actualizando el núcleo de WordPress", "running")
    _wp(user, domain, ["core", "update"])
    _wp(user, domain, ["core", "update-db"])
    step("Actualizando plugins", "running")
    _wp(user, domain, ["plugin", "update", "--all"])
    step("Actualizando temas", "running")
    _wp(user, domain, ["theme", "update", "--all"])
    step("WordPress actualizado")
    log_action(user, f"iaachz-panel: WordPress actualizado en {domain}")
    return {"ok": True}


def ssl_issue(user, domain):
    web = check_web_domain(user, domain)
    chk = dns_check(domain)
    if not chk["points_here"]:
        where = f"apunta a {', '.join(chk['a'])}" if chk["a"] else "no tiene registro A"
        raise HPError(f"{domain} {where}; debe apuntar a este servidor ({', '.join(chk['server_ips'])}). "
                      "Corrige el registro A en tu proveedor de DNS y vuelve a intentarlo.")
    aliases = [a for a in (web.get("ALIAS") or "").split(",") if a]
    ok_aliases = [a for a in aliases if resolve(a) and all(x in chk["server_ips"] for x in resolve(a))]
    skipped = sorted(set(aliases) - set(ok_aliases))
    step("Solicitando certificado Let's Encrypt", "running")
    if skipped:
        step("Alias omitidos (no apuntan aquí): " + ", ".join(skipped), "warning")
    hestia("v-add-letsencrypt-domain", user, domain, ",".join(ok_aliases), timeout=600)
    hestia("v-add-web-domain-ssl-force", user, domain, "yes", check=False)
    step("Certificado emitido y HTTPS forzado")
    return {"ok": True, "skipped_aliases": skipped}
