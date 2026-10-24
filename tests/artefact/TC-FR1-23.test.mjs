// TC-FR1-23 Category tidy-up — artefact half (AS-06): content/planned-categories.json validates SCH-14 and lists healthy-living
// and stress with parent wellness; it agrees with the plugin's data/tidy.json (the source `wp lld tidy apply --planned-out`
// writes from) and with FX-WP-TIDY.
import { describe, expect, test } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { ROOT } from '../lib/paths.mjs';
import { validateFile } from '../lib/validate.mjs';
import { fixtures } from '../lib/fixtures.mjs';

const FILE = resolve(ROOT, 'content', 'planned-categories.json');
const tidy = JSON.parse(readFileSync(resolve(ROOT, 'site', 'public_html', 'wp-content', 'mu-plugins', 'lld-tools', 'data', 'tidy.json'), 'utf8'));

describe('TC-FR1-23 category tidy-up — artefacts', () => {
  test('TC-FR1-23-AS-06', () => {
    const v = validateFile('planned-categories.schema.json', FILE); expect(v.ok, v.errors?.join('; ')).toBe(true);
    const doc = JSON.parse(readFileSync(FILE, 'utf8'));
    for (const slug of ['healthy-living', 'stress']) {
      const c = doc.categories.find((x) => x.slug === slug);
      expect(c, `${slug} listed`).toBeTruthy(); expect(c.parent, `${slug} parent`).toBe('wellness');
    }
    expect(doc.categories, 'same list as the plugin data').toEqual(tidy.planned);
    const fx = fixtures('FX-WP-TIDY')[0].inputs;
    expect(tidy.delete.map((d) => d.slug).sort(), 'plugin deletes exactly the FX-WP-TIDY categories').toEqual([...fx.delete_redirect_wellness, ...fx.delete_redirect_root].sort());
    expect(tidy.hide_noindex.map((h) => h.slug)).toEqual(fx.hide_noindex);
    expect(tidy.default_category).toBe(fx.default_category);
  });
});
