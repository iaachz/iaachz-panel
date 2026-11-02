"""Read-only project discovery. Project files are read with the domain owner's privileges."""
import json
import os

from . import apps, runtimes
from .common import HPError, check_web_domain, domain_dir, run, safe_subpath
from .i18n import tr

# Never execute package.json, require(), project scripts, or dotenv during inspection.
WORKER = r'''
import json, os, re, sys
base, requested = sys.argv[1:]
base = os.path.realpath(base)
def inside(path):
    return os.path.commonpath([base, os.path.realpath(path)]) == base
def read(path, limit=262144):
    if not inside(path) or not os.path.isfile(path): return ''
    with open(path, encoding='utf-8') as f: return f.read(limit)
def package(root):
    text = read(os.path.join(root, 'package.json'))
    if not text: return None
    data = json.loads(text)
    if not isinstance(data, dict): raise ValueError('package.json debe contener un objeto')
    return data
candidates = []
# Bounded discovery: conventional roots plus one nested folder; never walk node_modules.
for folder in ('app', 'public_html', 'private'):
    path = os.path.join(base, folder)
    if not inside(path) or not os.path.isdir(path): continue
    if os.path.isfile(os.path.join(path, 'package.json')): candidates.append(folder)
    for name in sorted(os.listdir(path))[:100]:
        child = os.path.join(path, name)
        if name.startswith('.') or name in ('node_modules', 'datos', 'vendor'): continue
        if inside(child) and os.path.isfile(os.path.join(child, 'package.json')):
            candidates.append(folder + '/' + name)
rel = requested or (candidates[0] if len(candidates) == 1 else '')
res = dict(candidates=candidates, root=rel, found=False)
if rel:
    root = os.path.join(base, rel)
    if not inside(root): raise ValueError('La carpeta debe pertenecer al dominio')
    pkg = package(root)
    if pkg is not None:
        scripts = pkg.get('scripts') or {}
        if not isinstance(scripts, dict): raise ValueError('scripts debe ser un objeto')
        scripts = {k: v for k, v in scripts.items() if isinstance(k, str) and isinstance(v, str)}
        deps = {}
        for key in ('dependencies', 'devDependencies'):
            values = pkg.get(key) or {}
            if not isinstance(values, dict): raise ValueError(key + ' debe ser un objeto')
            deps.update(values)
        main = pkg.get('main') or ''
        entry = next((f for f in (main, 'server.js', 'app.js', 'index.js', 'server.mjs', 'index.mjs', 'src/server.js')
                      if isinstance(f, str) and f and inside(os.path.join(root, f)) and os.path.isfile(os.path.join(root, f))), '')
        env = read(os.path.join(root, '.env'), 65536)
        port = re.search(r'^\s*(?:export\s+)?PORT\s*=\s*([^\r\n]*)', env, re.M)
        port = port.group(1).split('#', 1)[0].strip().strip('\"\x27') if port else ''
        invalid_port = bool(port) and (not port.isdigit() or not 1 <= int(port) <= 65535)
        paths = [root]
        if os.path.isdir(os.path.join(root, 'datos')):
            paths += [os.path.join(root, 'datos')]
            for current, dirs, files in os.walk(os.path.join(root, 'datos'), followlinks=False):
                dirs[:] = [d for d in dirs if not os.path.islink(os.path.join(current, d))]
                paths += [os.path.join(current, f) for f in files[:100]]
                if len(paths) > 200: break
        unwritable = [os.path.relpath(p, root) for p in paths if inside(p) and not os.access(p, os.W_OK)][:5]
        framework = next((name for key, name in [('next','Next.js'), ('nuxt','Nuxt'), ('@nestjs/core','NestJS'), ('express','Express'), ('fastify','Fastify'), ('vite','Vite')] if key in deps), 'Node.js')
        engines = pkg.get('engines') or {}
        res.update(found=True, name=str(pkg.get('name') or '')[:120], framework=framework,
                   scripts=scripts, entry=entry, node_spec=str(engines.get('node') or '') if isinstance(engines, dict) else '',
                   dependencies=bool(deps), dependencies_installed=os.path.isdir(os.path.join(root, 'node_modules')),
                   lock=os.path.isfile(os.path.join(root, 'package-lock.json')), invalid_port=invalid_port,
                   unwritable=unwritable, whatsapp='whatsapp-web.js' in deps,
                   build='build' in scripts and not any(os.path.isdir(os.path.join(root, p)) for p in ('.next','.output','dist','build')))
print(json.dumps(res))
'''


def inspect(user, domain, root=''):
    check_web_domain(user, domain)
    base = domain_dir(user, domain)
    if root:
        root = os.path.relpath(safe_subpath(base, root), base)
    p = run(['/usr/bin/python3', '-I', '-c', WORKER, base, root], user=user, timeout=20)
    if p.returncode:
        raise HPError('No se pudo revisar el proyecto: comprueba la carpeta, sus permisos y package.json.')
    info = json.loads(p.stdout)
    info['checks'] = []
    if not info['found']:
        return info
    installed = sorted(runtimes.node_installed(), key=int)
    # Select a version for editing; leave unsupported engine expressions for explicit review.
    version = installed[-1] if installed else ''
    mode, startup = ('npm', 'start') if 'start' in info['scripts'] else ('file', info['entry'])
    if info['framework'] == 'NestJS' and 'start:prod' in info['scripts']:
        mode, startup = 'npm', 'start:prod'
    info['config'] = dict(type='node', root=info['root'], version=version,
                          mode='production', start_mode=mode, startup=startup)
    def check(state, text, *args):
        info['checks'].append(dict(state=state, text=tr(text) % args if args else tr(text)))
    check('ok', 'Proyecto detectado: %s', info['framework'])
    if version:
        check('ok', 'Node.js %s disponible', version)
    else:
        check('error', 'Instala una versión de Node.js en Entornos.')
    if info['node_spec']:
        check('warning', 'El proyecto pide Node.js %s. Revisa la versión seleccionada antes de activar.', info['node_spec'])
    if startup:
        if mode == 'npm':
            check('ok', 'Inicio: npm run %s', startup)
        else:
            check('ok', 'Inicio: node %s', startup)
    else:
        check('error', 'No se encontró un archivo o script de inicio. Configúralo antes de activar.')
    if info['unwritable']:
        check('error', 'El usuario %s no puede escribir en: %s. Revisa los permisos en Archivos.', user, ', '.join(info['unwritable']))
    else:
        check('ok', 'El usuario de la aplicación puede escribir en el proyecto y sus datos revisados.')
    if info['dependencies']:
        if info['dependencies_installed']:
            check('ok', 'Dependencias presentes.')
        else:
            check('pending', 'Se instalarán las dependencias al activar (%s).', 'npm ci' if info['lock'] else 'npm install')
    if info['invalid_port']:
        check('warning', 'El PORT del .env no es válido. El panel asignará el puerto del servicio; revisa que el código use process.env.PORT sin sobrescribirlo.')
    if info['build']:
        check('warning', 'Este proyecto tiene un paso de compilación pendiente: ejecuta el script build antes de iniciarlo.')
    if info['whatsapp']:
        check('warning', 'WhatsApp requiere Chrome/Chromium con sus bibliotecas y vincular la cuenta por QR. Esta revisión no inicia el navegador.')
    if info['root'].startswith('public_html'):
        check('warning', 'El código y los datos están en public_html. Al activar Node.js el dominio se servirá a través de la aplicación; evita volver a hosting estático con estos archivos allí.')
    info['blocking'] = any(c['state'] == 'error' for c in info['checks'])
    return info
