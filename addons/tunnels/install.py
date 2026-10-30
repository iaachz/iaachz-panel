#!/usr/bin/python3
import pathlib,shutil,datetime,subprocess,os,ast
source=pathlib.Path('/opt/iaachz-tunnels')
web=pathlib.Path('/usr/local/hestia/web')
stamp=datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
backup=source/'backups'/stamp;backup.mkdir(parents=True,mode=0o700)
ast.parse((source/'manager.py').read_text())
paths={web/'add/tunnel/index.php':source/'controller.php',web/'templates/pages/add_tunnel.php':source/'page.php'}
for target,src in paths.items():
 if target.exists():shutil.copy2(target,backup/target.name)
 target.parent.mkdir(parents=True,exist_ok=True)
 shutil.copyfile(src,target);target.chmod(0o644)
listing=web/'templates/pages/list_web.php';old=listing.read_text();shutil.copy2(listing,backup/'list_web.php')
if '<!-- tunnel-btn:inicio -->' not in old:
 needle='<!-- subdominio-btn:fin -->'
 assert needle in old,'Could not locate existing toolbar; no changes made to it'
 button='''<!-- tunnel-btn:inicio -->
                <?php if (($_SESSION['userContext'] ?? '') === 'admin' && empty($_SESSION['look'])) { ?>
                <a href="/add/tunnel/" class="button button-secondary js-button-create"><i class="fas fa-network-wired icon-blue"></i>Agregar túnel</a>
                <?php } ?>
                <!-- tunnel-btn:fin -->'''
 listing.write_text(old.replace(needle,needle+'\n                '+button,1))
for p in [listing,*paths]:
 result=subprocess.run(['php','-l',str(p)],capture_output=True,text=True)
 if result.returncode:listing.write_text(old);raise RuntimeError('PHP validation failed: '+str(p))
# Integrate with the iaachz-panel surface currently used by the administrator.
procontroller=(source/'controller.php').read_text()
procontroller=procontroller.replace("include $_SERVER['DOCUMENT_ROOT'] . '/inc/main.php';","include $_SERVER['DOCUMENT_ROOT'] . '/pro/inc/pro.php';")
procontroller=procontroller.replace("render_page($user,$TAB,'add_tunnel');","hp_render('Túneles VPN','sites','tunnels',compact('form','inventory','editing','read_only'));")
procontroller=procontroller.replace('/add/tunnel/','/pro/tunnels/')
propage=(source/'page.php').read_text()
cut=propage.index('<form method="post"')
propage='<?php hp_page_header($editing ? "Editar túnel" : "Agregar túnel", "Publica tus apps por VPN, sin mover sus archivos.", ["Sitios web y dominios"=>"/pro/sites/"], \'<a class="hp-btn" href="/pro/tunnels/">Actualizar estado</a>\'); ?>\n<div class="hp-card hp-mb"><div class="hp-card-body">\n'+propage[cut:]
propage=propage.replace('</div></form>','</div></form></div></div>')
propage=propage.rsplit('</div>',1)[0]
start=propage.index('<h1 class="u-mb10">')
end=propage.index('<?php show_alert_message($_SESSION); ?>',start)
propage=propage[:start]+propage[end+len('<?php show_alert_message($_SESSION); ?>'):]
propage=propage.replace('/add/tunnel/','/pro/tunnels/').replace('class="form-container"','class="hp-form"').replace('class="form-control"','class="hp-input"').replace('class="form-select"','class="hp-select"').replace('button button-secondary','hp-btn').replace('class="button"','class="hp-btn hp-btn-primary"').replace('class="toolbar-buttons"','class="hp-btn-group"')
profiles={web/'pro/tunnels/index.php':procontroller,web/'pro/pages/tunnels.php':propage,web/'pro/pages/tunnels-summary.php':(source/'summary.php').read_text()}
for target,text in profiles.items():
 target.parent.mkdir(parents=True,exist_ok=True);target.write_text(text);target.chmod(0o644)
 subprocess.run(['php','-l',str(target)],check=True,capture_output=True)
 mirror=pathlib.Path('/opt/iaachz-panel/src/web')/target.relative_to(web)
 mirror.parent.mkdir(parents=True,exist_ok=True);mirror.write_text(text);mirror.chmod(0o644)
for target in [web/'pro/pages/sites.php',pathlib.Path('/opt/iaachz-panel/src/web/pro/pages/sites.php')]:
 text=target.read_text();shutil.copy2(target,backup/('pro-'+str(len(text))+'-sites.php'))
 if 'tunnel-pro:inicio' not in text:
  needle='hp_page_header("Sitios web y dominios",'
  assert needle in text,'Pro toolbar not found'
  text=text.replace(needle,'// tunnel-pro:inicio\nif (hp_admin()) $actions .= \'<a class="hp-btn" href="/pro/tunnels/"><i class="fas fa-network-wired"></i>Agregar túnel</a>\';\n// tunnel-pro:fin\n'+needle,1)
 if 'tunnels-summary.php' not in text and 'domain-groups:v1' not in text:text+='\n<?php include $_SERVER["DOCUMENT_ROOT"] . "/pro/pages/tunnels-summary.php"; ?>\n'
 target.write_text(text);subprocess.run(['php','-l',str(target)],check=True,capture_output=True)
wrapper=pathlib.Path('/usr/local/hestia/bin/v-tunnel')
wrapper.write_text('#!/usr/bin/python3 -I\nimport runpy\nrunpy.run_path("/opt/iaachz-tunnels/manager.py",run_name="__main__")\n');wrapper.chmod(0o755)
root=pathlib.Path('/var/lib/iaachz-tunnels');root.mkdir(exist_ok=True,mode=0o700)
pathlib.Path('/var/www/acme-iaachz-tunnels').mkdir(exist_ok=True,mode=0o755)
common=pathlib.Path('/etc/nginx/hestia.d' if pathlib.Path('/etc/nginx/hestia.d').is_dir() else '/etc/nginx/conf.d')/'iaachz-tunnel-common.conf'
common.write_text("map $http_upgrade $iaachz_tunnel_connection {\n    default upgrade;\n    '' close;\n}\n");common.chmod(0o644)
units=pathlib.Path('/etc/systemd/system')
(units/'iaachz-tunnels-reconcile.service').write_text('''[Unit]
Description=Configure pending Hestia VPN tunnel certificates
After=network-online.target nginx.service tailscaled.service
[Service]
Type=oneshot
ExecStart=/usr/local/hestia/bin/v-tunnel reconcile-all
TimeoutStartSec=900
UMask=0077
''')
(units/'iaachz-tunnels-reconcile.timer').write_text('''[Unit]
Description=Retry pending Hestia VPN tunnels every 15 minutes
[Timer]
OnBootSec=2min
OnUnitActiveSec=15min
Unit=iaachz-tunnels-reconcile.service
[Install]
WantedBy=timers.target
''')
hook=pathlib.Path('/etc/letsencrypt/renewal-hooks/deploy/60-iaachz-tunnels')
hook.parent.mkdir(parents=True,exist_ok=True)
hook.write_text('''#!/bin/sh
case "$RENEWED_LINEAGE" in
  /etc/letsencrypt/live/ht-*) /usr/sbin/nginx -t && /usr/bin/systemctl reload nginx ;;
esac
''');hook.chmod(0o700)
post=pathlib.Path('/etc/hestiacp/hooks/post_install.sh')
text=post.read_text()
if '# iaachz-tunnels' not in text:
 shutil.copy2(post,backup/'post_install.sh')
 post.write_text(text+'\n[ -f /opt/iaachz-tunnels/install.py ] && /usr/bin/python3 /opt/iaachz-tunnels/install.py # iaachz-tunnels\n')
subprocess.run(['nginx','-t'],check=True)
subprocess.run(['systemctl','reload','nginx'],check=True)
subprocess.run(['systemctl','daemon-reload'],check=True)
subprocess.run(['systemctl','enable','--now','iaachz-tunnels-reconcile.timer'],check=True)
# Reload only the panel PHP worker so OPcache cannot retain the old toolbar.
subprocess.run(['systemctl','reload','hestia-php'],check=False,capture_output=True)
print('Installed VPN tunnel assistant. Backups: '+str(backup))
