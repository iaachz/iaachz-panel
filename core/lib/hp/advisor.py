"""Asesor (como Plesk Advisor) y alertas del monitor (como Plesk Monitoring).

advisor()  -> lista de recomendaciones con gravedad y enlace para resolverlas.
check_alerts() -> cron: avisa en las notificaciones del panel (y por correo si se configura)
                  cuando CPU/RAM/disco superan umbrales, un servicio cae, una app falla o un SSL vence.
"""

import os
import subprocess
import time

from . import apps, metrics, sites
from .i18n import tr
from .common import DATA, hestia_conf, hestia_json, list_users, load_json, notify, run, save_json, settings, user_conf

ALERT_STATE = DATA + "/alerts_state.json"
REALERT = 6 * 3600


def advisor():
    items = []

    def add(level, title, detail, link=None, action=None):
        items.append({"level": level, "title": tr(title), "detail": tr(detail), "link": link, "action": action})

    conf = hestia_conf()
    live = metrics.live()
    for d in live["disks"]:
        if d["percent"] >= 90:
            add("danger", f"Disco {d['mount']} casi lleno ({d['percent']}%)", "Libera espacio o amplía el disco.", "/pro/monitor/")
        elif d["percent"] >= 80:
            add("warning", f"Disco {d['mount']} al {d['percent']}%", "Revisa copias de seguridad antiguas y registros.", "/list/backup/")
    if live["mem"]["swap_total"] == 0 and live["mem"]["total"] < 8 * 1024 ** 3:
        add("info", "Sin memoria swap", "Un archivo swap evita caídas por falta de RAM en picos de carga.")
    if live["mem"]["percent"] >= 90:
        add("danger", f"RAM al {live['mem']['percent']}%", "Revisa los procesos que más consumen.", "/pro/monitor/")

    svcs = metrics.services()
    down = [n for n, s in svcs.items() if s.get("STATE") != "running"]
    if down:
        add("danger", "Servicios detenidos: " + ", ".join(down), "Reinícialos desde Inicio → Servicios.", "/pro/#services")

    if not conf.get("FIREWALL_SYSTEM"):
        add("warning", "Cortafuegos de Hestia desactivado", "Actívalo para proteger SSH, FTP y el panel (incluye fail2ban).", "/list/firewall/")
    if conf.get("API_ALLOWED_IP", "") == "" and conf.get("API") == "yes":
        add("info", "API de Hestia abierta a cualquier IP", "Limita la API a IPs de confianza en Configuración del servidor.", "/edit/server/")
    if conf.get("BACKUP_SYSTEM") and not conf.get("BACKUP_REMOTE") and "local" == conf.get("BACKUP_SYSTEM"):
        add("info", "Copias solo locales", "Si el disco falla se pierden las copias. Configura un destino remoto (SFTP, S3, B2, Rclone).", "/edit/server/#backups")

    up = run(["bash", "-c", "apt list --upgradable 2>/dev/null | grep -c upgradable"], timeout=60).stdout.strip()
    if up.isdigit() and int(up) > 0:
        add("warning" if int(up) > 20 else "info", f"{up} paquetes con actualizaciones", "Aplica las actualizaciones del sistema (apt upgrade).", "/list/updates/")
    if os.path.exists("/var/run/reboot-required"):
        add("warning", "El servidor necesita reiniciarse", "Hay actualizaciones del kernel o librerías pendientes de aplicar.")

    now = time.time()
    for s in sites.list_sites(list_users()):
        if s.get("hosting") == "none" or s.get("suspended"):
            continue
        link = f"/pro/site/?user={s['user']}&domain={s['domain']}"
        if not s.get("ssl"):
            add("warning", f"{s['domain']} sin HTTPS", "Emite un certificado gratuito de Let's Encrypt.", link, "ssl")
        elif s.get("cert") and s["cert"]["days"] < 14:
            add("danger" if s["cert"]["days"] < 3 else "warning", f"SSL de {s['domain']} vence en {s['cert']['days']} días",
                "Renueva el certificado.", link, "ssl")
        elif not s.get("force_ssl"):
            add("info", f"{s['domain']} no fuerza HTTPS", "Redirige HTTP a HTTPS para proteger a los visitantes.", link)
        if s.get("app") and s["app"]["state"] in ("failed", "missing"):
            add("danger", f"La aplicación de {s['domain']} ha fallado", "Revisa los registros de la aplicación.", link)
        php = s.get("backend") or ""
        if php.startswith("PHP-") and php.replace("PHP-", "").replace("_", ".") in ("5.6", "7.0", "7.1", "7.2", "7.3", "7.4", "8.0", "8.1"):
            add("warning", f"{s['domain']} usa {s['php']} (sin soporte)", "Cambia a PHP 8.3 o superior.", link)
        if s.get("cms") == "WordPress":
            add("info", f"WordPress en {s['domain']}", "Mantén núcleo, plugins y temas actualizados.", link, "wp")
    order = {"danger": 0, "warning": 1, "info": 2}
    items.sort(key=lambda x: order.get(x["level"], 3))
    score = max(0, 100 - sum({"danger": 20, "warning": 7, "info": 2}.get(i["level"], 0) for i in items))
    return {"score": score, "items": items}


# ------------------------------------------------------------------- alertas

def _send_mail(to, subject, body):
    if not to:
        return
    try:
        msg = f"To: {to}\nSubject: {subject}\nContent-Type: text/plain; charset=utf-8\n\n{body}\n"
        subprocess.run(["/usr/sbin/sendmail", "-t"], input=msg, text=True, timeout=30)
    except Exception:
        pass


def check_alerts():
    cfg = settings()
    state = load_json(ALERT_STATE, {})
    active = {}
    host = os.uname().nodename

    def alert(key, title, detail):
        active[key] = {"title": tr(title), "detail": tr(detail)}

    cpu = metrics.recent_avg("cpu", 5)
    if cfg["alert_cpu"] and cpu is not None and cpu >= cfg["alert_cpu"]:
        alert("cpu", f"CPU alta: {cpu:.0f}%", f"La CPU promedió {cpu:.0f}% los últimos 5 minutos (umbral {cfg['alert_cpu']}%).")
    mem = metrics.recent_avg("mem", 5)
    if cfg["alert_mem"] and mem is not None and mem >= cfg["alert_mem"]:
        alert("mem", f"RAM alta: {mem:.0f}%", f"La memoria promedió {mem:.0f}% (umbral {cfg['alert_mem']}%).")
    if cfg["alert_load"]:
        la = os.getloadavg()[1]
        if la >= cfg["alert_load"]:
            alert("load", f"Carga alta: {la:.2f}", f"Carga media de 5 minutos {la:.2f} (umbral {cfg['alert_load']}).")
    for d in metrics._disks():
        if cfg["alert_disk"] and d["percent"] >= cfg["alert_disk"]:
            alert("disk:" + d["mount"], f"Disco {d['mount']} al {d['percent']}%", f"Quedan {d['free'] // 1024 ** 3} GB libres.")
    if cfg["alert_services"]:
        for name, s in hestia_json("v-list-sys-services").items():
            if s.get("STATE") != "running":
                alert("svc:" + name, f"Servicio {name} detenido", f"El servicio {name} ({s.get('SYSTEM')}) no está en marcha.")
    if cfg["alert_apps"]:
        for a in apps.list_all():
            if a.get("enabled", True) and a["state"] in ("failed", "missing", "stopped"):
                alert(f"app:{a['user']}:{a['domain']}", f"App de {a['domain']} caída",
                      f"La aplicación {a['type']} de {a['domain']} está en estado {a['state']}.")
    if cfg["alert_ssl_days"]:
        for s in sites.list_sites(list_users()):
            c = s.get("cert")
            if c and c["days"] < cfg["alert_ssl_days"]:
                alert("ssl:" + s["domain"], f"SSL de {s['domain']} vence en {c['days']} días",
                      f"El certificado ({c['issuer']}) vence pronto.")

    now = int(time.time())
    email = cfg.get("alert_email") or user_conf("admin").get("CONTACT", "")
    for key, a in active.items():
        prev = state.get(key)
        if not prev or now - prev.get("sent", 0) >= REALERT:
            notify("admin", "⚠ " + a["title"], a["detail"])
            _send_mail(email, f"[{host}] {a['title']}", a["detail"])
            state[key] = {"sent": now, "title": a["title"]}
    for key in list(state):
        if key not in active:
            notify("admin", tr("✔ Resuelto: %s").replace("%s", state[key]["title"]), tr("La condición volvió a la normalidad."))
            state.pop(key)
    save_json(ALERT_STATE, state)
    _cleanup_orphans()
    try:
        from . import apache, geo
        apache.sync()  # sigue cambios de versión de PHP, alias o dominios borrados
        try:
            geo.sync()  # reglas por país de dominios borrados o reconstruidos por Hestia
        except Exception:
            pass
    except Exception:
        pass
    try:
        from . import db, pma
        db.cleanup_uploads()
        pma.cleanup()
    except Exception:
        pass
    return {"active": list(active)}


def _cleanup_orphans():
    """Apps cuyo dominio se borró desde Hestia: detiene su servicio/contenedor."""
    for u in list_users():
        try:
            web = set(sites.web_domains(u))
            for d in list(apps.load_apps(u)):
                if d not in web:
                    apps.cleanup_domain(u, d)
        except Exception:
            pass
