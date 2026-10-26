// Data Model A3 (integration of C2's erasure and C5's sent programme emails): records naming an enrolment are deleted
// with that enrolment. Withdrawing health consent deletes the member's enrolments, so LLD_Members_Erasure::withdraw_health
// must also delete their mail_sent claims that name an enrolment (review:<enrolment>:<week>, end:<enrolment>), while the
// daily-reminder and welcome claims stay. Runs the plugin's own class through the PHP command line with a small
// in-memory stand-in for $wpdb that applies the DELETE ... LIKE it is given.
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const erasure = resolve(here, '../../site/public_html/wp-content/plugins/lld-members/includes/class-erasure.php').replace(/\\/g, '/');

const harness = `
define('ABSPATH', __DIR__ . '/');
class LLD_Members_Schema {
  public static function table($s) { return 'wp_lld_' . $s; }
  public static function health_tables() { return array('goal_choice', 'enrolment', 'weekly_plan'); }
}
class LLD_Members_Photos { public static function delete_member($m, $k) {} }
class LLD_Members_Privacy { public static function sweep_downloads($m) {} }
class FakeDb {
  public $rows = array();
  public $queries = array();
  public function prepare($sql, ...$args) {
    $i = 0;
    return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
      $v = $args[$i++];
      return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
    }, $sql);
  }
  public function esc_like($t) { return addcslashes($t, '_%\\\\'); }
  public function query($sql) {
    $this->queries[] = $sql;
    if (preg_match("/^DELETE FROM wp_lld_mail_sent WHERE member_id = (\\d+) AND claim_key LIKE '(.*)'$/", $sql, $m)) {
      $like = stripslashes($m[2]);
      $re = '';
      for ($k = 0, $n = strlen($like); $k < $n; $k++) {
        $c = $like[$k];
        if ('\\\\' === $c && $k + 1 < $n) { $re .= preg_quote($like[++$k], '/'); }
        elseif ('%' === $c) { $re .= '.*'; }
        elseif ('_' === $c) { $re .= '.'; }
        else { $re .= preg_quote($c, '/'); }
      }
      $before = count($this->rows);
      $this->rows = array_values(array_filter($this->rows, function ($r) use ($m, $re) {
        return !((int) $r['member_id'] === (int) $m[1] && preg_match('/^' . $re . '$/s', $r['claim_key']));
      }));
      return $before - count($this->rows);
    }
    return 0;
  }
  public function get_row($sql) { return null; }
  public function get_results($sql) { return array(); }
  public function get_col($sql) { return array(); }
}
$wpdb = new FakeDb();
$wpdb->rows = array(
  array('member_id' => 7, 'claim_key' => 'review:41:1'),
  array('member_id' => 7, 'claim_key' => 'review:41:2'),
  array('member_id' => 7, 'claim_key' => 'end:41'),
  array('member_id' => 7, 'claim_key' => 'daily'),
  array('member_id' => 7, 'claim_key' => 'welcome'),
  array('member_id' => 8, 'claim_key' => 'review:52:1'),
  array('member_id' => 8, 'claim_key' => 'end:52'),
);
require '${erasure}';
LLD_Members_Erasure::withdraw_health(7, false);
echo json_encode(array_map(function ($r) { return $r['member_id'] . ' ' . $r['claim_key']; }, $wpdb->rows));
`;

test('withdrawing health consent deletes the claims that name an enrolment and keeps the daily-reminder claims', () => {
  const left = JSON.parse(execFileSync('php', ['-d', 'display_errors=stderr', '-r', harness], { encoding: 'utf8' }));
  // Member 7: the weekly-review and end-of-track claims went with the enrolment; daily and welcome stay.
  expect(left.filter((row) => row.startsWith('7 '))).toEqual(['7 daily', '7 welcome']);
  // Another member's claims are untouched.
  expect(left.filter((row) => row.startsWith('8 '))).toEqual(['8 review:52:1', '8 end:52']);
});
