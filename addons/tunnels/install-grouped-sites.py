import pathlib,datetime,shutil,subprocess
base=pathlib.Path('/opt/iaachz-tunnels');web=pathlib.Path('/usr/local/hestia/web/pro/pages');src=pathlib.Path('/opt/iaachz-panel/src/web/pro/pages')
backup=base/'backups'/('domain-groups-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S'));backup.mkdir(parents=True,mode=0o700)
page=web/'sites.php';old=page.read_text();shutil.copy2(page,backup/'sites.php')
partial=web/'sites-hosting-row.php'
if not partial.exists():
 marker='<?php foreach ($sites as $i => $s) {'
 start=old.index(marker)+len(marker);end=old.rindex('<?php } ?>\n\t</div>')
 row='<?php\n'+old[start:end]
 partial.write_text(row)
subprocess.run(['php','-l',str(partial)],check=True,capture_output=True)
new=(base/'grouped-sites.php').read_text()
page.write_text(new)
result=subprocess.run(['php','-l',str(page)],capture_output=True,text=True)
if result.returncode:page.write_text(old);raise RuntimeError(result.stdout+result.stderr)
for p in [page,partial]:
 p.chmod(0o644)
 if (src/p.name).exists():shutil.copy2(src/p.name,backup/('source-'+p.name))
 shutil.copy2(p,src/p.name)
print('Grouped domain view installed. Backup: '+str(backup))
