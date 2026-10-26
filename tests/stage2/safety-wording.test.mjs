// The safety matrix and the Sleep and Stress screening use the exact wording of requirements section 8.2 and FR2-38.
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';
import { matrixRows, safetyTags } from './tools/safety-matrix.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members/includes');
const safety = readFileSync(resolve(plugin, 'class-safety.php'), 'utf8');
const personal = readFileSync(resolve(plugin, 'class-personal.php'), 'utf8');
const content = readFileSync(resolve(plugin, 'class-content.php'), 'utf8');
const requirements = readFileSync(resolve(root, 'docs/stage2/01_Requirements_Stage2.md'), 'utf8');

const section = requirements.slice(requirements.indexOf('### 8.2 Safety comes first'), requirements.indexOf('### 8.3'));
const tableRows = section.split('\n').filter((line) => line.startsWith('| **'));
const upperFirst = (text) => text.charAt(0).toUpperCase() + text.slice(1);

test('TC-FR2-25-AS-05 every quoted line of the 8.2 matrix is in the launch rules, word for word', () => {
  expect(tableRows.length).toBe(10);
  // "general safe" names a set of content, not a line members read.
  const quoted = tableRows.flatMap((line) => [...line.matchAll(/"([^"]+)"/g)].map((m) => m[1])).filter((text) => text !== 'general safe');
  expect(quoted.length).toBeGreaterThanOrEqual(8);
  for (const text of quoted) {
    expect(safety.includes(`'${text}'`) || safety.includes(`'${upperFirst(text)}'`), text).toBe(true);
  }
  expect(safety).toContain("'help_link' => 'https://findahelpline.com'");
});

test('TC-FR2-25-AS-05 the runner rows exclude exactly the tags of each 8.2 row', () => {
  const byName = {
    unknown: '**Unknown:**', kidney: '**Kidney disease**', pregnancy: '**Pregnancy or breastfeeding**',
    'eating-disorder': '**Eating disorder, now or in the past**', diabetes: '**Type 2 diabetes**',
    'blood-pressure': '**High blood pressure**', cholesterol: '**High cholesterol**',
  };
  for (const [name, label] of Object.entries(byName)) {
    const line = tableRows.find((row) => row.includes(label));
    // The first sentence lists the excluded tags; later sentences are notes (for example high-intensity under high blood pressure).
    const excluded = line.split('|')[2].split('. ')[0];
    const tags = safetyTags.filter((tag) => new RegExp(`(^|[\\s,;])${tag}(?=[\\s,;.]|$)`).test(excluded));
    const row = matrixRows.find((r) => r.name === name);
    expect([...row.hidden].sort(), name).toEqual(tags.sort());
    expect(Boolean(row.badges), `${name} badges`).toBe(excluded.includes('weight-loss badges'));
    expect(Boolean(row.track), `${name} track`).toBe(line.includes('Healthy Weight track not offered'));
  }
});

test('TC-FR2-38-AS-01 self-harm is checked before the other answers and holds the track for the session', () => {
  const body = personal.slice(personal.indexOf('private static function screening()'), personal.indexOf('private static function emergency_html()'));
  expect(body.indexOf("'yes' === $harm")).toBeGreaterThan(-1);
  expect(body.indexOf("'yes' === $harm")).toBeLessThan(body.indexOf('Answer all three questions'));
  expect(body).toContain("self::session_mark( $member_id, 'lld_hold', 'sleep-stress' )");
  expect(personal).toContain('If you are in danger now, call your local emergency number');
  expect(personal).toContain('999 UK, 911 US and Canada, 112 EU and India, 000 Australia');
  expect(personal).toContain('https://findahelpline.com');
  for (const fn of ['private static function tracks()', 'private static function enrol(']) {
    const start = personal.indexOf(fn);
    expect(personal.slice(start, start + 1600), fn).toContain('track_held( $member_id )');
  }
});

test('TC-FR2-08-AS-04 goals are read from the approved version only', () => {
  const goals = content.slice(content.indexOf('public static function approved_goals()'), content.indexOf('public static function goal_gaps()'));
  expect(goals).toContain('self::shown( $post->ID )');
  expect(goals).toContain('LLD_Members_Safety::track_allowed( $member_id');
  expect(content).not.toMatch(/function goal_ready\(\s*\$goal_id/);
  expect(personal).toContain('LLD_Members_Content::approved_goals()');
  expect(personal).toContain('LLD_Members_Content::member_goals( $member_id )');
});
