// Follow-up items from the C3 and C4 reviews. The browser checks are in tests/stage2/e2e; these pin the code paths.
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const plugin = resolve(dirname(fileURLToPath(import.meta.url)), '../../site/public_html/wp-content/plugins/lld-members/includes');
const read = (name) => readFileSync(resolve(plugin, name), 'utf8').replace(/\r\n/g, '\n');
const content = read('class-content.php');
const personal = read('class-personal.php');
const mail = read('class-mail.php');
const account = read('class-account.php');

function body(source, signature) {
  const start = source.indexOf(signature);
  expect(start, signature).toBeGreaterThan(-1);
  const next = source.indexOf('\n\t}\n', start);
  return source.slice(start, next);
}

test('TC-NFR2-07-AS-01 shown() skips trashed working copies and re-checks each version fingerprint', () => {
  expect(body(content, 'public static function shown(')).toMatch(/'trash'/);
  const record = body(content, 'private static function record_problem(');
  expect(record).toMatch(/substr\( hash\( 'sha256', \(string\) \$version->post_content \), 0, 16 \)/);
  expect(record).toContain('_lld_stored_hash');
});

test('TC-NFR2-07-AS-01 linked items use their approved version, and approving one re-versions its parents (B16)', () => {
  const links = body(content, 'private static function link_fingerprints(');
  expect(links).toContain('self::shown( $id )');
  expect(content).not.toContain('latest_fingerprint');
  const ids = body(content, 'private static function linked_ids(');
  for (const key of ["'activity_ids'", "'meals', 'swaps'", "'_id'"]) expect(ids).toContain(key);
  expect(body(content, 'private static function decide(')).toContain('self::reversion_parents(');
  expect(body(content, 'public static function approve_now(')).toContain('self::reversion_parents(');
});

test('TC-FR2-38-AS-06 typed tags must already be in the managed tag list (B2)', () => {
  expect(body(content, 'public static function capture(')).toContain('self::unknown_tags( $posted )');
  expect(body(content, 'public static function save_article_tags(')).toContain('self::unknown_tags( $tags )');
  expect(body(content, 'public static function unknown_tags(')).toContain("term_exists(");
});

test('TC-FR2-08-AS-04 matrix rows and other owner records are not library items', () => {
  const owner = body(content, 'private static function not_in_library(');
  for (const type of ['lld_matrix', 'lld_wording', 'lld_rule', 'lld_qa']) expect(owner).toContain(`'${type}'`);
  expect(body(content, 'private static function one(')).toContain('self::not_in_library()');
  expect(body(content, 'private static function catalog(')).toContain('self::not_in_library()');
});

test('TC-NFR2-07-AS-03 the wording check covers diagnosis, treatment and cure claims', () => {
  const list = body(content, 'public static function flagged_phrases(');
  for (const phrase of ['cures', 'treats', 'heals', 'reverses', 'diagnosis', 'clinically proven', 'stop taking']) expect(list).toContain(`'${phrase}'`);
  expect(body(content, 'public static function wording_flags(')).toContain('self::flagged_phrases()');
});

test('TC-FR2-25-AS-06 the profile reminder needs health consent and is not sent again after erasure', () => {
  const reminders = body(personal, 'private static function reminders(');
  const guard = reminders.indexOf("has_consent( $member_id, 'health' )");
  expect(guard).toBeGreaterThan(-1);
  expect(reminders).toContain('self::health_erased( $member_id )');
  expect(guard).toBeLessThan(reminders.indexOf('send_profile_reminder'));
  expect(guard).toBeLessThan(reminders.indexOf('$wpdb->insert'));
  expect(body(personal, 'private static function health_erased(')).toContain("what_erased IN ('health', 'account')");
});

test('TC-FR2-06-AS-03 suggestion history (C15) is written only with health consent', () => {
  expect(body(personal, 'public static function remember_open(')).toContain("has_consent( (int) $member_id, 'health' )");
  expect(body(personal, 'private static function hide_item(')).toContain("has_consent( (int) $member_id, 'health' )");
});

test('TC-FR2-06-AS-01 the tool card goes through the safety matrix', () => {
  expect(body(personal, 'private static function tool_card(')).toContain('LLD_Members_Safety::allows( $member_id, $card )');
});

test('TC-FR2-06-AS-07 EM-15 does not name the goal', () => {
  const em15 = body(mail, 'public static function send_goal_live(');
  expect(em15).toContain('unset( $title )');
  expect(em15).not.toMatch(/\{\$title\}|\. \$title/);
});

test('TC-FR2-25-AS-01 the health route refuses "none" with a condition', () => {
  expect(body(account, 'public static function rest_health(')).toContain('None cannot be combined with a condition.');
});
