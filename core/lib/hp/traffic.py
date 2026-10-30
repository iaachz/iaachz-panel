"""Tráfico web y conexiones: lo que Hestia no mide.

- nginx stub_status (127.0.0.1:8084, ya lo trae Hestia): peticiones/s, conexiones nuevas/s y activas.
- /proc/net/snmp: conexiones TCP establecidas y nuevas entrantes/s (todos los servicios).
- MySQL/MariaDB: consultas/s y conexiones abiertas.
- Registros de acceso de cada dominio (/var/log/nginx/domains/*.log): se lee SOLO lo nuevo cada minuto
  y se resume por dominio (peticiones, códigos 2xx-5xx, bytes) y por IP (peticiones y errores).
- IPs distintas por dominio (visitantes): por hora durante 8 días y por día durante 400 días.
"""

import glob
import os
import re
import sqlite3
import time
import urllib.request

from .common import DATA, load_json, run, save_json

LOG_DIR = "/var/log/nginx/domains"
OFFSETS = DATA + "/traffic_offsets.json"
STUB = "http://127.0.0.1:8084/"
MAX_READ = 64 * 1024 * 1024  # por archivo y minuto: protege ante registros enormes
RAW_KEEP = 8 * 86400
HOURLY_KEEP = 400 * 86400
IP_KEEP = 8 * 86400
# ip - usuario [fecha] "MÉTODO /ruta HTTP/x" código bytes "referer" "agente"
RE_LINE = re.compile(rb'^(\S+) \S+ \S+ \[[^\]]*\] "(?:[A-Z]+ )?([^" ]*)[^"]*" "?(\d{3})"? (\d+|-)(?: "[^"]*" "([^"]*)")?')
RE_BOT = re.compile(rb"bot|crawl|spider|slurp|curl|wget|python-requests|go-http|scrapy|headless|monitor|uptime", re.I)
PATHS_PER_DOMAIN = 60  # por minuto se guardan solo las URLs más pedidas (y con error) de cada dominio
ROOT_CNF = "/root/.my.cnf"


# ------------------------------------------------------------------ contadores

def nginx_stub():
    try:
        with urllib.request.urlopen(STUB, timeout=2) as r:
            text = r.read().decode()
    except Exception:
        return None
    nums = [int(x) for x in re.findall(r"\d+", text)]
    # Active connections: N / accepts handled requests / Reading Writing Waiting
    if len(nums) < 7:
        return None
    return {"active": nums[0], "accepts": nums[1], "handled": nums[2], "requests": nums[3],
            "reading": nums[4], "writing": nums[5], "waiting": nums[6]}


def tcp_counters():
    with open("/proc/net/snmp") as fh:
        lines = [l.split() for l in fh if l.startswith("Tcp:")]
    if len(lines) < 2:
        return {"est": 0, "passive": 0}
    d = dict(zip(lines[0][1:], lines[1][1:]))
    return {"est": int(d.get("CurrEstab", 0)), "passive": int(d.get("PassiveOpens", 0))}


def mysql_status():
    client = "/usr/bin/mariadb" if os.path.exists("/usr/bin/mariadb") else "/usr/bin/mysql"
    if not os.path.exists(ROOT_CNF) or not os.path.exists(client):
        return None
    p = run([client, f"--defaults-file={ROOT_CNF}", "-N", "-B", "-e",
             "SHOW GLOBAL STATUS WHERE Variable_name IN ('Questions','Threads_connected','Threads_running',"
             "'Connections','Slow_queries','Aborted_connects')"], timeout=10)
    if p.returncode != 0:
        return None
    return {k: int(v) for k, v in (l.split("\t") for l in p.stdout.splitlines() if "\t" in l)}


def sample():
    return {"t": time.time(), "nginx": nginx_stub(), "tcp": tcp_counters(), "mysql": mysql_status()}


def rates(a, b):
    """Tasas por segundo entre dos muestras (contadores que se reinician cuentan como 0)."""
    dt = max(b["t"] - a["t"], 0.001)
    d = lambda x, y: max(y - x, 0) / dt
    res = {"req": None, "conn": None, "active": None, "reading": None, "writing": None, "waiting": None,
           "tcp_est": b["tcp"]["est"], "tcp_new": round(d(a["tcp"]["passive"], b["tcp"]["passive"]), 2),
           "db_qps": None, "db_conn": None, "db_running": None}
    if a["nginx"] and b["nginx"]:
        res.update(req=round(d(a["nginx"]["requests"], b["nginx"]["requests"]), 2),
                   conn=round(d(a["nginx"]["accepts"], b["nginx"]["accepts"]), 2),
                   active=b["nginx"]["active"], reading=b["nginx"]["reading"],
                   writing=b["nginx"]["writing"], waiting=b["nginx"]["waiting"])
    if a["mysql"] and b["mysql"]:
        res.update(db_qps=round(d(a["mysql"].get("Questions", 0), b["mysql"].get("Questions", 0)), 2),
                   db_conn=b["mysql"].get("Threads_connected"), db_running=b["mysql"].get("Threads_running"))
    return res


def connections_by_ip(limit=12):
    """Conexiones HTTP/HTTPS abiertas ahora mismo agrupadas por IP."""
    p = run(["ss", "-Htn", "state", "established", "( sport = :80 or sport = :443 )"], timeout=10)
    counts = {}
    for line in p.stdout.splitlines():
        f = line.split()
        if len(f) < 4:
            continue
        peer = f[-1].rsplit(":", 1)[0].strip("[]")
        if peer.startswith("::ffff:"):
            peer = peer[7:]
        counts[peer] = counts.get(peer, 0) + 1
    top = sorted(counts.items(), key=lambda x: -x[1])[:limit]
    return {"total": sum(counts.values()), "ips": len(counts), "top": [{"ip": ip, "conns": n} for ip, n in top]}


# ------------------------------------------------------------------ registros por dominio

def _db():
    con = sqlite3.connect(DATA + "/metrics.db", timeout=10)
    for t in ("web_dom", "web_dom_h"):
        con.execute(f"CREATE TABLE IF NOT EXISTS {t} (ts INTEGER, domain TEXT, req INTEGER, s2 INTEGER, s3 INTEGER,"
                    f" s4 INTEGER, s5 INTEGER, bytes INTEGER, bots INTEGER DEFAULT 0, PRIMARY KEY (ts, domain))")
    con.execute("CREATE TABLE IF NOT EXISTS web_path (ts INTEGER, domain TEXT, path TEXT, req INTEGER, e404 INTEGER,"
                " e5 INTEGER, PRIMARY KEY (ts, domain, path))")
    con.execute("CREATE TABLE IF NOT EXISTS web_ip (ts INTEGER, ip TEXT, req INTEGER, err INTEGER, PRIMARY KEY (ts, ip))")
    # visitantes: IPs distintas por dominio; hreq = peticiones que no son de bots
    for t in ("web_dip", "web_dipd"):
        con.execute(f"CREATE TABLE IF NOT EXISTS {t} (ts INTEGER, domain TEXT, ip TEXT, req INTEGER, hreq INTEGER, "
                    f"PRIMARY KEY (ts, domain, ip))")
    if "blk" not in [r[1] for r in con.execute("PRAGMA table_info(web_ip)")]:
        con.execute("ALTER TABLE web_ip ADD COLUMN blk INTEGER DEFAULT 0")  # 451: bloqueado por país o IP
    return con


def collect_logs(ts):
    """Lee lo nuevo de cada registro de acceso y guarda el resumen del minuto. Devuelve totales 2xx..5xx."""
    offsets = load_json(OFFSETS, {})
    doms, ips, paths, dips = {}, {}, {}, {}
    seen = set()
    for path in glob.glob(f"{LOG_DIR}/*.log"):
        if path.endswith(".error.log"):
            continue
        seen.add(path)
        try:
            st = os.stat(path)
        except OSError:
            continue
        prev = offsets.get(path)
        if prev is None:  # primera vez: se empieza desde el final (no se re-procesa el historial)
            offsets[path] = {"ino": st.st_ino, "off": st.st_size}
            continue
        off = prev["off"] if prev["ino"] == st.st_ino and st.st_size >= prev["off"] else 0
        if st.st_size == off:
            offsets[path] = {"ino": st.st_ino, "off": off}
            continue
        with open(path, "rb") as fh:
            fh.seek(off)
            chunk = fh.read(min(st.st_size - off, MAX_READ))
        end = chunk.rfind(b"\n")
        if end < 0:
            continue
        offsets[path] = {"ino": st.st_ino, "off": off + end + 1}
        domain = os.path.basename(path)[:-4]
        d = doms.setdefault(domain, [0, 0, 0, 0, 0, 0, 0])  # req, 2xx, 3xx, 4xx, 5xx, bytes, bots
        dp = paths.setdefault(domain, {})
        di = dips.setdefault(domain, {})
        for line in chunk[:end].split(b"\n"):
            m = RE_LINE.match(line)
            if not m:
                continue
            code = int(m.group(3))
            d[0] += 1
            k = code // 100
            if 2 <= k <= 5:
                d[k - 1] += 1
            if m.group(4) != b"-":
                d[5] += int(m.group(4))
            bot = m.group(5) is not None and (not m.group(5) or bool(RE_BOT.search(m.group(5))))
            if bot:
                d[6] += 1
            url = m.group(2).split(b"?", 1)[0][:200].decode("utf-8", "replace") or "/"
            pe = dp.setdefault(url, [0, 0, 0])
            pe[0] += 1
            if code == 404:
                pe[1] += 1
            elif code >= 500:
                pe[2] += 1
            ip = m.group(1).decode("ascii", "replace")
            v = di.setdefault(ip, [0, 0])
            v[0] += 1
            v[1] += 0 if bot else 1
            e = ips.setdefault(ip, [0, 0, 0])
            e[0] += 1
            if code >= 400:
                e[1] += 1
            if code == 451:
                e[2] += 1
    for p in list(offsets):
        if p not in seen:
            offsets.pop(p)
    save_json(OFFSETS, offsets)

    minute = ts - ts % 60
    hour = ts - ts % 3600
    con = _db()
    with con:
        for dom, v in doms.items():
            # se suma (no se reemplaza): la recogida puede ejecutarse dos veces en el mismo minuto
            con.execute("INSERT INTO web_dom VALUES (?,?,?,?,?,?,?,?,?) ON CONFLICT(ts, domain) DO UPDATE SET "
                        "req=req+excluded.req, s2=s2+excluded.s2, s3=s3+excluded.s3, s4=s4+excluded.s4, "
                        "s5=s5+excluded.s5, bytes=bytes+excluded.bytes, bots=bots+excluded.bots", (minute, dom, *v))
            con.execute("INSERT INTO web_dom_h VALUES (?,?,?,?,?,?,?,?,?) ON CONFLICT(ts, domain) DO UPDATE SET "
                        "req=req+excluded.req, s2=s2+excluded.s2, s3=s3+excluded.s3, s4=s4+excluded.s4, "
                        "s5=s5+excluded.s5, bytes=bytes+excluded.bytes, bots=bots+excluded.bots", (hour, dom, *v))
        for dom, dp in paths.items():
            # las más pedidas y las que dieron error, con tope por minuto para no inflar la base
            keep = sorted(dp.items(), key=lambda x: -(x[1][0] + 1000 * (x[1][1] + x[1][2])))[:PATHS_PER_DOMAIN]
            for url, (req, e404, e5) in keep:
                con.execute("INSERT INTO web_path VALUES (?,?,?,?,?,?) ON CONFLICT(ts, domain, path) DO UPDATE SET "
                            "req=req+excluded.req, e404=e404+excluded.e404, e5=e5+excluded.e5",
                            (hour, dom, url, req, e404, e5))
        for ip, (req, err, blk) in ips.items():
            con.execute("INSERT INTO web_ip (ts, ip, req, err, blk) VALUES (?,?,?,?,?) ON CONFLICT(ts, ip) DO UPDATE SET "
                        "req=req+excluded.req, err=err+excluded.err, blk=blk+excluded.blk", (hour, ip, req, err, blk))
        _save_dips(con, dips, hour, ts - ts % 86400)
        con.execute("DELETE FROM web_dip WHERE ts < ?", (ts - IP_KEEP,))
        con.execute("DELETE FROM web_dipd WHERE ts < ?", (ts - HOURLY_KEEP,))
        con.execute("DELETE FROM web_dom WHERE ts < ?", (ts - RAW_KEEP,))
        con.execute("DELETE FROM web_dom_h WHERE ts < ?", (ts - HOURLY_KEEP,))
        con.execute("DELETE FROM web_ip WHERE ts < ?", (ts - IP_KEEP,))
        con.execute("DELETE FROM web_path WHERE ts < ?", (ts - IP_KEEP,))
    con.close()
    tot = [sum(v[i] for v in doms.values()) for i in range(1, 5)]
    return {"s2": tot[0], "s3": tot[1], "s4": tot[2], "s5": tot[3]}


def _save_dips(con, dips, hour, day):
    for dom, di in dips.items():
        for ip, (req, hreq) in di.items():
            for t, bucket in (("web_dip", hour), ("web_dipd", day)):
                con.execute(f"INSERT INTO {t} VALUES (?,?,?,?,?) ON CONFLICT(ts, domain, ip) DO UPDATE SET "
                            "req=req+excluded.req, hreq=hreq+excluded.hreq", (bucket, dom, ip, req, hreq))


def backfill():
    """Una sola vez: rellena los visitantes de los días que aún guardan los registros (actuales y rotados)."""
    from .weblog import _epoch, _files, _lines_newest_first
    mark = DATA + "/traffic_backfill.json"
    if load_json(mark, {}).get("visitors"):
        return {"skipped": True}
    now = int(time.time())
    hsince, dsince = now - IP_KEEP, now - HOURLY_KEEP
    re_ts = re.compile(r'^(\S+) \S+ \S+ \[([^\]]+)\] "[^"]*" "?\d{3}"? \S+(?: "[^"]*" "([^"]*)")?')
    con = _db()
    n = 0
    for path in glob.glob(f"{LOG_DIR}/*.log"):
        if path.endswith(".error.log"):
            continue
        domain = os.path.basename(path)[:-4]
        hours, days = {}, {}
        for f in _files(domain):
            old = False
            for line in _lines_newest_first(f):
                m = re_ts.match(line)
                if not m:
                    continue
                t = _epoch(m.group(2))
                if t < dsince:
                    old = True
                    break
                ua = m.group(3)
                human = 0 if ua is not None and (not ua or RE_BOT.search(ua.encode())) else 1
                for store, bucket, ok in ((hours, t - t % 3600, t >= hsince), (days, t - t % 86400, True)):
                    if ok:
                        v = store.setdefault((bucket, m.group(1)), [0, 0])
                        v[0] += 1
                        v[1] += human
                n += 1
            if old:
                break
        with con:
            for t, store in (("web_dip", hours), ("web_dipd", days)):
                con.executemany(f"INSERT INTO {t} VALUES (?,?,?,?,?) ON CONFLICT(ts, domain, ip) DO UPDATE SET "
                                "req=req+excluded.req, hreq=hreq+excluded.hreq",
                                [(b, domain, ip, q, h) for (b, ip), (q, h) in store.items()])
    con.close()
    save_json(mark, {"visitors": now})
    return {"lines": n}


def backfill_requests():
    """Una sola vez: peticiones, códigos, URLs e IPs anteriores a que empezara la medición (desde los registros)."""
    from .weblog import _epoch, _files, _lines_newest_first
    mark = DATA + "/traffic_backfill.json"
    state = load_json(mark, {})
    if state.get("requests"):
        return {"skipped": True}
    now = int(time.time())
    re_full = re.compile(r'^(\S+) \S+ \S+ \[([^\]]+)\] "(?:[A-Z]+ )?([^" ]*)[^"]*" "?(\d{3})"? (\d+|-)(?: "[^"]*" "([^"]*)")?')
    con = _db()
    n = 0
    for path in glob.glob(f"{LOG_DIR}/*.log"):
        if path.endswith(".error.log"):
            continue
        domain = os.path.basename(path)[:-4]
        first = con.execute("SELECT MIN(ts) FROM web_dom WHERE domain = ?", (domain,)).fetchone()[0] or now
        mins, hrs, ips, paths = {}, {}, {}, {}
        for f in _files(domain):
            old = False
            for line in _lines_newest_first(f):
                m = re_full.match(line)
                if not m:
                    continue
                t = _epoch(m.group(2))
                if t >= first:  # eso ya lo contó la medición normal
                    continue
                if t < now - HOURLY_KEEP:
                    old = True
                    break
                code = int(m.group(4))
                k = code // 100
                ua = m.group(6)
                bot = ua is not None and (not ua or bool(RE_BOT.search(ua.encode())))
                size = int(m.group(5)) if m.group(5).isdigit() else 0
                hour = t - t % 3600
                for store, bucket, ok in ((mins, t - t % 60, t >= now - RAW_KEEP), (hrs, hour, True)):
                    if ok:
                        d = store.setdefault(bucket, [0, 0, 0, 0, 0, 0, 0])
                        d[0] += 1
                        if 2 <= k <= 5:
                            d[k - 1] += 1
                        d[5] += size
                        d[6] += bot
                if t >= now - IP_KEEP:
                    e = ips.setdefault((hour, m.group(1)), [0, 0, 0])
                    e[0] += 1
                    e[1] += code >= 400
                    e[2] += code == 451
                    url = m.group(3).split("?", 1)[0][:200] or "/"
                    pe = paths.setdefault((hour, url), [0, 0, 0])
                    pe[0] += 1
                    pe[1] += code == 404
                    pe[2] += code >= 500
                n += 1
            if old:
                break
        # en URLs se guardan las más pedidas y las que dieron error de cada hora, como en la medición normal
        keep = {}
        for (hour, url), v in paths.items():
            keep.setdefault(hour, []).append((url, v))
        with con:
            con.executemany("INSERT OR IGNORE INTO web_dom VALUES (?,?,?,?,?,?,?,?,?)", [(b, domain, *v) for b, v in mins.items()])
            con.executemany("INSERT INTO web_dom_h VALUES (?,?,?,?,?,?,?,?,?) ON CONFLICT(ts, domain) DO UPDATE SET "
                            "req=req+excluded.req, s2=s2+excluded.s2, s3=s3+excluded.s3, s4=s4+excluded.s4, "
                            "s5=s5+excluded.s5, bytes=bytes+excluded.bytes, bots=bots+excluded.bots",
                            [(b, domain, *v) for b, v in hrs.items()])
            con.executemany("INSERT INTO web_ip (ts, ip, req, err, blk) VALUES (?,?,?,?,?) ON CONFLICT(ts, ip) DO UPDATE SET "
                            "req=req+excluded.req, err=err+excluded.err, blk=blk+excluded.blk",
                            [(h, ip, *v) for (h, ip), v in ips.items()])
            for hour, lst in keep.items():
                lst.sort(key=lambda x: -(x[1][0] + 1000 * (x[1][1] + x[1][2])))
                con.executemany("INSERT INTO web_path VALUES (?,?,?,?,?,?) ON CONFLICT(ts, domain, path) DO UPDATE SET "
                                "req=req+excluded.req, e404=e404+excluded.e404, e5=e5+excluded.e5",
                                [(hour, domain, url, *v) for url, v in lst[:PATHS_PER_DOMAIN * 3]])
    con.close()
    state["requests"] = now
    save_json(mark, state)
    return {"lines": n}


def rebuild_minutes():
    """Una sola vez: rehace el detalle por minuto de 8 días desde los registros (antes un minuto podía sobrescribirse)."""
    from .weblog import _epoch, _files, _lines_newest_first
    mark = DATA + "/traffic_backfill.json"
    state = load_json(mark, {})
    if state.get("minutes"):
        return {"skipped": True}
    now = int(time.time())
    cutoff = now - now % 60 - 60  # los minutos más recientes los sigue sumando la recogida normal
    since = now - RAW_KEEP
    re_min = re.compile(r'^\S+ \S+ \S+ \[([^\]]+)\] "[^"]*" "?(\d{3})"? (\d+|-)(?: "[^"]*" "([^"]*)")?')
    con = _db()
    n = 0
    for path in glob.glob(f"{LOG_DIR}/*.log"):
        if path.endswith(".error.log"):
            continue
        domain = os.path.basename(path)[:-4]
        mins = {}
        for f in _files(domain):
            old = False
            for line in _lines_newest_first(f):
                m = re_min.match(line)
                if not m:
                    continue
                t = _epoch(m.group(1))
                if t >= cutoff:
                    continue
                if t < since:
                    old = True
                    break
                k = int(m.group(2)) // 100
                ua = m.group(4)
                d = mins.setdefault(t - t % 60, [0, 0, 0, 0, 0, 0, 0])
                d[0] += 1
                if 2 <= k <= 5:
                    d[k - 1] += 1
                d[5] += int(m.group(3)) if m.group(3).isdigit() else 0
                d[6] += ua is not None and (not ua or bool(RE_BOT.search(ua.encode())))
                n += 1
            if old:
                break
        with con:
            con.execute("DELETE FROM web_dom WHERE domain = ? AND ts < ?", (domain, cutoff))
            con.executemany("INSERT INTO web_dom VALUES (?,?,?,?,?,?,?,?,?)", [(b, domain, *v) for b, v in mins.items()])
    con.close()
    state["minutes"] = now
    save_json(mark, state)
    return {"lines": n}


def visitors(con, since, domains=None):
    """IPs distintas por dominio y en total: todas y sin bots (por hora hasta 8 días, por día más allá)."""
    if int(time.time()) - since <= IP_KEEP:
        table, b = "web_dip", since - since % 3600
    else:
        table, b = "web_dipd", since - since % 86400
    where, args = "ts >= ?", [b]
    if domains is not None:
        where += f" AND domain IN ({','.join('?' * len(domains))})"
        args += list(domains)
    per = {d: (ips, hum) for d, ips, hum in con.execute(
        f"SELECT domain, COUNT(DISTINCT ip), COUNT(DISTINCT CASE WHEN hreq > 0 THEN ip END) FROM {table} "
        f"WHERE {where} GROUP BY domain", args)}
    tot = con.execute(f"SELECT COUNT(DISTINCT ip), COUNT(DISTINCT CASE WHEN hreq > 0 THEN ip END) FROM {table} "
                      f"WHERE {where}", args).fetchone()
    return per, {"ips": tot[0] or 0, "visitors": tot[1] or 0}


def top(rng_seconds, limit=15, domains=None):
    """Dominios e IPs con más peticiones en el periodo (y reparto de códigos)."""
    since = int(time.time()) - rng_seconds
    table = "web_dom" if rng_seconds <= 7 * 86400 else "web_dom_h"
    con = _db()
    where, args = "ts >= ?", [since]
    if domains is not None:
        where += f" AND domain IN ({','.join('?' * len(domains))})"
        args += list(domains)
    rows = con.execute(f"SELECT domain, SUM(req), SUM(s2), SUM(s3), SUM(s4), SUM(s5), SUM(bytes), SUM(bots) "
                       f"FROM {table} WHERE {where} GROUP BY domain ORDER BY SUM(req) DESC LIMIT ?",
                       args + [limit]).fetchall()
    tot = con.execute(f"SELECT SUM(req), SUM(s2), SUM(s3), SUM(s4), SUM(s5), SUM(bytes), SUM(bots) FROM {table} "
                      f"WHERE {where}", args).fetchone()
    # URLs e IPs se guardan por hora durante 8 días
    hsince = max(since, int(time.time()) - IP_KEEP)
    hsince -= hsince % 3600
    pwhere, pargs = "ts >= ?", [hsince]
    if domains is not None:
        pwhere += f" AND domain IN ({','.join('?' * len(domains))})"
        pargs += list(domains)
    urls = con.execute(f"SELECT domain, path, SUM(req), SUM(e404), SUM(e5) FROM web_path WHERE {pwhere} "
                       f"GROUP BY domain, path ORDER BY SUM(req) DESC LIMIT ?", pargs + [limit]).fetchall()
    errs = con.execute(f"SELECT domain, path, SUM(req), SUM(e404), SUM(e5) FROM web_path WHERE {pwhere} "
                       f"GROUP BY domain, path HAVING SUM(e404) + SUM(e5) > 0 "
                       f"ORDER BY SUM(e404) + SUM(e5) DESC LIMIT ?", pargs + [limit]).fetchall()
    ips, suspects = [], []
    if domains is None:
        ips = con.execute("SELECT ip, SUM(req), SUM(err) FROM web_ip WHERE ts >= ? GROUP BY ip "
                          "ORDER BY SUM(req) DESC LIMIT ?", (hsince, limit)).fetchall()
        # escáneres y ataques: bastantes peticiones y la mayoría con error 4xx/5xx
        suspects = con.execute("SELECT ip, SUM(req), SUM(err) FROM web_ip WHERE ts >= ? GROUP BY ip "
                               "HAVING SUM(req) >= 30 AND SUM(err) * 2 >= SUM(req) ORDER BY SUM(err) DESC LIMIT ?",
                               (hsince, limit)).fetchall()
    peak = con.execute(f"SELECT ts, SUM(req) FROM {table} WHERE {where} GROUP BY ts ORDER BY SUM(req) DESC LIMIT 1",
                       args).fetchone()
    vis, vtot = visitors(con, since, domains)
    con.close()
    from .geo import country  # país de cada IP (vacío si la base aún no está descargada)
    keys = ("req", "s2", "s3", "s4", "s5", "bytes", "bots")
    url_row = lambda r: {"domain": r[0], "path": r[1], "req": r[2], "e404": r[3], "e5": r[4]}
    return {
        "domains": [dict(domain=r[0], ips=vis.get(r[0], (0, 0))[0], visitors=vis.get(r[0], (0, 0))[1],
                         **{k: r[i + 1] or 0 for i, k in enumerate(keys)}) for r in rows],
        "total": dict({k: (tot[i] or 0) if tot else 0 for i, k in enumerate(keys)}, **vtot),
        "urls": [url_row(r) for r in urls],
        "errors": [url_row(r) for r in errs],
        "ips": [{"ip": r[0], "req": r[1], "err": r[2], "cc": country(r[0])} for r in ips],
        "suspects": [{"ip": r[0], "req": r[1], "err": r[2], "cc": country(r[0])} for r in suspects],
        "peak": {"ts": peak[0], "req": peak[1], "per": 60 if table == "web_dom" else 3600} if peak else None,
    }


def domain_series(domain, rng_seconds, points=120):
    since = int(time.time()) - rng_seconds
    table = "web_dom" if rng_seconds <= 7 * 86400 else "web_dom_h"
    bucket = max(60 if table == "web_dom" else 3600, rng_seconds // points)
    con = _db()
    rows = con.execute(f"SELECT (ts / ?) * ? AS b, SUM(req), SUM(s4), SUM(s5), SUM(bytes) FROM {table} "
                       f"WHERE domain = ? AND ts >= ? GROUP BY b ORDER BY b",
                       (bucket, bucket, domain, since)).fetchall()
    con.close()
    return {"bucket": bucket, "ts": [r[0] for r in rows], "req": [r[1] for r in rows],
            "s4": [r[2] for r in rows], "s5": [r[3] for r in rows], "bytes": [r[4] for r in rows]}
