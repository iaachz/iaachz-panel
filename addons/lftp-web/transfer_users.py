"""Provision isolated file engines for real, active Hestia users (root CLI only)."""
from pathlib import Path
import os,sys,re,json,pwd,grp,secrets,subprocess,fcntl,socket,base64,hmac,hashlib,time,shutil

STATE=Path('/var/lib/nexopanel/transfers')
KEYS=Path('/etc/lftp-web/users')
DATA=Path('/var/lib/lftp-web-users')
BIN=Path('/usr/local/hestia/bin')

def run(*args):
    p=subprocess.run([str(x) for x in args],capture_output=True,text=True,timeout=40)
    if p.returncode:raise ValueError(Path(str(args[0])).name+' failed: '+p.stderr[-300:])
    return p.stdout.strip()

def validate_payload(data):
    if not isinstance(data,dict) or data.get('method') not in {'GET','HEAD','POST','PUT','PATCH','DELETE'}:raise ValueError('Método inválido')
    if not isinstance(data.get('uri'),str) or not data['uri'].startswith('/pro/transfers/service/') or len(data['uri'])>8192:raise ValueError('Ruta inválida')
    if not re.fullmatch('[a-f0-9]{32,128}',data.get('csrf','')):raise ValueError('Sesión inválida')
    return {k:data[k] for k in ['method','uri','csrf']}|{'theme':'dark' if data.get('theme')=='dark' else 'light'}

def prepare(user):
    if not re.fullmatch('[a-zA-Z0-9][a-zA-Z0-9_.-]{0,31}',user):raise ValueError('Usuario inválido')
    account=json.loads(run(BIN/'v-list-user',user,'json')).get(user,{})
    if account.get('ROLE') not in ('admin','user') or account.get('SUSPENDED')!='no':raise ValueError('Se requiere un usuario activo')
    info=pwd.getpwnam(user);home=Path('/home')/user
    if home.is_symlink() or home.resolve()!=home or Path(info.pw_dir)!=home or not home.is_dir():raise ValueError('Home de usuario inválido')
    # Hestia protects the home root. Give each OS user a private, bounded scratch directory.
    scratch=home/'.lftp-tmp'
    if scratch.is_symlink() or (scratch.exists() and (scratch.resolve().parent!=home or not scratch.is_dir())):
        raise ValueError('Carpeta temporal inválida')
    scratch.mkdir(mode=0o700,exist_ok=True);scratch.chmod(0o700);os.chown(scratch,info.pw_uid,info.pw_gid)
    trash=home/'.trash'
    if trash.is_symlink() or (trash.exists() and (trash.resolve().parent!=home or not trash.is_dir())):
        raise ValueError('Papelera inválida')
    trash.mkdir(mode=0o700,exist_ok=True);trash.chmod(0o700);os.chown(trash,info.pw_uid,info.pw_gid)
    for parent,mode in [(STATE,0o700),(KEYS,0o711),(DATA,0o711)]:parent.mkdir(parents=True,exist_ok=True);parent.chmod(mode)
    with (STATE/'engines.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        table=STATE/'engines.json';engines=json.loads(table.read_text()) if table.exists() else {}
        entry=engines.get(user)
        if entry and (entry['uid']!=info.pw_uid or entry['home']!=str(home)):raise ValueError('La cuenta cambió; revisa su motor anterior')
        if not entry:
            used={x['port'] for x in engines.values()}
            port=None
            for candidate in range(28000,29000):
                if candidate in used:continue
                with socket.socket() as sock:
                    try:sock.bind(('127.0.0.1',candidate));port=candidate;break
                    except OSError:pass
            if port is None:raise ValueError('No hay puertos internos disponibles')
            entry={'port':port,'uid':info.pw_uid,'home':str(home)};engines[user]=entry
            table.write_text(json.dumps(engines));table.chmod(0o600)
        key=KEYS/(user+'.key')
        if not key.exists():key.write_text(secrets.token_urlsafe(48)+'\n')
        key.chmod(0o640);os.chown(key,0,info.pw_gid)
        data=DATA/user;data.mkdir(exist_ok=True);data.chmod(0o700);os.chown(data,info.pw_uid,info.pw_gid)
        unit=Path('/etc/systemd/system')/('lftp-web-user-'+user+'.service')
        body=f'''[Unit]
Description=Hestia files for {user}
After=network.target
[Service]
User={user}
Group={grp.getgrgid(info.pw_gid).gr_name}
Environment=LFTP_WEB_HOST=127.0.0.1 LFTP_WEB_PORT={entry['port']} LFTP_WEB_AUTH_MODE=hestia LFTP_WEB_URL_PREFIX=/pro/transfers/service LFTP_WEB_HTTPS=1 LFTP_WEB_PROXY_HOPS=1 PYTHONDONTWRITEBYTECODE=1
Environment=LFTP_WEB_ROOT={home} LFTP_WEB_STATE={data} LFTP_WEB_SCOPE=account LFTP_WEB_ACCOUNT={user} LFTP_WEB_ROOT_LABEL={home} LFTP_WEB_HESTIA_SECRET_FILE={key}
ExecStart=/usr/bin/python3 /opt/lftp-web/app.py
Restart=on-failure
UMask=0022
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=tmpfs
BindPaths={home}
ReadWritePaths={home} {data}
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
CapabilityBoundingSet=
[Install]
WantedBy=multi-user.target
'''
        if not unit.exists() or unit.read_text()!=body:
            unit.write_text(body);unit.chmod(0o644);run('systemctl','daemon-reload')
        active=subprocess.run(['systemctl','is-active','--quiet',unit.name]).returncode==0
        if not active:
            run('systemctl','start',unit.name)
            for attempt in range(30):
                with socket.socket() as sock:
                    if sock.connect_ex(('127.0.0.1',entry['port']))==0:break
                time.sleep(.1)
            else:raise ValueError('El motor de archivos no respondió')
    return dict(entry,role=account['ROLE']),key

def main():
    if os.geteuid()!=0:raise ValueError('Solo root')
    if sys.argv[1]=='--retire':retire(sys.argv[2]);print(json.dumps({'retired':True}));return
    user=sys.argv[1];raw=base64.b64decode(sys.argv[2],validate=True)
    if len(raw)>12000:raise ValueError('Solicitud demasiado grande')
    payload=validate_payload(json.loads(raw))
    entry,key=prepare(user)
    value=payload|{'user':user,'role':entry['role'],'ts':int(time.time())}
    encoded=base64.urlsafe_b64encode(json.dumps(value,separators=(',',':')).encode()).decode().rstrip('=')
    token=encoded+'.'+hmac.new(key.read_text().strip().encode(),encoded.encode(),hashlib.sha256).hexdigest()
    print(json.dumps({'port':entry['port'],'assertion':token}))

def retire(user):
    if not re.fullmatch('[a-zA-Z0-9][a-zA-Z0-9_.-]{0,31}',user):raise ValueError('Usuario inválido')
    if not STATE.exists():return
    with (STATE/'engines.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        table=STATE/'engines.json';engines=json.loads(table.read_text()) if table.exists() else {}
        if user not in engines:return
        unit='lftp-web-user-'+user+'.service'
        subprocess.run(['systemctl','stop',unit],check=False,capture_output=True)
        subprocess.run(['systemctl','reset-failed',unit],check=False,capture_output=True)
        Path('/etc/systemd/system',unit).unlink(missing_ok=True)
        (KEYS/(user+'.key')).unlink(missing_ok=True)
        data=DATA/user
        if data.exists():
            if data.is_symlink() or data.resolve().parent!=DATA:raise ValueError('Estado de cuenta inválido')
            retired=STATE/'retired';retired.mkdir(mode=0o700,exist_ok=True);retired.chmod(0o700)
            shutil.move(str(data),str(retired/(user+'-'+secrets.token_hex(6))))
        engines.pop(user);table.write_text(json.dumps(engines));table.chmod(0o600)
        run('systemctl','daemon-reload')

if __name__=='__main__':
    try:main()
    except Exception as error:print(json.dumps({'error':str(error)}));sys.exit(1)
