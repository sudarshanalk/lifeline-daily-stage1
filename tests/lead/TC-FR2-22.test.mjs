// TC-FR2-22 search console work (S2-L5): IndexNow body and the monthly reminder, from lld-tools/search.php without WordPress.
// AS-01/AS-02 need the console exports (owner input O-3); the live send and log are checked on staging and after launch.
import { describe, expect, test } from 'vitest';
import { call, SEARCH_CFG } from './search-php.mjs';

const KEY = 'a'.repeat(32);

describe('TC-FR2-22 search consoles, IndexNow and the monthly checklist', () => {
  test('TC-FR2-22-AS-03', () => {
    // one notice per host: only this host's addresses, each once, with the key and its key file
    const b = call('lld_search_indexnow_body', 'lifelinedaily.com', KEY, `https://lifelinedaily.com/${KEY}.txt`,
      ['https://lifelinedaily.com/new-article/', 'https://lifelinedaily.com/new-article/', 'https://other.example/x/', 'https://LIFELINEDAILY.com/updated/']);
    expect(b).toEqual({ host: 'lifelinedaily.com', key: KEY, keyLocation: `https://lifelinedaily.com/${KEY}.txt`, urlList: ['https://lifelinedaily.com/new-article/', 'https://LIFELINEDAILY.com/updated/'] });
  });

  test('TC-FR2-22-AS-04', () => {
    // the reminder lists every checklist item, totals under 30 minutes, and carries no personal or health details
    const m = call('lld_search_checklist_email', SEARCH_CFG, 'https://lifelinedaily.com/', '2026-11');
    expect(m.minutes).toBeLessThan(30);
    expect(m.minutes).toBe(SEARCH_CFG.checklist.items.reduce((s, i) => s + i.minutes, 0));
    SEARCH_CFG.checklist.items.forEach((i, n) => expect(m.body).toContain(`${n + 1}. ${i.task}`));
    expect(m.subject).toMatch(/under 30 minutes/);
    expect(m.body).not.toMatch(/@|weight|diabetes|\bkg\b/i);
  });
});
