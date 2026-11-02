"""Keep legacy /fm links pointed at the per-account integrated file manager."""
from pathlib import Path
import re,subprocess

BLOCK = '''# iaachz-files-route:begin
        location = /fm { return 302 /pro/transfers/; }
        location ^~ /fm/ { return 302 /pro/transfers/?open=$arg_path; }
# iaachz-files-route:end'''

def patch(text):
    if '# iaachz-files-route:begin' in text:
        return re.sub(r'# iaachz-files-route:begin.*?# iaachz-files-route:end',lambda _:BLOCK,text,flags=re.S)
    found=re.search(r'\blocation\s+/fm/\s*\{',text)
    if not found:raise ValueError('No se encontró la ruta nativa de archivos en nginx')
    depth=1;end=found.end()
    while end<len(text) and depth:
        if text[end]=='{':depth+=1
        elif text[end]=='}':depth-=1
        end+=1
    if depth:raise ValueError('Configuración nginx incompleta')
    return text[:found.start()]+BLOCK+text[end:]

def main():
    if not Path('/etc/nexopanel/transfers.json').is_file():return
    conf=Path('/usr/local/hestia/nginx/conf/nginx.conf');old=conf.read_text();new=patch(old)
    if new==old:return
    conf.write_text(new)
    try:
        subprocess.run(['/usr/local/hestia/nginx/sbin/hestia-nginx','-t'],check=True,capture_output=True)
        subprocess.run(['/usr/local/hestia/nginx/sbin/hestia-nginx','-s','reload'],check=True,capture_output=True)
    except Exception:
        conf.write_text(old)
        raise
if __name__=='__main__':main()
