"""Importar volcados de bases de datos (como "Importar volcado" de Plesk).

Formatos: .sql, .sql.gz, .zip (primer .sql), .bz2, .xz y .zst, detectados por su contenido.
A diferencia de `v-import-database` (que usa la cuenta administradora de MySQL), aquí se importa con un
usuario temporal que SOLO tiene permisos sobre esa base de datos, así un volcado con `USE otra_bd`
no puede tocar datos de otros clientes. Se omiten CREATE DATABASE/USE y las cláusulas DEFINER
(que fallarían sin privilegios de root), igual que hace Plesk.
"""

import bz2
import gzip
import json
import lzma
import os
import re
import shutil
import subprocess
import tempfile
import zipfile

from .common import (DATA, USERS_DIR, HPError, check_user, ensure_dir, hestia, home_of, log_action, read_conf,
                     run)
from .jobs import step

UPLOADS = "/var/lib/hestia-pro/uploads"  # escribe el panel (hestiaweb); v-hp mueve el archivo antes de usarlo
STAGE = DATA + "/imports"
RE_UPLOAD = re.compile(r"^[a-f0-9]{16,64}$")
RE_DEFINER = re.compile(rb"(/\*!50013 )?DEFINER\s*=\s*(`[^`]*`|'[^']*'|\w+)@(`[^`]*`|'[^']*'|[\w.%-]+)(\s+SQL SECURITY DEFINER)?( \*/)?", re.I)
RE_SKIP = re.compile(rb"^\s*(CREATE\s+DATABASE\b|USE\s+`?[^\s;`]+`?\s*;)", re.I)


def databases(user):
    return {d["DB"]: d for d in read_conf(f"{USERS_DIR}/{user}/db.conf") if "DB" in d}


def _check_db(user, db):
    check_user(user)
    dbs = databases(user)
    if db not in dbs:
        raise HPError(f"La base de datos {db} no pertenece a {user}")
    return dbs[db]


def check_source(user, source):
    """Validación rápida (antes de lanzar la tarea) del origen del volcado."""
    if source.startswith("upload:"):
        if not RE_UPLOAD.match(source[7:]) or not os.path.isfile(f"{UPLOADS}/{source[7:]}.part"):
            raise HPError("No se encontró el archivo subido (vuelve a subirlo)")
        return
    home = os.path.realpath(home_of(user))
    path = os.path.realpath(source if source.startswith("/") else os.path.join(home, source))
    if not path.startswith(home + "/") or not os.path.isfile(path):
        raise HPError(f"No existe ese archivo dentro de tu carpeta personal ({home})")


def _stage_file(user, source):
    """Mueve el archivo subido (o copia el que el usuario indicó en su carpeta) a una zona solo-root."""
    ensure_dir(STAGE)
    if source.startswith("upload:"):
        uid = source[7:]
        if not RE_UPLOAD.match(uid):
            raise HPError("Subida no válida")
        src = f"{UPLOADS}/{uid}.part"
        if not os.path.isfile(src) or os.path.islink(src):
            raise HPError("No se encontró el archivo subido (vuelve a subirlo)")
        dst = f"{STAGE}/{uid}"
        shutil.move(src, dst)
        for meta in (f"{UPLOADS}/{uid}.json",):
            if os.path.exists(meta):
                os.remove(meta)
        return dst, True
    # ruta dentro de la carpeta personal del usuario (archivos grandes subidos por FTP/SFTP)
    home = os.path.realpath(home_of(user))
    path = os.path.realpath(source if source.startswith("/") else os.path.join(home, source))
    if not path.startswith(home + "/") or not os.path.isfile(path):
        raise HPError("El archivo debe estar dentro de tu carpeta personal (" + home + ")")
    return path, False


def _open(path):
    with open(path, "rb") as fh:
        magic = fh.read(6)
    size = os.path.getsize(path)
    raw = open(path, "rb")
    if magic[:2] == b"\x1f\x8b":
        return gzip.GzipFile(fileobj=raw), raw, size, "gzip"
    if magic[:3] == b"BZh":
        return bz2.BZ2File(raw), raw, size, "bzip2"
    if magic[:6] == b"\xfd7zXZ\x00":
        return lzma.LZMAFile(raw), raw, size, "xz"
    if magic[:4] == b"\x28\xb5\x2f\xfd":
        raw.close()
        proc = subprocess.Popen(["zstd", "-dc", path], stdout=subprocess.PIPE)
        return proc.stdout, open(path, "rb"), size, "zstd"
    if magic[:4] == b"PK\x03\x04":
        raw.close()
        zf = zipfile.ZipFile(path)
        members = [m for m in zf.infolist() if not m.is_dir() and m.filename.lower().endswith(".sql")]
        if not members:
            raise HPError("El .zip no contiene ningún archivo .sql")
        member = max(members, key=lambda m: m.file_size)
        step(f"Usando {member.filename} del archivo zip")
        return zf.open(member), open(path, "rb"), size, "zip"
    return raw, raw, size, "sql"


def _client():
    return "/usr/bin/mariadb" if os.path.exists("/usr/bin/mariadb") else "/usr/bin/mysql"


def _temp_user(user, db):
    p = hestia("v-add-database-temp-user", user, db, "mysql", "", "60")
    try:
        login = json.loads(p.stdout)["login"]
    except (ValueError, KeyError):
        raise HPError("No se pudo crear el usuario temporal de importación")
    return login["user"], login["password"]


def _cnf(dbuser, dbpass):
    fd, path = tempfile.mkstemp(prefix="hp-my-", suffix=".cnf", dir=STAGE)
    with os.fdopen(fd, "w") as fh:
        fh.write(f"[client]\nuser={dbuser}\npassword=\"{dbpass}\"\nhost=127.0.0.1\nprotocol=TCP\ndefault-character-set=utf8mb4\n")  # el usuario temporal es @'%': por TCP
    os.chmod(path, 0o600)
    return path


ROOT_CNF = "/root/.my.cnf"  # cuenta administradora que Hestia deja para root


def _root(sql, db=None, timeout=120):
    """Consulta como administrador (solo sobre nombres ya validados). Devuelve filas desescapadas."""
    args = [_client(), f"--defaults-file={ROOT_CNF}", "-N", "-B", "-e", sql] + ([db] if db else [])
    p = run(args, timeout=timeout, check=True)
    unesc = lambda v: re.sub(r"\\(.)", lambda m: {"n": "\n", "t": "\t", "0": "\0"}.get(m.group(1), m.group(1)), v)
    return [[unesc(c) for c in line.split("\t")] for line in p.stdout.split("\n") if line]


def _fix_definers(db, owner, tmp):
    """Las vistas, triggers y rutinas creados al importar quedan a nombre del usuario temporal (que se borra).
    Se recrean a nombre del usuario real de la base de datos; sus permisos siguen limitados a ella."""
    old = f"`{tmp}`@`%`"
    new = f"`{owner}`@`localhost`"
    fixed = 0
    stmts = []
    for (name,) in _root(f"SELECT table_name FROM information_schema.views WHERE table_schema='{db}' "
                         f"AND definer='{tmp}@%'"):
        row = _root(f"SHOW CREATE VIEW `{db}`.`{name}`")[0]
        stmts.append(re.sub(r"^CREATE ", "CREATE OR REPLACE ", row[1].replace(f"DEFINER={old}", f"DEFINER={new}"), count=1))
    for (name,) in _root(f"SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema='{db}' "
                         f"AND definer='{tmp}@%'"):
        row = _root(f"SHOW CREATE TRIGGER `{db}`.`{name}`")[0]
        stmts.append(f"SET SESSION sql_mode='{row[1]}'$$\nDROP TRIGGER `{name}`$$\n" + row[2].replace(f"DEFINER={old}", f"DEFINER={new}"))
    for name, kind in _root(f"SELECT routine_name, routine_type FROM information_schema.routines "
                            f"WHERE routine_schema='{db}' AND definer='{tmp}@%'"):
        row = _root(f"SHOW CREATE {kind} `{db}`.`{name}`")[0]
        stmts.append(f"SET SESSION sql_mode='{row[1]}'$$\nDROP {kind} `{name}`$$\n" + row[2].replace(f"DEFINER={old}", f"DEFINER={new}"))
    if stmts:
        run([_client(), f"--defaults-file={ROOT_CNF}", db], timeout=300, check=True,
            input="DELIMITER $$\n" + "$$\n".join(stmts) + "$$\nDELIMITER ;\n")
        fixed = len(stmts)
    return fixed


def _wipe(cnf, db):
    q = ("SELECT CONCAT(IF(table_type='VIEW','DROP VIEW IF EXISTS `','DROP TABLE IF EXISTS `'), table_name, '`;') "
         f"FROM information_schema.tables WHERE table_schema='{db}' UNION ALL "
         f"SELECT CONCAT('DROP ', routine_type, ' IF EXISTS `', routine_name, '`;') "
         f"FROM information_schema.routines WHERE routine_schema='{db}'")
    p = run([_client(), f"--defaults-file={cnf}", "-N", "-B", "-e", q, db], timeout=120, check=True)
    drops = p.stdout.strip()
    if drops:
        run([_client(), f"--defaults-file={cnf}", db], timeout=600,
            input="SET FOREIGN_KEY_CHECKS=0;\n" + drops + "\nSET FOREIGN_KEY_CHECKS=1;\n", check=True)
    return len(drops.splitlines()) if drops else 0


def import_dump(user, db, source, wipe="no"):
    info = _check_db(user, db)
    if info.get("TYPE", "mysql") != "mysql":
        raise HPError("La importación desde el panel es para MySQL/MariaDB; para PostgreSQL usa v-import-database")
    if info.get("SUSPENDED") == "yes":
        raise HPError("La base de datos está suspendida")
    path, owned = _stage_file(user, source)
    dbuser = cnf = None
    try:
        stream, raw, size, kind = _open(path)
        step(f"Volcado {kind} de {size / 1048576:.1f} MB listo para importar")
        dbuser, dbpass = _temp_user(user, db)
        cnf = _cnf(dbuser, dbpass)
        if wipe == "yes":
            step("Vaciando la base de datos", "running")
            n = _wipe(cnf, db)
            step(f"Base de datos vaciada ({n} tablas/vistas eliminadas)")
        step(f"Importando en {db}", "running")
        err = tempfile.TemporaryFile()
        proc = subprocess.Popen([_client(), f"--defaults-file={cnf}", "--max-allowed-packet=1G", db],
                                stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=err)
        skipped = definers = 0
        last = 0
        try:
            for line in stream:
                if line[:1] in b"CcUu" and RE_SKIP.match(line):
                    skipped += 1
                    continue
                if b"DEFINER" in line:
                    line, n = RE_DEFINER.subn(b"", line)
                    definers += n
                proc.stdin.write(line)
                pct = int(raw.tell() * 100 / size) if size else 0
                if pct >= last + 10:
                    last = pct - pct % 10
                    print(f"{last}% importado", flush=True)
            proc.stdin.close()
        except BrokenPipeError:
            pass
        code = proc.wait()
        err.seek(0)
        msg = err.read().decode("utf-8", "replace").strip()
        if code != 0:
            raise HPError("MySQL devolvió un error al importar: " + (msg[-1200:] or f"código {code}"))
        notes = []
        n = _fix_definers(db, info.get("DBUSER") or db, dbuser)
        if n:
            notes.append(f"{n} vistas/triggers/rutinas asignados a {info.get('DBUSER')}")
        if skipped:
            notes.append(f"{skipped} sentencias CREATE DATABASE/USE omitidas")
        if definers:
            notes.append(f"{definers} cláusulas DEFINER quitadas")
        step("Importación terminada" + (" (" + ", ".join(notes) + ")" if notes else ""))
    finally:
        if dbuser:
            hestia("v-delete-database-temp-user", user, db, dbuser, "mysql", check=False)
        if cnf and os.path.exists(cnf):
            os.remove(cnf)
        if owned and os.path.exists(path):
            os.remove(path)
    hestia("v-update-database-disk", user, db, check=False)
    log_action(user, f"Hestia Pro: volcado importado en la base de datos {db}", category="Databases")
    return {"ok": True, "database": db}


def tables(user, db):
    _check_db(user, db)  # nombre validado: la consulta solo lee information_schema
    rows = _root("SELECT table_name, IFNULL(table_rows,0), IFNULL(data_length + index_length,0), IFNULL(engine,'VIEW') "
                 f"FROM information_schema.tables WHERE table_schema='{db}' ORDER BY table_name")
    return [{"name": r[0], "rows": int(r[1]) if r[1].isdigit() else 0,
             "size": int(r[2]) if r[2].isdigit() else 0, "engine": r[3]} for r in rows if len(r) >= 4]


def cleanup_uploads(max_age=6 * 3600):
    """Borra subidas a medias o abandonadas."""
    import time
    if not os.path.isdir(UPLOADS):
        return
    for f in os.listdir(UPLOADS):
        p = os.path.join(UPLOADS, f)
        try:
            if time.time() - os.path.getmtime(p) > max_age:
                os.remove(p)
        except OSError:
            pass
