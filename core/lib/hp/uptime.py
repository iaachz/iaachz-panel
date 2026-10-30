"""Disponibilidad de cada sitio web: cuánto lleva en línea, si se cayó el puerto o la conexión, y el registro de incidentes.

Cada minuto (cron `v-hp uptime-check`) se sondea, desde el propio servidor:
  - el puerto de la aplicación (Node.js / Python / Docker / proxy), si el dominio tiene una;
  - una petición HTTP(S) real a nginx con el Host del dominio (así se comprueba toda la cadena nginx -> PHP-FPM / app);
  - la conexión a internet del servidor (varios destinos; basta con que responda uno).
Un sitio se da por caído tras 2 fallos seguidos (evita falsas alarmas) y el incidente se fecha desde el primer fallo.
El registro (`uptime.log`, una línea JSON por evento) guarda caídas, recuperaciones, cortes de internet y huecos en los que el
propio servidor o el panel no estuvieron funcionando.
"""

import json
import os
import socket
import ssl
import time
from concurrent.futures import ThreadPoolExecutor

from . import apps
from .common import DATA, ensure_dir, list_users, load_json, notify, save_json, settings, user_conf, web_domains
from .i18n import tr

STATE = DATA + "/uptime.json"
DAYS = DATA + "/uptime_days.json"
LOG = DATA + "/uptime.log"

FAILS_TO_DOWN = 2          # fallos seguidos para dar un sitio por caído
GAP_SECONDS = 150          # sin sondeos durante más de esto = el servidor o el panel estuvieron parados
KEEP_DAYS = 90
LOG_MAX_BYTES = 2_000_000
LOG_KEEP_LINES = 6000
CONNECT_TIMEOUT = 3
HTTP_TIMEOUT = 6
INTERNET_HOSTS = (("1.1.1.1", 443), ("8.8.8.8", 53), ("9.9.9.9", 443))
INTERNET = "_internet"


# ------------------------------------------------------------------ sondeo

def _tcp(host, port, timeout=CONNECT_TIMEOUT):
    """True si el puerto acepta conexiones."""
    try:
        with socket.create_connection((host, int(port)), timeout=timeout):
            return True
    except (OSError, ValueError):
        return False


def _http(domain, use_ssl):
    """Petición real a nginx local con el Host del dominio. Devuelve (código, ms, motivo)."""
    port = 443 if use_ssl else 80
    t0 = time.time()
    try:
        raw = socket.create_connection(("127.0.0.1", port), timeout=CONNECT_TIMEOUT)
    except ConnectionRefusedError:
        return 0, 0, "nginx"
    except OSError:
        return 0, 0, "timeout"
    try:
        raw.settimeout(HTTP_TIMEOUT)
        sock = raw
        if use_ssl:
            ctx = ssl.create_default_context()
            ctx.check_hostname = False
            ctx.verify_mode = ssl.CERT_NONE
            sock = ctx.wrap_socket(raw, server_hostname=domain)
        sock.sendall(f"GET / HTTP/1.1\r\nHost: {domain}\r\nUser-Agent: iaachz-panel-uptime\r\n"
                     f"Accept: */*\r\nConnection: close\r\n\r\n".encode())
        head = b""
        while b"\r\n" not in head and len(head) < 512:
            chunk = sock.recv(256)
            if not chunk:
                break
            head += chunk
        ms = int((time.time() - t0) * 1000)
        parts = head.split(b" ", 2)
        code = int(parts[1]) if len(parts) > 1 and parts[1].isdigit() else 0
        if not code:
            return 0, ms, "invalid"
        return code, ms, ("http%d" % code if code >= 500 else None)
    except ssl.SSLError:
        return 0, int((time.time() - t0) * 1000), "tls"
    except socket.timeout:
        return 0, int((time.time() - t0) * 1000), "timeout"
    except OSError:
        return 0, int((time.time() - t0) * 1000), "reset"
    finally:
        try:
            raw.close()
        except OSError:
            pass


def probe_site(t):
    """t = {domain, ssl, port, host}. Devuelve {ok, ms, code, reason}."""
    if t.get("port") and not _tcp(t.get("host") or "127.0.0.1", t["port"]):
        return {"ok": False, "ms": 0, "code": 0, "reason": "port"}
    code, ms, reason = _http(t["domain"], t["ssl"])
    return {"ok": reason is None, "ms": ms, "code": code, "reason": reason}


def probe_internet():
    with ThreadPoolExecutor(max_workers=len(INTERNET_HOSTS)) as ex:
        return any(ex.map(lambda hp: _tcp(hp[0], hp[1]), INTERNET_HOSTS))


def targets(users=None):
    """Sitios que se vigilan: dominios web con su puerto de aplicación si lo tienen."""
    out = []
    for u in (users if users is not None else list_users()):
        uapps = apps.load_apps(u)
        for d, w in web_domains(u).items():
            if w.get("SUSPENDED") == "yes":
                continue
            app = uapps.get(d) or {}
            if app and app.get("enabled") is False:
                continue
            port, host = None, None
            if app.get("type") in ("node", "python", "docker") and app.get("port"):
                port, host = app["port"], "127.0.0.1"
            elif app.get("type") == "proxy" and app.get("target"):
                hp = str(app["target"]).split("://", 1)[-1].split("/", 1)[0]
                if ":" in hp:
                    host, _, p = hp.rpartition(":")
                    port = p if p.isdigit() else None
                    host = host.strip("[]") if port else None
            out.append({"domain": d, "user": u, "ssl": w.get("SSL") == "yes", "port": port, "host": host})
    return out


# ------------------------------------------------------------------ registro

def _log(event):
    ensure_dir(DATA)
    try:
        if os.path.getsize(LOG) > LOG_MAX_BYTES:
            with open(LOG) as fh:
                lines = fh.readlines()[-LOG_KEEP_LINES:]
            with open(LOG, "w") as fh:
                fh.writelines(lines)
    except OSError:
        pass
    new = not os.path.exists(LOG)
    with open(LOG, "a") as fh:
        fh.write(json.dumps(event, ensure_ascii=False) + "\n")
    if new:
        os.chmod(LOG, 0o600)


def _read_log(limit=2000):
    try:
        with open(LOG, errors="replace") as fh:
            lines = fh.readlines()[-limit:]
    except FileNotFoundError:
        return []
    out = []
    for ln in lines:
        try:
            out.append(json.loads(ln))
        except ValueError:
            pass
    return out


def _fmt_duration(sec):
    sec = int(sec)
    d, h, m = sec // 86400, sec % 86400 // 3600, sec % 3600 // 60
    return f"{d} d {h} h" if d else (f"{h} h {m} min" if h else f"{max(m, 1)} min")


# ------------------------------------------------------------------ tick (cron)

def _apply(state, key, res, now, user, internet_ok, events, cfg):
    """Actualiza el estado de `key` con el resultado y anota las transiciones."""
    s = state.get(key) or {"state": "up", "since": now, "fails": 0, "first_fail": None}
    s["last"], s["ms"], s["code"] = now, res.get("ms", 0), res.get("code", 0)
    if res["ok"]:
        if s["state"] == "down":
            dur = now - s["since"]
            events.append({"ts": now, "domain": key, "user": user, "event": "up", "duration": dur,
                           "reason": s.get("reason"), "code": s.get("down_code", 0), "internet": internet_ok})
            s.update(state="up", since=now)
            _notify_up(key, dur, cfg, user)
        s.update(fails=0, first_fail=None, reason=None)
    else:
        s["fails"] = s.get("fails", 0) + 1
        s["first_fail"] = s.get("first_fail") or now
        s["reason"] = res.get("reason")
        if s["state"] != "down" and s["fails"] >= FAILS_TO_DOWN:
            s.update(state="down", since=s["first_fail"], down_code=res.get("code", 0))
            events.append({"ts": s["since"], "domain": key, "user": user, "event": "down",
                           "reason": s["reason"], "code": res.get("code", 0), "internet": internet_ok})
            _notify_down(key, s["reason"], cfg, user)
    state[key] = s


def _notify_down(key, reason, cfg, user):
    if not cfg.get("alert_uptime", True):
        return
    if key == INTERNET:
        subject, detail = tr("Sin conexión a internet"), tr("El servidor no puede llegar a internet.")
    else:
        subject = tr(f"Sitio caído: {key}")
        detail = tr(f"{key} no responde ({_reason_text(reason)}).")
    notify("admin", "⚠ " + subject, detail)
    if user and user != "admin":
        notify(user, "⚠ " + subject, detail)
    _mail(cfg, subject, detail)


def _notify_up(key, dur, cfg, user):
    if not cfg.get("alert_uptime", True):
        return
    if key == INTERNET:
        subject = tr(f"Conexión a internet recuperada tras {_fmt_duration(dur)}")
    else:
        subject = tr(f"Sitio recuperado: {key} (estuvo caído {_fmt_duration(dur)})")
    notify("admin", "✔ " + subject, "")
    if user and user != "admin":
        notify(user, "✔ " + subject, "")
    _mail(cfg, subject, subject)


def _reason_text(reason):
    if reason == "port":
        return tr("el puerto de la aplicación está cerrado")
    if reason == "nginx":
        return tr("nginx no acepta conexiones")
    if reason == "timeout":
        return tr("tiempo de espera agotado")
    if reason == "tls":
        return tr("error de TLS")
    if reason == "reset":
        return tr("conexión cortada")
    if reason == "invalid":
        return tr("respuesta no válida")
    if reason and reason.startswith("http"):
        return tr("error HTTP %s") .replace("%s", reason[4:])
    return tr("error")


def _mail(cfg, subject, body):
    email = cfg.get("alert_email") or user_conf("admin").get("CONTACT", "")
    if not email:
        return
    from .advisor import _send_mail
    _send_mail(email, f"[{os.uname().nodename}] {subject}", body)


def check():
    """Llamado por cron cada minuto."""
    now = int(time.time())
    cfg = settings()
    state = load_json(STATE, {})
    events = []

    last_run = state.get("_probe", {}).get("last", 0)
    if last_run and now - last_run > GAP_SECONDS:
        events.append({"ts": last_run, "domain": "*", "event": "gap", "duration": now - last_run})
        # tras un hueco no se sabe qué pasó en medio: los sitios caídos antes siguen contando desde su fallo
    state["_probe"] = {"last": now}

    internet_ok = probe_internet()
    _apply(state, INTERNET, {"ok": internet_ok, "reason": "no_internet"}, now, "", internet_ok, events, cfg)

    tg = targets()
    with ThreadPoolExecutor(max_workers=12) as ex:
        results = list(ex.map(probe_site, tg))

    days = load_json(DAYS, {})
    today = time.strftime("%Y-%m-%d")
    live = set()
    for t, res in zip(tg, results):
        d = t["domain"]
        live.add(d)
        _apply(state, d, res, now, t["user"], internet_ok, events, cfg)
        row = days.setdefault(d, {}).setdefault(today, [0, 0, 0])
        row[0] += 1
        row[1] += 1 if res["ok"] else 0
        row[2] += res.get("ms", 0) if res["ok"] else 0

    for k in [k for k in state if k not in live and k not in (INTERNET, "_probe")]:
        state.pop(k)
    cutoff = time.strftime("%Y-%m-%d", time.localtime(now - KEEP_DAYS * 86400))
    for d in list(days):
        if d not in live:
            days.pop(d)
            continue
        for day in [x for x in days[d] if x < cutoff]:
            days[d].pop(day)

    for e in sorted(events, key=lambda e: e["ts"]):
        _log(e)
    save_json(STATE, state)
    save_json(DAYS, days)
    return {"sites": len(tg), "down": sum(1 for t in tg if state.get(t["domain"], {}).get("state") == "down"),
            "internet": internet_ok}


# ------------------------------------------------------------------ lectura (panel)

def _pct(daymap, n_days):
    checks = ok = 0
    for i in range(n_days):
        row = daymap.get(time.strftime("%Y-%m-%d", time.localtime(time.time() - i * 86400)))
        if row:
            checks += row[0]
            ok += row[1]
    return round(100 * ok / checks, 3) if checks else None


def status(users):
    """Estado actual de los sitios de `users` (y de la conexión a internet del servidor)."""
    state = load_json(STATE, {})
    days = load_json(DAYS, {})
    now = int(time.time())
    last_down = {}
    for e in _read_log():
        if e.get("event") == "down":
            last_down[e["domain"]] = e
    sites = []
    for t in targets(users):
        d = t["domain"]
        s = state.get(d)
        dm = days.get(d, {})
        okrows = [r for r in dm.values() if r[1]]
        avg_ms = round(sum(r[2] for r in okrows) / max(sum(r[1] for r in okrows), 1)) if okrows else None
        sites.append({
            "domain": d, "user": t["user"], "port": t["port"],
            "state": (s or {}).get("state", "unknown"), "since": (s or {}).get("since"),
            "seconds": now - s["since"] if s else None,
            "reason": (s or {}).get("reason") if s and s.get("state") == "down" else None,
            "code": (s or {}).get("code"), "ms": (s or {}).get("ms"), "avg_ms": avg_ms,
            "today": _pct(dm, 1), "d7": _pct(dm, 7), "d30": _pct(dm, 30),
            "last_down": last_down.get(d, {}).get("ts"),
        })
    sites.sort(key=lambda x: (x["state"] != "down", x["domain"]))
    net = state.get(INTERNET) or {}
    return {"sites": sites, "now": now,
            "internet": {"state": net.get("state", "unknown"), "since": net.get("since"),
                         "seconds": now - net["since"] if net.get("since") else None},
            "last_check": state.get("_probe", {}).get("last")}


def log(users, domain="", limit=300, admin=False):
    """Registro de incidentes, el más reciente primero. `domain` vacío = todos los de `users`."""
    mine = {t["domain"] for t in targets(users)}
    if domain:
        mine = mine & {domain}
    state = load_json(STATE, {})
    out = []
    for e in reversed(_read_log(6000)):
        d = e.get("domain")
        if d in mine or (admin and not domain and d in ("*", INTERNET)):
            out.append(e)
            if len(out) >= max(1, min(int(limit), 1000)):
                break
    ongoing = [k for k, v in state.items() if k in mine and isinstance(v, dict) and v.get("state") == "down"]
    return {"events": out, "ongoing": ongoing}
