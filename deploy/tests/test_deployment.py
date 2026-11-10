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
    def test_members_plugin_is_managed_other_plugins_are_not(self):
        self.assertTrue(r.owned('wp-content/plugins/lld-members/lld-members.php'))
        self.assertTrue(r.owned('wp-content/plugins/lld-members/includes/schema.php'))
        for name in ['wp-content/plugins/paid-memberships-pro/a.php','wp-content/plugins/lld-members-evil/a.php','wp-content/plugins/index.php']:
            self.assertFalse(r.owned(name),name)
    def test_members_plugin_backed_up_and_db_update_only_when_active(self):
        with tempfile.TemporaryDirectory() as tmp, patch.object(Path,'home',return_value=Path(tmp)):
            root=Path(tmp)/'domains/stg.lifelinedaily.com/public_html';plug=root/'wp-content/plugins/lld-members'
            plug.mkdir(parents=True);(root/'wp-config.php').write_text('x');(plug/'lld-members.php').write_text('v1')
            (root/'wp-content/plugins/other.php').write_text('not ours')
            self.assertEqual([p.relative_to(root).as_posix() for p in r.files(root)],['wp-content/plugins/lld-members/lld-members.php'])
            stamp='20261025T120000Z-123456789abc';r.run('staging',root,'backup',stamp)
            calls=[]
            def fake(cmd,**kw):
                calls.append(cmd[2:]);return type('R',(),{'returncode':1 if 'is-active' in cmd and not active else 0})()
            active=False
            with patch.object(r.subprocess,'run',side_effect=fake):r.run('staging',root,'dbupdate',stamp)
            self.assertEqual(calls,[['plugin','is-active','lld-members']])
            calls.clear();active=True
            with patch.object(r.subprocess,'run',side_effect=fake):r.run('staging',root,'dbupdate',stamp)
            self.assertEqual(calls,[['plugin','is-active','lld-members'],['lld-members','db-update']])
            r.run('staging',root,'unlock',stamp)
    def test_backup_restore_preserves_runtime_and_removes_new_overlay_file(self):
        with tempfile.TemporaryDirectory() as tmp, patch.object(Path,'home',return_value=Path(tmp)):
            root=Path(tmp)/'domains/stg.lifelinedaily.com/public_html';root.mkdir(parents=True)
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
