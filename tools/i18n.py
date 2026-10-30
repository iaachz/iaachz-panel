#!/usr/bin/env python3
"""Herramienta de traducciones de iaachz-panel (solo para desarrollo, no se instala).

  tools/i18n.py keys              lista los textos traducibles que hay en el código
  tools/i18n.py check [idioma]    informa de textos sin traducir / sobrantes en core/web/pro/lang/<idioma>.json
  tools/i18n.py init <idioma>     crea o completa el archivo del idioma con los textos que falten (valor vacío)

Los textos fuente están en español y hacen de clave:  __("Guardar")  (PHP),  HP.t('Guardar')  (JS),
mensajes de Python (HPError, jobs.step, asesor...) con %s en lugar de cada variable.
Para añadir un idioma: `tools/i18n.py init xx`, rellenar el JSON y añadir su nombre en HP_LANG_NAMES
(core/web/pro/inc/i18n.php).
"""

import ast
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent / "core"
LANG_DIR = ROOT / "web" / "pro" / "lang"

PHP_CALL = re.compile(r"""(?<![\w$])(?<!->)(?:__e?|HP\.t)\(\s*(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")""")


def unescape(s, q):
    return s.replace("\\" + q, q).replace("\\\\", "\\")


def web_keys():
    keys = {}
    for f in sorted((ROOT / "web").rglob("*")):
        if f.suffix not in (".php", ".js") or "lang" in f.parts[-2:-1]:
            continue
        for m in PHP_CALL.finditer(f.read_text(encoding="utf-8")):
            raw, q = (m.group(1), "'") if m.group(1) is not None else (m.group(2), '"')
            keys.setdefault(unescape(raw, q), []).append(str(f.relative_to(ROOT)))
    return keys


def py_template(node):
    if isinstance(node, ast.Constant) and isinstance(node.value, str):
        return node.value
    if isinstance(node, ast.JoinedStr):
        out = []
        for v in node.values:
            if isinstance(v, ast.Constant):
                out.append(str(v.value))
            else:
                out.append("%s")
        return "".join(out)
    return None


def py_keys():
    keys = {}
    names = {"HPError": (0,), "fail": (0,), "step": (0, 2), "add": (1, 2), "alert": (1, 2), "tr": (0,)}
    for f in sorted((ROOT / "lib" / "hp").glob("*.py")) + [ROOT / "bin" / "v-hp"]:
        tree = ast.parse(f.read_text(encoding="utf-8"))
        for n in ast.walk(tree):
            if isinstance(n, ast.Call):
                fn = n.func.id if isinstance(n.func, ast.Name) else n.func.attr if isinstance(n.func, ast.Attribute) else None
                if fn in names and (fn != "add" or f.name == "advisor.py") and (fn != "alert" or f.name == "advisor.py"):
                    for i in names[fn]:
                        if i < len(n.args):
                            t = py_template(n.args[i])
                            if t and re.search(r"[A-Za-zÁ-ú]{3}", t) and t != "%s":
                                keys.setdefault(t, []).append(f.name)
        if f.name == "v-hp":  # títulos de tareas: lambda a: f"..."
            for n in ast.walk(tree):
                if isinstance(n, ast.Lambda):
                    t = py_template(n.body)
                    if t and re.search(r"[A-Za-zÁ-ú]{3}", t) and "%s" in t or (t and " " in t and not t.startswith("admin")):
                        keys.setdefault(t, []).append(f.name)
    return keys


def all_keys():
    keys = web_keys()
    for k, v in py_keys().items():
        keys.setdefault(k, []).extend(v)
    return keys


def load(lang):
    f = LANG_DIR / f"{lang}.json"
    return json.loads(f.read_text(encoding="utf-8")) if f.exists() else {}


def save(lang, data):
    LANG_DIR.mkdir(parents=True, exist_ok=True)
    (LANG_DIR / f"{lang}.json").write_text(json.dumps(data, ensure_ascii=False, indent=1) + "\n", encoding="utf-8", newline="\n")


def main(argv):
    cmd = argv[0] if argv else "check"
    keys = all_keys()
    if cmd == "keys":
        for k in sorted(keys):
            print(k)
        print(f"# {len(keys)} textos", file=sys.stderr)
        return 0
    if cmd == "init":
        lang = argv[1]
        data = load(lang)
        for k in keys:
            data.setdefault(k, "")
        save(lang, {k: data[k] for k in keys})
        print(f"{lang}.json: {len(keys)} textos, {sum(1 for v in data.values() if not v)} sin traducir")
        return 0
    langs = [argv[1]] if len(argv) > 1 else sorted(p.stem for p in LANG_DIR.glob("*.json"))
    bad = 0
    for lang in langs:
        data = load(lang)
        missing = [k for k in keys if not data.get(k)]
        extra = [k for k in data if k not in keys]
        pct_bad = [k for k in keys if data.get(k) and k.count("%s") != data[k].count("%s")]
        print(f"{lang}: {len(keys) - len(missing)}/{len(keys)} traducidos, {len(extra)} sobrantes, {len(pct_bad)} con %s distintos")
        for k in missing[:40]:
            print("   falta:", k[:100])
        for k in pct_bad:
            print("   %s distintos:", k[:100])
        bad += len(missing) + len(pct_bad)
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
