"""Web file manager. Native protocol clients list files; lftp moves remote bytes."""
from __future__ import annotations

import ftplib
import hashlib
import io
import ipaddress
import json
import os
from pathlib import Path, PurePosixPath
import queue
import re
import secrets
import shlex
import shutil
import signal
import socket
import sqlite3
import stat
import subprocess
import tempfile
import threading
import time
import uuid
import zipfile
from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
from urllib.parse import quote as urlquote

import paramiko
from flask import Flask, Response, abort, g, jsonify, render_template, request, send_file, session
from werkzeug.middleware.proxy_fix import ProxyFix

BASE = Path(__file__).resolve().parent
HESTIA_MODE = os.environ.get('LFTP_WEB_AUTH_MODE') == 'hestia'
HESTIA_SCOPE = os.environ.get('LFTP_WEB_SCOPE', 'admin')
HESTIA_ACCOUNT = os.environ.get('LFTP_WEB_ACCOUNT', '')
URL_PREFIX = os.environ.get('LFTP_WEB_URL_PREFIX', '')
_bridge_secret_path = Path(os.environ.get('LFTP_WEB_HESTIA_SECRET_FILE', '/etc/lftp-web/hestia.key'))
HESTIA_SECRET = _bridge_secret_path.read_text().strip() if HESTIA_MODE and _bridge_secret_path.exists() else ''
ROOT = Path(os.environ.get('LFTP_WEB_ROOT', BASE / 'data' / 'files')).resolve()
STATE = Path(os.environ.get('LFTP_WEB_STATE', BASE / 'data' / 'state')).resolve()
ROOT.mkdir(parents=True, exist_ok=True)
STAGING = ROOT / '.lftp-tmp' if HESTIA_MODE and HESTIA_SCOPE == 'account' else ROOT
if STAGING.is_symlink() or not STAGING.resolve().is_relative_to(ROOT):
    raise RuntimeError('Carpeta temporal fuera de la cuenta')
STAGING.mkdir(mode=0o700, exist_ok=True)
STATE.mkdir(parents=True, exist_ok=True)
UMASK = os.umask(0)
os.umask(UMASK)


def shareable(path):
    """Staging items are created private (mkstemp 0600, mkdtemp 0700); once published they follow the
    service umask, so the files folder can be shared with a group of administrators."""
    if os.name != 'nt':
        os.chmod(path, (0o777 if os.path.isdir(path) else 0o666) & ~UMASK)
        home_owner(path)

def home_owner(path, previous=None):
    """Admin-created files belong to the destination Hestia account, not the engine."""
    if not HESTIA_MODE or HESTIA_SCOPE!='admin' or ROOT!=Path('/home'):return
    import pwd
    target=Path(path)
    if previous is not None:
        os.chown(target,previous.st_uid,previous.st_gid);return
    parts=target.relative_to(ROOT).parts
    if not parts:return
    try:owner=pwd.getpwnam(parts[0])
    except KeyError:return
    if Path(owner.pw_dir)==ROOT/parts[0]:os.chown(target,owner.pw_uid,owner.pw_gid)
KNOWN_HOSTS = STATE / 'known_hosts'
KNOWN_HOSTS.touch(exist_ok=True)
SSH_LOGIN_HOST = os.environ.get('LFTP_WEB_SSH_HOST', '127.0.0.1')
SSH_LOGIN_PORT = int(os.environ.get('LFTP_WEB_SSH_PORT', '22'))
# Access is granted by membership of a local Linux group, managed with instalar.sh / usermod.
SSH_LOGIN_GROUP = os.environ.get('LFTP_WEB_SSH_GROUP', 'lftpweb')
SSH_LOGIN_KNOWN_HOSTS = STATE / 'login_known_hosts'
SSH_LOGIN_KNOWN_HOSTS.touch(exist_ok=True)
SESSION_IDLE_SECONDS = 30 * 60
SESSION_MAX_SECONDS = 8 * 60 * 60
login_slots = threading.BoundedSemaphore(4)
app = Flask(__name__)
app.secret_key = secrets.token_bytes(32)
app.config.update(MAX_CONTENT_LENGTH=4 * 1024**3, SESSION_COOKIE_HTTPONLY=True,
                  SESSION_COOKIE_SAMESITE='Strict', SESSION_COOKIE_NAME='lftp_web_session',
                  SESSION_COOKIE_SECURE=os.environ.get('LFTP_WEB_HTTPS') == '1')
if os.environ.get('LFTP_WEB_PROXY_HOPS') == '1':
    # Only enable behind a single trusted proxy; never trust client IP headers.
    app.wsgi_app = ProxyFix(app.wsgi_app, x_for=0, x_proto=1, x_host=0, x_port=0)
connections: dict[str, dict] = {}
lock = threading.RLock()
pool = ThreadPoolExecutor(max_workers=2, thread_name_prefix='transfer')
processes: dict[str, subprocess.Popen] = {}
cancelled: set[str] = set()
DB = STATE / 'jobs.sqlite3'


@contextmanager
def db():
    con = sqlite3.connect(DB, timeout=20)
    con.row_factory = sqlite3.Row
    try:
        yield con
        con.commit()
    finally:
        con.close()


with db() as con:
    con.execute('CREATE TABLE IF NOT EXISTS auth_sessions (token_hash TEXT PRIMARY KEY, username TEXT, '
                'created REAL, last_seen REAL)')
    con.execute('CREATE TABLE IF NOT EXISTS auth_limits (identity TEXT PRIMARY KEY, started REAL, count INTEGER)')
    con.execute('CREATE TABLE IF NOT EXISTS jobs (id TEXT PRIMARY KEY, payload TEXT, status TEXT, '
                'created REAL, updated REAL, bytes INTEGER DEFAULT 0, total INTEGER DEFAULT 0, '
                'message TEXT DEFAULT "", download TEXT)')
    con.execute("UPDATE jobs SET status='interrupted', message='Servicio reiniciado; crea de nuevo la transferencia.' "
                "WHERE status IN ('queued','running')")


def clean_text(value, maxlen=4096):
    if not isinstance(value, str) or len(value) > maxlen or any(ord(c) < 32 or ord(c) == 127 for c in value):
        raise ValueError('Texto inválido o demasiado largo.')
    return value


def local_path(path):
    path = clean_text(path)
    candidate = (ROOT / path.lstrip('/')).resolve()
    if not candidate.is_relative_to(ROOT):
        raise ValueError('La ruta está fuera de la carpeta permitida.')
    return candidate


def remote_path(path):
    path = clean_text(path)
    if not path.startswith('/'):
        raise ValueError('La ruta remota debe ser absoluta.')
    return str(PurePosixPath(path))


def get_connection(cid):
    with lock:
        if cid not in connections:
            raise ValueError('La conexión ya no está disponible. Conecta de nuevo.')
        return connections[cid].copy()


def public_connection(c):
    return {k: c[k] for k in ('id', 'name', 'protocol', 'host', 'port', 'username')}


def sanitized(error):
    text = str(error)
    with lock:
        for c in connections.values():
            if c['password']:
                text = text.replace(c['password'], '[oculto]')
    return text[-1200:]


def digest(value):
    return hashlib.sha256(value.encode()).hexdigest()


def group_members():
    """Current members of the access group, read on every check so removals revoke sessions."""
    try:
        import grp
        import pwd
        group = grp.getgrnam(SSH_LOGIN_GROUP)
    except (ImportError, KeyError):
        return frozenset()  # No group (or not Linux): fail closed.
    members = set(group.gr_mem)
    members.update(p.pw_name for p in pwd.getpwall() if p.pw_gid == group.gr_gid)
    return frozenset(members)


def auth_configured():
    if HESTIA_MODE:
        return len(HESTIA_SECRET) >= 32
    return bool(group_members())


def login_allowed(username):
    return username in group_members()


def authenticated_user(touch=False):
    if HESTIA_MODE:
        return getattr(g, 'hestia_user', None)
    token = session.get('sid')
    if not isinstance(token, str):
        return None
    now, token_hash = time.time(), digest(token)
    with db() as con:
        row = con.execute('SELECT * FROM auth_sessions WHERE token_hash=?', (token_hash,)).fetchone()
        if not row or not login_allowed(row['username']) or now-row['last_seen'] >= SESSION_IDLE_SECONDS or now-row['created'] >= SESSION_MAX_SECONDS:
            con.execute('DELETE FROM auth_sessions WHERE token_hash=?', (token_hash,))
            session.clear()
            return None
        if touch:
            con.execute('UPDATE auth_sessions SET last_seen=? WHERE token_hash=?', (now, token_hash))
        return row['username']


@app.before_request
def protect():
    if HESTIA_MODE:
        if request.path == '/_health':
            return
        from hestia_bridge import validate_assertion
        query = request.query_string.decode('ascii', 'strict')
        uri = URL_PREFIX + request.path + ('?' + query if query else '')
        assertion = validate_assertion(request.headers.get('X-NexoPanel-Auth', ''), HESTIA_SECRET, request.method, uri,
            allowed_roles=('admin','user') if HESTIA_SCOPE=='account' else ('admin',), expected_user=HESTIA_ACCOUNT if HESTIA_SCOPE=='account' else None)
        if not assertion:
            abort(403, 'Accede mediante tu sesión de Hestia.')
        g.hestia_user = assertion['user']
        g.hestia_csrf = assertion['csrf']
        g.hestia_theme = assertion.get('theme','light')
        g.username = assertion['user']
        if request.path in ('/api/login', '/api/logout'):
            abort(405, 'La sesión se administra desde Hestia.')
    if request.path.startswith('/api/'):
        if request.method != 'GET' and request.headers.get('X-App-Request') != 'lftp-web':
            abort(403)
        if request.path not in ('/api/login', '/api/status'):
            # Background polling does not extend an idle session.
            g.username = authenticated_user(touch=not (request.path == '/api/jobs' and request.method == 'GET'))
            if not g.username:
                abort(401, 'Inicia sesión con tu usuario SSH.')


@app.after_request
def security_headers(response):
    response.headers['X-Content-Type-Options'] = 'nosniff'
    response.headers['Referrer-Policy'] = 'same-origin' if HESTIA_MODE else 'no-referrer'
    response.headers['X-Frame-Options'] = 'SAMEORIGIN' if HESTIA_MODE else 'DENY'
    # setdefault: a stricter policy set by the view (file previews) is kept.
    response.headers.setdefault('Content-Security-Policy', "default-src 'self'; script-src 'self'; "
        "style-src 'self' 'unsafe-inline'; img-src 'self' data:; media-src 'self'; font-src 'self'; "
        "connect-src 'self'; frame-ancestors " + ("'self'" if HESTIA_MODE else "'none'") + "; base-uri 'self'; form-action 'self'")
    if HESTIA_MODE:
        response.headers['Cache-Control'] = 'no-store'
    if request.path.startswith('/api/') and request.path != '/api/preview':
        response.headers['Cache-Control'] = 'no-store'
    if request.is_secure:
        response.headers['Strict-Transport-Security'] = 'max-age=31536000'
    return response


@app.errorhandler(Exception)
def handle_error(error):
    code = getattr(error, 'code', 400)
    if request.path.startswith('/api/'):
        return jsonify(error=sanitized(error)), code
    return str(error), code


@app.get('/')
def index():
    return render_template('index.html', prefix=URL_PREFIX, integrated=HESTIA_MODE, hestia_csrf=getattr(g, 'hestia_csrf', ''), theme=getattr(g,'hestia_theme','light'))

@app.get('/_health')
def internal_health():
    return jsonify(ok=auth_configured(), auth_mode='hestia' if HESTIA_MODE else 'ssh', lftp=bool(shutil.which('lftp')))


@app.get('/api/status')
def status():
    return jsonify(authenticated=bool(authenticated_user()), lftp=bool(shutil.which('lftp')),
                   auth_mode='hestia' if HESTIA_MODE else 'ssh', auth_configured=auth_configured(), password_required=not HESTIA_MODE,
                   root_label=os.environ.get('LFTP_WEB_ROOT_LABEL','Carpeta de archivos del servicio'), scope=HESTIA_SCOPE if HESTIA_MODE else 'workspace')


def reserve_login_attempt(username, address):
    """Persist limits across restarts; reserve before contacting SSH to bound concurrency."""
    now = time.time()
    buckets = [('ip:'+address, 5, 900), ('user:'+username, 10, 900), ('global', 60, 60)]
    with db() as con:
        con.execute('BEGIN IMMEDIATE')
        con.execute('DELETE FROM auth_limits WHERE started < ?', (now-900,))
        for identity, maximum, window in buckets:
            key = digest(identity)
            row = con.execute('SELECT started,count FROM auth_limits WHERE identity=?', (key,)).fetchone()
            if row and now-row['started'] < window and row['count'] >= maximum:
                return False
        for identity, maximum, window in buckets:
            key = digest(identity)
            con.execute('INSERT INTO auth_limits(identity,started,count) VALUES (?,?,1) '
                        'ON CONFLICT(identity) DO UPDATE SET count=CASE WHEN started <= ? THEN 1 ELSE count+1 END, '
                        'started=CASE WHEN started <= ? THEN excluded.started ELSE started END',
                        (key, now, now-window, now-window))
    return True


def verify_ssh_credentials(username, password):
    client = paramiko.SSHClient()
    try:
        client.load_host_keys(str(SSH_LOGIN_KNOWN_HOSTS))
        client.set_missing_host_key_policy(paramiko.RejectPolicy())
        client.connect(SSH_LOGIN_HOST, port=SSH_LOGIN_PORT, username=username, password=password,
                       timeout=10, banner_timeout=10, auth_timeout=10,
                       look_for_keys=False, allow_agent=False)
        transport = client.get_transport()
        return bool(transport and transport.is_authenticated())
    except (paramiko.SSHException, OSError, ValueError):
        # Do not expose SSH diagnostics, passwords or account existence to a visitor.
        return False
    finally:
        client.close()


@app.post('/api/login')
def login():
    try:
        loopback = ipaddress.ip_address(request.remote_addr or '').is_loopback
    except ValueError:
        loopback = False
    if not request.is_secure and not loopback:
        abort(403, 'Usa HTTPS para introducir credenciales SSH.')
    if not auth_configured():
        abort(503, 'El administrador debe crear el usuario de acceso: sudo lftp-web-configurar')
    body = request.get_json() or {}
    username = clean_text(body.get('username', ''), 128)
    password = clean_text(body.get('password', ''), 1024)
    if not reserve_login_attempt(username, request.remote_addr or 'unknown'):
        abort(429, 'Demasiados intentos de acceso. Espera 15 minutos e inténtalo de nuevo.')
    if not login_slots.acquire(blocking=False):
        abort(429, 'Hay varios accesos en curso. Inténtalo más tarde.')
    try:
        valid = bool(login_allowed(username) and password and verify_ssh_credentials(username, password))
    finally:
        login_slots.release()
    if not valid:
        abort(401, 'No se pudo validar el acceso SSH. Comprueba las credenciales o consulta al administrador.')
    old_token = session.get('sid')
    session.clear()
    token, now = secrets.token_urlsafe(32), time.time()
    with db() as con:
        if isinstance(old_token, str):
            con.execute('DELETE FROM auth_sessions WHERE token_hash=?', (digest(old_token),))
        con.execute('DELETE FROM auth_sessions WHERE created < ? OR last_seen < ?',
                    (now-SESSION_MAX_SECONDS, now-SESSION_IDLE_SECONDS))
        con.execute('INSERT INTO auth_sessions VALUES (?,?,?,?)', (digest(token), username, now, now))
    session['sid'] = token
    return jsonify(ok=True)


@app.post('/api/logout')
def logout():
    token = session.get('sid')
    if isinstance(token, str):
        with db() as con:
            con.execute('DELETE FROM auth_sessions WHERE token_hash=?', (digest(token),))
    session.clear()
    return jsonify(ok=True)


@contextmanager
def remote(c):
    if c['protocol'] == 'sftp':
        client = paramiko.SSHClient()
        client.load_host_keys(str(KNOWN_HOSTS))
        client.set_missing_host_key_policy(paramiko.RejectPolicy())
        try:
            client.connect(c['host'], port=c['port'], username=c['username'], password=c['password'],
                           timeout=15, auth_timeout=15, banner_timeout=15,
                           look_for_keys=False, allow_agent=False)
            with client.open_sftp() as sftp:
                sftp.get_channel().settimeout(20)
                yield sftp
        finally:
            client.close()
    else:
        client = ftplib.FTP_TLS() if c['protocol'] == 'ftps' else ftplib.FTP()
        # FTP_TLS must use a verifying context (ftplib's default varies by Python version).
        if c['protocol'] == 'ftps':
            import ssl
            client.context = ssl.create_default_context()
        try:
            client.connect(c['host'], c['port'], timeout=20)
            client.login(c['username'], c['password'])
            if c['protocol'] == 'ftps':
                client.prot_p()
            yield client
        finally:
            client.close()


def perm_text(mode):
    return format(stat.S_IMODE(mode), '03o') if mode else None


def list_remote(c, client, path):
    entries = []
    if c['protocol'] == 'sftp':
        for f in client.listdir_attr(path):
            # Symlinks are shown but never followed or transferred.
            kind = 'directory' if stat.S_ISDIR(f.st_mode) else 'file' if stat.S_ISREG(f.st_mode) else 'other'
            entries.append(dict(name=f.filename, kind=kind, size=f.st_size, modified=f.st_mtime, perm=perm_text(f.st_mode)))
        return entries
    from datetime import datetime, timezone
    try:
        for name, facts in client.mlsd(path, facts=['type', 'size', 'modify', 'unix.mode']):
            if facts.get('type') in ('cdir', 'pdir'):
                continue
            try:
                modified = datetime.strptime(facts.get('modify', '')[:14], '%Y%m%d%H%M%S').replace(tzinfo=timezone.utc).timestamp()
            except ValueError:
                modified = 0
            try:
                perm = format(int(facts['unix.mode'], 8) & 0o777, '03o')
            except (KeyError, ValueError):
                perm = None
            entries.append(dict(name=name, kind='directory' if facts.get('type') == 'dir' else
                                'file' if facts.get('type') == 'file' else 'other',
                                size=int(facts.get('size', 0)), modified=modified, perm=perm))
    except ftplib.error_perm as exc:
        if not str(exc).startswith(('500', '501', '502', '504')):
            raise
        raise ValueError('Este servidor FTP no admite MLSD. Usa SFTP o un servidor con MLSD.') from exc
    return entries


def remote_entries(c, path):
    with remote(c) as client:
        return list_remote(c, client, path)


def remote_kind(c, path):
    """'file', 'directory', 'other' or None, looked up in the parent listing (FTP and SFTP alike)."""
    p = PurePosixPath(path)
    if str(p) == '/':
        return 'directory'
    return next((e['kind'] for e in remote_entries(c, str(p.parent)) if e['name'] == p.name), None)


def remote_tree_size(c, path, limit=50000):
    """Total bytes of regular files below path, in one session; None if the tree is too large."""
    total = count = 0
    pending = [path]
    with remote(c) as client:
        while pending:
            folder = pending.pop()
            for e in list_remote(c, client, folder):
                count += 1
                if count > limit:
                    return None
                if e['kind'] == 'directory':
                    pending.append(str(PurePosixPath(folder) / e['name']))
                elif e['kind'] == 'file':
                    total += e['size']
    return total


@app.route('/api/connections', methods=['GET', 'POST'])
def connection_api():
    if request.method == 'GET':
        with lock:
            return jsonify(connections=[public_connection(c) for c in connections.values()])
    body = request.get_json() or {}
    protocol = body.get('protocol')
    if protocol not in ('ftp', 'ftps', 'sftp'):
        raise ValueError('Protocolo no admitido.')
    host = clean_text(body.get('host', ''), 253).strip()
    # Only a hostname or IP; never a URL or lftp option/command.
    if not host or not re.fullmatch(r'[A-Za-z0-9.:-]+', host) or host.startswith('-'):
        raise ValueError('Introduce solamente el nombre del servidor o su IP.')
    if ':' in host:
        ipaddress.IPv6Address(host)
    port = int(body.get('port') or (22 if protocol == 'sftp' else 21))
    if not 1 <= port <= 65535:
        raise ValueError('Puerto inválido.')
    c = dict(id=uuid.uuid4().hex, name=clean_text(body.get('name') or host, 80),
             protocol=protocol, host=host, port=port,
             username=clean_text(body.get('username', ''), 200), password=clean_text(body.get('password', ''), 1000))
    if not c['username']:
        raise ValueError('Introduce el usuario.')
    path = remote_path(body.get('path') or '/')
    remote_entries(c, path)  # Verify before retaining credentials.
    with lock:
        connections[c['id']] = c
    return jsonify(connection=public_connection(c), path=path)


@app.delete('/api/connections/<cid>')
def connection_close(cid):
    with lock:
        connections.pop(cid, None)
    return jsonify(ok=True)


def safe_name(name):
    name = clean_text(name, 240)
    if not name or name in ('.', '..') or '/' in name or '\\' in name or name.startswith('.lftp-'):
        raise ValueError('Nombre inválido.')
    return name


def public_local(path):
    relative = path.relative_to(ROOT).as_posix()
    return '/' if relative == '.' else '/' + relative


def local_kind(path):
    return 'directory' if path.is_dir() else 'file' if path.is_file() else 'other'


def local_files(base):
    """Regular files below base (or base itself), never following symlinks or entering staging areas."""
    if base.is_file() and not base.is_symlink():
        yield base
        return
    for root, dirs, names in os.walk(base):
        dirs[:] = sorted(d for d in dirs if not d.startswith('.lftp-') and not os.path.islink(os.path.join(root, d)))
        for name in sorted(names):
            p = Path(root) / name
            if not name.startswith('.lftp-') and not p.is_symlink() and p.is_file():
                yield p


def tree_size(base):
    total = 0
    for p in local_files(base):
        try:
            total += p.stat().st_size
        except OSError:
            pass  # A file may vanish while a transfer is still writing.
    return total


def remove_symlinks(base):
    """Remote trees can carry symlinks; none may survive inside the served root. Everything left
    must also stay readable and writable by the service, whatever permissions came from the remote."""
    for root, dirs, names in os.walk(base):
        for name in dirs + names:
            p = os.path.join(root, name)
            if os.path.islink(p):
                os.unlink(p)
            elif os.name != 'nt':
                mode = os.stat(p).st_mode
                needed = 0o700 if stat.S_ISDIR(mode) else 0o600
                if mode & needed != needed:
                    os.chmod(p, stat.S_IMODE(mode) | needed)


def remove_if_empty(folder):
    # Parallel jobs empty the same folder; on Windows a just-emptied folder can stay busy for a moment.
    for _ in range(10):
        try:
            folder.rmdir()
            return
        except FileNotFoundError:
            return
        except OSError:
            try:
                if any(folder.iterdir()):
                    return  # Another job still has an item here; it removes the folder later.
            except OSError:
                return
            time.sleep(0.05)


def trash_local(path):
    """Move an entry without walking its contents (legacy owners may differ)."""
    trash = ROOT / '.trash'
    if path == ROOT or path == trash or path.is_relative_to(trash):
        raise ValueError('No se puede eliminar la raíz ni el contenido de .trash.')
    if trash.is_symlink() or trash.resolve() != trash:
        raise ValueError('La papelera no puede ser un enlace simbólico.')
    trash.mkdir(mode=0o700, exist_ok=True)
    entry = trash / (time.strftime('%Y%m%d-%H%M%S', time.gmtime()) + '-' + uuid.uuid4().hex)
    entry.mkdir(mode=0o700)
    contents = entry / 'items'
    contents.mkdir(mode=0o700)
    destination = contents / path.name
    try:
        (entry / '.trash-info.json').write_text(json.dumps(dict(
            original=public_local(path), name=path.name, deleted_at=time.time()
        ), ensure_ascii=False) + '\n', encoding='utf-8')
        # Deliberately no copy/delete fallback: a failed rename must keep the source intact.
        os.rename(path, destination)
    except Exception:
        (entry / '.trash-info.json').unlink(missing_ok=True)
        contents.rmdir()
        entry.rmdir()
        raise
    return public_local(destination)


def delete_local(path):
    if path == ROOT:
        raise ValueError('No se puede eliminar la carpeta raíz.')
    if path.is_dir() and not path.is_symlink():
        shutil.rmtree(path)
    else:
        path.unlink()


@app.get('/api/files')
def files():
    cid = request.args.get('connection', 'local')
    path = request.args.get('path', '/')
    if cid == 'local':
        folder = local_path(path)
        if not folder.is_dir():
            raise ValueError('La carpeta no existe.')
        entries = []
        for p in folder.iterdir():
            if p.is_symlink() or not p.resolve().is_relative_to(ROOT) or p.name.startswith('.lftp-'):
                continue
            info = p.stat()
            entries.append(dict(name=p.name, kind=local_kind(p), size=info.st_size, modified=info.st_mtime,
                                perm=perm_text(info.st_mode)))
        path = public_local(folder)
    else:
        path = remote_path(path)
        entries = remote_entries(get_connection(cid), path)
    entries.sort(key=lambda e: (e['kind'] != 'directory', e['name'].lower()))
    return jsonify(path=path, entries=entries)


@app.post('/api/folders')
def folders():
    body = request.get_json() or {}
    name = safe_name(body.get('name', ''))
    path = str(PurePosixPath(body.get('path', '/')) / name)
    cid = body.get('connection', 'local')
    if cid == 'local':
        target=local_path(path);target.mkdir();home_owner(target)
    else:
        c = get_connection(cid)
        with remote(c) as client:
            (client.mkdir if c['protocol'] == 'sftp' else client.mkd)(remote_path(path))
    return jsonify(ok=True)


@app.post('/api/newfile')
def new_file():
    body = request.get_json() or {}
    name = safe_name(body.get('name', ''))
    path = str(PurePosixPath(body.get('path', '/')) / name)
    cid = body.get('connection', 'local')
    if cid == 'local':
        target=local_path(path);target.open('x').close();home_owner(target)
    else:
        c = get_connection(cid)
        path = remote_path(path)
        if remote_kind(c, path):
            raise ValueError('Ya existe un elemento con ese nombre.')
        with remote(c) as client:
            if c['protocol'] == 'sftp':
                client.open(path, 'wx').close()
            else:
                client.storbinary('STOR ' + path, io.BytesIO())
    return jsonify(ok=True)


@app.post('/api/move')
def move():
    """Rename or move within one location (same connection)."""
    body = request.get_json() or {}
    cid = body.get('connection', 'local')
    if cid == 'local':
        source, target = local_path(body.get('source', '')), local_path(body.get('target', ''))
        if source == ROOT or not source.exists():
            raise ValueError('El origen no existe.')
        if target.exists():
            raise ValueError('Ya existe un elemento con ese nombre en el destino.')
        if not target.parent.is_dir():
            raise ValueError('La carpeta de destino no existe.')
        if source.is_dir() and target.is_relative_to(source):
            raise ValueError('No puedes mover una carpeta dentro de sí misma.')
        safe_name(target.name)
        os.rename(source, target)
    else:
        c = get_connection(cid)
        source, target = remote_path(body.get('source', '')), remote_path(body.get('target', ''))
        safe_name(PurePosixPath(target).name)
        if source == '/' or target == source or target.startswith(source.rstrip('/') + '/'):
            raise ValueError('No puedes mover una carpeta dentro de sí misma.')
        if remote_kind(c, target):
            raise ValueError('Ya existe un elemento con ese nombre en el destino.')
        with remote(c) as client:
            client.rename(source, target)
    return jsonify(ok=True)


@app.post('/api/delete')
def delete():
    body = request.get_json() or {}
    cid = body.get('connection', 'local')
    paths = body.get('paths') or []
    if not isinstance(paths, list) or not 1 <= len(paths) <= 1000:
        raise ValueError('Selecciona entre 1 y 1000 elementos.')
    if cid == 'local':
        targets = [local_path(p) for p in paths]
        trash = ROOT / '.trash'
        for t in targets:
            if t == ROOT or t == trash or t.is_relative_to(trash) or not t.exists():
                raise ValueError(f'No se puede eliminar «{t.name or "/"}».')
        if len(set(targets)) != len(targets) or any(
            a != b and a.is_relative_to(b) for a in targets for b in targets
        ):
            raise ValueError('Selecciona elementos distintos sin incluir su carpeta contenedora.')
        moved = []
        for t in targets:
            try:
                moved.append(dict(source=public_local(t), trash=trash_local(t)))
            except OSError as exc:
                return jsonify(error=f'No se pudo mover «{t.name}» a .trash: {exc.strerror}. '
                               f'Elementos ya movidos: {len(moved)}.', moved=moved), 400
        return jsonify(ok=True, moved=moved)
    get_connection(cid)
    items = [remote_path(p) for p in paths]
    if '/' in items:
        raise ValueError('No se puede eliminar la carpeta raíz.')
    require_lftp()
    first = items[0]
    jid = start_job(dict(kind='delete', items=items, source=dict(connection=cid, path=first),
                         destination=dict(connection=cid, path=str(PurePosixPath(first).parent))), delete_remote)
    return jsonify(id=jid), 202


@app.post('/api/chmod')
def chmod():
    body = request.get_json() or {}
    mode = str(body.get('mode', ''))
    if not re.fullmatch(r'[0-7]{3}', mode):
        raise ValueError('Permisos inválidos. Usa tres dígitos, por ejemplo 644 o 755.')
    cid = body.get('connection', 'local')
    if cid == 'local':
        target = local_path(body.get('path', ''))
        if target == ROOT or not target.exists():
            raise ValueError('El elemento no existe.')
        os.chmod(target, int(mode, 8))
    else:
        c = get_connection(cid)
        path = remote_path(body.get('path', ''))
        with remote(c) as client:
            if c['protocol'] == 'sftp':
                client.chmod(path, int(mode, 8))
            else:
                client.sendcmd(f'SITE CHMOD {mode} {path}')
    return jsonify(ok=True)


@app.get('/api/size')
def size():
    cid = request.args.get('connection', 'local')
    if cid == 'local':
        target = local_path(request.args.get('path', '/'))
        files_count = sum(1 for _ in local_files(target))
        return jsonify(size=tree_size(target), files=files_count)
    total = remote_tree_size(get_connection(cid), remote_path(request.args.get('path', '/')))
    if total is None:
        raise ValueError('La carpeta tiene más de 50 000 elementos; no se calculó su tamaño.')
    return jsonify(size=total)


TEXT_LIMIT = 2 * 1024 * 1024


def read_remote_text(c, path):
    too_big = ValueError('El archivo supera 2 MB; descárgalo para verlo.')
    with remote(c) as client:
        if c['protocol'] == 'sftp':
            info = client.stat(path)
            if not stat.S_ISREG(info.st_mode):
                raise ValueError('Selecciona un archivo.')
            if info.st_size > TEXT_LIMIT:
                raise too_big
            with client.open(path, 'rb') as source:
                return source.read(TEXT_LIMIT + 1)
        buffer = io.BytesIO()

        def collect(chunk):
            buffer.write(chunk)
            if buffer.tell() > TEXT_LIMIT:
                raise too_big
        client.retrbinary('RETR ' + path, collect)
        return buffer.getvalue()


def write_remote_text(c, path, data):
    with remote(c) as client:
        if c['protocol'] == 'sftp':
            with client.open(path, 'wb') as out:
                out.write(data)
        else:
            client.storbinary('STOR ' + path, io.BytesIO(data))


@app.route('/api/text', methods=['GET', 'POST'])
def text_file():
    if request.method == 'GET':
        cid = request.args.get('connection', 'local')
        if cid == 'local':
            target = local_path(request.args.get('path', ''))
            if not target.is_file():
                abort(404)
            if target.stat().st_size > TEXT_LIMIT:
                raise ValueError('El archivo supera 2 MB; descárgalo para verlo.')
            data = target.read_bytes()
        else:
            data = read_remote_text(get_connection(cid), remote_path(request.args.get('path', '')))
        if len(data) > TEXT_LIMIT:
            raise ValueError('El archivo supera 2 MB; descárgalo para verlo.')
        try:
            return jsonify(content=data.decode('utf-8'))
        except UnicodeDecodeError:
            raise ValueError('No es un archivo de texto UTF-8.') from None
    body = request.get_json() or {}
    cid = body.get('connection', 'local')
    if cid != 'local':
        path = remote_path(body.get('path', ''))
        safe_name(PurePosixPath(path).name)
        content = body.get('content')
        if not isinstance(content, str) or len(content.encode()) > TEXT_LIMIT:
            raise ValueError('Contenido inválido o mayor de 2 MB.')
        write_remote_text(get_connection(cid), path, content.encode('utf-8'))
        return jsonify(ok=True)
    target = local_path(body.get('path', ''))
    safe_name(target.name)
    content = body.get('content')
    if not isinstance(content, str) or len(content.encode()) > TEXT_LIMIT:
        raise ValueError('Contenido inválido o mayor de 2 MB.')
    if not target.parent.is_dir() or target.is_dir():
        raise ValueError('Ruta inválida.')
    try:
        fd, name = tempfile.mkstemp(prefix='.lftp-', dir=target.parent)
    except PermissionError:
        # Hestia can protect the home directory while allowing a user to edit
        # an existing file inside it. Respect that distinction without chmod.
        if not HESTIA_MODE or HESTIA_SCOPE!='account' or not target.is_file():raise
        fd=os.open(target,os.O_WRONLY|os.O_NOFOLLOW)
        with os.fdopen(fd,'wb') as out:
            out.write(content.encode('utf-8'));out.truncate();out.flush();os.fsync(out.fileno())
        return jsonify(ok=True)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8', newline='') as out:
            out.write(content)
        if target.exists():
            previous=target.stat();shutil.copystat(target, name);home_owner(name,previous)
        else:
            shareable(name)
        os.replace(name, target)
    finally:
        Path(name).unlink(missing_ok=True)
    return jsonify(ok=True)


def update_job(jid, **values):
    values['updated'] = time.time()
    with db() as con:
        con.execute('UPDATE jobs SET ' + ','.join(k + '=?' for k in values) + ' WHERE id=?',
                    (*values.values(), jid))


last_progress: dict[str, float] = {}


def progress(jid, done):
    """Throttled byte counter: at most a couple of SQLite writes per second per job."""
    now = time.monotonic()
    if now - last_progress.get(jid, 0) >= 0.5:
        last_progress[jid] = now
        update_job(jid, bytes=done)


def check_cancel(jid):
    if jid in cancelled:
        raise ValueError('Transferencia cancelada.')


def lq(value):
    """Quote lftp's own command language; no OS shell is used."""
    value = clean_text(str(value))
    return '"' + value.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$').replace('`', '\\`') + '"'


def lftp_script(c, command):
    host = '[' + c['host'] + ']' if ':' in c['host'] else c['host']
    scheme = 'sftp' if c['protocol'] == 'sftp' else 'ftp'
    lines = ['set cmd:fail-exit yes', 'set net:timeout 20', 'set net:max-retries 2',
             'set net:reconnect-interval-base 3', 'set ssl:verify-certificate yes',
             'set xfer:clobber yes', 'set xfer:use-temp-file no',
             'set sftp:auto-confirm no',
             'set sftp:connect-program ' + lq('ssh -a -x -o StrictHostKeyChecking=yes -o UserKnownHostsFile=' + shlex.quote(str(KNOWN_HOSTS))),
             'set ftp:ssl-allow ' + ('yes' if c['protocol'] == 'ftps' else 'no')]
    if c['protocol'] == 'ftps':
        lines += ['set ftp:ssl-force yes', 'set ftp:ssl-protect-data yes', 'set ftp:ssl-protect-list yes']
    lines += ['open ' + lq(f'{scheme}://{host}:{c["port"]}'),
              'user ' + lq(c['username']) + ' ' + lq(c['password']), command, 'bye']
    return '\n'.join(lines) + '\n'


def mirror_command(source, target, reverse=False):
    # Never create or follow symlinks; files that differ are replaced (xfer:clobber yes).
    # Downloads skip remote permissions: FTP servers report them inconsistently and lftp can
    # end up applying 000, leaving files the service itself cannot read.
    return ('mirror --no-symlinks --parallel=2 ' + ('-R ' if reverse else '--no-perms ')
            + lq(source) + ' ' + lq(target))


def require_lftp():
    if not shutil.which('lftp'):
        raise ValueError('Falta lftp. La interfaz funciona; para operaciones remotas ejecútala en Linux.')


def run_lftp(jid, c, command, monitor=None):
    """monitor: a Path whose size is the progress, or a callable returning the bytes done."""
    if not shutil.which('lftp'):
        raise ValueError('lftp no está instalado. Ejecuta esta aplicación en Linux con lftp instalado.')
    check_cancel(jid)
    # stderr/stdout never reach logs; only a scrubbed tail is returned on failure.
    with tempfile.TemporaryFile() as output:
        proc = subprocess.Popen(['lftp', '--norc'], stdin=subprocess.PIPE, stdout=output, stderr=output,
                                start_new_session=os.name != 'nt')
        with lock:
            processes[jid] = proc
        try:
            proc.stdin.write(lftp_script(c, command).encode())
            proc.stdin.close()
            next_check = 0.0
            while proc.poll() is None:
                if jid in cancelled:
                    terminate(proc)
                if monitor is not None and time.monotonic() >= next_check:
                    if callable(monitor):
                        update_job(jid, bytes=monitor())
                        next_check = time.monotonic() + 1  # Walking a tree is costlier than a stat.
                    elif monitor.exists():
                        update_job(jid, bytes=monitor.stat().st_size)
                time.sleep(0.3)
            check_cancel(jid)
            if proc.returncode:
                output.seek(max(0, output.tell() - 2000))
                message = output.read().decode(errors='replace')
                if c['password']:
                    message = message.replace(c['password'], '[oculto]')
                raise ValueError(message or 'lftp terminó con error.')
        finally:
            if proc.poll() is None:
                terminate(proc)
            with lock:
                processes.pop(jid, None)


def terminate(proc):
    try:
        if os.name != 'nt':
            os.killpg(proc.pid, signal.SIGKILL)
        else:
            proc.kill()
        proc.wait(timeout=5)
    except (ProcessLookupError, subprocess.TimeoutExpired):
        pass


def publish_file(staged, target):
    """Publish without overwriting a destination created by a concurrent job."""
    if os.name == 'nt':
        # Windows rename refuses an existing target; hardlinks may be unavailable.
        os.rename(staged, target)
    else:
        os.link(staged, target)


def publish(staged, target, overwrite):
    shareable(staged)
    if overwrite and target.exists():
        home_owner(staged,target.stat())
        os.replace(staged, target)
    else:
        publish_file(staged, target)


def copy_file(jid, source, target, done=0):
    with source.open('rb') as inp, target.open('wb') as out:
        while chunk := inp.read(1024 * 1024):
            check_cancel(jid)
            out.write(chunk)
            done += len(chunk)
            progress(jid, done)
    shutil.copystat(source, target)
    home_owner(target)
    return done


def copy_tree(jid, source, target):
    done = 0
    for root, dirs, _ in os.walk(source):
        dirs[:] = [d for d in dirs if not d.startswith('.lftp-') and not os.path.islink(os.path.join(root, d))]
        directory=target / Path(root).relative_to(source)
        existed=directory.exists();directory.mkdir(parents=True, exist_ok=True)
        if not existed:home_owner(directory)
    for f in local_files(source):
        done = copy_file(jid, f, target / f.relative_to(source), done)
    return done


def run_job(jid, payload, worker):
    try:
        if jid in cancelled:
            update_job(jid, status='cancelled', message='Cancelada antes de iniciar.')
            return
        update_job(jid, status='running', message='Preparando…')
        worker(jid, payload)
    except Exception as error:
        update_job(jid, status='cancelled' if jid in cancelled else 'failed', message=sanitized(error))
    finally:
        last_progress.pop(jid, None)
        with lock:
            cancelled.discard(jid)


def start_job(payload, worker):
    jid, now = uuid.uuid4().hex, time.time()
    with db() as con:
        con.execute('INSERT INTO jobs (id,payload,status,created,updated) VALUES (?,?,?,?,?)',
                    (jid, json.dumps(payload), 'queued', now, now))
    pool.submit(run_job, jid, payload, worker)
    return jid


deferred: dict[str, dict] = {}


def complete(jid, **values):
    """Mark a transfer done, or hold the values while a move still has to delete its source."""
    if jid in deferred:
        deferred[jid] = values
    else:
        update_job(jid, status='completed', **values)


def transfer(jid, payload):
    run_job(jid, payload, do_transfer)


def do_transfer(jid, payload):
    src, dst = payload['source'], payload['destination']
    overwrite = bool(payload.get('overwrite'))
    if src['connection'] == 'local':
        source = local_path(src['path'])
        kind = local_kind(source) if source.exists() else None
        if kind == 'directory' and dst['connection'] == 'local' and local_path(dst['path']).is_relative_to(source):
            raise ValueError('No puedes copiar una carpeta dentro de sí misma.')
    else:
        kind = remote_kind(get_connection(src['connection']), src['path'])
        if (kind == 'directory' and src['connection'] == dst['connection']
                and (dst['path'] + '/').startswith(src['path'].rstrip('/') + '/')):
            raise ValueError('No puedes copiar una carpeta dentro de sí misma.')
    if kind not in ('file', 'directory'):
        raise ValueError('El origen no existe o no es un archivo ni una carpeta.')
    move = bool(payload.get('move'))
    if move:
        deferred[jid] = {}  # «Completada» only once the source is gone.
    try:
        (transfer_file if kind == 'file' else transfer_folder)(jid, src, dst, overwrite)
        if move:
            update_job(jid, message='Eliminando el origen…')
            if src['connection'] == 'local':
                source = local_path(src['path'])
                delete_local(source)
                if payload.get('upload'):
                    remove_if_empty(source.parent)  # The staging area goes once its last item is sent.
            else:
                remove_remote(jid, get_connection(src['connection']), src['path'], kind)
            values = deferred.get(jid) or {}
            values['message'] = 'Subido correctamente.' if payload.get('upload') else 'Movido correctamente.'
            update_job(jid, status='completed', **values)
    finally:
        deferred.pop(jid, None)


def transfer_file(jid, src, dst, overwrite):
    temp = None
    try:
        dest_local = dst['connection'] == 'local'
        target = local_path(dst['path']) if dest_local else None
        if target and target.exists() and (not overwrite or target.is_dir()):
            raise ValueError('Ya existe «%s» en el destino.' % target.name)
        if target and not target.parent.is_dir():
            raise ValueError('La carpeta de destino no existe.')
        # Stage complete files before publishing or forwarding them.
        fd, name = tempfile.mkstemp(prefix='.lftp-', dir=target.parent if target else STAGING)
        os.close(fd)
        temp = Path(name)
        if src['connection'] == 'local':
            source = local_path(src['path'])
            update_job(jid, total=source.stat().st_size)
            staged = source
        else:
            c = get_connection(src['connection'])
            parent, filename = str(PurePosixPath(src['path']).parent), PurePosixPath(src['path']).name
            matches = [e for e in remote_entries(c, parent) if e['name'] == filename and e['kind'] == 'file']
            if not matches:
                raise ValueError('El origen debe ser un archivo regular.')
            update_job(jid, total=matches[0]['size'], message='Descargando al servidor…')
            run_lftp(jid, c, 'get ' + lq(src['path']) + ' -o ' + lq(temp), temp)
            staged = temp
        check_cancel(jid)
        if dest_local:
            if staged is not temp:
                copy_file(jid, staged, temp)
            publish(temp, target, overwrite)
            size = target.stat().st_size
            complete(jid, bytes=size, total=size, message='Archivo guardado en el servidor.',
                       download=public_local(target))
        else:
            c = get_connection(dst['connection'])
            parent, filename = str(PurePosixPath(dst['path']).parent), PurePosixPath(dst['path']).name
            existing = next((e for e in remote_entries(c, parent) if e['name'] == filename), None)
            if existing and (not overwrite or existing['kind'] == 'directory'):
                raise ValueError('Ya existe «%s» en el destino remoto.' % filename)
            update_job(jid, message='Subiendo al destino remoto…')
            run_lftp(jid, c, ('' if overwrite else 'set xfer:clobber no\n') + 'put ' + lq(staged) + ' -o ' + lq(dst['path']))
            size = staged.stat().st_size
            complete(jid, bytes=size, total=size, message='Transferencia completada.')
    finally:
        if temp:
            temp.unlink(missing_ok=True)


def transfer_folder(jid, src, dst, overwrite):
    """Whole folders: lftp mirror for remote ends, a plain tree copy between local paths."""
    staging = None
    try:
        if src['connection'] == 'local':
            source = local_path(src['path'])
            update_job(jid, total=tree_size(source))
        else:
            c_src = get_connection(src['connection'])
            update_job(jid, message='Calculando tamaño…')
            total = remote_tree_size(c_src, src['path'])
            if total is not None:
                update_job(jid, total=total)
        if dst['connection'] == 'local':
            target = local_path(dst['path'])
            if target.exists() and not (overwrite and target.is_dir()):
                raise ValueError('Ya existe «%s» en el destino.' % target.name)
            if not target.parent.is_dir():
                raise ValueError('La carpeta de destino no existe.')
            # A new folder is assembled aside and appears complete; an existing one is merged in place.
            work = target if target.exists() else Path(tempfile.mkdtemp(prefix='.lftp-', dir=target.parent))
            staging = None if work == target else work
            update_job(jid, message='Copiando carpeta…' if src['connection'] == 'local' else 'Descargando carpeta al servidor…')
            if src['connection'] == 'local':
                copy_tree(jid, source, work)
            else:
                run_lftp(jid, c_src, mirror_command(src['path'], work), lambda: tree_size(work))
                remove_symlinks(work)
            check_cancel(jid)
            if staging:
                shareable(staging)
                os.rename(staging, target)
                staging = None
            size = tree_size(target)
            complete(jid, bytes=size, total=size, message='Carpeta guardada en el servidor.',
                       download=public_local(target) + '/')  # Trailing slash: offered as a ZIP.
        else:
            c_dst = get_connection(dst['connection'])
            existing = remote_kind(c_dst, dst['path'])
            if existing and not (overwrite and existing == 'directory'):
                raise ValueError('Ya existe «%s» en el destino remoto.' % PurePosixPath(dst['path']).name)
            if src['connection'] == 'local':
                local_source = source
            else:
                staging = Path(tempfile.mkdtemp(prefix='.lftp-', dir=STAGING))
                update_job(jid, message='Descargando carpeta al servidor…')
                run_lftp(jid, c_src, mirror_command(src['path'], staging), lambda: tree_size(staging))
                remove_symlinks(staging)
                local_source = staging
            size = tree_size(local_source)
            update_job(jid, message='Subiendo carpeta al destino remoto…')
            run_lftp(jid, c_dst, mirror_command(local_source, dst['path'], reverse=True))
            complete(jid, bytes=size, total=size, message='Carpeta transferida.')
    finally:
        if staging and staging.exists():
            shutil.rmtree(staging, ignore_errors=True)


def remove_remote(jid, c, path, kind=None):
    kind = kind or remote_kind(c, path)
    if kind:
        run_lftp(jid, c, ('rm -r ' if kind == 'directory' else 'rm ') + lq(path))


def delete_remote(jid, payload):
    c = get_connection(payload['source']['connection'])
    items = payload['items']
    update_job(jid, total=len(items))
    for done, path in enumerate(items, 1):
        check_cancel(jid)
        update_job(jid, message=f'Eliminando {PurePosixPath(path).name}…')
        remove_remote(jid, c, path)
        update_job(jid, bytes=done)
    update_job(jid, status='completed', message=f'{len(items)} elemento(s) eliminado(s).')


def zip_member_name(base, path):
    """Name inside the archive: the selected item's own name, then its relative path."""
    top = 'archivos' if base == ROOT else base.name
    return top if path == base else top + '/' + path.relative_to(base).as_posix()


def write_zip(archive, sources, jid=None, skip=None):
    done = 0
    for base in sources:
        if base.is_dir():
            for root, dirs, _ in os.walk(base):
                dirs[:] = sorted(d for d in dirs if not d.startswith('.lftp-') and not os.path.islink(os.path.join(root, d)))
                if not dirs and not any(local_files(Path(root))):
                    archive.writestr(zip_member_name(base, Path(root)) + '/', b'')  # Keep empty folders.
        for f in local_files(base):
            if skip and f == skip:
                continue
            info = zipfile.ZipInfo.from_file(f, zip_member_name(base, f))
            info.compress_type = zipfile.ZIP_DEFLATED
            with f.open('rb') as inp, archive.open(info, 'w', force_zip64=True) as out:
                while chunk := inp.read(1024 * 1024):
                    if jid:
                        check_cancel(jid)
                    out.write(chunk)
                    done += len(chunk)
                    if jid:
                        progress(jid, done)
    return done


def zip_job(jid, payload):
    sources = [local_path(p) for p in payload['items']]
    target = local_path(payload['destination']['path'])
    overwrite = bool(payload.get('overwrite'))
    if target.exists() and (not overwrite or target.is_dir()):
        raise ValueError('Ya existe «%s».' % target.name)
    update_job(jid, total=sum(tree_size(s) for s in sources), message='Comprimiendo…')
    fd, name = tempfile.mkstemp(prefix='.lftp-', suffix='.zip', dir=target.parent)
    os.close(fd)
    try:
        with zipfile.ZipFile(name, 'w', zipfile.ZIP_DEFLATED, allowZip64=True) as archive:
            write_zip(archive, sources, jid, skip=target)
        publish(Path(name), target, overwrite)
    finally:
        Path(name).unlink(missing_ok=True)
    size = target.stat().st_size
    update_job(jid, status='completed', message=f'ZIP creado ({size} bytes).', download=public_local(target))


def unzip_job(jid, payload):
    archive_path = local_path(payload['source']['path'])
    target = local_path(payload['destination']['path'])
    overwrite = bool(payload.get('overwrite'))
    if target.exists() and not (overwrite and target.is_dir()):
        raise ValueError('Ya existe «%s».' % target.name)
    staging = None
    with zipfile.ZipFile(archive_path) as archive:
        members, total = [], 0
        for info in archive.infolist():
            name = info.filename.replace('\\', '/')
            parts = [p for p in name.split('/') if p not in ('', '.')]
            # Zip-slip and friends: no absolute paths, drive letters, parent references or staging names.
            if (name.startswith('/') or re.match(r'^[A-Za-z]:', name) or '..' in parts
                    or any(p.startswith('.lftp-') or any(ord(ch) < 32 for ch in p) for p in parts)):
                raise ValueError(f'El ZIP contiene una ruta no permitida: {name[:200]}')
            if not parts or stat.S_ISLNK(info.external_attr >> 16):
                continue  # Symlinks are never recreated.
            members.append((info, parts))
            total += info.file_size
        if total > shutil.disk_usage(target.parent).free:
            raise ValueError('No hay espacio suficiente para extraer el ZIP.')
        update_job(jid, total=total, message='Extrayendo…')
        work = target if target.exists() else Path(tempfile.mkdtemp(prefix='.lftp-', dir=target.parent))
        staging = None if work == target else work
        try:
            done = 0
            for info, parts in members:
                check_cancel(jid)
                dest = work.joinpath(*parts)
                if not dest.resolve().is_relative_to(work.resolve()):
                    raise ValueError('El ZIP contiene una ruta no permitida.')
                if info.is_dir():
                    dest.mkdir(parents=True, exist_ok=True)
                    continue
                dest.parent.mkdir(parents=True, exist_ok=True)
                # ZipExtFile stops at the declared size, so `total` bounds what is written.
                with archive.open(info) as inp, dest.open('wb') as out:
                    while chunk := inp.read(1024 * 1024):
                        check_cancel(jid)
                        out.write(chunk)
                        done += len(chunk)
                        progress(jid, done)
            if staging:
                shareable(staging)
                os.rename(staging, target)
                staging = None
        finally:
            if staging and staging.exists():
                shutil.rmtree(staging, ignore_errors=True)
    update_job(jid, status='completed', bytes=total, message='ZIP extraído.', download=public_local(target) + '/')


@app.route('/api/jobs', methods=['GET', 'POST'])
def jobs():
    if request.method == 'GET':
        with db() as con:
            rows = [dict(row) for row in con.execute('SELECT * FROM jobs ORDER BY created DESC LIMIT 100')]
        for row in rows:
            row['payload'] = json.loads(row['payload'])
        return jsonify(jobs=rows)
    body = request.get_json() or {}
    payload = {'kind': 'transfer', 'overwrite': bool(body.get('overwrite')), 'move': bool(body.get('move'))}
    for key in ('source', 'destination'):
        endpoint = body.get(key, {})
        cid = endpoint.get('connection', 'local')
        path = endpoint.get('path', '')
        if cid == 'local':
            local_path(path)
        else:
            get_connection(cid)
            path = remote_path(path)
        payload[key] = dict(connection=cid, path=path)
    if payload['source'] == payload['destination']:
        raise ValueError('Origen y destino coinciden.')
    if not PurePosixPath(payload['destination']['path']).name:
        raise ValueError('Selecciona un nombre de destino.')
    if any(payload[k]['connection'] != 'local' for k in ('source', 'destination')):
        require_lftp()
    return jsonify(id=start_job(payload, do_transfer)), 202


@app.post('/api/jobs/clear')
def jobs_clear():
    with db() as con:
        con.execute("DELETE FROM jobs WHERE status NOT IN ('queued','running')")
    return jsonify(ok=True)


@app.post('/api/jobs/<jid>/cancel')
def cancel(jid):
    with db() as con:
        row = con.execute('SELECT status FROM jobs WHERE id=?', (jid,)).fetchone()
    if not row:
        abort(404)
    if row['status'] in ('queued', 'running'):
        with lock:
            cancelled.add(jid)
            proc = processes.get(jid)
            if proc:
                terminate(proc)
    return jsonify(ok=True)


@app.post('/api/archive')
def archive_create():
    body = request.get_json() or {}
    folder = local_path(body.get('path', '/'))
    names = body.get('items') or []
    if not isinstance(names, list) or not 1 <= len(names) <= 1000:
        raise ValueError('Selecciona entre 1 y 1000 elementos.')
    items = [public_local(local_path(public_local(folder) + '/' + safe_name(n))) for n in names]
    name = safe_name(body.get('name', ''))
    if not name.lower().endswith('.zip'):
        name += '.zip'
    payload = dict(kind='zip', items=items, overwrite=bool(body.get('overwrite')),
                   source=dict(connection='local', path=items[0]),
                   destination=dict(connection='local', path=public_local(folder / name)))
    return jsonify(id=start_job(payload, zip_job)), 202


@app.post('/api/extract')
def archive_extract():
    body = request.get_json() or {}
    source = local_path(body.get('path', ''))
    if not source.is_file() or not zipfile.is_zipfile(source):
        raise ValueError('Selecciona un archivo ZIP válido.')
    name = safe_name(body.get('name') or source.stem)
    payload = dict(kind='unzip', overwrite=bool(body.get('overwrite')),
                   source=dict(connection='local', path=public_local(source)),
                   destination=dict(connection='local', path=public_local(source.parent / name)))
    return jsonify(id=start_job(payload, unzip_job)), 202


@app.get('/api/download')
def download():
    path = local_path(request.args.get('path', ''))
    if not path.is_file() or path.name.startswith('.lftp-'):
        abort(404)
    return send_file(path, as_attachment=True, download_name=path.name, conditional=True)


class QueueWriter(io.RawIOBase):
    """Non-seekable sink: zipfile then writes data descriptors, so the archive streams as it is built."""

    def __init__(self, chunks, stop):
        super().__init__()
        self.chunks, self.stop = chunks, stop

    def writable(self):
        return True

    def write(self, data):
        data = bytes(data)
        while True:
            if self.stop.is_set():
                raise OSError('Descarga interrumpida por el cliente.')
            try:
                self.chunks.put(data, timeout=1)
                return len(data)
            except queue.Full:
                continue


def streamed(produce):
    """Run produce(writer) in a thread and stream what it writes; it stops when the client goes away."""
    chunks, gone = queue.Queue(maxsize=32), threading.Event()

    def run():
        try:
            produce(QueueWriter(chunks, gone))
        except Exception:
            pass  # An incomplete stream ends early; the browser reports a failed transfer.
        finally:
            while not gone.is_set():
                try:
                    chunks.put(None, timeout=1)
                    break
                except queue.Full:
                    continue

    def stream():
        try:
            while (chunk := chunks.get()) is not None:
                yield chunk
        finally:
            gone.set()

    threading.Thread(target=run, daemon=True).start()
    return stream()


@app.get('/api/download-zip')
def download_zip():
    sources = [local_path(p) for p in request.args.getlist('path')]
    if not 1 <= len(sources) <= 1000 or not all(s.exists() for s in sources):
        abort(404)

    def produce(writer):
        with zipfile.ZipFile(writer, 'w', zipfile.ZIP_DEFLATED, allowZip64=True) as archive:
            write_zip(archive, sources)

    name = ('archivos' if sources[0] == ROOT else sources[0].name) + '.zip' if len(sources) == 1 else 'archivos.zip'
    return Response(streamed(produce), mimetype='application/zip',
                    headers={'Content-Disposition': "attachment; filename*=UTF-8''" + urlquote(name)})


PREVIEW_TYPES = {
    '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.png': 'image/png', '.gif': 'image/gif', '.webp': 'image/webp',
    '.avif': 'image/avif', '.bmp': 'image/bmp', '.ico': 'image/x-icon', '.svg': 'image/svg+xml',
    '.mp4': 'video/mp4', '.m4v': 'video/mp4', '.webm': 'video/webm', '.ogv': 'video/ogg', '.mov': 'video/quicktime',
    '.mp3': 'audio/mpeg', '.m4a': 'audio/mp4', '.ogg': 'audio/ogg', '.oga': 'audio/ogg', '.wav': 'audio/wav',
    '.flac': 'audio/flac', '.aac': 'audio/aac', '.opus': 'audio/ogg',
}
# Previews are user files served inline from this origin: a sandboxed policy keeps an SVG or a
# mislabelled file from running script even if someone opens its URL directly.
PREVIEW_CSP = "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox"


@app.get('/api/preview')
def preview():
    cid = request.args.get('connection', 'local')
    raw = request.args.get('path', '')
    mime = PREVIEW_TYPES.get(PurePosixPath(raw).suffix.lower())
    if not mime:
        abort(415)
    if cid == 'local':
        target = local_path(raw)
        if not target.is_file() or target.name.startswith('.lftp-'):
            abort(404)
        response = send_file(target, mimetype=mime, conditional=True)  # Range requests: videos can seek.
    else:
        c, path = get_connection(cid), remote_path(raw)

        def produce(writer):
            with remote(c) as client:
                if c['protocol'] == 'sftp':
                    with client.open(path, 'rb') as source:
                        source.prefetch()
                        while data := source.read(256 * 1024):
                            writer.write(data)
                else:
                    client.retrbinary('RETR ' + path, writer.write, blocksize=64 * 1024)

        response = Response(streamed(produce), mimetype=mime)
    response.headers['Content-Disposition'] = 'inline'
    response.headers['Content-Security-Policy'] = PREVIEW_CSP
    response.headers['Cache-Control'] = 'private, max-age=300'
    return response


def upload_area(batch):
    """Hidden staging folder for one browser upload headed to a remote server."""
    if not isinstance(batch, str) or not re.fullmatch(r'[0-9a-f]{32}', batch):
        raise ValueError('Lote de subida inválido.')
    return STAGING / ('.lftp-up-' + batch)


def clean_upload_areas(max_age=24 * 3600):
    # Leftovers of uploads whose remote transfer never ran (browser closed, service restarted).
    for area in STAGING.glob('.lftp-up-*'):
        try:
            if area.is_dir() and not area.is_symlink() and time.time() - area.stat().st_mtime > max_age:
                shutil.rmtree(area, ignore_errors=True)
        except OSError:
            pass


@app.post('/api/upload')
def upload():
    batch = request.form.get('batch')
    if batch:
        folder = upload_area(batch)
        folder.mkdir(exist_ok=True)
    else:
        folder = local_path(request.form.get('path', '/'))
    incoming = request.files.get('file')
    if not incoming or not incoming.filename:
        raise ValueError('Selecciona un archivo.')
    # Folder uploads send each file with its path relative to the chosen folder.
    relative = (request.form.get('relative') or incoming.filename).replace('\\', '/')
    parts = [safe_name(p) for p in relative.split('/') if p]
    if not parts or len(parts) > 64:
        raise ValueError('Nombre de archivo inválido.')
    target = local_path(public_local(folder) + '/' + '/'.join(parts))
    overwrite = request.form.get('overwrite') == '1'
    if target.exists() and (not overwrite or target.is_dir()):
        raise ValueError('Ya existe «%s».' % target.name)
    target.parent.mkdir(parents=True, exist_ok=True)
    fd, filename = tempfile.mkstemp(prefix='.lftp-', dir=target.parent)
    os.close(fd)
    try:
        incoming.save(filename)
        publish(Path(filename), target, overwrite)
    finally:
        Path(filename).unlink(missing_ok=True)
    return jsonify(ok=True)


@app.post('/api/upload-commit')
def upload_commit():
    """Send a finished browser upload (files and folders) from its staging area to a remote server."""
    body = request.get_json() or {}
    area = upload_area(body.get('batch'))
    cid = body.get('connection')
    get_connection(cid)
    require_lftp()
    folder = remote_path(body.get('path', '/'))
    names = [safe_name(n) for n in body.get('names') or []] or sorted(p.name for p in area.iterdir())
    if not area.is_dir() or not names or not all((area / n).exists() for n in names):
        raise ValueError('La subida no está completa. Vuelve a intentarlo.')
    ids = []
    for name in names:
        payload = dict(kind='transfer', upload=True, move=True, overwrite=bool(body.get('overwrite')),
                       source=dict(connection='local', path=public_local(area / name)),
                       destination=dict(connection=cid, path=str(PurePosixPath(folder) / name)))
        ids.append(start_job(payload, do_transfer))
    return jsonify(ids=ids), 202


if __name__ == '__main__':
    host = os.environ.get('LFTP_WEB_HOST', '127.0.0.1')
    port = int(os.environ.get('LFTP_WEB_PORT', '8080'))
    if host not in ('127.0.0.1', 'localhost', '::1') and os.environ.get('LFTP_WEB_HTTPS') != '1':
        raise SystemExit('Configura HTTPS mediante un proxy y LFTP_WEB_HTTPS=1 antes de escuchar fuera de localhost.')
    if not auth_configured():
        print(f'Acceso bloqueado: el grupo {SSH_LOGIN_GROUP} no existe o está vacío. Ejecuta: sudo lftp-web-configurar', flush=True)
    clean_upload_areas()
    print(f'LFTP Web disponible en http://{host}:{port}', flush=True)
    try:
        from waitress import serve
    except ImportError:
        app.run(host=host, port=port, threaded=True, debug=False, use_reloader=False)
    else:
        proxy = {}
        if os.environ.get('LFTP_WEB_PROXY_HOPS') == '1':
            # Waitress drops X-Forwarded-* unless the proxy is trusted. Trust only the local proxy, one hop:
            # the client IP is the address nginx appends, so per-IP login limits apply to real clients.
            proxy = dict(trusted_proxy='127.0.0.1', trusted_proxy_count=1, clear_untrusted_proxy_headers=True,
                         trusted_proxy_headers={'x-forwarded-for', 'x-forwarded-proto'})
        serve(app, host=host, port=port, threads=8, **proxy)
