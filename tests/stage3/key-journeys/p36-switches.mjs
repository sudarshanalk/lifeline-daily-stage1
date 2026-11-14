// Feature switches for one spec (P3-36). CI sets the Stage 3 switches in tests/ci/stage2-wp.sh and leaves some off
// (terms_first, prices_launch, access_tiers, pause …), while staging has every feature on. A spec whose criterion
// needs a switch turns it on through the test route (/test/admin, as the admin panel spec does) and puts every
// switch back as it found it, so no other spec sees a changed site.
import { expect } from '@playwright/test';

async function admin(request, data) {
  const response = await request.post('/wp-json/lld-members/v1/test/admin', { data });
  expect(response.ok(), `${data.op} ${data.feature || ''}: ${await response.text()}`).toBeTruthy();
  return response.json();
}

/** Turn these features on for everyone; returns what to hand to switchesBack(). */
export async function switchesOn(request, features) {
  const saved = [];
  for (const feature of features) {
    const before = await admin(request, { op: 'feature', feature });
    saved.push({ feature, row: before.row });
    if (before.state !== 'everyone') {
      await admin(request, { op: 'restore', feature, row: { state: 'everyone', approved_by: 'spec', changed_at: '', override: false } });
      expect((await admin(request, { op: 'feature', feature })).state, feature).toBe('everyone');
    }
  }
  return saved;
}

/** Put each switch back exactly as switchesOn() found it (a switch with no stored row loses its row again). */
export async function switchesBack(request, saved = []) {
  for (const { feature, row } of saved) {
    await admin(request, row ? { op: 'restore', feature, row } : { op: 'restore', feature });
  }
}
