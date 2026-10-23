import importlib.util, json, tempfile, unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
def module(name):
    spec=importlib.util.spec_from_file_location(name,ROOT/'deploy'/f'{name}.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
r=module('remote');pre=module('preflight')
class Safety(unittest.TestCase):
    def test_managed_headers_idempotent_preserves_wordpress(self):
        current='# WordPress\nKeep this\n'; add='# BEGIN lld\nHeader x y\n# END lld\n'
        once=r.block(current,add)
        self.assertEqual(r.block(once,add),once);self.assertTrue(once.startswith(current))
        with self.assertRaises(ValueError):r.block('# BEGIN lld\n',add)
        with self.assertRaises(ValueError):r.block('# END lld\n# BEGIN lld\n',add)
    def test_protected_paths(self):
        for name in ['tools/status.json','tools/api/config.php','tools/api/outbox/result.html','tools/api/state/rl.json','wp-config.php','wp-content/uploads/a.jpg']:
            self.assertFalse(r.owned(name),name)
        self.assertTrue(r.owned('tools/api/outbox/.htaccess'))
    def test_backup_restore_preserves_runtime_and_removes_new_overlay_file(self):
        with tempfile.TemporaryDirectory() as tmp, patch.object(Path,'home',return_value=Path(tmp)):
            root=Path(tmp)/'domains/lifelinedaily.com/public_html/stg';root.mkdir(parents=True)
            (root/'wp-config.php').write_text('private config')
            (root/'tools/api').mkdir(parents=True)
            (root/'tools/a.html').write_text('before')
            (root/'tools/api/config.php').write_text('secret')
            (root/'tools/status.json').write_text('withdrawn')
            (root/'.htaccess').write_text('WordPress\n')
            stamp='20261023T120000Z-123456789abc'
            r.run('staging',root,'backup',stamp)
            r.run('staging',root,'unlock',stamp)
            (root/'tools/a.html').write_text('after');(root/'tools/new.html').write_text('new')
            r.run('staging',root,'restore',stamp);r.run('staging',root,'unlock',stamp)
            self.assertEqual((root/'tools/a.html').read_text(),'before')
            self.assertFalse((root/'tools/new.html').exists())
            self.assertEqual((root/'tools/api/config.php').read_text(),'secret')
            self.assertEqual((root/'tools/status.json').read_text(),'withdrawn')
            self.assertEqual((root/'.htaccess').read_text(),'WordPress\n')
    def test_template_comment_markers_do_not_break_idempotency(self):
        comment='# template mentions "# BEGIN lld" and "# END lld" inline\n'
        content='# BEGIN lld\n'+comment+'Header x y\n# END lld\n'
        once=r.block('WordPress\n',content)
        self.assertEqual(r.block(once,content),once)
    def test_wrong_destination_rejected(self):
        with self.assertRaises(ValueError):r.run('staging',Path('/tmp/live'),'check','20261023T120000Z-123456789abc')
    def test_staging_allows_missing_approval_production_rejects(self):
        pre.check(ROOT,'staging')
        with self.assertRaises((ValueError,FileNotFoundError)):pre.check(ROOT,'production','rc-unapproved')
if __name__=='__main__':unittest.main()
