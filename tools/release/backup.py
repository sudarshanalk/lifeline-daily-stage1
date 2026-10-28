"""NFR1-08 full backup and restore (DEC-19). Executed over SSH on stdin, like deploy/remote.py:

    ssh ... "python3 - <env> \"$HOME/<docroot>\" <op> <backup-id>" < tools/release/backup.py

ops
  backup   database dump + wp-content + public_html/tools archives, a per-file SHA-256 inventory and manifest.json,
           verified after writing; then the DEC-19 retention policy is applied to older full backups
  verify   compare the live database (CHECKSUM TABLE) and files (inventory) with a backup's manifest; changes nothing
  restore  clean restore from a backup: archives checked against the manifest, extracted into an empty directory and
           checked against the inventory, every table dropped and the dump imported, wp-content and tools swapped in
           whole; the replaced directories are kept in the backup folder (pre-restore/), never deleted
  history  timestamps of every full backup (RPO evidence, TC-NFR1-08-AS-05)

Everything is written under ~/lld-backups/<env>/full/<backup-id>/ (mode 0700/0600). The document root must be the fixed
LLD path for the environment. Credentials are read with `wp config get` into a temporary 0600 option file and never
printed. wp-config.php is not archived (credentials stay server-owned); wp-content/ai1wm-backups (migration-plugin
archives, i.e. backups of backups) is excluded. Output: one JSON document on stdout.
Python 3.6+ (the host runs 3.9).
"""
import datetime, gzip, hashlib, json, os, re, shutil, subprocess, sys, tarfile, time
from pathlib import Path

POLICY = {'rpo_hours': 24, 'rto_hours': 4, 'retain': {'daily': 14, 'weekly': 8}}   # DEC-19; tools/release/backup-policy.json
EXCLUDE = ['wp-content/ai1wm-backups']
VOLATILE = ('wp-content/litespeed/', 'wp-content/cache/', 'wp-content/upgrade/', 'wp-content/upgrade-temp-backup/')
TREES = ['wp-content', 'tools']
ID_RE = r'full-[0-9]{8}T[0-9]{6}Z'


def now():
    return datetime.datetime.utcnow().replace(microsecond=0).isoformat() + 'Z'


def sha256_file(p):
    h = hashlib.sha256()
    with open(p, 'rb') as f:
        for chunk in iter(lambda: f.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def fixed_root(env, root):
    expected = Path.home() / 'domains/lifelinedaily.com/public_html'
    if env == 'staging':
        expected = expected / 'stg'
    if env not in ('staging', 'production') or root != expected or root.resolve() != expected:
        raise ValueError('Document root does not match fixed LLD destination')
    if not (root / 'wp-config.php').is_file():
        raise ValueError('WordPress configuration missing')
    return root


class Db:
    """mysql/mysqldump with credentials from wp-config via WP-CLI, held in a 0600 option file for the run only."""

    def __init__(self, root, workdir):
        get = lambda k: subprocess.run(['wp', '--path=' + str(root), '--skip-plugins', '--skip-themes', 'config', 'get', k],
                                       check=True, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, universal_newlines=True).stdout.strip()
        self.name = get('DB_NAME')
        host = get('DB_HOST')
        port = None
        if ':' in host:
            host, port = host.split(':', 1)
        self.cnf = workdir / '.client.cnf'
        fd = os.open(str(self.cnf), os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, 'w') as f:
            f.write('[client]\nuser="%s"\npassword="%s"\nhost="%s"\n' % (get('DB_USER'), get('DB_PASSWORD').replace('"', '\\"'), host))
            if port:
                f.write('port=%s\n' % port)

    def close(self):
        if self.cnf.exists():
            self.cnf.unlink()

    def sql(self, statement):
        out = subprocess.run(['mysql', '--defaults-extra-file=' + str(self.cnf), '-N', '-B', self.name, '-e', statement],
                             check=True, stdout=subprocess.PIPE, universal_newlines=True).stdout
        return [line.split('\t') for line in out.splitlines() if line]

    def tables(self):
        return sorted(r[0] for r in self.sql('SHOW TABLES'))

    def checksums(self):
        result = {}
        names = self.tables()
        for i in range(0, len(names), 40):
            batch = names[i:i + 40]
            for row in self.sql('CHECKSUM TABLE ' + ', '.join('`%s`' % n for n in batch)):
                result[row[0].split('.', 1)[-1]] = row[1]
        return result

    def options_digest(self):
        """wp_options without the rows WordPress and plugins rewrite on their own (transients, cron, caches, queues):
        lets a verify tell real settings drift from churn, since CHECKSUM TABLE covers the whole table."""
        rows = self.sql("SET SESSION group_concat_max_len = 1073741824; SELECT MD5(GROUP_CONCAT(CONCAT(option_name, '=', MD5(option_value)) "
                        "ORDER BY option_name SEPARATOR '\n')) FROM wp_options WHERE option_name NOT LIKE '%transient%' AND option_name <> 'cron' "
                        "AND option_name NOT LIKE 'litespeed%' AND option_name NOT LIKE 'action_scheduler%' AND option_name NOT LIKE '%_lock' "
                        "AND option_name NOT LIKE 'wpseo_%cache%' AND option_name NOT LIKE '%last_run%' "
                        "AND option_name NOT IN ('pmpro_views', 'pmpro_visits', 'pmpro_logins', 'pmpro_library_conflicts')")
        return rows[0][0] if rows else None

    def dump(self, target):
        with open(str(target), 'wb') as raw:
            with gzip.GzipFile(fileobj=raw, mode='wb', compresslevel=6) as gz:
                p = subprocess.Popen(['mysqldump', '--defaults-extra-file=' + str(self.cnf), '--single-transaction', '--quick',
                                      '--routines', '--triggers', '--no-tablespaces', '--default-character-set=utf8mb4', self.name],
                                     stdout=subprocess.PIPE)
                for chunk in iter(lambda: p.stdout.read(1 << 20), b''):
                    gz.write(chunk)
                if p.wait() != 0:
                    raise ValueError('mysqldump failed')

    def load(self, source):
        p = subprocess.Popen(['mysql', '--defaults-extra-file=' + str(self.cnf), self.name], stdin=subprocess.PIPE)
        with gzip.open(str(source), 'rb') as gz:
            for chunk in iter(lambda: gz.read(1 << 20), b''):
                p.stdin.write(chunk)
        p.stdin.close()
        if p.wait() != 0:
            raise ValueError('database import failed')


def excluded(rel):
    return any(rel == e or rel.startswith(e + '/') for e in EXCLUDE)


def inventory(base):
    """{relative path: sha256 | 'link:<target>' | 'dir'} for TREES under base, exclusions applied."""
    inv = {}
    for tree in TREES:
        top = base / tree
        if not top.exists():
            continue
        for dirpath, dirnames, filenames in os.walk(str(top)):
            rel_dir = Path(dirpath).relative_to(base).as_posix()
            dirnames[:] = sorted(d for d in dirnames if not excluded(rel_dir + '/' + d))
            for d in list(dirnames):
                p = Path(dirpath) / d
                if p.is_symlink():
                    inv[rel_dir + '/' + d] = 'link:' + os.readlink(str(p))
                    dirnames.remove(d)
            if not filenames and not dirnames:
                inv[rel_dir] = 'dir'
            for name in sorted(filenames):
                p = Path(dirpath) / name
                rel = rel_dir + '/' + name
                inv[rel] = ('link:' + os.readlink(str(p))) if p.is_symlink() else sha256_file(p)
    return inv


def inventory_from_archives(paths):
    """Same shape as inventory(), read from the archives themselves, so the inventory describes exactly what was archived
    even when the live site changed while the archive was being written."""
    inv, dirs, parents = {}, set(), set()
    for path in paths:
        with tarfile.open(str(path)) as tar:
            for m in tar:
                name = m.name.rstrip('/')
                parent = name.rsplit('/', 1)[0] if '/' in name else ''
                parents.add(parent)
                if m.isdir():
                    dirs.add(name)
                elif m.issym():
                    inv[name] = 'link:' + m.linkname
                elif m.isfile():
                    h = hashlib.sha256(); f = tar.extractfile(m)
                    for chunk in iter(lambda: f.read(1 << 20), b''):
                        h.update(chunk)
                    inv[name] = h.hexdigest()
                elif m.islnk():
                    inv[name] = ('hardlink', m.linkname)
    for name, v in list(inv.items()):   # hard links: same content as their target
        if isinstance(v, tuple):
            inv[name] = inv.get(v[1], 'missing-link-target')
    for d in dirs:
        if d not in parents:
            inv[d] = 'dir'
    return inv


def digest(inv):
    return hashlib.sha256(json.dumps(inv, sort_keys=True).encode()).hexdigest()


def compare_inventory(want, have):
    missing = sorted(k for k in want if k not in have)
    added = sorted(k for k in have if k not in want)
    changed = sorted(k for k in want if k in have and want[k] != have[k])
    split = lambda xs: ([x for x in xs if not x.startswith(VOLATILE)], [x for x in xs if x.startswith(VOLATILE)])
    out = {}
    for label, xs in (('missing', missing), ('added', added), ('changed', changed)):
        hard, soft = split(xs)
        out[label] = {'count': len(hard), 'sample': hard[:20], 'volatile_cache_count': len(soft)}
    out['equal'] = all(out[k]['count'] == 0 for k in ('missing', 'added', 'changed'))
    return out


def tool_hashes(base):
    names = {'protein': 'protein-requirement-tool.html', 'diabetes': 'diabetes-risk-tool.html'}
    return {slug: sha256_file(base / 'tools' / f) for slug, f in names.items() if (base / 'tools' / f).is_file()}


def wp(root, *args):
    return subprocess.run(['wp', '--path=' + str(root)] + list(args), stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)


def site_state(root):
    return {
        'siteurl': wp(root, 'option', 'get', 'siteurl').stdout.strip(),
        'lld_members_schema_version': wp(root, 'option', 'get', 'lld_members_schema_version').stdout.strip() or None,
    }


def prune(full):
    """DEC-19 retention: keep the newest backup of each of the last 14 days that have one and of each of the last 8 ISO
    weeks that have one; remove the rest. Only directories named full-<timestamp> holding a manifest are considered."""
    entries = []
    for d in full.iterdir():
        if d.is_dir() and re.fullmatch(ID_RE, d.name) and (d / 'manifest.json').is_file():
            entries.append((datetime.datetime.strptime(d.name[5:], '%Y%m%dT%H%M%SZ'), d))
    entries.sort(reverse=True)
    keep, days, weeks = set(), [], []
    for ts, d in entries:
        day, week = ts.date(), ts.isocalendar()[:2]
        if day not in days and len(days) < POLICY['retain']['daily']:
            days.append(day); keep.add(d)
        if week not in weeks and len(weeks) < POLICY['retain']['weekly']:
            weeks.append(week); keep.add(d)
    removed = []
    for ts, d in entries:
        if d not in keep:
            shutil.rmtree(str(d)); removed.append(d.name)
    return {'kept': sorted(d.name for d in keep), 'removed': removed}


def run(env, root, op, backup_id):
    root = fixed_root(env, root)
    base = Path.home() / 'lld-backups' / env
    full = base / 'full'
    for p in (base, full):
        if p.is_symlink():
            raise ValueError('Symlinked backup storage')
    if op == 'history':
        rows = []
        if full.is_dir():
            for d in sorted(full.iterdir()):
                m = d / 'manifest.json'
                if m.is_file():
                    doc = json.loads(m.read_text())
                    rows.append({'backup_id': doc['backup_id'], 'created_at': doc['created_at'], 'verified': doc.get('verified', False)})
        return {'env': env, 'policy': POLICY, 'backups': rows}
    if not re.fullmatch(ID_RE, backup_id):
        raise ValueError('Invalid backup id (full-YYYYMMDDTHHMMSSZ)')
    target = full / backup_id

    if op == 'backup':
        base.mkdir(mode=0o700, parents=True, exist_ok=True); full.mkdir(mode=0o700, exist_ok=True)
        os.chmod(str(base), 0o700); os.chmod(str(full), 0o700)
        target.mkdir(mode=0o700)
        t0 = time.time(); started = now()
        db = Db(root, target)
        try:
            checks_before = db.checksums()
            options_before = db.options_digest()
            db.dump(target / 'db.sql.gz')
            tables = db.tables()
        finally:
            db.close()
        for tree in TREES:
            with tarfile.open(str(target / (tree + '.tgz')), 'w:gz', compresslevel=6) as tar:
                tar.add(str(root / tree), arcname=tree, filter=lambda ti: None if excluded(ti.name) else ti)
        inv = inventory_from_archives([target / (t + '.tgz') for t in TREES])
        with gzip.open(str(target / 'inventory.json.gz'), 'wt') as f:
            json.dump(inv, f, sort_keys=True)
        archives = {}
        for name in ['db.sql.gz'] + [t + '.tgz' for t in TREES] + ['inventory.json.gz']:
            p = target / name; os.chmod(str(p), 0o600)
            archives[name] = {'sha256': sha256_file(p), 'bytes': p.stat().st_size}
        # verification after writing: gzip streams complete, tar members readable, dump ends with the completion marker
        with gzip.open(str(target / 'db.sql.gz'), 'rb') as gz:
            tail = b''
            for chunk in iter(lambda: gz.read(1 << 20), b''):
                tail = (tail + chunk)[-4096:]
        if b'-- Dump completed' not in tail:
            raise ValueError('Database dump is incomplete')
        members = 0
        for tree in TREES:
            with tarfile.open(str(target / (tree + '.tgz'))) as tar:
                members += len(tar.getmembers())
        manifest = {
            'backup_id': backup_id, 'env': env, 'created_at': started, 'finished_at': now(), 'duration_s': round(time.time() - t0, 1),
            'contents': {'database': 'db.sql.gz', 'wp-content': 'wp-content.tgz', 'public_html/tools': 'tools.tgz', 'inventory': 'inventory.json.gz'},
            'excluded': EXCLUDE + ['wp-config.php (credentials stay server-owned)'],
            'archives': archives,
            'database': {'tables': len(tables), 'checksums': checks_before, 'options_digest': options_before},
            'inventory': {'files': len(inv), 'sha256': digest(inv)},
            'tool_hashes': tool_hashes(root),
            'site': site_state(root),
            'tar_members': members,
            'verified': True,
            'policy': POLICY,
        }
        (target / 'manifest.json').write_text(json.dumps(manifest, indent=1, sort_keys=True))
        os.chmod(str(target / 'manifest.json'), 0o600)
        manifest['retention'] = prune(full)
        return manifest

    manifest = json.loads((target / 'manifest.json').read_text())
    if op == 'verify':
        db = Db(root, target)
        try:
            live = db.checksums()
            live_options = db.options_digest()
        finally:
            db.close()
        want = manifest['database']['checksums']
        db_diff = {t: {'backup': want.get(t), 'live': live.get(t)} for t in sorted(set(want) | set(live)) if want.get(t) != live.get(t)}
        with gzip.open(str(target / 'inventory.json.gz'), 'rt') as f:
            inv_want = json.load(f)
        return {'backup_id': backup_id, 'checked_at': now(),
                'database': {'equal': not db_diff, 'differences': db_diff,
                             'options_settings_equal': manifest['database'].get('options_digest') == live_options if manifest['database'].get('options_digest') else None},
                'files': compare_inventory(inv_want, inventory(root)), 'tool_hashes': tool_hashes(root), 'site': site_state(root)}

    if op == 'restore':
        lock = base / 'deploy.lock'
        with lock.open('x') as f:   # the overlay deploy takes the same lock: no deploy can run during a restore
            f.write(backup_id)
        maint = root / '.maintenance'
        timings = {}
        try:
            t0 = time.time()
            for name, meta in manifest['archives'].items():
                if sha256_file(target / name) != meta['sha256']:
                    raise ValueError('Archive checksum mismatch: ' + name)
            timings['archives_checked_s'] = round(time.time() - t0, 1)
            fresh, aside = target / 'restore', target / 'pre-restore'
            if fresh.exists() or aside.exists():
                raise ValueError('A restore from this backup was already started; inspect ' + str(target))
            fresh.mkdir(mode=0o700); aside.mkdir(mode=0o700)
            for tree in TREES:
                with tarfile.open(str(target / (tree + '.tgz'))) as tar:
                    for m in tar.getmembers():
                        if m.name.startswith('/') or '..' in Path(m.name).parts or not (m.name == tree or m.name.startswith(tree + '/')):
                            raise ValueError('Unsafe archive entry ' + m.name)
                    tar.extractall(str(fresh))
            with gzip.open(str(target / 'inventory.json.gz'), 'rt') as f:
                inv_want = json.load(f)
            clean = compare_inventory(inv_want, inventory(fresh))
            if not clean['equal']:
                raise ValueError('Extracted files differ from the inventory: ' + json.dumps(clean)[:500])
            timings['extracted_and_checked_s'] = round(time.time() - t0, 1)
            if os.stat(str(fresh)).st_dev != os.stat(str(root)).st_dev:
                raise ValueError('Backup storage and document root are on different filesystems; swap would copy')
            maint.write_text('<?php $upgrading = %d; ?>' % int(time.time()))
            t_swap = time.time()
            db = Db(root, target)
            try:
                names = db.tables()
                if names:
                    db.sql('SET FOREIGN_KEY_CHECKS=0; DROP TABLE ' + ', '.join('`%s`' % n for n in names))
                if db.tables():
                    raise ValueError('Database not empty before import')
                db.load(target / 'db.sql.gz')
                timings['database_imported_s'] = round(time.time() - t_swap, 1)
            finally:
                db.close()
            for tree in TREES:
                os.rename(str(root / tree), str(aside / tree))
                os.rename(str(fresh / tree), str(root / tree))
            for rel in EXCLUDE:   # excluded paths were not archived: keep the server's copy
                if (aside / rel).exists() and not (root / rel).exists():
                    os.rename(str(aside / rel), str(root / rel))
            timings['files_swapped_s'] = round(time.time() - t_swap, 1)
        finally:
            if maint.exists():
                maint.unlink()
            if lock.is_file() and lock.read_text() == backup_id:
                lock.unlink()
        wp(root, 'cache', 'flush')
        purge = wp(root, 'litespeed-purge', 'all')
        timings['total_s'] = round(time.time() - t0, 1)
        timings['downtime_s'] = round(time.time() - t_swap, 1)
        return {'backup_id': backup_id, 'restored_at': now(), 'timings': timings, 'purge_ok': purge.returncode == 0,
                'pre_restore_copy': str(aside.relative_to(Path.home())), 'site': site_state(root)}
    raise ValueError('Unknown operation')


if __name__ == '__main__':
    try:
        print(json.dumps(run(sys.argv[1], Path(sys.argv[2]), sys.argv[3], sys.argv[4] if len(sys.argv) > 4 else ''), indent=1, sort_keys=True))
    except Exception as e:
        sys.exit('Backup operation refused: ' + str(e))
