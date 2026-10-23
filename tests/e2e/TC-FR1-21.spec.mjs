// TC-FR1-21 e2e assertions: AS-01 (landing iframe src, crawl), AS-03 (iframe height follows content at four widths),
// AS-05 (a page on another origin cannot frame the tool). Also NFR1-15-AS-02 (= AS-05).
// Local run: the .htaccess emulator serves /tools/* and the embed harness at /__harness/landing.html (the exact embed
// contract the lld-tools shortcode renders). Set LLD_WP_URL to crawl the real landing pages instead for AS-01.
import { test, expect } from '@playwright/test';
import http from 'node:http';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { startHtaccessServer } from '../lib/htaccess-server.mjs';

const here = resolve(fileURLToPath(new URL('.', import.meta.url)));
const LANDING = { protein: '/health-tools/protein/', diabetes: '/health-tools/diabetes-risk-check/' };
const TOOL = { protein: 'protein-requirement-tool.html', diabetes: 'diabetes-risk-tool.html' };
let srv;
test.beforeAll(async () => { srv = await startHtaccessServer({ harnessDir: resolve(here, 'harness') }); });
test.afterAll(async () => { await srv?.close(); });

/** Body text of a frame, or null when the frame cannot be evaluated (blocked by CSP → Chromium error page). */
const frameText = async (frame) => {
  if (!frame) return null;
  try { return await Promise.race([frame.evaluate(() => document.body ? document.body.innerText : ''), new Promise((_, rej) => setTimeout(() => rej(new Error('frame not evaluable')), 5000))]); }
  catch { return null; }
};
const landingUrl = (slug) => process.env.LLD_WP_URL ? process.env.LLD_WP_URL.replace(/\/$/, '') + LANDING[slug] : `${srv.url}/__harness/landing.html?tool=${slug}`;

test('TC-FR1-21-AS-01', async ({ page }) => {
  const origin = process.env.LLD_WP_URL ? (process.env.LLD_TOOLS_URL || process.env.LLD_WP_URL).replace(/\/$/, '') : srv.url;
  for (const slug of ['protein', 'diabetes']) {
    await page.goto(landingUrl(slug));
    const frame = page.locator('iframe.lld-tool-frame, iframe#lld-tool-frame');
    await expect(frame, `${slug}: one tool iframe`).toHaveCount(1);
    const src = await frame.getAttribute('src');
    expect(src, `${slug}: real src on the configured origin`).toMatch(new RegExp('^' + origin.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/tools/'));
    expect(src).not.toBe('about:blank');
    expect(await frame.getAttribute('data-litespeed-src'), `${slug}: not lazy-loaded (DEC-31)`).toBeNull();
    expect(await frame.getAttribute('data-src')).toBeNull();
    // fixed height / min-height removed from the markup (height is set from messages, 600 px fallback)
    const styleAttr = (await frame.getAttribute('style')) || '';
    expect(styleAttr).not.toMatch(/min-height\s*:\s*\d+px/);
  }
});

test('TC-FR1-21-AS-03', async ({ page }) => {
  for (const width of [320, 375, 768, 1440]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(landingUrl('protein'));
    const frameEl = page.locator('iframe.lld-tool-frame, iframe#lld-tool-frame');
    const frame = page.frameLocator('iframe.lld-tool-frame, iframe#lld-tool-frame');
    // complete the form (FX-PR-NRM-01-like healthy adult) and get a result rendered inside the frame
    await frame.locator('#age').fill('35');
    await frame.locator('#sex button[data-val="female"]').click();
    await frame.locator('#hVal').fill('165');
    await frame.locator('#wVal').fill('60');
    await frame.locator('#eth button[data-val="WhiteNA"]').click();
    await frame.locator('#act button[data-val="moderate"]').click();
    await frame.locator('#res button[data-val="0"]').click();
    await frame.locator('#goal button[data-val="maintain"]').click();
    await frame.locator('#diet button[data-val="omnivore"]').click();
    await frame.locator('#meals').selectOption('3');
    await frame.locator('#kidney button[data-val="no"]').click();
    await frame.locator('#liver button[data-val="no"]').click();
    await frame.locator('#form button.cta').click();
    await expect(frame.locator('#results')).toBeVisible();
    // let the resize message propagate
    await page.waitForTimeout(600);
    const m = await frameEl.evaluate((el) => ({ client: el.clientHeight, content: el.contentDocument.documentElement.scrollHeight, overflowY: getComputedStyle(el.contentDocument.documentElement).overflowY }));
    expect(Math.abs(m.client - m.content), `width ${width}: iframe ${m.client} vs content ${m.content}`).toBeLessThanOrEqual(20);
    const scrollbar = await frameEl.evaluate((el) => el.contentWindow.innerWidth - el.contentDocument.documentElement.clientWidth);
    expect(scrollbar, `width ${width}: inner vertical scrollbar`).toBe(0);
  }
});

test('TC-FR1-21-AS-05', async ({ page }) => {
  // a page on http://localhost:9999 (another origin) framing the tool → frame blocked by CSP frame-ancestors
  const toolUrl = `${(process.env.LLD_TOOLS_URL || srv.url).replace(/\/$/, '')}/tools/${TOOL.protein}`;
  const evil = http.createServer((_, res) => { res.setHeader('Content-Type', 'text/html'); res.end(`<!doctype html><iframe id="f" src="${toolUrl}"></iframe>`); });
  await new Promise((ok) => evil.listen(9999, '127.0.0.1', ok));
  try {
    const errors = [];
    page.on('console', (msg) => errors.push(msg.text()));
    await page.goto('http://localhost:9999/');
    await page.waitForTimeout(1500);
    const frame = page.frames().find((f) => f !== page.mainFrame());
    const bodyText = await frameText(frame);
    const blocked = !frame || frame.url() === 'about:blank' || frame.url().startsWith('chrome-error://') || errors.some((e) => /frame-ancestors|Refused to (frame|display)/i.test(e)) || bodyText === null;
    expect(blocked, `frame url=${frame?.url()} console=${errors.join(' | ')} text=${String(bodyText).slice(0, 80)}`).toBe(true);
    expect(bodyText || '').not.toContain('Daily Protein Requirement');
  } finally { evil.closeAllConnections(); await new Promise((ok) => evil.close(ok)); } // keep-alive sockets would otherwise hold close() open
});

test('TC-NFR1-15-AS-02', async ({ page }) => {
  // same assertion as TC-FR1-21-AS-05, under the NFR1-15 id (docs/03 TC-NFR1-15-AS-02 = TC-FR1-21-AS-05)
  const toolUrl = `${(process.env.LLD_TOOLS_URL || srv.url).replace(/\/$/, '')}/tools/${TOOL.diabetes}`;
  const evil = http.createServer((_, res) => { res.setHeader('Content-Type', 'text/html'); res.end(`<!doctype html><iframe id="f" src="${toolUrl}"></iframe>`); });
  await new Promise((ok) => evil.listen(9999, '127.0.0.1', ok));
  try {
    const errors = [];
    page.on('console', (msg) => errors.push(msg.text()));
    await page.goto('http://localhost:9999/');
    await page.waitForTimeout(1500);
    const frame = page.frames().find((f) => f !== page.mainFrame());
    const bodyText = await frameText(frame);
    expect(bodyText || '').not.toContain('Diabetes Risk Assessment');
    expect(bodyText === null || errors.some((e) => /frame-ancestors|Refused to (frame|display)/i.test(e)), `console=${errors.join(' | ')}`).toBe(true);
  } finally { evil.closeAllConnections(); await new Promise((ok) => evil.close(ok)); } // keep-alive sockets would otherwise hold close() open
});
