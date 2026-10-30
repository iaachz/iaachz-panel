#!/usr/bin/python3
"""Standalone admin-only Nginx/Tailscale assistant for HestiaCP."""
import base64,datetime,fcntl,hashlib,http.client,ipaddress,json,os,pathlib,re,shutil,socket,subprocess,sys,tempfile,time

ROOT=pathlib.Path('/var/lib/hestia-tunnels')
CONF=pathlib.Path('/etc/nginx/conf.d')
BIN='/usr/local/hestia/bin/'
ZONE_DIR=pathlib.Path('/etc/bind/manual-zones')
ACME=pathlib.Path('/var/www/acme-hestia-tunnels')
def _cfg():
    data={}
    for path in ('/etc/hestia-tunnels/settings.json','/usr/local/hestia/data/hp/settings.json'):
        try:data.update(json.loads(pathlib.Path(path).read_text()))
        except (OSError,ValueError):pass
    return data
def _list(key):return [x.strip() for x in str(_cfg().get(key,'')).split(',') if x.strip()]
def _detect_ip():
    s=socket.socket(socket.AF_INET,socket.SOCK_DGRAM)
    try:s.connect(('192.0.2.1',9));return s.getsockname()[0]
    except OSError:return '127.0.0.1'
    finally:s.close()
IP=_cfg().get('public_ip') or _detect_ip()
HIDDEN=_list('hidden_domains')
LABELS=dict((k.strip(),v.strip()) for k,_,v in (p.partition('=') for p in _list('target_labels')))
NET=ipaddress.ip_network('100.64.0.0/10')

def panel_visible(domain):
    domain=domain.lower().rstrip('.')
    return not any(domain==root or domain.endswith('.'+root) for root in HIDDEN)

class Error(Exception):pass
def run(args,timeout=30,check=True):
    r=subprocess.run(args,capture_output=True,text=True,timeout=timeout)
    if check and r.returncode:raise Error((r.stderr or r.stdout or args[0]+' failed')[-1500:])
    return r
def now():return datetime.datetime.now(datetime.timezone.utc).isoformat()
def ident(domain):return 'ht-'+hashlib.sha256(domain.encode()).hexdigest()[:24]
def valid_domain(d):
    d=str(d).strip().lower().rstrip('.')
    if len(d)>240 or '.' not in d or not all(re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?',p) for p in d.split('.')):raise Error('Escribe un dominio válido, sin https:// ni rutas.')
    try:ipaddress.ip_address(d);raise Error('Escribe un dominio, no una dirección IP.')
    except ValueError:pass
    return d
def statepath(d):return ROOT/(ident(d)+'.json')
def load(d):
    p=statepath(d)
    return json.loads(p.read_text()) if p.exists() else None
def save(s):
    p=statepath(s['domain']);tmp=p.with_suffix('.tmp')
    tmp.write_text(json.dumps(s,ensure_ascii=False,indent=2));tmp.chmod(0o600);tmp.replace(p)
def admin(user):
    if not re.fullmatch(r'[a-zA-Z0-9_-]{1,64}',user):raise Error('Usuario no válido.')
    x=json.loads(run([BIN+'v-list-user',user,'json']).stdout)
    if x.get(user,{}).get('ROLE')!='admin':raise Error('Solo los administradores pueden gestionar túneles.')
def targets():
    data=json.loads(run(['tailscale','status','--json']).stdout)
    items=[]
    for peer in data.get('Peer',{}).values():
        if peer.get('OS')!='linux':continue
        for addr in peer.get('TailscaleIPs',[]):
            if ':' not in addr and ipaddress.ip_address(addr) in NET:
                name=LABELS.get(addr) or peer.get('HostName',addr)
                items.append({'ip':addr,'name':name,'online':bool(peer.get('Online'))})
    return sorted(items,key=lambda x:(not x['online'],x['name']))
def zones(user):
    result=[]
    for p in ZONE_DIR.glob('*.db'):
        try:result.append({'domain':valid_domain(p.stem),'kind':'manual'})
        except Error:continue
    for d in json.loads(run([BIN+'v-list-dns-domains',user,'json']).stdout):
        result.append({'domain':d,'kind':'hestia'})
    return sorted(result,key=lambda z:len(z['domain']),reverse=True)
def domain_options(user):
    roots={z['domain'] for z in zones(user) if panel_visible(z['domain'])}
    web=json.loads(run([BIN+'v-list-web-domains',user,'json']).stdout)
    for d in web:
        if panel_visible(d) and not any(d==root or d.endswith('.'+root) for root in roots):roots.add(d)
    return sorted(roots)
def existing_proxies():
    """Read-only inventory of VPN proxies installed before this assistant."""
    found={}
    for path in CONF.glob('*.conf'):
        if path.name.startswith('hestia-tunnel-'):continue
        text=path.read_text(errors='replace')
        for start in re.finditer(r'\bserver\s*\{',text):
            depth=1;pos=start.end()
            while pos<len(text) and depth:
                if text[pos]=='{':depth+=1
                elif text[pos]=='}':depth-=1
                pos+=1
            block=text[start.end():pos-1]
            # Do not inventory an ACME fallback as a web app when '/' only redirects.
            if re.search(r'location\s+/\s*\{\s*return\s',block):continue
            upstream=re.search(r'\bproxy_pass\s+http://(100\.\d+\.\d+\.\d+):(\d+)',block)
            names=re.search(r'\bserver_name\s+([^;]+);',block)
            if not upstream or not names:continue
            try:
                if ipaddress.ip_address(upstream[1]) not in NET:continue
            except ValueError:continue
            tls=bool(re.search(r'\blisten\s+[^;]*\bssl\b',block))
            for name in names[1].split():
                try:d=valid_domain(name)
                except Error:continue
                if not panel_visible(d):continue
                item={'domain':d,'target':upstream[1],'port':int(upstream[2]),'https':tls,'status':'existing','source':path.name}
                if d not in found or tls:found[d]=item
    return sorted(found.values(),key=lambda s:s['domain'])
def owned(s,user):
    if not s or s.get('owner')!=user:raise Error('Túnel no encontrado para este administrador.')
def probe(s):
    peer=next((x for x in targets() if x['ip']==s['target']),None)
    if not peer or not peer['online']:raise Error('El servidor VPN no está conectado. Revisa Tailscale y vuelve a probar.')
    conn=http.client.HTTPConnection(s['target'],s['port'],timeout=5)
    try:
        conn.request('GET','/',headers={'Host':s['domain'],'Connection':'close'})
        response=conn.getresponse()
        return {'reachable':True,'domain':s['domain'],'http_status':response.status,'message':'Conexión correcta: HTTP '+str(response.status)}
    except (OSError,http.client.HTTPException):raise Error('La app no responde por HTTP en ese puerto. Debe escuchar en la IP VPN o en 0.0.0.0, no solo en localhost.')
    finally:conn.close()
def payload(user,text):
    try:p=json.loads(base64.b64decode(text,validate=True))
    except Exception:raise Error('Datos de formulario no válidos.')
    parent=str(p.get('parent_domain','')).strip().lower().rstrip('.')
    if parent:
        if parent not in domain_options(user):raise Error('El dominio seleccionado no está disponible. También puedes escribir un dominio completo con DNS externo.')
        sub=str(p.get('subdomain','')).strip().lower()
        d=valid_domain((sub+'.' if sub else '')+parent)
    else:d=valid_domain(p.get('domain',''))
    if not panel_visible(d):raise Error('Este dominio se administra fuera de Hestia y no puede añadirse al asistente.')
    try:port=int(p.get('port',0))
    except (ValueError,TypeError):raise Error('Puerto no válido.')
    if not 1<=port<=65535:raise Error('El puerto debe estar entre 1 y 65535.')
    target=str(p.get('target',''))
    if target not in [x['ip'] for x in targets()]:raise Error('Elige uno de los servidores de tu VPN.')
    profile=p.get('profile','standard')
    if profile not in ['standard','large']:raise Error('Perfil no válido.')
    return {'domain':d,'target':target,'port':port,'profile':profile,'https':bool(p.get('https',True)),'auto_dns':bool(p.get('auto_dns',True)),'owner':user}
def collision(d):
    config=run(['nginx','-T']).stdout
    for match in re.finditer(r'\bserver_name\s+([^;]+);',config):
        if d in match.group(1).split():return True
    return False
def proxy(s):
    maximum='0' if s['profile']=='large' else '10g'
    return f'''    client_max_body_size {maximum};
    client_body_timeout 3600s;
    send_timeout 3600s;
    location / {{
        proxy_pass http://{s['target']}:{s['port']};
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port $server_port;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $hestia_tunnel_connection;
        proxy_connect_timeout 10s;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_request_buffering off;
        proxy_buffering off;
        proxy_max_temp_file_size 0;
        proxy_redirect off;
    }}
'''
def render(s):
    d=s['domain'];sid=ident(d)
    cert=pathlib.Path('/etc/letsencrypt/live')/sid/'fullchain.pem'
    ssl=s['https'] and cert.exists()
    http=f'''# Managed by Hestia VPN tunnels: {d}
server {{
    listen {IP}:80;
    server_name {d};
    access_log /var/log/nginx/{sid}.access.log;
    error_log /var/log/nginx/{sid}.error.log;
    location ^~ /.well-known/acme-challenge/ {{
        root {ACME};
        default_type text/plain;
        try_files $uri =404;
    }}
'''
    http+=('    location / { return 301 https://$host$request_uri; }\n' if ssl else '    location / { return 503; }\n' if s['https'] else proxy(s))+'}\n'
    if ssl:
        http+=f'''server {{
    listen {IP}:443 ssl;
    server_name {d};
    ssl_certificate /etc/letsencrypt/live/{sid}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{sid}/privkey.pem;
    access_log /var/log/nginx/{sid}.access.log;
    error_log /var/log/nginx/{sid}.error.log;
'''+proxy(s)+'}\n'
    return http
def install(s):
    p=CONF/('hestia-tunnel-'+ident(s['domain'])+'.conf');old=p.read_text() if p.exists() else None
    p.write_text(render(s));p.chmod(0o644)
    try:run(['nginx','-t']);run(['systemctl','reload','nginx'])
    except Exception:
        if old is None:p.unlink(missing_ok=True)
        else:p.write_text(old)
        raise
def canonical(zone,p):return run(['named-checkzone','-D','-o','-',zone,str(p)]).stdout
def write_zone(zone,p,text):
    old=p.read_text();backup=ROOT/'dns-backups';backup.mkdir(exist_ok=True,mode=0o700)
    shutil.copy2(p,backup/(zone+'-'+str(datetime.datetime.now().timestamp())+'.db'))
    text=re.sub(r'(\bIN\s+SOA\s+\S+\s+\S+\s+)(\d+)',lambda m:m[1]+str(int(m[2])+1),text,count=1)
    p.write_text(text)
    try:run(['named-checkzone',zone,str(p)]);run(['rndc','reload',zone])
    except Exception:p.write_text(old);raise
def add_dns(s):
    if not s['auto_dns']:return 'DNS externo: crea un registro A hacia '+IP+'.'
    z=next((z for z in zones(s['owner']) if s['domain']==z['domain'] or s['domain'].endswith('.'+z['domain'])),None)
    if not z:return 'No hay zona DNS local. Crea un registro A hacia '+IP+' en tu proveedor DNS.'
    d=s['domain'];zone=z['domain'];record='@' if d==zone else d[:-len(zone)-1]
    if z['kind']=='manual':
        p=ZONE_DIR/(zone+'.db');text=canonical(zone,p)
        existing=[l for l in text.splitlines() if l.split() and l.split()[0]==d+'.' and re.search(r'\bIN\s+(?:A|AAAA|CNAME)\s',l)]
        if existing:
            return 'DNS existente conservado; verifica que el registro A apunte a '+IP+'.'
        line=d+'. 300 IN A '+IP+'\n';write_zone(zone,p,text+line)
        s['dns_created']={'kind':'manual','zone':zone,'record':record}
    else:
        records=json.loads(run([BIN+'v-list-dns-records',s['owner'],zone,'json']).stdout)
        if any(v.get('RECORD')==record for v in records.values()):return 'DNS existente conservado; no se sobrescribió.'
        run([BIN+'v-add-dns-record',s['owner'],zone,record,'A',IP,'','','yes','300'])
        s['dns_created']={'kind':'hestia','zone':zone,'record':record}
    return 'Registro DNS A creado automáticamente.'
def dns_ready(d):
    for resolver in ['1.1.1.1','8.8.8.8']:
        for kind in ['A','AAAA']:
            r=run(['dig','@'+resolver,d,kind,'+noall','+comments','+answer','+time=3','+tries=1'],timeout=8,check=False)
            if r.returncode or 'status: NOERROR' not in r.stdout:return False
            answers=[l.split() for l in r.stdout.splitlines() if l and not l.startswith(';')]
            ips={x[-1] for x in answers if len(x)>=5 and x[-2]==kind}
            if (kind=='A' and ips!={IP}) or (kind=='AAAA' and ips):return False
    return True
def reconcile(s):
    s['last_check']=now()
    try:
        s['probe']=probe(s)
        if not s['https']:
            install(s);s.update(status='active',message='Túnel HTTP activo.');save(s);return s
        if not dns_ready(s['domain']):
            s.update(status='waiting_dns',message='Pendiente de DNS público. HTTPS se activará automáticamente cuando apunte a '+IP+'.');save(s);return s
        sid=ident(s['domain']);cert=pathlib.Path('/etc/letsencrypt/live')/sid/'fullchain.pem'
        if not cert.exists():
            run(['certbot','certonly','--non-interactive','--agree-tos','--webroot','-w',str(ACME),'--cert-name',sid,'-d',s['domain']],timeout=180)
        install(s)
        # Nginx reload acknowledges its signal before the new workers accept TLS.
        for attempt in range(5):
            r=run(['curl','--noproxy','*','--resolve',s['domain']+':443:'+IP,'-sS','--max-time','15','-o','/dev/null','-w','%{http_code}','https://'+s['domain']+'/'],timeout=20,check=False)
            if r.returncode==0:break
            if attempt==4:raise Error('HTTPS aún no responde con un certificado válido. Se reintentará automáticamente; revisa DNS y conectividad si persiste.')
            time.sleep(1)
        status=int(r.stdout)
        if status==502 or status==504:raise Error('El proxy está creado pero la aplicación no responde. Revisa el servidor y puerto.')
        s.update(status='active',message='HTTPS activo; la aplicación responde con HTTP '+str(status)+'.',last_error='')
    except Exception as e:s.update(status='error',message=str(e),last_error=now())
    save(s);return s
def create(user,text):
    s=payload(user,text);old=load(s['domain'])
    if old:owned(old,user)
    elif collision(s['domain']):raise Error('Este dominio ya tiene una configuración Nginx. No se reemplazó. Elige otro dominio o subdominio.')
    s['probe']=probe(s)
    if old:s={**old,**s}
    s.setdefault('created_at',now());s['updated_at']=now()
    # Validate and load Nginx before touching DNS or saving a new managed state.
    install(s)
    try:s['dns_note']=add_dns(s)
    except Exception as e:s['dns_note']='No se pudo crear DNS automáticamente: '+str(e)
    s.update(status='pending',message='Comprobando DNS, conexión y certificado…');save(s)
    run(['systemctl','start','--no-block','hestia-tunnels-reconcile.service'])
    return s
def remove(user,d):
    s=load(valid_domain(d));owned(s,user)
    p=CONF/('hestia-tunnel-'+ident(d)+'.conf');old=p.read_text() if p.exists() else None
    p.unlink(missing_ok=True)
    try:run(['nginx','-t']);run(['systemctl','reload','nginx'])
    except Exception:
        if old is not None:p.write_text(old)
        raise
    # Keep DNS and issued certificates available: removing a proxy must not destroy shared DNS.
    archive=ROOT/'removed';archive.mkdir(exist_ok=True,mode=0o700)
    statepath(d).replace(archive/(ident(d)+'-'+str(datetime.datetime.now().timestamp())+'.json'))
    return {'removed':d,'message':'Túnel eliminado. DNS y certificados conservados para evitar cambios destructivos.'}
def main():
    if os.geteuid()!=0:raise Error('Se requiere el ejecutor autorizado de Hestia.')
    os.umask(0o077);ROOT.mkdir(exist_ok=True,mode=0o700)
    command=sys.argv[1] if len(sys.argv)>1 else ''
    if command not in ['list','probe']:
        lock=open(ROOT/'manager.lock','w')
        try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            if command=='reconcile-all':return []
            raise Error('Hay otra configuración en curso. Espera unos segundos y vuelve a intentarlo.')
    if command=='reconcile-all':
        result=[]
        for p in ROOT.glob('ht-*.json'):
            s=json.loads(p.read_text())
            if s.get('status')!='active':result.append(reconcile(s))
        return result
    if len(sys.argv)<3:raise Error('Falta usuario.')
    user=sys.argv[2];admin(user)
    if command=='list':return {'items':[json.loads(p.read_text()) for p in ROOT.glob('ht-*.json') if json.loads(p.read_text()).get('owner')==user and panel_visible(json.loads(p.read_text())['domain'])],'existing':existing_proxies(),'targets':targets(),'zones':[z for z in zones(user) if panel_visible(z['domain'])],'domains':domain_options(user),'public_ip':IP}
    arg=sys.argv[3] if len(sys.argv)>3 else ''
    if command=='save':return create(user,arg)
    if command=='probe':return probe(payload(user,arg))
    if command=='delete':return remove(user,valid_domain(arg))
    if command=='retry':
        s=load(valid_domain(arg));owned(s,user);s.update(status='pending',message='Comprobando DNS, conexión y certificado…');save(s)
        run(['systemctl','start','--no-block','hestia-tunnels-reconcile.service']);return s
    raise Error('Acción no válida.')
if __name__=='__main__':
    try:print(json.dumps({'ok':True,'result':main()},ensure_ascii=False))
    except Exception as e:print(json.dumps({'ok':False,'error':str(e)},ensure_ascii=False));sys.exit(1)
