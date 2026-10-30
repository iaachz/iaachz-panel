"""Muestreo continuo para el modo "En vivo" del Monitor.

Un servicio (iaachz-panel-sampler) toma una muestra cada 2 s y guarda las últimas 5 minutos en
/usr/local/hestia/data/hp/live.json. Así, al abrir o refrescar el Monitor, los gráficos en vivo
aparecen ya con los últimos 5 minutos y `metrics-live` responde sin tener que esperar a medir.
"""

import json
import os
import time

from . import metrics, traffic
from .common import DATA, ensure_dir, load_json

LIVE = DATA + "/live.json"
INTERVAL = 2.0
KEEP = 150  # 150 × 2 s = 5 minutos


def _snapshot():
    return {"t": time.time(), "cpu": metrics._cpu_times(), "cores": metrics._per_cpu(), "net": metrics._net_bytes(),
            "io": metrics._disk_io(), "web": traffic.sample()}


def _point(a, b):
    dt = max(b["t"] - a["t"], 0.001)
    tot = max(b["cpu"][0] - a["cpu"][0], 1)
    cores = [round(100 * (1 - (y[1] - x[1]) / max(y[0] - x[0], 1)), 1) for x, y in zip(a["cores"], b["cores"])]
    mem = metrics._meminfo()
    disks = metrics._disks()
    root = next((d for d in disks if d["mount"] == "/"), disks[0] if disks else {"percent": 0})
    la = os.getloadavg()
    return {
        "time": round(b["t"], 1),
        "cpu": round(100 * (1 - (b["cpu"][1] - a["cpu"][1]) / tot), 1),
        "cpu_cores": cores,
        "mem": mem["percent"], "swap": mem["swap_percent"], "disk": root["percent"],
        "load": [round(x, 2) for x in la],
        "net": {"rx": int(max(b["net"][0] - a["net"][0], 0) / dt), "tx": int(max(b["net"][1] - a["net"][1], 0) / dt)},
        "io": {"read": int(max(b["io"][0] - a["io"][0], 0) / dt), "write": int(max(b["io"][1] - a["io"][1], 0) / dt)},
        "web": traffic.rates(a["web"], b["web"]),
    }


def _write(points):
    tmp = LIVE + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(points, fh, separators=(",", ":"))
    os.chmod(tmp, 0o600)
    os.replace(tmp, LIVE)


def loop():
    ensure_dir(DATA)
    points = [p for p in load_json(LIVE, []) if time.time() - p.get("time", 0) < KEEP * INTERVAL]
    prev = _snapshot()
    while True:
        # mantiene el ritmo aunque una muestra tarde (p. ej. MySQL ocupado)
        time.sleep(max(0.2, INTERVAL - (time.time() - prev["t"])))
        cur = _snapshot()
        try:
            points.append(_point(prev, cur))
            del points[:-KEEP]
            _write(points)
        except Exception as e:  # una muestra fallida no debe parar el servicio
            print("iaachz-panel-sampler:", e, flush=True)
        prev = cur


def recent(max_age=6):
    """Última muestra si es reciente (el servicio está en marcha); si no, None."""
    pts = load_json(LIVE, [])
    if pts and time.time() - pts[-1].get("time", 0) <= max_age:
        return pts[-1]
    return None


def history():
    now = time.time()
    return [p for p in load_json(LIVE, []) if now - p.get("time", 0) <= KEEP * INTERVAL + 5]
