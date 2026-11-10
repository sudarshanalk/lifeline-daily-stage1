// Account-section review fixes that a browser run in CI cannot reach (Account_Section_Review.md, 31 Oct 2026).
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const plugin = resolve(dirname(fileURLToPath(import.meta.url)), '../../site/public_html/wp-content/plugins/lld-members');
const read = (name) => readFileSync(resolve(plugin, name), 'utf8');

test('TC-FR2-02-AS-01 settings shows the quick goal, track, height and diary-photo fields only where the test routes are open', () => {
  const account = read('includes/class-account.php');
  const gate = account.indexOf('if ( lld_members_test_routes_open() && self::has_consent( $member_id, \'health\' ) ) {');
  expect(gate).toBeGreaterThan(0);
  for (const id of ["'id' => 'lld_goal'", "'id' => 'lld_track'", "'id' => 'lld_height'", 'id="lld_diary"']) {
    const at = account.indexOf(id);
    expect(at, id).toBeGreaterThan(gate);
    expect(account.indexOf('$main .= self::consent_fields( $member_id );'), id).toBeGreaterThan(at);
  }
});

test('TC-FR2-06-AS-04 "coming soon" shows only when nothing of that kind is approved; safety exclusions keep the matrix message', () => {
  const premium = read('includes/class-premium.php');
  const programme = read('includes/class-programme.php');
  // every "coming soon" sits behind a check that no item of that type is approved at all
  const soon = [...premium.matchAll(/lld_coming_soon\(/g), ...programme.matchAll(/lld_coming_soon\(/g)].length;
  expect(soon).toBe(6);
  // no challenge this month but one approved for a later month: the member is told when it starts
  expect(premium).toMatch(/\$next = \$shown \? null : self::next_challenge\( \$member_id, \$today \);/);
  expect(premium).toMatch(/\$when <= \$month \|\| ! LLD_Members_Safety::allows\( \$member_id, \$challenge \)/);
  expect(premium).toMatch(/! \$plan\['days'\] && ! LLD_Members_Content::has_approved\( 'lld_meal' \)/);
  expect(premium).toMatch(/has_approved\( 'lld_workout' \) \? LLD_Members_Safety::empty_html\( \$member_id \) : lld_coming_soon/);
  expect(premium).toMatch(/has_approved\( 'lld_challenge' \) \? LLD_Members_Safety::empty_html\( \$member_id \) : lld_coming_soon/);
  expect(programme).toMatch(/! \$plan\['actions'\] && ! LLD_Members_Content::has_approved\( 'lld_activity' \)/);
  // the safety empty states are still there for members whose rules exclude approved items
  expect(premium).toContain("Contact support if you need a plan that fits you.");
  expect(programme).toContain("$plan['note'] ) . '</p>' . LLD_Members_Safety::empty_html( $member_id )");
  const content = read('includes/class-content.php');
  expect(content).toMatch(/public static function has_approved\( \$type \)/);
  expect(content).toMatch(/null !== self::shown\( \(int\) \$id \)/);
});
