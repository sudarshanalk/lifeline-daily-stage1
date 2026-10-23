// TC-FR1-18 Editorial ownership and review metadata — e2e + wpcli against the local WordPress stack (snapshot + overlay plugin,
// DEC-69). Fixtures: FX-WP-POST-01 (approved, next review 2027), FX-WP-POST-06 (next review yesterday), FX-WP-POST-08 (review
// pending), FX-WP-USERS (contributor, editor) — all seeded for the run and removed afterwards; the landing pages' review meta is
// restored to its prior state. Owner values use the fixture placeholder "[Clinical reviewer]", never a real name.
import { test, expect } from '@playwright/test';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startWpStack, available } from '../lib/wp-stack.mjs';
import { validateFile } from '../lib/validate.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const ROOT = resolve(here, '..', '..');
const site = JSON.parse(readFileSync(resolve(ROOT, 'fixtures', 'site', 'site.json'), 'utf8')).fixtures;
const fx = (id) => site.find((f) => f.id === id).inputs;
const schema = JSON.parse(readFileSync(resolve(ROOT, 'schemas', 'wp-meta.schema.json'), 'utf8'));
const LANDING = { protein: 6102, 'diabetes-risk-check': 6066 };   // page ids in the snapshot database (as TC-FR1-04)
const META = ['lld_review_owner', 'lld_reviewed_date', 'lld_next_review_date', 'lld_review_status'];
const a = available();
test.describe.configure({ mode: 'serial' });
test.skip(!a.ok, a.reason);

let stack; const seeded = { posts: {}, users: [] }; const landingBefore = {};
const ymd = (d) => d.toISOString().slice(0, 10);
const yesterday = ymd(new Date(Date.now() - 86400000));
const ok = (r, what) => { if (r.status !== 0) throw new Error(`${what}: ${r.stdout}${r.stderr}`); return r.stdout.trim(); };

test.beforeAll(async () => {
  stack = await startWpStack();
  if (stack.external) return;
  const catId = (slug) => stack.wp('term', 'list', 'category', `--slug=${slug}`, '--field=term_id').stdout.trim();
  const seed = (id, inputs) => {
    const pid = ok(stack.wp('post', 'create', '--post_type=post', `--post_status=${inputs.status}`, `--post_title=${id} (TC-FR1-18 seed)`, `--post_content=<p>Seeded by TC-FR1-18 (${id}); removed after the run.</p>`, `--post_category=${catId(inputs.category)}`, '--porcelain'), `seed ${id}`);
    for (const k of [...META, 'lld_tool_override']) if (inputs[k] !== undefined) ok(stack.wp('post', 'meta', 'update', pid, k, inputs[k] === '<yesterday>' ? yesterday : inputs[k]), `${id} ${k}`);
    seeded.posts[id] = pid;
  };
  seed('FX-WP-POST-01', fx('FX-WP-POST-01'));
  seed('FX-WP-POST-06', { ...fx('FX-WP-POST-06'), lld_review_status: 'approved' });
  seed('FX-WP-POST-08', fx('FX-WP-POST-08'));
  for (const role of ['contributor', 'editor']) {
    const login = `lld-test-${role}`;
    stack.wp('user', 'delete', login, '--yes');
    seeded.users.push(ok(stack.wp('user', 'create', login, `${login}@example.test`, `--role=${role}`, '--porcelain'), `user ${role}`));
  }
  for (const id of Object.values(LANDING)) landingBefore[id] = Object.fromEntries(META.map((k) => [k, stack.wp('post', 'meta', 'get', String(id), k).stdout.trim()]));
});
test.afterAll(async () => {
  if (stack && !stack.external) {
    for (const pid of Object.values(seeded.posts)) stack.wp('post', 'delete', pid, '--force');
    for (const uid of seeded.users) stack.wp('user', 'delete', uid, '--yes');
    for (const [id, before] of Object.entries(landingBefore)) for (const [k, v] of Object.entries(before)) v ? stack.wp('post', 'meta', 'update', id, k, v) : stack.wp('post', 'meta', 'delete', id, k);
  }
  await stack?.close();
});

test('TC-FR1-18-AS-01', async ({ page }) => {
  const owner = fx('FX-WP-POST-01').lld_review_owner, date = fx('FX-WP-POST-01').lld_reviewed_date;
  for (const [slug, id] of Object.entries(LANDING)) {
    ok(stack.wp('post', 'meta', 'update', String(id), 'lld_review_owner', owner), 'owner');
    ok(stack.wp('post', 'meta', 'update', String(id), 'lld_reviewed_date', date), 'date');
    await page.goto(`${stack.url}/health-tools/${slug}/`);
    const byline = page.locator('[data-test=review-byline]').last();
    await expect(byline, `${slug}: byline rendered from meta`).toBeVisible();
    await expect(byline).toHaveText(`Reviewed by ${owner} on 1 October 2026`);
    await expect(byline.locator('time')).toHaveAttribute('datetime', date);
  }
});

test('TC-FR1-18-AS-02', async () => {
  const rows = JSON.parse(ok(stack.wp('lld', 'review-report', '--format=json'), 'review-report'));
  const ids = new Set(rows.map((r) => String(r.id)));
  expect(ids.has(seeded.posts['FX-WP-POST-06']), 'stale review date listed').toBe(true);
  expect(rows.find((r) => String(r.id) === seeded.posts['FX-WP-POST-06']).reasons).toMatch(/overdue/);
  expect(ids.has(seeded.posts['FX-WP-POST-08']), 'pending review listed').toBe(true);
  expect(rows.find((r) => String(r.id) === seeded.posts['FX-WP-POST-08']).reasons).toMatch(/status pending/);
  expect(ids.has(seeded.posts['FX-WP-POST-01']), 'current, approved post excluded').toBe(false);
});

test('TC-FR1-18-AS-03', async () => {
  const draft = ok(stack.wp('post', 'create', '--post_type=post', '--post_author=1', '--post_status=draft', '--post_title=TC-FR1-18 role draft (seed)', '--porcelain'), 'draft');
  seeded.posts.roleDraft = draft;
  const asContrib = stack.wp('post', 'update', draft, '--post_status=publish', '--user=lld-test-contributor');
  expect(asContrib.status, 'contributor cannot publish').not.toBe(0);
  expect(stack.wp('post', 'get', draft, '--field=post_status').stdout.trim(), 'still a draft').toBe('draft');
  const asEditor = stack.wp('post', 'update', draft, '--post_status=publish', '--user=lld-test-editor');
  expect(asEditor.status, `editor publishes: ${asEditor.stderr}`).toBe(0);
  expect(stack.wp('post', 'get', draft, '--field=post_status').stdout.trim()).toBe('publish');
});

test('TC-FR1-18-AS-04', async ({ page }) => {
  // the protein card's related article is pointed at FX-WP-POST-08 in the harness copy of the plugin data (never the repository)
  const cardsPath = join(stack.muDir, 'lld-tools', 'data', 'tool-cards.json');
  const original = readFileSync(cardsPath, 'utf8');
  const cards = JSON.parse(original);
  cards.cards.find((c) => c.tool_slug === 'protein').related_post_id = Number(seeded.posts['FX-WP-POST-08']);
  writeFileSync(cardsPath, JSON.stringify(cards));
  try {
    await page.goto(`${stack.url}/health-tools/protein/`);
    const related = page.locator('[data-test=related-reading]');
    await expect(related).toBeVisible();
    expect(await related.locator(`[data-post-id="${seeded.posts['FX-WP-POST-08']}"]`).count(), 'pending post excluded').toBe(0);
    await expect(related).not.toContainText('FX-WP-POST-08');
    // the same post, once approved, is listed — the exclusion is the review filter, not the post's absence
    ok(stack.wp('post', 'meta', 'update', seeded.posts['FX-WP-POST-08'], 'lld_review_status', 'approved'), 'approve');
    await page.goto(`${stack.url}/health-tools/protein/`);
    await expect(page.locator(`[data-test=related-reading] [data-post-id="${seeded.posts['FX-WP-POST-08']}"]`)).toHaveCount(1);
  } finally {
    writeFileSync(cardsPath, original);
    stack.wp('post', 'meta', 'update', seeded.posts['FX-WP-POST-08'], 'lld_review_status', 'pending');
  }
});

test('TC-FR1-18-AS-05', async () => {
  const out = ok(stack.wp('lld', 'meta', 'list'), 'meta list');
  const dir = mkdtempSync(join(tmpdir(), 'lld-meta-'));
  try {
    const f = join(dir, 'meta.json'); writeFileSync(f, out);
    const v = validateFile('wp-meta.schema.json', f); expect(v.ok, v.errors?.join('; ')).toBe(true);
  } finally { rmSync(dir, { recursive: true, force: true }); }
  const doc = JSON.parse(out);
  expect(doc.meta.map((m) => m.key).sort(), 'registered keys = SCH-13 key set').toEqual([...schema.properties.meta.items.properties.key.enum].sort());
  for (const m of doc.meta) expect(m.applies_to.length, `${m.key}: registered with a sanitiser`).toBeGreaterThan(0);
  expect(doc.meta.find((m) => m.key === 'lld_tool_override').applies_to).toEqual(['post']);
  expect(doc.meta.find((m) => m.key === 'lld_tool_slug').applies_to).toEqual(['page']);
  // the sanitisers run on write: an out-of-enum status and a malformed date are stored empty
  const pid = seeded.posts['FX-WP-POST-01'];
  stack.wp('post', 'meta', 'update', pid, 'lld_review_status', 'bogus');
  expect(stack.wp('post', 'meta', 'get', pid, 'lld_review_status').stdout.trim()).toBe('');
  stack.wp('post', 'meta', 'update', pid, 'lld_next_review_date', 'next year');
  expect(stack.wp('post', 'meta', 'get', pid, 'lld_next_review_date').stdout.trim()).toBe('');
});
