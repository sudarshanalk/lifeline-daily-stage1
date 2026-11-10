"""Executed over SSH on stdin. No database access; only the managed overlay."""
import hashlib, io, json, os, re, shutil, subprocess, sys, tarfile
from pathlib import Path

BEGIN, END = '# BEGIN lld', '# END lld'
def block(current, replacement):
    begins = len(re.findall(r'(?m)^# BEGIN lld\r?$', current))
    ends = len(re.findall(r'(?m)^# END lld\r?$', current))
    if begins != ends or begins > 1:
        raise ValueError('Ambiguous managed .htaccess block')
    if begins:
        if re.search(r'(?m)^# BEGIN lld\r?$',current).start() > re.search(r'(?m)^# END lld\r?$',current).start(): raise ValueError('Reversed block markers')
        return re.sub(r'(?m)^# BEGIN lld\r?\n.*?^# END lld(?:\r?\n|$)',lambda _:replacement,current,flags=re.S)
    return current + ('\n' if current and not current.endswith('\n') else '') + replacement

def owned(name):
    if name in ('tools/status.json','tools/api/config.php'): return False
    if name.startswith(('tools/api/state/','tools/api/outbox/')):
        return name.endswith('/.htaccess') and name.count('/') == 3
    return name.startswith(('tools/','wp-content/mu-plugins/lld-tools/','wp-content/plugins/lld-members/')) or name == 'wp-content/mu-plugins/lld-tools-loader.php'

def files(root):
    result=[]
    for base in ['tools','wp-content/mu-plugins/lld-tools','wp-content/mu-plugins/lld-tools-loader.php','wp-content/plugins/lld-members']:
        p=root/base
        if p.is_symlink(): raise ValueError('Symlink in managed tree')
        items = list(p.rglob('*')) if p.is_dir() else ([p] if p.exists() else [])
        for item in items:
            if item.is_symlink(): raise ValueError('Symlink in managed tree')
            if item.is_file() and owned(item.relative_to(root).as_posix()): result.append(item)
    return result

def atomic(path,data):
    tmp=path.with_name(path.name+'.lld-tmp')
    with tmp.open('xb') as f: f.write(data)
    if path.exists(): os.chmod(tmp,path.stat().st_mode & 0o777)
    else: os.chmod(tmp,0o644)
    os.replace(tmp,path)

def run(env,root,op,stamp):
    # staging is its own Hostinger website since 28 Oct 2026 (was the stg folder inside the production site)
    expected=Path.home()/('domains/stg.lifelinedaily.com/public_html' if env=='staging' else 'domains/lifelinedaily.com/public_html')
    if env not in ('staging','production') or root != expected or root.resolve()!=expected:
        raise ValueError('Document root does not match fixed LLD destination')
    for ancestor in [root,*root.parents]:
        if ancestor.is_symlink(): raise ValueError('Symlinked document root')
    if not (root/'wp-config.php').is_file(): raise ValueError('WordPress configuration missing')
    if not re.fullmatch(r'[0-9]{8}T[0-9]{6}Z-[0-9a-f]{12}',stamp): raise ValueError('Invalid backup id')
    base=Path.home()/'lld-backups'/env
    lock=base/'deploy.lock'
    archive=base/(stamp+'.tgz')
    if base.is_symlink() or base.parent.is_symlink(): raise ValueError('Symlinked backup storage')
    files(root)  # refuse symlinks even on dry run
    for name in ['.htaccess','.htaccess.lld','wp-content','wp-content/mu-plugins','wp-content/plugins']:
        if (root/name).is_symlink(): raise ValueError('Symlinked managed destination')
    if op=='unlock':
        if lock.is_file() and lock.read_text()==stamp: lock.unlink()
        return
    if op=='check':
        if not (root/'tools/api/config.php').is_file(): raise ValueError('Server-owned tools/api/config.php must be provisioned first')
        if not (root/'tools/status.json').is_file(): raise ValueError('Server-owned tools/status.json must be provisioned first')
        if not shutil.which('wp') or not shutil.which('rsync'): raise ValueError('WP-CLI and rsync required')
        subprocess.run(['wp','--path='+str(root),'--skip-plugins','--skip-themes','core','is-installed'],check=True,stdout=subprocess.DEVNULL)
        print('Remote read-only preflight passed');return
    if op=='backup':
        base.mkdir(parents=True,exist_ok=True,mode=0o700);os.chmod(base,0o700)
        with lock.open('x') as f:f.write(stamp)
        paths=files(root)
        current=(root/'.htaccess').read_text() if (root/'.htaccess').exists() else ''
        match=re.search(r'(?m)^# BEGIN lld\r?\n.*?^# END lld(?:\r?\n|$)',current,re.S)
        block(current,'') # validate before backup
        meta={'files':{},'block':match.group(0) if match else ''}
        try:
            with tarfile.open(archive,'x:gz') as tar:
                for p in paths:
                    name=p.relative_to(root).as_posix();data=p.read_bytes()
                    meta['files'][name]=hashlib.sha256(data).hexdigest()
                    tar.add(p,arcname=name,recursive=False)
                data=json.dumps(meta).encode();info=tarfile.TarInfo('lld-backup.json');info.size=len(data);tar.addfile(info,io.BytesIO(data))
            os.chmod(archive,0o600)
            with tarfile.open(archive) as tar:
                for name,h in meta['files'].items():
                    if hashlib.sha256(tar.extractfile(name).read()).hexdigest()!=h:raise ValueError('Backup verification failed')
        except Exception:
            if archive.exists():archive.unlink()
            raise
        print('Backup verified: '+stamp);return
    if op=='restore':
        if lock.exists(): raise ValueError('Deploy lock exists; resolve interrupted deployment first')
        with lock.open('x') as f:f.write(stamp)
        with tarfile.open(archive) as tar:
            meta=json.load(tar.extractfile('lld-backup.json'));contents={}
            for name,h in meta['files'].items():
                if not owned(name) or '..' in Path(name).parts or Path(name).is_absolute():raise ValueError('Unsafe archive entry')
                member=tar.getmember(name)
                if not member.isfile():raise ValueError('Non-file backup entry')
                data=tar.extractfile(member).read()
                if hashlib.sha256(data).hexdigest()!=h:raise ValueError('Backup checksum mismatch')
                contents[name]=data
            for p in files(root):
                if p.relative_to(root).as_posix() not in contents:p.unlink()
            for name,data in contents.items():
                p=root/name;p.parent.mkdir(parents=True,exist_ok=True);atomic(p,data)
            ht=root/'.htaccess';cur=ht.read_text() if ht.exists() else ''
            atomic(ht,block(cur,meta['block']).encode())
        print('Overlay restored; config, status and runtime data preserved');return
    if not lock.is_file() or lock.read_text()!=stamp:raise ValueError('Deployment lock missing')
    if op=='headers':
        source=root/'.htaccess.lld'
        if source.exists():
            content=source.read_text()
            if re.search(r'(?m)^# (BEGIN|END) lld\r?$',content):raise ValueError('Nested managed markers')
            ht=root/'.htaccess';cur=ht.read_text() if ht.exists() else ''
            atomic(ht,block(cur,BEGIN+'\n'+content.rstrip()+'\n'+END+'\n').encode());source.unlink()
    elif op=='dbupdate':
        # Stage 2 membership plugin (Build Method P0-3): run its idempotent database update once it is active.
        # The plugin is activated by hand the first time, so an inactive or absent plugin is reported and skipped.
        wp=['wp','--path='+str(root)]
        if subprocess.run(wp+['plugin','is-active','lld-members'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode!=0:
            print('lld-members not active; database update skipped');return
        subprocess.run(wp+['lld-members','db-update'],check=True)
    elif op=='purge':
        subprocess.run(['wp','--path='+str(root),'litespeed-purge','all'],check=True)
    elif op=='finish':
        for old in sorted(base.glob('*.tgz'),reverse=True)[10:]:old.unlink()
    else:raise ValueError('Unknown operation')

if __name__=='__main__':
    try:run(sys.argv[1],Path(sys.argv[2]),sys.argv[3],sys.argv[4])
    except Exception as e:sys.exit('Remote operation refused: '+str(e))
