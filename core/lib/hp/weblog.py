"""Explorador de registros de acceso y de errores de nginx por dominio, con filtros.

Lee /var/log/nginx/domains/<dominio>.log y sus copias rotadas (.log.1, .log.2.gz, … la rotación es
semanal), de la más nueva a la más antigua, y devuelve las peticiones que cumplen el filtro junto con
un resumen (códigos, URLs e IPs) para seguir filtrando con un clic.
"""

import calendar
import glob
import gzip
import os
import re
import time

from .common import HPError, check_domain_fmt, web_domains
from .sites import domain_owner
from .geo import reader
from .traffic import LOG_DIR, RE_BOT

RE_FULL = re.compile(r'^(\S+) \S+ (\S+) \[([^\]]+)\] "([^"]*)" "?(\d{3})"? (\d+|-)(?: "([^"]*)" "([^"]*)")?')
MONTHS = {m: i for i, m in enumerate(("Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"), 1)}
RANGES = {"15m": 900, "1h": 3600, "6h": 21600, "24h": 86400, "7d": 7 * 86400, "30d": 30 * 86400, "all": 10 ** 10}
MAX_SCAN = 3_000_000  # líneas como máximo por consulta
MAX_ROWS = 500


def _epoch(stamp):
    # 24/Oct/2026:15:28:03 +0000
    try:
        d, mon, rest = stamp.split("/", 2)
        y, hh, mm, ss = rest[:4], rest[5:7], rest[8:10], rest[11:13]
        t = calendar.timegm((int(y), MONTHS[mon], int(d), int(hh), int(mm), int(ss)))
        off = rest[14:]
        if off and off[0] in "+-":
            sign = 1 if off[0] == "+" else -1
            t -= sign * (int(off[1:3]) * 3600 + int(off[3:5]) * 60)
        return t
    except (ValueError, KeyError, IndexError):
        return 0


def _files(domain, kind="log"):
    base = f"{LOG_DIR}/{domain}.{'error.log' if kind == 'error' else 'log'}"
    num = lambda p: int(re.search(r"\.(\d+)(?:\.gz)?$", p).group(1))
    rotated = sorted((p for p in glob.glob(base + ".*") if re.search(r"\.\d+(?:\.gz)?$", p)), key=num)
    return ([base] if os.path.exists(base) else []) + rotated


def _lines_newest_first(path, block=1 << 20):
    """Líneas de la última a la primera sin cargar el archivo entero (los .gz se leen enteros)."""
    if path.endswith(".gz"):
        with gzip.open(path, "rt", errors="replace") as fh:
            yield from reversed(fh.readlines())
        return
    with open(path, "rb") as fh:
        fh.seek(0, os.SEEK_END)
        pos, rest = fh.tell(), b""
        while pos > 0:
            step = min(block, pos)
            pos -= step
            fh.seek(pos)
            chunk = fh.read(step) + rest
            lines = chunk.split(b"\n")
            rest = lines.pop(0)
            for ln in reversed(lines):
                if ln:
                    yield ln.decode("utf-8", "replace")
        if rest:
            yield rest.decode("utf-8", "replace")


def resolve(user, domain, is_admin):
    """Dominios que puede leer: el suyo (o cualquiera si es admin; '*' = todos los suyos / todos)."""
    if domain == "*":
        if is_admin:
            return sorted({os.path.basename(p)[:-4] for p in glob.glob(f"{LOG_DIR}/*.log") if not p.endswith(".error.log")})
        return sorted(web_domains(user))
    domain = check_domain_fmt(domain)
    if is_admin:
        if domain_owner(domain) or os.path.exists(f"{LOG_DIR}/{domain}.log"):
            return [domain]
        raise HPError("No hay registros para ese dominio")
    if domain not in web_domains(user):
        raise HPError("El dominio no pertenece a tu cuenta")
    return [domain]


def _status_ok(code, want):
    if not want or want == "all":
        return True
    if want == "err":
        return code >= 400
    if want in ("2xx", "3xx", "4xx", "5xx"):
        return code // 100 == int(want[0])
    if want.isdigit():
        return code == int(want)
    return True


def search(domains, status="all", ip="", q="", rng="24h", bots="", limit=300, cc=""):
    since = time.time() - RANGES.get(rng, 86400)
    rd = reader()
    ccs = {}  # caché IP -> país
    cc = (cc or "").strip().upper()

    def country(addr):
        if rd is None:
            return ""
        c = ccs.get(addr)
        if c is None:
            c = ccs[addr] = rd.lookup(addr) or "--"
        return c

    ip = (ip or "").strip()
    q = (q or "").strip().lower()
    limit = max(10, min(int(limit or 300), MAX_ROWS))
    rows, codes, paths, ips = [], {}, {}, {}
    total = matched = scanned = 0
    oldest = None
    for domain in domains:
        kept = 0  # filas por dominio: con "todos los dominios" se mezclan y ordenan por hora al final
        for path in _files(domain):
            stop = False
            for line in _lines_newest_first(path):
                scanned += 1
                if scanned > MAX_SCAN:
                    stop = True
                    break
                m = RE_FULL.match(line)
                if not m:
                    continue
                t = _epoch(m.group(3))
                if t and t < since:
                    stop = True
                    break
                total += 1
                code = int(m.group(5))
                rip, req, ua = m.group(1), m.group(4), m.group(8) or ""
                is_bot = not ua or ua == "-" or "+http" in ua or bool(RE_BOT.search(ua.encode()))
                if bots == "only" and not is_bot or bots == "exclude" and is_bot:
                    continue
                if ip and rip != ip:
                    continue
                if cc and country(rip) != cc:
                    continue
                parts = req.split(" ")
                method, url = (parts[0], parts[1]) if len(parts) >= 2 else ("", req)
                if q and q not in url.lower():
                    continue
                # resumen calculado con el resto de filtros pero SIN el de código, para poder cambiar de código con un clic
                codes[code] = codes.get(code, 0) + 1
                if not _status_ok(code, status):
                    continue
                matched += 1
                p = url.split("?", 1)[0][:200]
                paths[p] = paths.get(p, 0) + 1
                ips[rip] = ips.get(rip, 0) + 1
                oldest = min(oldest or t, t)
                if kept < limit:
                    kept += 1
                    rows.append({"t": t, "domain": domain, "ip": rip, "method": method, "url": url[:2000], "proto": parts[2] if len(parts) > 2 else "",
                                 "status": code, "bytes": int(m.group(6)) if m.group(6).isdigit() else 0,
                                 "ref": (m.group(7) or "")[:500], "ua": ua[:500], "bot": is_bot, "user": m.group(2) if m.group(2) != "-" else "",
                                 "cc": country(rip)})
            if stop:
                break
    rows.sort(key=lambda r: -r["t"])
    rows = rows[:limit]
    top = lambda d: [{"k": k, "n": n} for k, n in sorted(d.items(), key=lambda x: -x[1])[:12]]
    ip_top = [dict(e, cc=country(e["k"])) for e in top(ips)]
    return {"rows": rows, "total": total, "matched": matched, "shown": len(rows), "truncated": scanned > MAX_SCAN,
            "codes": [{"code": c, "n": n} for c, n in sorted(codes.items())], "paths": top(paths), "ips": ip_top,
            "oldest": oldest, "domains": domains}


RE_ERR_TIME = re.compile(r"^(\d{4})/(\d{2})/(\d{2}) (\d{2}):(\d{2}):(\d{2})")


def errors(domains, rng="24h", q="", limit=200):
    """Registro de errores de nginx (y de Apache si el dominio usa nginx + Apache)."""
    since = time.time() - RANGES.get(rng, 86400)
    q = (q or "").strip().lower()
    out = []
    for domain in domains:
        sources = [("nginx", p) for p in _files(domain, "error")]
        apache = f"/var/log/apache2/domains/{domain}.error.log"
        if os.path.exists(apache):
            sources.append(("apache", apache))
        for src, path in sources:
            n = 0
            for line in _lines_newest_first(path):
                line = line.rstrip("\n")
                m = RE_ERR_TIME.match(line)
                # nginx escribe la hora local del servidor
                t = time.mktime(tuple(int(x) for x in m.groups()) + (0, 0, -1)) if m else 0
                if t and t < since:
                    break
                if q and q not in line.lower():
                    continue
                out.append({"t": t, "domain": domain, "src": src, "line": line[:3000]})
                n += 1
                if n >= limit:
                    break
    out.sort(key=lambda x: -x["t"])
    return {"rows": out[:limit]}
