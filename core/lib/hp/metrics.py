"""Métricas del servidor: instantáneas en vivo e historial (SQLite, 1 muestra/minuto)."""

import os
import platform
import sqlite3
import time

from . import traffic
from .common import DATA, HESTIA, ensure_dir, hestia_conf, load_json, run, save_json

DB = DATA + "/metrics.db"
STATE = DATA + "/metrics_state.json"
RAW_KEEP = 8 * 86400
HOURLY_KEEP = 400 * 86400
FIELDS = ("cpu", "mem", "swap", "disk", "load1", "load5", "load15",
          "rx", "tx", "rd", "wr", "procs", "mem_used", "mem_total",
          # tráfico (ver traffic.py): peticiones/s y conexiones nuevas/s de nginx, conexiones activas,
          # TCP establecidas y nuevas/s, consultas MySQL/s y conexiones MySQL, respuestas 2xx..5xx por minuto
          "req", "conn", "active", "tcp_est", "tcp_new", "db_qps", "db_conn", "s2", "s3", "s4", "s5",
          # picos reales del minuto, tomados de las muestras de 2 s (sampler.py)
          "cpu_pk", "req_pk")
PEAKS = {"cpu_pk", "req_pk"}


def _agg(field):
    """Al juntar minutos en horas o en tramos de gráfico: los picos se conservan, el resto se promedia."""
    return f"MAX({field})" if field in PEAKS else f"AVG({field})"
IGNORED_FS = {"tmpfs", "devtmpfs", "squashfs", "overlay", "efivarfs", "proc", "sysfs", "cgroup2", "nsfs"}


# --------------------------------------------------------------- lecturas /proc

def _cpu_times():
    with open("/proc/stat") as fh:
        parts = [int(x) for x in fh.readline().split()[1:]]
    idle = parts[3] + (parts[4] if len(parts) > 4 else 0)
    return sum(parts), idle


def _per_cpu():
    res = []
    with open("/proc/stat") as fh:
        for line in fh:
            if line.startswith("cpu") and line[3].isdigit():
                p = [int(x) for x in line.split()[1:]]
                res.append((sum(p), p[3] + p[4]))
    return res


def _net_bytes():
    rx = tx = 0
    with open("/proc/net/dev") as fh:
        for line in fh.readlines()[2:]:
            name, data = line.split(":", 1)
            name = name.strip()
            if name == "lo" or name.startswith(("veth", "docker", "br-")):
                continue
            f = data.split()
            rx += int(f[0])
            tx += int(f[8])
    return rx, tx


def _disk_io():
    """Bytes leídos/escritos sumando solo discos físicos (no particiones, loop, raid ni dm)."""
    rd = wr = 0
    try:
        disks = {d for d in os.listdir("/sys/block")
                 if not d.startswith(("loop", "ram", "zram", "dm-", "md", "sr", "fd"))}
        with open("/proc/diskstats") as fh:
            for line in fh:
                f = line.split()
                if f[2] in disks:
                    rd += int(f[5]) * 512
                    wr += int(f[9]) * 512
    except FileNotFoundError:
        pass
    return rd, wr


def _meminfo():
    m = {}
    with open("/proc/meminfo") as fh:
        for line in fh:
            k, v = line.split(":", 1)
            m[k] = int(v.split()[0]) * 1024
    total = m.get("MemTotal", 1)
    avail = m.get("MemAvailable", m.get("MemFree", 0))
    used = total - avail
    stotal = m.get("SwapTotal", 0)
    sused = stotal - m.get("SwapFree", 0)
    return {"total": total, "used": used, "available": avail,
            "cached": m.get("Cached", 0) + m.get("Buffers", 0),
            "percent": round(used * 100 / total, 1),
            "swap_total": stotal, "swap_used": sused,
            "swap_percent": round(sused * 100 / stotal, 1) if stotal else 0}


def _disks():
    seen, res = set(), []
    with open("/proc/mounts") as fh:
        for line in fh:
            dev, mnt, fs = line.split()[:3]
            if fs in IGNORED_FS or dev in seen or mnt.startswith(("/snap", "/proc", "/sys", "/run", "/dev", "/var/lib/docker")):
                continue
            if not dev.startswith("/"):
                continue
            seen.add(dev)
            try:
                st = os.statvfs(mnt)
            except OSError:
                continue
            total = st.f_blocks * st.f_frsize
            if total < 512 * 1024 * 1024:
                continue
            free = st.f_bavail * st.f_frsize
            used = total - st.f_bfree * st.f_frsize
            res.append({"mount": mnt, "device": dev, "fs": fs, "total": total, "used": used, "free": free,
                        "percent": round(used * 100 / (used + free), 1) if used + free else 0,
                        "inodes_percent": round((st.f_files - st.f_ffree) * 100 / st.f_files, 1) if st.f_files else 0})
    return res


def _os_name():
    try:
        with open("/etc/os-release") as fh:
            for line in fh:
                if line.startswith("PRETTY_NAME="):
                    return line.split("=", 1)[1].strip().strip('"')
    except FileNotFoundError:
        pass
    return platform.system()


def _uptime():
    with open("/proc/uptime") as fh:
        return int(float(fh.read().split()[0]))


def _cpu_model():
    try:
        with open("/proc/cpuinfo") as fh:
            for line in fh:
                if line.startswith("model name"):
                    return line.split(":", 1)[1].strip()
    except FileNotFoundError:
        pass
    return platform.machine()


def top_processes(limit=8):
    p = run(["ps", "-eo", "pid,user:16,pcpu,pmem,rss,etimes,comm", "--sort=-pcpu", "--no-headers"], timeout=10)
    res = []
    me = os.getpid()
    for line in p.stdout.splitlines():
        f = line.split(None, 6)
        if len(f) == 7 and int(f[0]) != me and f[6] not in ("ps", "v-hp"):
            if len(res) >= limit:
                break
            res.append({"pid": int(f[0]), "user": f[1], "cpu": float(f[2]), "mem": float(f[3]),
                        "rss": int(f[4]) * 1024, "time": int(f[5]), "cmd": f[6]})
    return res


def _measure():
    """Mide CPU, red, disco y tráfico durante 1 s (solo si el servicio de muestreo no está activo)."""
    t1, i1 = _cpu_times()
    pc1 = _per_cpu()
    n1 = _net_bytes()
    d1 = _disk_io()
    w1 = traffic.sample()
    time.sleep(1.0)
    t2, i2 = _cpu_times()
    pc2 = _per_cpu()
    n2 = _net_bytes()
    d2 = _disk_io()
    w2 = traffic.sample()
    dt = max(t2 - t1, 1)
    return {
        "time": time.time(),
        "cpu": round(100 * (1 - (i2 - i1) / dt), 1),
        "cpu_cores": [round(100 * (1 - (b[1] - a[1]) / max(b[0] - a[0], 1)), 1) for a, b in zip(pc1, pc2)],
        "net": {"rx": int(n2[0] - n1[0]), "tx": int(n2[1] - n1[1])},
        "io": {"read": int(d2[0] - d1[0]), "write": int(d2[1] - d1[1])},
        "web": traffic.rates(w1, w2),
    }


def live():
    from . import sampler
    s = sampler.recent() or _measure()
    la = os.getloadavg()
    conf = hestia_conf()
    procs = len([d for d in os.listdir("/proc") if d.isdigit()])
    return {
        "time": s["time"],
        "hostname": platform.node(),
        "os": _os_name(),
        "kernel": platform.release(),
        "cpu_model": _cpu_model(),
        "cores": os.cpu_count(),
        "uptime": _uptime(),
        "hestia_version": conf.get("VERSION", ""),
        "cpu": s["cpu"],
        "cpu_cores": s["cpu_cores"],
        "load": [round(x, 2) for x in la],
        "mem": _meminfo(),
        "disks": _disks(),
        "net": s["net"],
        "io": s["io"],
        "procs": procs,
        "top": top_processes(),
        "web": s["web"],
        "web_ips": traffic.connections_by_ip(),
    }


# ---------------------------------------------------------------- historial

def _db():
    ensure_dir(DATA)
    con = sqlite3.connect(DB, timeout=10)
    cols = ", ".join(f"{f} REAL" for f in FIELDS)
    con.execute(f"CREATE TABLE IF NOT EXISTS raw (ts INTEGER PRIMARY KEY, {cols})")
    con.execute(f"CREATE TABLE IF NOT EXISTS hourly (ts INTEGER PRIMARY KEY, {cols})")
    for table in ("raw", "hourly"):  # bases creadas por versiones anteriores
        have = {r[1] for r in con.execute(f"PRAGMA table_info({table})")}
        for f in FIELDS:
            if f not in have:
                con.execute(f"ALTER TABLE {table} ADD COLUMN {f} REAL")
    return con


def collect():
    """Llamado por cron cada minuto: calcula promedios desde la muestra anterior."""
    ts = int(time.time())
    total, idle = _cpu_times()
    rx, tx = _net_bytes()
    rd, wr = _disk_io()
    prev = load_json(STATE, {})
    web_now = traffic.sample()
    cur = {"ts": ts, "total": total, "idle": idle, "rx": rx, "tx": tx, "rd": rd, "wr": wr, "web": web_now}
    save_json(STATE, cur)
    codes = traffic.collect_logs(ts)
    if not prev or ts - prev.get("ts", 0) <= 0 or ts - prev["ts"] > 600:
        return {"skipped": True}
    dt = ts - prev["ts"]
    dtot = max(total - prev["total"], 1)
    mem = _meminfo()
    disks = _disks()
    root = next((d for d in disks if d["mount"] == "/"), disks[0] if disks else {"percent": 0})
    la = os.getloadavg()
    row = {
        "cpu": round(100 * (1 - (idle - prev["idle"]) / dtot), 2),
        "mem": mem["percent"], "swap": mem["swap_percent"], "disk": root["percent"],
        "load1": la[0], "load5": la[1], "load15": la[2],
        "rx": max(rx - prev["rx"], 0) / dt, "tx": max(tx - prev["tx"], 0) / dt,
        "rd": max(rd - prev["rd"], 0) / dt, "wr": max(wr - prev["wr"], 0) / dt,
        "procs": len([d for d in os.listdir("/proc") if d.isdigit()]),
        "mem_used": mem["used"], "mem_total": mem["total"],
    }
    web = traffic.rates(prev["web"], web_now) if prev.get("web") else {}
    for f in ("req", "conn", "active", "tcp_est", "tcp_new", "db_qps", "db_conn"):
        row[f] = web.get(f)
    row.update(codes)
    # Las muestras de 2 s del servicio de muestreo alimentan este minuto: los valores instantáneos
    # (conexiones abiertas, RAM) pasan a ser el promedio del minuto y se guardan los picos reales.
    from . import sampler
    pts = [p for p in sampler.history() if prev["ts"] < p.get("time", 0) <= ts]
    row["cpu_pk"], row["req_pk"] = row["cpu"], row["req"]
    if len(pts) >= 5:
        avg = lambda vals: (sum(vals) / len(vals)) if vals else None
        w = [p.get("web") or {} for p in pts]
        row["cpu_pk"] = max(p["cpu"] for p in pts)
        reqs = [x["req"] for x in w if x.get("req") is not None]
        row["req_pk"] = max(reqs) if reqs else row["req"]
        row["mem"] = round(avg([p["mem"] for p in pts]), 2)
        for f in ("active", "tcp_est", "db_conn"):
            v = avg([x[f] for x in w if x.get(f) is not None])
            if v is not None:
                row[f] = round(v, 2)
    con = _db()
    with con:
        con.execute(f"INSERT OR REPLACE INTO raw (ts, {', '.join(FIELDS)}) VALUES ({', '.join('?' * (len(FIELDS) + 1))})",
                    [ts] + [row[f] for f in FIELDS])
        hour = ts - ts % 3600
        avgs = ", ".join(_agg(f) for f in FIELDS)
        con.execute(f"INSERT OR REPLACE INTO hourly (ts, {', '.join(FIELDS)}) "
                    f"SELECT ?, {avgs} FROM raw WHERE ts >= ? AND ts < ?", (hour, hour, hour + 3600))
        con.execute("DELETE FROM raw WHERE ts < ?", (ts - RAW_KEEP,))
        con.execute("DELETE FROM hourly WHERE ts < ?", (ts - HOURLY_KEEP,))
    con.close()
    return row


RANGES = {"1h": 3600, "6h": 6 * 3600, "24h": 86400, "7d": 7 * 86400, "30d": 30 * 86400, "365d": 365 * 86400}


def history(rng="24h", points=240):
    span = RANGES.get(rng, 86400)
    since = int(time.time()) - span
    table = "raw" if span <= 7 * 86400 else "hourly"
    bucket = max(60 if table == "raw" else 3600, span // points)
    con = _db()
    avgs = ", ".join(_agg(f) for f in FIELDS)
    rows = con.execute(f"SELECT (ts / ?) * ? AS b, {avgs}, MAX(COALESCE(cpu_pk, cpu)), MAX(mem), "
                       f"MAX(COALESCE(req_pk, req)) FROM {table} "
                       f"WHERE ts >= ? GROUP BY b ORDER BY b", (bucket, bucket, since)).fetchall()
    con.close()
    series = {f: [] for f in FIELDS}
    series["cpu_max"], series["mem_max"], series["req_max"], series["ts"] = [], [], [], []
    for r in rows:
        series["ts"].append(r[0])
        for i, f in enumerate(FIELDS):
            series[f].append(round(r[i + 1], 2) if r[i + 1] is not None else None)
        series["cpu_max"].append(round(r[-3] or 0, 2))
        series["mem_max"].append(round(r[-2] or 0, 2))
        series["req_max"].append(round(r[-1], 2) if r[-1] is not None else None)
    return {"range": rng, "bucket": bucket, "series": series}


def recent_avg(field, minutes=5):
    try:
        con = _db()
        r = con.execute(f"SELECT AVG({field}), COUNT(*) FROM raw WHERE ts >= ?",
                        (int(time.time()) - minutes * 60,)).fetchone()
        con.close()
        return r[0] if r and r[1] else None
    except sqlite3.Error:
        return None


def services():
    """Estado de servicios de Hestia (v-list-sys-services) más los de iaachz-panel."""
    from .common import hestia_json
    data = hestia_json("v-list-sys-services")
    for name in ("docker", "apache2"):
        if os.path.exists(f"/usr/lib/systemd/system/{name}.service") or os.path.exists(f"/lib/systemd/system/{name}.service"):
            st = run(["systemctl", "is-active", name], timeout=10).stdout.strip()
            data[name] = {"SYSTEM": "containers" if name == "docker" else "apache (detrás de nginx)", "STATE": "running" if st == "active" else "stopped",
                          "CPU": "0", "MEM": "0", "RTIME": "0"}
    return data
