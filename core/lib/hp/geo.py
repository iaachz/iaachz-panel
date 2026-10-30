"""Países: base DB-IP Lite (CC BY 4.0), mapa de visitas y control de acceso por país e IP.

- Sitios web: nginx con su módulo «geo» integrado (no hace falta compilar módulos para el nginx de nginx.org).
  /etc/nginx/conf.d/hp-geo.conf asigna un país a cada IP solo para los países usados en alguna regla, y cada
  dominio con reglas recibe nginx.conf_hp_geo / nginx.ssl.conf_hp_geo. Los bloqueados reciben 451.
- Servidor: cadenas propias HP_GEO (países o IPs bloqueados en todos los puertos) y HP_ADMIN (lista blanca para
  SSH, panel y FTP) con ipset. No se vacía ni se reemplaza nada más de iptables (Docker, Tailscale, Hestia…).
- La lista blanca de administración se aplica con vuelta atrás automática a los 3 minutos si no se confirma.
"""

import datetime
import gzip
import hashlib
import ipaddress
import json
import os
import re
import shutil
import socket
import struct
import time
import urllib.request

from . import jobs
from .common import (DATA, HESTIA, HPError, check_web_domain, ensure_dir, hestia_conf, home_of, list_users, load_json,
                     locked, log_action, notify, now, run, save_json, web_domains, which)

GEO = "/var/lib/iaachz-panel/geo"
MMDB = GEO + "/country.mmdb"
CIDR = GEO + "/cidr"
NGX = GEO + "/nginx"
WWW = GEO + "/www"
RULES = DATA + "/geo.json"
NGINX_CONF = "/etc/nginx/conf.d/hp-geo.conf"
SNIPPETS = ("nginx.conf_hp_geo", "nginx.ssl.conf_hp_geo")
ROLLBACK_SECS = 180
ATTRIBUTION = '<a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a>'
# Redes que nunca se bloquean: el propio servidor, redes privadas y Tailscale
LOCAL4 = ["127.0.0.0/8", "10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "100.64.0.0/10", "169.254.0.0/16"]
LOCAL6 = ["::1/128", "fc00::/7", "fe80::/10"]
RE_CC = re.compile(r"^[A-Z]{2}$")


def _step(name, status="ok", detail=""):
    if os.environ.get("HP_JOB"):
        jobs.step(name, status, detail)


# ------------------------------------------------------------------ lector MMDB (formato MaxMind DB)

class Reader:
    """Lector mínimo de bases .mmdb (solo lo necesario para país por IP y recorrer el árbol)."""

    def __init__(self, path=MMDB):
        with open(path, "rb") as fh:
            self.buf = fh.read()
        i = self.buf.rfind(b"\xab\xcd\xefMaxMind.com")
        if i < 0:
            raise HPError("La base de países está dañada")
        self.meta, _ = self._decode(i + 14, i + 14)
        self.nodes = self.meta["node_count"]
        self.rs = self.meta["record_size"]
        self.nb = self.rs * 2 // 8
        self.data = self.nodes * self.nb + 16
        self.v4 = 0
        if self.meta.get("ip_version") == 6:
            n = 0
            for _ in range(96):
                if n >= self.nodes:
                    break
                n = self._rec(n, 0)
            self.v4 = n
        self._cc = {}

    def _rec(self, node, bit):
        b, o = self.buf, node * self.nb
        if self.rs == 24:
            o += 3 * bit
            return (b[o] << 16) | (b[o + 1] << 8) | b[o + 2]
        if self.rs == 28:
            if bit == 0:
                return ((b[o + 3] & 0xF0) << 20) | (b[o] << 16) | (b[o + 1] << 8) | b[o + 2]
            return ((b[o + 3] & 0x0F) << 24) | (b[o + 4] << 16) | (b[o + 5] << 8) | b[o + 6]
        o += 4 * bit
        return int.from_bytes(b[o:o + 4], "big")

    def _decode(self, off, base):
        b = self.buf
        ctrl = b[off]
        off += 1
        t = ctrl >> 5
        if t == 1:  # puntero
            s, v = (ctrl >> 3) & 3, ctrl & 7
            if s == 0:
                p = (v << 8) | b[off]
                off += 1
            elif s == 1:
                p = ((v << 16) | (b[off] << 8) | b[off + 1]) + 2048
                off += 2
            elif s == 2:
                p = ((v << 24) | int.from_bytes(b[off:off + 3], "big")) + 526336
                off += 3
            else:
                p = int.from_bytes(b[off:off + 4], "big")
                off += 4
            return self._decode(base + p, base)[0], off
        if t == 0:
            t = 7 + b[off]
            off += 1
        size = ctrl & 0x1F
        if size >= 29:
            if size == 29:
                size = 29 + b[off]
                off += 1
            elif size == 30:
                size = 285 + int.from_bytes(b[off:off + 2], "big")
                off += 2
            else:
                size = 65821 + int.from_bytes(b[off:off + 3], "big")
                off += 3
        if t == 2:
            return b[off:off + size].decode("utf-8", "replace"), off + size
        if t == 7:
            d = {}
            for _ in range(size):
                k, off = self._decode(off, base)
                d[k], off = self._decode(off, base)
            return d, off
        if t == 11:
            a = []
            for _ in range(size):
                v, off = self._decode(off, base)
                a.append(v)
            return a, off
        if t in (5, 6, 9, 10):
            return int.from_bytes(b[off:off + size], "big"), off + size
        if t == 8:
            return int.from_bytes(b[off:off + size], "big", signed=size == 4), off + size
        if t == 3:
            return struct.unpack(">d", b[off:off + 8])[0], off + 8
        if t == 15:
            return struct.unpack(">f", b[off:off + 4])[0], off + 4
        if t == 14:
            return bool(size), off
        return b[off:off + size], off + size

    def _cc_of(self, rec):
        cc = self._cc.get(rec)
        if cc is None:
            v = self._decode(self.data + rec - self.nodes - 16, self.data)[0]
            cc = ""
            if isinstance(v, dict):
                cc = ((v.get("country") or {}).get("iso_code") or (v.get("registered_country") or {}).get("iso_code") or "")
            self._cc[rec] = cc
        return cc

    def lookup(self, ip):
        try:
            a = ipaddress.ip_address(ip)
        except ValueError:
            return ""
        if a.version == 6 and a.ipv4_mapped:
            a = a.ipv4_mapped
        if not a.is_global:
            return "LO"
        if a.version == 4:
            node, bits, n = self.v4, a.packed, 32
        else:
            node, bits, n = 0, a.packed, 128
        for i in range(n):
            if node >= self.nodes:
                break
            node = self._rec(node, (bits[i >> 3] >> (7 - (i & 7))) & 1)
        return self._cc_of(node) if node > self.nodes else ""

    def networks(self):
        """{país: [redes]} recorriendo todo el árbol (IPv4 e IPv6)."""
        out = {}
        for fam, start, maxbits in ((4, self.v4, 32), (6, 0, 128)):
            stack = [(start, 0, 0)]
            while stack:
                node, val, depth = stack.pop()
                if node > self.nodes:
                    cc = self._cc_of(node)
                    if cc:
                        if fam == 4:
                            net = socket.inet_ntoa(struct.pack(">I", val << (32 - depth)))
                        else:
                            net = socket.inet_ntop(socket.AF_INET6, (val << (128 - depth)).to_bytes(16, "big"))
                        out.setdefault(cc, []).append(f"{net}/{depth}")
                    continue
                if node == self.nodes or depth >= maxbits:
                    continue
                if fam == 6 and node == self.v4 and depth > 0:  # alias de IPv4 dentro del árbol IPv6
                    continue
                stack.append((self._rec(node, 1), (val << 1) | 1, depth + 1))
                stack.append((self._rec(node, 0), val << 1, depth + 1))
        return out


_reader = None


def reader():
    """Lector compartido (None si la base aún no está descargada)."""
    global _reader
    if _reader is None and os.path.exists(MMDB):
        try:
            _reader = Reader()
        except Exception:
            _reader = None
    return _reader


def country(ip):
    r = reader()
    return r.lookup(ip) if r else ""


# ------------------------------------------------------------------ base de datos y estado

def _version():
    return load_json(GEO + "/version.json", {})


def status():
    v = _version()
    return {"db": os.path.exists(MMDB), "month": v.get("month"), "updated": v.get("updated"),
            "ipset": bool(which("ipset")), "ready": os.path.exists(MMDB) and bool(which("ipset")),
            "attribution": ATTRIBUTION}


def build_cidr(force=False):
    """Prepara las listas de redes por país (una vez por actualización de la base)."""
    stamp = f"{CIDR}/.stamp"
    mt = str(int(os.path.getmtime(MMDB))) if os.path.exists(MMDB) else ""
    if not mt:
        raise HPError("Falta la base de países: pulsa «Activar» en Seguridad")
    if not force and os.path.exists(stamp) and open(stamp).read() == mt:
        return
    _step("Preparando las redes de cada país")
    nets = Reader().networks()
    tmp = CIDR + ".new"
    shutil.rmtree(tmp, ignore_errors=True)
    os.makedirs(tmp, mode=0o755)
    for cc, lst in nets.items():
        with open(f"{tmp}/{cc}.txt", "w") as fh:
            fh.write("\n".join(lst) + "\n")
    with open(f"{tmp}/.stamp", "w") as fh:
        fh.write(mt)
    shutil.rmtree(CIDR, ignore_errors=True)
    os.replace(tmp, CIDR)
    shutil.rmtree(NGX, ignore_errors=True)  # se regeneran al aplicar


def _nets_of(cc, fam=None):
    try:
        with open(f"{CIDR}/{cc}.txt") as fh:
            lst = fh.read().split()
    except FileNotFoundError:
        return []
    if fam == 4:
        return [n for n in lst if ":" not in n]
    if fam == 6:
        return [n for n in lst if ":" in n]
    return lst


def update_db():
    """Descarga la base de países del mes (o la del anterior si aún no está publicada)."""
    global _reader
    ensure_dir(GEO, 0o755)
    os.chmod(GEO, 0o755)
    today = datetime.date.today()
    err = ""
    for back in range(3):
        y, m = today.year, today.month - back
        while m <= 0:
            m += 12
            y -= 1
        ym = f"{y}-{m:02d}"
        if back == 0 and _version().get("month") == ym and os.path.exists(MMDB):
            _step(f"La base de países de {ym} ya está al día")
            return status()
        url = f"https://download.db-ip.com/free/dbip-country-lite-{ym}.mmdb.gz"
        tmp = MMDB + ".new"
        try:
            _step(f"Descargando la base de países de {ym} (DB-IP Lite)", "running")
            req = urllib.request.Request(url, headers={"User-Agent": "iaachz-panel"})
            with urllib.request.urlopen(req, timeout=180) as r, open(tmp + ".gz", "wb") as fh:
                shutil.copyfileobj(r, fh)
            with gzip.open(tmp + ".gz") as g, open(tmp, "wb") as fh:
                shutil.copyfileobj(g, fh)
            os.remove(tmp + ".gz")
            if Reader(tmp).lookup("8.8.8.8") != "US":
                raise HPError("la base descargada no supera la prueba")
            os.chmod(tmp, 0o644)
            os.replace(tmp, MMDB)
            save_json(GEO + "/version.json", {"month": ym, "updated": now()}, 0o644)
            _reader = None
            _step(f"Base de países de {ym} instalada")
            build_cidr(force=True)
            apply_all()
            return status()
        except Exception as e:
            err = str(e)
            for f in (tmp, tmp + ".gz"):
                if os.path.exists(f):
                    os.remove(f)
    raise HPError("No se pudo descargar la base de países: " + err)


BLOCK_PAGE = """<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Acceso no disponible</title><style>body{font-family:system-ui,sans-serif;background:#f4f6fa;color:#1f2937;display:grid;place-items:center;min-height:100vh;margin:0}
main{background:#fff;padding:40px 36px;border-radius:14px;box-shadow:0 10px 30px rgb(0 0 0/.08);max-width:440px;text-align:center}
h1{font-size:1.3rem;margin:.2em 0 .6em}p{color:#64748b;line-height:1.5;margin:.4em 0}b{font-size:2.4rem}</style></head>
<body><main><b>&#127760;</b><h1>Este sitio no está disponible desde tu ubicación</h1>
<p>El administrador ha limitado el acceso por país o por dirección IP.</p><p>This site is not available from your location.</p></main></body></html>
"""


def install():
    if not which("ipset"):
        _step("Instalando ipset", "running")
        p = run(["apt-get", "install", "-y", "-q", "ipset"], env=dict(os.environ, DEBIAN_FRONTEND="noninteractive"), timeout=900)
        if p.returncode != 0:
            raise HPError("No se pudo instalar ipset: " + (p.stderr or p.stdout)[-800:])
        _step("ipset instalado")
    ensure_dir(WWW, 0o755)
    with open(WWW + "/hp-geo-blocked.html", "w") as fh:
        fh.write(BLOCK_PAGE)
    os.chmod(WWW + "/hp-geo-blocked.html", 0o644)
    update_db()
    build_cidr()
    return status()


# ------------------------------------------------------------------ reglas

def _default_rules():
    return {"sites": {}, "admin": {"enabled": False, "services": ["ssh", "panel"], "ips": [], "countries": []},
            "server": {"countries": [], "ips": []}}


def load_rules():
    r = _default_rules()
    r.update(load_json(RULES, {}))
    return r


def _ccs(v):
    out = []
    for c in (v or []):
        c = str(c).strip().upper()
        if not RE_CC.match(c):
            raise HPError(f"Código de país no válido: {c}")
        if c not in out:
            out.append(c)
    return out[:250]


def _nets(v):
    items = v if isinstance(v, list) else re.split(r"[\s,;]+", str(v or ""))
    out = []
    for raw in items:
        raw = str(raw).strip()
        if not raw or raw.startswith("#"):
            continue
        try:
            n = ipaddress.ip_network(raw, strict=False)
        except ValueError:
            raise HPError(f"IP o rango no válido: {raw}")
        if n.prefixlen == 0:
            raise HPError(f"Rango demasiado amplio: {raw}")
        s = str(n.network_address) if n.num_addresses == 1 else str(n)
        if s not in out:
            out.append(s)
    if len(out) > 5000:
        raise HPError("Demasiadas IPs (máximo 5000)")
    return out


def _site_active(s):
    return (s.get("mode") in ("allow", "deny") and (s.get("countries") or s.get("allow_ips"))) or s.get("deny_ips")


def _h(domain):
    return hashlib.sha1(domain.encode()).hexdigest()[:10]


def server_ips():
    ips = set()
    try:
        ips.update(os.listdir(HESTIA + "/data/ips"))
    except OSError:
        pass
    p = run(["hostname", "-I"], timeout=10)
    ips.update(p.stdout.split())
    out = []
    for ip in ips:
        try:
            out.append(str(ipaddress.ip_address(ip)))
        except ValueError:
            pass
    return sorted(out)


def _owners():
    """{dominio: usuario} de todos los dominios web."""
    out = {}
    for u in list_users():
        for d in web_domains(u):
            out[d] = u
    return out


def _snippet(domain, s):
    h = _h(domain)
    lines = ["# iaachz-panel: acceso por país e IP (generado; se cambia en Seguridad > Acceso por país)",
             "set $hp_geo_block 0;"]
    ccs = "|".join(s.get("countries") or [])
    if s.get("mode") == "allow":
        lines.append(f'if ($hp_cc !~ "^({ccs + "|" if ccs else ""}LO)$") {{ set $hp_geo_block 1; }}')
    elif s.get("mode") == "deny" and ccs:
        lines.append(f'if ($hp_cc ~ "^({ccs})$") {{ set $hp_geo_block 1; }}')
    if s.get("deny_ips"):
        lines.append(f"if ($hp_id_{h}) {{ set $hp_geo_block 1; }}")
    if s.get("allow_ips"):
        lines.append(f"if ($hp_ia_{h}) {{ set $hp_geo_block 0; }}")
    lines += [
        r'if ($uri ~ "^/(\.well-known/acme-challenge/|hp-geo-blocked\.html$)") { set $hp_geo_block 0; }',
        "if ($hp_geo_block) { return 451; }",
        "error_page 451 /hp-geo-blocked.html;",
        f"location = /hp-geo-blocked.html {{ internal; root {WWW}; }}",
        "",
    ]
    return "\n".join(lines)


def _ngx_country(cc):
    path = f"{NGX}/{cc}.conf"
    if not os.path.exists(path):
        ensure_dir(NGX, 0o755)
        with open(path + ".tmp", "w") as fh:
            fh.write("".join(f"{n} {cc};\n" for n in _nets_of(cc)))
        os.replace(path + ".tmp", path)
    return path


def apply_nginx(rules=None):
    rules = rules or load_rules()
    owners = _owners()
    sites = {d: s for d, s in rules["sites"].items() if _site_active(s) and d in owners}
    need = sorted({c for s in sites.values() if s.get("mode") in ("allow", "deny") for c in s.get("countries") or []})
    if need:
        build_cidr()
    conf = ["# iaachz-panel: países e IPs por dominio (generado por v-hp; no editar)"]
    if sites:
        conf += ["geo $hp_cc {", "\tdefault --;"]
        conf += [f"\t{n} LO;" for n in LOCAL4 + LOCAL6 + server_ips()]
        conf += [f"\tinclude {_ngx_country(cc)};" for cc in need]
        conf.append("}")
        for d, s in sorted(sites.items()):
            for key, var in (("allow_ips", "ia"), ("deny_ips", "id")):
                if s.get(key):
                    conf += [f"geo $hp_{var}_{_h(d)} {{", "\tdefault 0;"] + [f"\t{n} 1;" for n in s[key]] + ["}"]
    # escribe solo lo que cambia, guardando copia para volver atrás si nginx no acepta la configuración
    backup = {}

    def put(path, text):
        cur = open(path).read() if os.path.exists(path) else None
        if cur == text:
            return
        backup[path] = cur
        if text is None:
            if os.path.exists(path):
                os.remove(path)
            return
        with open(path + ".hptmp", "w") as fh:
            fh.write(text)
        os.chmod(path + ".hptmp", 0o644)
        os.replace(path + ".hptmp", path)

    put(NGINX_CONF, "\n".join(conf) + "\n")
    if sites and not os.path.exists(WWW + "/hp-geo-blocked.html"):
        ensure_dir(WWW, 0o755)
        with open(WWW + "/hp-geo-blocked.html", "w") as fh:
            fh.write(BLOCK_PAGE)
    for d, u in owners.items():
        cdir = f"{home_of(u)}/conf/web/{d}"
        if not os.path.isdir(cdir):
            continue
        text = _snippet(d, sites[d]) if d in sites else None
        for name in SNIPPETS:
            put(f"{cdir}/{name}", text)
    if not backup:
        return {"changed": False}
    t = run(["nginx", "-t"], timeout=60)
    if t.returncode != 0:
        for p, b in backup.items():
            if b is None:
                if os.path.exists(p):
                    os.remove(p)
            else:
                with open(p, "w") as fh:
                    fh.write(b)
        raise HPError("nginx rechazó la configuración (se dejó como estaba): " + t.stderr.strip()[-800:])
    run(["systemctl", "reload", "nginx"], timeout=60)
    return {"changed": True}


# ------------------------------------------------------------------ cortafuegos (ipset + iptables)

def services():
    ssh = []
    p = run(["sshd", "-T"], timeout=15)
    for line in p.stdout.splitlines():
        if line.startswith("port "):
            ssh.append(line.split()[1])
    panel = hestia_conf().get("BACKEND_PORT") or "8083"
    return {
        "ssh": {"label": "SSH", "ports": ",".join(ssh or ["22"])},
        "panel": {"label": "Panel de Hestia", "ports": panel},
        "ftp": {"label": "FTP", "ports": "21,12000:12100"},
    }


def _ipt(fam, *args):
    return run(["iptables" if fam == 4 else "ip6tables", "-w", "10"] + list(args), timeout=30)


def _set_load(name, fam, nets):
    """Carga (o reemplaza de forma atómica) un ipset hash:net."""
    family = "inet" if fam == 4 else "inet6"
    tmp = name + "-t"
    lines = [f"create {tmp} hash:net family {family} hashsize 4096 maxelem 2000000 -exist", f"flush {tmp}"]
    lines += [f"add {tmp} {n} -exist" for n in nets]
    p = run(["ipset", "restore", "-exist"], input="\n".join(lines) + "\n", timeout=300)
    if p.returncode != 0:
        raise HPError(f"ipset ({name}): " + p.stderr.strip()[-500:])
    run(["ipset", "create", name, "hash:net", "family", family, "hashsize", "4096", "maxelem", "2000000", "-exist"], timeout=30)
    run(["ipset", "swap", tmp, name], timeout=30)
    run(["ipset", "destroy", tmp], timeout=30)


def _split(nets, fam):
    return [n for n in nets if (":" in n) == (fam == 6)]


def _drop_jumps(fam, chain_target):
    for parent in ("INPUT", "DOCKER-USER"):
        p = _ipt(fam, "-S", parent)
        for line in p.stdout.splitlines():
            if line.startswith(f"-A {parent} ") and f"-j {chain_target}" in line.split(" -m comment")[0] + " ":
                _ipt(fam, *(["-D"] + line.split()[1:]))


def _fw_clear():
    for fam in (4, 6):
        for ch in ("HP_GEO", "HP_ADMIN"):
            _drop_jumps(fam, ch)
            _ipt(fam, "-F", ch)
            _ipt(fam, "-X", ch)
    if which("ipset"):
        p = run(["ipset", "list", "-n"], timeout=30)
        for name in p.stdout.split():
            if name.startswith("hp-"):
                run(["ipset", "destroy", name], timeout=30)


def fw_apply(rules=None):
    rules = rules or load_rules()
    adm, srv = rules["admin"], rules["server"]
    use_admin = bool(adm.get("enabled") and adm.get("services"))
    use_geo = bool(srv.get("countries") or srv.get("ips"))
    if not use_admin and not use_geo:
        _fw_clear()
        return {"active": False}
    if not which("ipset"):
        raise HPError("Falta ipset: pulsa «Activar» en Seguridad")
    if adm.get("countries") or srv.get("countries"):
        build_cidr()
    svc = services()
    ports = ",".join(svc[s]["ports"] for s in adm.get("services", []) if s in svc)
    mine = server_ips()
    docker = _ipt(4, "-S", "DOCKER-USER").returncode == 0
    for fam in (4, 6):
        _drop_jumps(fam, "HP_GEO")
        _drop_jumps(fam, "HP_ADMIN")
        for ch in ("HP_GEO", "HP_ADMIN"):
            _ipt(fam, "-N", ch)
            _ipt(fam, "-F", ch)
        local = (LOCAL4 if fam == 4 else LOCAL6) + _split(mine, fam)
        allow = _split(adm.get("ips", []), fam)
        for c in adm.get("countries", []):
            allow += _nets_of(c, fam)
        if use_admin:
            _set_load(f"hp-adm{fam}", fam, allow)
        rules_admin = [["-i", "lo", "-j", "RETURN"], ["-m", "conntrack", "--ctstate", "ESTABLISHED,RELATED", "-j", "RETURN"]]
        rules_admin += [["-s", n, "-j", "RETURN"] for n in local]
        rules_admin += [["-m", "set", "--match-set", f"hp-adm{fam}", "src", "-j", "RETURN"], ["-j", "DROP"]]
        if use_admin:
            for r in rules_admin:
                _ipt(fam, "-A", "HP_ADMIN", *r)
        if use_geo:
            geo = [["-i", "lo", "-j", "RETURN"], ["-m", "conntrack", "--ctstate", "ESTABLISHED,RELATED", "-j", "RETURN"]]
            geo += [["-s", n, "-j", "RETURN"] for n in local]
            if use_admin:
                geo.append(["-m", "set", "--match-set", f"hp-adm{fam}", "src", "-j", "RETURN"])
            ips = _split(srv.get("ips", []), fam)
            if ips:
                _set_load(f"hp-bip{fam}", fam, ips)
                geo.append(["-m", "set", "--match-set", f"hp-bip{fam}", "src", "-j", "DROP"])
            for c in srv.get("countries", []):
                nets = _nets_of(c, fam)
                if nets:
                    _set_load(f"hp-b{fam}-{c.lower()}", fam, nets)
                    geo.append(["-m", "set", "--match-set", f"hp-b{fam}-{c.lower()}", "src", "-j", "DROP"])
            for r in geo:
                _ipt(fam, "-A", "HP_GEO", *r)
        pos = 1
        if use_geo:
            _ipt(fam, "-I", "INPUT", str(pos), "-j", "HP_GEO")
            pos += 1
            if docker and fam == 4:
                _ipt(fam, "-I", "DOCKER-USER", "1", "-j", "HP_GEO")
        if use_admin and ports:
            _ipt(fam, "-I", "INPUT", str(pos), "-p", "tcp", "-m", "multiport", "--dports", ports, "-j", "HP_ADMIN")
    # quita los conjuntos que ya no se usan
    p = run(["ipset", "list", "-n"], timeout=30)
    keep = {f"hp-adm{f}" for f in (4, 6) if use_admin} | {f"hp-bip{f}" for f in (4, 6) if srv.get("ips")}
    keep |= {f"hp-b{f}-{c.lower()}" for f in (4, 6) for c in srv.get("countries", [])}
    for name in p.stdout.split():
        if name.startswith("hp-") and name not in keep:
            run(["ipset", "destroy", name], timeout=30)
    save_json(DATA + "/geo-fw.json", {"applied": now()})
    return {"active": True}


def fw_counters():
    """Paquetes descartados por país desde la última aplicación."""
    out = {}
    for fam in (4, 6):
        p = _ipt(fam, "-L", "HP_GEO", "-v", "-x", "-n")
        for line in p.stdout.splitlines():
            m = re.match(r"\s*(\d+)\s+(\d+)\s+DROP.*match-set hp-b[46]-([a-z]{2}) src", line)
            if m:
                cc = m.group(3).upper()
                out[cc] = out.get(cc, 0) + int(m.group(1))
            m = re.match(r"\s*(\d+)\s+(\d+)\s+DROP.*match-set hp-bip[46] src", line)
            if m:
                out["IP"] = out.get("IP", 0) + int(m.group(1))
    return out


def apply_all():
    rules = load_rules()
    apply_nginx(rules)
    fw_apply(rules)


def boot():
    """Al arrancar el servidor (servicio iaachz-panel-firewall): vuelve a cargar los conjuntos y las cadenas."""
    rules = load_rules()
    if rules["admin"].get("enabled") or rules["server"].get("countries") or rules["server"].get("ips"):
        fw_apply(rules)
    return {"ok": True}


# ------------------------------------------------------------------ comprobaciones de seguridad

def _in_nets(ip, nets):
    try:
        a = ipaddress.ip_address(ip)
    except ValueError:
        return False
    for n in nets:
        try:
            if a in ipaddress.ip_network(n, strict=False):
                return True
        except ValueError:
            pass
    return False


def _allowed_by(ip, nets, ccs):
    """¿La lista blanca deja pasar esta IP? (las redes locales y privadas siempre pasan)"""
    try:
        if not ipaddress.ip_address(ip).is_global:
            return True
    except ValueError:
        return False
    return _in_nets(ip, nets) or (bool(ccs) and country(ip) in ccs)


def ssh_peers():
    p = run(["ss", "-Htn", "state", "established", "( sport = :22 )"], timeout=10)
    out = []
    for line in p.stdout.splitlines():
        f = line.split()
        if len(f) >= 4:
            ip = f[-1].rsplit(":", 1)[0].strip("[]")
            if ip.startswith("::ffff:"):
                ip = ip[7:]
            if ip not in out:
                out.append(ip)
    return out


def check(client, draft=None):
    """Quién quedaría fuera con el borrador de reglas (tu IP actual y las sesiones SSH abiertas)."""
    rules = load_rules()
    adm = dict(rules["admin"], **(draft or {}).get("admin", {})) if draft else rules["admin"]
    srv = dict(rules["server"], **(draft or {}).get("server", {})) if draft else rules["server"]
    adm_ips, adm_cc = _nets(adm.get("ips", [])), _ccs(adm.get("countries", []))
    srv_ips, srv_cc = _nets(srv.get("ips", [])), _ccs(srv.get("countries", []))

    def verdict(ip):
        cc = country(ip)
        in_admin = _allowed_by(ip, adm_ips, adm_cc)
        blocked_srv = not (adm.get("enabled") and in_admin) and (cc in srv_cc or _in_nets(ip, srv_ips))
        try:
            local = not ipaddress.ip_address(ip).is_global
        except ValueError:
            local = False
        ok_admin = not adm.get("enabled") or in_admin
        return {"ip": ip, "cc": cc, "admin": ok_admin or local, "server": not blocked_srv or local}

    return {"client": verdict(client) if client else None, "ssh": [verdict(ip) for ip in ssh_peers()],
            "services": services()}


# ------------------------------------------------------------------ cambios desde el panel

RE_SSH_FAIL = re.compile(r"(?:Failed \S+ for (?:invalid user )?\S* ?|Invalid user \S* ?)from (\S+) port")


def ssh_attacks():
    """Intentos fallidos de entrar por SSH en las últimas 24 h (por IP y país)."""
    p = run(["journalctl", "-u", "ssh", "-u", "sshd", "--since", "-24h", "--no-pager", "-o", "cat"], timeout=60)
    ips = {}
    for line in p.stdout.splitlines():
        m = RE_SSH_FAIL.search(line)
        if m:
            ips[m.group(1)] = ips.get(m.group(1), 0) + 1
    ccs = {}
    for ip, n in ips.items():
        c = country(ip) or "--"
        ccs[c] = ccs.get(c, 0) + n
    top = sorted(ips.items(), key=lambda x: -x[1])[:6]
    return {"failed": sum(ips.values()), "ips": len(ips), "top": [{"ip": ip, "n": n, "cc": country(ip)} for ip, n in top],
            "countries": [{"cc": c, "n": n} for c, n in sorted(ccs.items(), key=lambda x: -x[1])[:8]]}


def view():
    r = load_rules()
    owners = _owners()
    sites = [{"domain": d, "user": u, **r["sites"].get(d, {"mode": "off", "countries": [], "allow_ips": [], "deny_ips": []})}
             for d, u in sorted(owners.items())]
    pend = r.get("pending")
    return {"status": status(), "sites": sites, "admin": r["admin"], "server": r["server"], "services": services(),
            "pending": {"until": pend["until"]} if pend and pend.get("until", 0) > now() else None,
            "applied": load_json(DATA + "/geo-fw.json", {}).get("applied"), "ssh": ssh_attacks(), "now": now()}


def site_set(user, domain, data):
    check_web_domain(user, domain)
    mode = data.get("mode") if data.get("mode") in ("off", "allow", "deny") else "off"
    rule = {"user": user, "mode": mode, "countries": _ccs(data.get("countries")),
            "allow_ips": _nets(data.get("allow_ips")), "deny_ips": _nets(data.get("deny_ips"))}
    if mode == "allow" and not rule["countries"] and not rule["allow_ips"]:
        raise HPError("Elige al menos un país o una IP con acceso")
    if mode == "allow" or mode == "deny":
        if rule["countries"] and not os.path.exists(MMDB):
            raise HPError("Falta la base de países: pulsa «Activar» en Seguridad")
    with locked("geo"):
        r = load_rules()
        prev = json.loads(json.dumps(r))
        if _site_active(rule):
            r["sites"][domain] = rule
        else:
            r["sites"].pop(domain, None)
        save_json(RULES, r)
        try:
            apply_nginx(r)
        except Exception:
            save_json(RULES, prev)
            raise
    log_action(user, f"Acceso por país de {domain}: {mode} {','.join(rule['countries'])}", "Info", "Web")
    return view()


def _schedule_rollback():
    run(["systemctl", "stop", "hp-fw-rollback.timer", "hp-fw-rollback.service"], timeout=30)
    run(["systemctl", "reset-failed", "hp-fw-rollback.timer", "hp-fw-rollback.service"], timeout=30)
    p = run(["systemd-run", "--unit", "hp-fw-rollback", f"--on-active={ROLLBACK_SECS}", "--timer-property=AccuracySec=1s",
             "/usr/bin/python3", "-I", HESTIA + "/bin/v-hp", "fw-rollback"], timeout=30)
    if p.returncode != 0:
        raise HPError("No se pudo programar la vuelta atrás: " + p.stderr.strip()[-300:])


def firewall_set(data, client):
    """Guarda lista blanca de administración y bloqueos del servidor; se deshace sola si no se confirma."""
    adm_in, srv_in = data.get("admin") or {}, data.get("server") or {}
    svc = services()
    adm = {"enabled": bool(adm_in.get("enabled")), "services": [s for s in adm_in.get("services", []) if s in svc],
           "ips": _nets(adm_in.get("ips")), "countries": _ccs(adm_in.get("countries"))}
    srv = {"countries": _ccs(srv_in.get("countries")), "ips": _nets(srv_in.get("ips"))}
    if adm["enabled"] and not adm["services"]:
        raise HPError("Marca al menos un servicio (SSH, panel o FTP)")
    if adm["enabled"] and not adm["ips"] and not adm["countries"]:
        raise HPError("Añade al menos tu IP o tu país a la lista blanca")
    if (adm["countries"] or srv["countries"]) and not os.path.exists(MMDB):
        raise HPError("Falta la base de países: pulsa «Activar» en Seguridad")
    if (adm["enabled"] or srv["countries"] or srv["ips"]) and not which("ipset"):
        raise HPError("Falta ipset: pulsa «Activar» en Seguridad")
    # nunca dejar fuera a quien está aplicando el cambio
    if client:
        cc = country(client)
        if adm["enabled"] and not _allowed_by(client, adm["ips"], adm["countries"]):
            raise HPError(f"Tu conexión actual ({client}{', ' + cc if cc else ''}) quedaría fuera de la lista blanca: añádela antes de guardar")
        if not (adm["enabled"] and _allowed_by(client, adm["ips"], adm["countries"])):
            if cc in srv["countries"] or _in_nets(client, srv["ips"]):
                raise HPError(f"Estarías bloqueando tu propia conexión ({client}{', ' + cc if cc else ''})")
    with locked("geo"):
        r = load_rules()
        safe = r.get("pending", {}).get("prev") or {"admin": r["admin"], "server": r["server"]}
        r["admin"], r["server"] = adm, srv
        # vuelta atrás solo si el cambio puede dejar fuera a alguien: lista blanca nueva o distinta, o países nuevos
        risky = (adm["enabled"] and adm != safe["admin"]) or bool(set(srv["countries"]) - set(safe["server"].get("countries", [])))
        was = r.get("pending") if (r.get("pending") or {}).get("until", 0) > now() else None
        r["pending"] = {"until": now() + ROLLBACK_SECS, "prev": safe} if risky else was  # un cambio pendiente sigue pendiente
        save_json(RULES, r)
        try:
            fw_apply(r)
            if risky:
                _schedule_rollback()
        except Exception:
            r["admin"], r["server"], r["pending"] = safe["admin"], safe["server"], None
            save_json(RULES, r)
            fw_apply(r)
            raise
    log_action("admin", "Cortafuegos de iaachz-panel: lista blanca " + ("activa" if adm["enabled"] else "inactiva") +
               f", {len(srv['countries'])} países y {len(srv['ips'])} IPs bloqueados", "Info", "Firewall")
    return view()


def confirm():
    with locked("geo"):
        r = load_rules()
        r["pending"] = None
        save_json(RULES, r)
    run(["systemctl", "stop", "hp-fw-rollback.timer", "hp-fw-rollback.service"], timeout=30)
    return view()


def rollback():
    with locked("geo"):
        r = load_rules()
        pend = r.get("pending")
        if not pend:
            return {"ok": True, "rolled_back": False}
        r["admin"], r["server"] = pend["prev"]["admin"], pend["prev"]["server"]
        r["pending"] = None
        save_json(RULES, r)
        fw_apply(r)
    log_action("admin", "Cortafuegos de iaachz-panel: cambio sin confirmar, se volvió a la configuración anterior", "Warning", "Firewall")
    notify("admin", "Cortafuegos: cambio deshecho", "No se confirmó el cambio de acceso en 3 minutos y se volvió a la configuración anterior.")
    return {"ok": True, "rolled_back": True}


def block_ip(ip, scope, client, domain="", user=""):
    """Bloqueo rápido desde el explorador de registros o el mapa."""
    ip = _nets([ip])[0]
    if client and _in_nets(client, [ip]):
        raise HPError("Esa es tu propia IP")
    if scope == "site":
        r = load_rules()
        s = r["sites"].get(domain) or {"mode": "off", "countries": [], "allow_ips": [], "deny_ips": []}
        return site_set(_owners().get(domain) or user, domain, dict(s, deny_ips=s.get("deny_ips", []) + [ip]))
    r = load_rules()
    return firewall_set({"admin": r["admin"], "server": dict(r["server"], ips=r["server"].get("ips", []) + [ip])}, client)


def block_country(cc, scope, client, domain="", user=""):
    cc = _ccs([cc])[0]
    r = load_rules()
    if scope == "site":
        s = r["sites"].get(domain) or {"mode": "deny", "countries": [], "allow_ips": [], "deny_ips": []}
        if s.get("mode") == "allow":
            s = dict(s, countries=[c for c in s["countries"] if c != cc])
        else:
            s = dict(s, mode="deny", countries=s.get("countries", []) + [cc])
        return site_set(_owners().get(domain) or user, domain, s)
    return firewall_set({"admin": r["admin"], "server": dict(r["server"], countries=r["server"].get("countries", []) + [cc])}, client)


def sync():
    """Cron: quita reglas de dominios borrados y repone los fragmentos de nginx si Hestia los regeneró."""
    r = load_rules()
    if not r["sites"]:
        return {"changed": False}
    owners = _owners()
    gone = [d for d in r["sites"] if d not in owners]
    if gone:
        with locked("geo"):
            r = load_rules()
            for d in gone:
                r["sites"].pop(d, None)
            save_json(RULES, r)
    return apply_nginx(r)


# ------------------------------------------------------------------ mapa

MAP_RANGES = {"1h": 3600, "6h": 21600, "24h": 86400, "7d": 7 * 86400, "30d": 30 * 86400}


def map_data(rng="24h"):
    from . import traffic
    span = MAP_RANGES.get(rng, 86400)
    since = now() - span
    hsince = since - since % 3600
    rd = reader()
    per = {}
    con = traffic._db()
    try:
        rows = con.execute("SELECT ip, SUM(req), SUM(err), SUM(blk) FROM web_ip WHERE ts >= ? GROUP BY ip", (hsince,)).fetchall()
    finally:
        con.close()
    for ip, req, err, blk in rows:
        cc = (rd.lookup(ip) if rd else "") or "--"
        c = per.setdefault(cc, {"req": 0, "err": 0, "blk": 0, "ips": 0, "top": []})
        c["req"] += req or 0
        c["err"] += err or 0
        c["blk"] += blk or 0
        c["ips"] += 1
        c["top"].append((req or 0, err or 0, ip))
    for c in per.values():
        c["top"] = [{"ip": ip, "req": q, "err": e} for q, e, ip in sorted(c["top"], reverse=True)[:6]]
    live = {}
    conns = traffic.connections_by_ip(limit=5000)
    for i in conns["top"]:
        cc = (rd.lookup(i["ip"]) if rd else "") or "--"
        e = live.setdefault(cc, {"conns": 0, "ips": 0})
        e["conns"] += i["conns"]
        e["ips"] += 1
    r = load_rules()
    blocked = {"server": r["server"].get("countries", []),
               "sites": {d: s for d, s in r["sites"].items() if s.get("mode") in ("allow", "deny") and s.get("countries")}}
    return {"range": rng, "countries": per, "live": live, "live_total": conns["total"], "fw": fw_counters() if which("ipset") else {},
            "blocked": blocked, "db": rd is not None, "attribution": ATTRIBUTION}
