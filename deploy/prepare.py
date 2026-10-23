import importlib.util, shutil, sys
from pathlib import Path
spec=importlib.util.spec_from_file_location('remote',Path(__file__).with_name('remote.py'));m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
source=Path('site/public_html'); target=Path(sys.argv[1]); origin=sys.argv[2]
for p in source.rglob('*'):
    if p.is_symlink():raise ValueError('Symlink in source overlay')
    if not p.is_file():continue
    name=p.relative_to(source).as_posix()
    if name in ('tools/api/config.php','tools/status.json'):continue
    if not m.owned(name) and name!='.htaccess.lld':raise ValueError('Unexpected overlay file: '+name)
    if name.startswith(('tools/api/state/','tools/api/outbox/')) and not name.endswith('/.htaccess'):continue
    q=target/name;q.parent.mkdir(parents=True,exist_ok=True)
    data=p.read_bytes()
    if p.suffix=='.html' or name=='.htaccess.lld':data=data.replace(b'__LLD_ORIGIN__',origin.encode())
    if name=='.htaccess.lld' and origin=='https://stg.lifelinedaily.com':
        data=data.replace(b'frame-ancestors https://lifelinedaily.com https://www.lifelinedaily.com', b'frame-ancestors https://lifelinedaily.com https://www.lifelinedaily.com https://stg.lifelinedaily.com')
    q.write_bytes(data)
