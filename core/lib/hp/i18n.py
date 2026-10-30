"""Traducción de los mensajes que genera Python (errores, pasos de tareas, avisos del asesor).

El texto fuente está en español y hace de clave, igual que en PHP (`__`) y JavaScript (`HP.t`); las listas
son las de /usr/local/hestia/web/pro/lang/<idioma>.json. Los mensajes con datos (f-strings) se escriben en
la lista con %s en lugar de cada variable: "No existe el dominio %s". Se traducen al salir (fail, jobs,
asesor), así que el código no tiene que llamar a nada.
"""

import json
import os
import re

LANG_DIR = "/usr/local/hestia/web/pro/lang"
DEFAULT = "es"

_lang = None
_dict = {}
_patterns = None


def set_lang(code):
    global _lang, _dict, _patterns
    code = re.sub(r"[^a-z]", "", str(code or "").lower())[:5]
    _lang, _dict, _patterns = code or DEFAULT, {}, None


def lang():
    if _lang is None:
        code = os.environ.get("HP_LANG", "")
        if not code:
            try:
                from .common import settings
                code = settings().get("language", DEFAULT)
            except Exception:
                code = DEFAULT
        # "auto" depende del usuario que mira la web; sin navegador (cron, tareas) se usa el idioma por defecto
        set_lang(DEFAULT if code == "auto" else code)
    return _lang


def _load():
    global _dict, _patterns
    if _patterns is not None:
        return
    _dict = {}
    if lang() != DEFAULT:
        try:
            with open(f"{LANG_DIR}/{lang()}.json", encoding="utf-8") as fh:
                _dict = json.load(fh)
        except (OSError, ValueError):
            _dict = {}
    _patterns = []
    for k, v in _dict.items():
        if "%s" in k:
            rx = "^" + r"(.*?)".join(re.escape(p) for p in k.split("%s")) + "$"
            _patterns.append((re.compile(rx, re.S), v))
    # las plantillas con más texto fijo primero
    _patterns.sort(key=lambda p: -len(p[0].pattern))


def tr(msg):
    """Traduce un mensaje; si no hay traducción devuelve el original."""
    if not msg or not isinstance(msg, str):
        return msg
    _load()
    if not _dict:
        return msg
    hit = _dict.get(msg)
    if hit:
        return hit
    for rx, out in _patterns:
        m = rx.match(msg)
        if m:
            parts = iter(m.groups())
            return re.sub(r"%s", lambda _: next(parts, ""), out)
    return msg
