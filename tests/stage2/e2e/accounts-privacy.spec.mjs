import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { inflateRawSync } from 'node:zlib';
import { expect, test } from '@playwright/test';
import { asOwner, createMember, signIn } from './helpers.mjs';
import { MAP, columnDifferences, mapProblems } from '../data-model.mjs';

const password = process.env.LLD_TEST_MEMBER_PASSWORD || randomBytes(18).toString('base64url');
const MAILPIT = process.env.MAILPIT_URL || 'http://127.0.0.1:8025';
const SENTINEL = 'SENTINEL-ELSE-5520';
const jpeg = Buffer.from(
  '/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAA//EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AfwD/2Q==',
  'base64'
);
// A real 64 by 48 HEIC (HEVC in a HEIF container), so the HEIC path is exercised, not a renamed JPEG.
const heic = Buffer.from(
  'AAAAGGZ0eXBoZWljAAAAAG1pZjFoZWljAAABJ21ldGEAAAAAAAAAIWhkbHIAAAAAAAAAAHBpY3QAAAAAAAAAAAAAAAAAAAAADnBpdG0AAAAAAAEAAAAeaWxvYwAAAABEAAABAAEAAAABAAABRwAAABgAAAAjaWluZgAAAAAAAQAAABVpbmZlAgAAAAABAABodmMxAAAAAKtpcHJwAAAAjmlwY28AAAByaHZjQwEECAAAAJ+oAAAAAB7wAPz9+PgAAA8DoAABABdAAQwB//8ECAAAAwCfqAAAAwAAHroCQKEAAQAnQgEBBAgAAAMAn6gAAAMAAB6gIIMWW6uTK8BaAgAAAwACAAADADIQogABAAZEAcFzwIkAAAAUaXNwZQAAAAAAAABAAAAAMAAAABVpcG1hAAAAAAAAAAEAAQKBAgAAACBtZGF0AAAAFCgBr3j3BAP/1t/9XH9xqRAg8AAe',
  'base64'
);
const png = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64'
);

function sized(name, bytes, extra = 0) {
  const path = join(tmpdir(), name);
  writeFileSync(path, Buffer.concat([bytes, Buffer.alloc(extra)]));
  return path;
}

// An EXIF block holding a GPS latitude (51 deg 30 min N), placed after the JFIF header.
function withGps(body) {
  const tiff = Buffer.alloc(80);
  tiff.write('II', 0, 'latin1');
  tiff.writeUInt16LE(42, 2);
  tiff.writeUInt32LE(8, 4);
  tiff.writeUInt16LE(1, 8); // IFD0: one entry, the GPS IFD pointer
  tiff.writeUInt16LE(0x8825, 10);
  tiff.writeUInt16LE(4, 12);
  tiff.writeUInt32LE(1, 14);
  tiff.writeUInt32LE(26, 18);
  tiff.writeUInt32LE(0, 22);
  tiff.writeUInt16LE(2, 26); // GPS IFD: latitude reference and latitude
  tiff.writeUInt16LE(1, 28);
  tiff.writeUInt16LE(2, 30);
  tiff.writeUInt32LE(2, 32);
  tiff.write('N\0', 36, 'latin1');
  tiff.writeUInt16LE(2, 40);
  tiff.writeUInt16LE(5, 42);
  tiff.writeUInt32LE(3, 44);
  tiff.writeUInt32LE(56, 48);
  tiff.writeUInt32LE(0, 52);
  [[51, 1], [30, 1], [0, 1]].forEach(([n, d], i) => {
    tiff.writeUInt32LE(n, 56 + i * 8);
    tiff.writeUInt32LE(d, 60 + i * 8);
  });
  const payload = Buffer.concat([Buffer.from('Exif\0\0', 'latin1'), tiff]);
  const head = Buffer.alloc(4);
  head.writeUInt16BE(0xffe1, 0);
  head.writeUInt16BE(payload.length + 2, 2);
  const at = body.readUInt16BE(2) === 0xffe0 ? 4 + body.readUInt16BE(4) : 2;
  return Buffer.concat([body.slice(0, at), head, payload, body.slice(at)]);
}

// Whether a JPEG carries an EXIF block, and its pixel size, read from its markers.
function jpegInfo(body) {
  let exif = false;
  let width = 0;
  let height = 0;
  let offset = 2;
  while (offset + 4 <= body.length && body[offset] === 0xff) {
    const marker = body[offset + 1];
    if (marker === 0xd9 || marker === 0xda) break;
    const length = body.readUInt16BE(offset + 2);
    if (marker === 0xe1 && body.slice(offset + 4, offset + 10).toString('latin1') === 'Exif\0\0') exif = true;
    if (marker >= 0xc0 && marker <= 0xc3) {
      height = body.readUInt16BE(offset + 5);
      width = body.readUInt16BE(offset + 7);
    }
    offset += 2 + length;
  }
  return { exif, width, height };
}

// The same HEIC with the size its header claims changed, for the pixel-limit checks.
function heicClaiming(width, height) {
  const body = Buffer.from(heic);
  const at = body.indexOf('ispe');
  body.writeUInt32BE(width, at + 8);
  body.writeUInt32BE(height, at + 12);
  return body;
}

// An EXIF block holding only an orientation (1 to 8), placed after the JFIF header.
function withOrientation(body, orientation) {
  const tiff = Buffer.alloc(26);
  tiff.write('II', 0, 'latin1');
  tiff.writeUInt16LE(42, 2);
  tiff.writeUInt32LE(8, 4);
  tiff.writeUInt16LE(1, 8);
  tiff.writeUInt16LE(0x0112, 10);
  tiff.writeUInt16LE(3, 12);
  tiff.writeUInt32LE(1, 14);
  tiff.writeUInt16LE(orientation, 18);
  tiff.writeUInt32LE(0, 22);
  const payload = Buffer.concat([Buffer.from('Exif\0\0', 'latin1'), tiff]);
  const head = Buffer.alloc(4);
  head.writeUInt16BE(0xffe1, 0);
  head.writeUInt16BE(payload.length + 2, 2);
  const at = body.readUInt16BE(2) === 0xffe0 ? 4 + body.readUInt16BE(4) : 2;
  return Buffer.concat([body.slice(0, at), head, payload, body.slice(at)]);
}

// The upright picture is 64 by 32, blue, with a red top-left quarter. The file holds it turned
// the way a camera stores it for each EXIF orientation, so only a correct turn shows it upright.
function orientedJpeg(orientation) {
  const path = join(tmpdir(), `lld-orient-${orientation}.jpg`);
  const script = join(tmpdir(), `lld-orient-${orientation}.php`);
  writeFileSync(
    script,
    `<?php
$r = imagecreatetruecolor(64, 32);
imagefilledrectangle($r, 0, 0, 63, 31, imagecolorallocate($r, 0, 0, 255));
imagefilledrectangle($r, 0, 0, 31, 15, imagecolorallocate($r, 255, 0, 0));
switch (${orientation}) {
  case 2: imageflip($r, IMG_FLIP_HORIZONTAL); break;
  case 3: $r = imagerotate($r, 180, 0); break;
  case 4: imageflip($r, IMG_FLIP_VERTICAL); break;
  case 5: $r = imagerotate($r, -90, 0); imageflip($r, IMG_FLIP_HORIZONTAL); break;
  case 6: $r = imagerotate($r, 90, 0); break;
  case 7: $r = imagerotate($r, 90, 0); imageflip($r, IMG_FLIP_HORIZONTAL); break;
  case 8: $r = imagerotate($r, -90, 0); break;
}
imagejpeg($r, ${JSON.stringify(path)}, 95);
`
  );
  execFileSync('php', [script], { stdio: 'pipe' });
  writeFileSync(path, withOrientation(readFileSync(path), orientation));
  return path;
}

// Size and the red level at the middle of each quarter of a stored JPEG.
function quarters(body) {
  const file = join(tmpdir(), 'lld-quarters.jpg');
  const script = join(tmpdir(), 'lld-quarters.php');
  writeFileSync(file, body);
  writeFileSync(
    script,
    `<?php
$im = imagecreatefromjpeg(${JSON.stringify(file)});
$w = imagesx($im); $h = imagesy($im);
$red = function ($x, $y) use ($im) { return (imagecolorat($im, $x, $y) >> 16) & 255; };
echo json_encode(array('width' => $w, 'height' => $h, 'tl' => $red(intdiv($w, 4), intdiv($h, 4)), 'tr' => $red(intdiv(3 * $w, 4), intdiv($h, 4)), 'bl' => $red(intdiv($w, 4), intdiv(3 * $h, 4)), 'br' => $red(intdiv(3 * $w, 4), intdiv(3 * $h, 4))));
`
  );
  return JSON.parse(execFileSync('php', [script], { encoding: 'utf8' }));
}

function makeJpeg(name, targetBytes, { width = 16, height = 16, gps = false } = {}) {
  const path = join(tmpdir(), name);
  const script = join(tmpdir(), `lld-make-${name}.php`);
  writeFileSync(
    script,
    `<?php
$im = imagecreatetruecolor(${width}, ${height});
imagefilledrectangle($im, 0, 0, ${width - 1}, ${height - 1}, imagecolorallocate($im, 20, 80, 40));
imagejpeg($im, ${JSON.stringify(path)}, 85);
`
  );
  execFileSync('php', [script], { stdio: 'pipe' });
  let body = readFileSync(path);
  if (gps) body = withGps(body);
  if (body.length < targetBytes) body = Buffer.concat([body, Buffer.alloc(Math.floor(targetBytes) - body.length)]);
  writeFileSync(path, body);
  return path;
}

async function reset(request, email) {
  expect((await request.post('/wp-json/lld-members/v1/test/reset', { data: { email } })).ok()).toBe(true);
}

async function mailMatching(text) {
  let last = '';
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const list = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
    for (const message of list.messages || []) {
      const id = message.ID || message.Id || message.id;
      const full = await fetch(`${MAILPIT}/api/v1/message/${id}`).then((res) => res.json());
      const to = JSON.stringify(full.To || full.to || '');
      last = `${full.Subject || ''}\n${full.Text || ''}`;
      if (last.includes(text) || to.toLowerCase().includes(String(text).toLowerCase())) return last;
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`Mail not found: ${text}. Last: ${last.slice(0, 160)}`);
}

function linkFrom(body) {
  const found = body.match(/https?:\/\/\S+/);
  if (!found) throw new Error(body.slice(0, 160));
  return found[0].replace(/[).,>]+$/, '');
}

const MINUTE = 60 * 1000;
const HOUR = 60 * MINUTE;

// UTC "Y-m-d H:i:s" a given number of milliseconds from now, for the test clock and restore floors.
function fromNow(ms) {
  return new Date(Date.now() + ms).toISOString().slice(0, 19).replace('T', ' ');
}

async function scanOf(request, data) {
  const res = await request.post('/wp-json/lld-members/v1/test/scan', { data });
  expect(res.ok(), await res.text()).toBe(true);
  return res.json();
}

// Restore procedure (Data Model T6): close, re-apply the emailed erasure list, verify, reopen.
async function erasure(request, data) {
  const res = await request.post('/wp-json/lld-members/v1/test/erasure', { data });
  expect(res.ok(), await res.text()).toBe(true);
  return res.json();
}

// The newest message to one address whose subject or text includes the given words.
async function mailTo(address, text) {
  let last = '';
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const list = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
    const messages = (list.messages || []).filter((message) => JSON.stringify(message.To || []).toLowerCase().includes(address.toLowerCase()));
    messages.sort((a, b) => new Date(b.Created || 0) - new Date(a.Created || 0));
    for (const message of messages) {
      const id = message.ID || message.Id || message.id;
      const full = await fetch(`${MAILPIT}/api/v1/message/${id}`).then((res) => res.json());
      last = `${full.Subject || ''}\n${full.Text || ''}`;
      if (last.includes(text)) return last;
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`No mail to ${address} with ${text}. Last: ${last.slice(0, 160)}`);
}

// The erasure-list email holding one member's line, with its recipients.
async function erasureMail(memberId, what) {
  const line = `\n${memberId} ${what} `;
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const list = await fetch(`${MAILPIT}/api/v1/messages`).then((res) => res.json());
    for (const message of list.messages || []) {
      if (!String(message.Subject || '').includes('Erasure list')) continue;
      const id = message.ID || message.Id || message.id;
      const full = await fetch(`${MAILPIT}/api/v1/message/${id}`).then((res) => res.json());
      const body = `${full.Subject || ''}\n${full.Text || ''}`;
      if (body.includes(line)) return { body, to: JSON.stringify(full.To || []) };
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`No erasure list with ${line.trim()}`);
}

function unzip(buffer) {
  const files = {};
  let offset = 0;
  while (offset + 30 < buffer.length && buffer.readUInt32LE(offset) === 0x04034b50) {
    const method = buffer.readUInt16LE(offset + 8);
    const compressed = buffer.readUInt32LE(offset + 18);
    const nameLength = buffer.readUInt16LE(offset + 26);
    const extraLength = buffer.readUInt16LE(offset + 28);
    const name = buffer.slice(offset + 30, offset + 30 + nameLength).toString();
    const start = offset + 30 + nameLength + extraLength;
    const raw = buffer.slice(start, start + compressed);
    files[name] = method === 0 ? raw : inflateRawSync(raw);
    offset = start + compressed;
  }
  return files;
}

test('TC-FR2-02-AS-01', async ({ page }) => {
  const email = 'tm-03e@test.lifelinedaily.invalid';
  await reset(page.request, email);
  const created = await createMember(page.request, { email, password, first_name: 'Sam', role: 'member', health_consent: true });
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_first').fill('Samantha');
  await page.locator('#lld_last').fill('Lee');
  await page.locator('#lld_display').fill('Sammy');
  await page.locator('#lld_age').fill('41');
  await page.locator('#lld_timezone').fill('Europe/Paris');
  await page.getByRole('checkbox', { name: 'Show imperial units' }).check();
  await page.locator('#lld_goal').fill('sleep');
  await page.locator('#lld_track').fill('sleep');
  await page.locator('#lld_height').fill('170');
  await page.getByRole('checkbox', { name: 'Daily reminder' }).check();
  await page.getByRole('checkbox', { name: 'Weekly review' }).check();
  await page.locator('#lld_reminder_time').fill('06:45');
  await page.locator('#lld_password').fill('newer-pass');
  const next = 'sam-new@test.lifelinedaily.invalid';
  await reset(page.request, next);
  await page.locator('#lld_email').fill(next);
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  // A fresh load of the page shows every new value.
  await page.goto('/members/settings/');
  await expect(page.locator('#lld_first')).toHaveValue('Samantha');
  await expect(page.locator('#lld_last')).toHaveValue('Lee');
  await expect(page.locator('#lld_display')).toHaveValue('Sammy');
  await expect(page.locator('#lld_age')).toHaveValue('41');
  await expect(page.getByText('Age: 41')).toBeVisible();
  await expect(page.locator('#lld_timezone')).toHaveValue('Europe/Paris');
  await expect(page.getByRole('checkbox', { name: 'Show imperial units' })).toBeChecked();
  await expect(page.locator('#lld_height')).toHaveValue('170');
  await expect(page.locator('#lld_goal')).toHaveValue('sleep');
  await expect(page.locator('#lld_track')).toHaveValue('sleep');
  await expect(page.getByRole('checkbox', { name: 'Daily reminder' })).toBeChecked();
  await expect(page.getByRole('checkbox', { name: 'Weekly review' })).toBeChecked();
  await expect(page.locator('#lld_reminder_time')).toHaveValue('06:45');
  await expect(page.getByText(`Check ${next} to confirm`)).toBeVisible();
  await page.context().clearCookies();
  await signIn(page, email, 'newer-pass');
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  const body = await mailMatching(next);
  await page.goto(linkFrom(body));
  await expect(page.getByText(`Your email is now ${next}`)).toBeVisible();
  await page.context().clearCookies();
  await signIn(page, next, 'newer-pass');
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  await page.goto('/members/settings/');
  await expect(page.locator('#lld_email')).toHaveValue(next);
  expect(created.member_id).toBeGreaterThan(0);
});

test('TC-FR2-02-AS-02', async ({ page }) => {
  test.setTimeout(300000);
  const email = 'tm-03f@test.lifelinedaily.invalid';
  const other = 'tm-04f@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await reset(page.request, other);
  const created = await createMember(page.request, { email, password, first_name: 'Sam', role: 'member' });
  await createMember(page.request, { email: other, password, first_name: 'Ola', role: 'member' });
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  // A 9.9 MB photo, 3200 by 2400 pixels, carrying a GPS position.
  const big = makeJpeg('photo-ok.jpg', 9.9 * 1024 * 1024, { width: 3200, height: 2400, gps: true });
  expect(jpegInfo(readFileSync(big))).toEqual({ exif: true, width: 3200, height: 2400 });
  await page.locator('#lld_photo').setInputFiles(big);
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  await expect(page.locator('img')).toBeVisible();
  // Stored without any EXIF block, so without the location, and resized to at most 1,600 pixels.
  const kept = await page.request.get(`/members/photo/${created.member_id}/`);
  expect(kept.ok()).toBe(true);
  const stored = jpegInfo(await kept.body());
  expect(stored.exif).toBe(false);
  expect(Math.max(stored.width, stored.height)).toBe(1600);
  expect(stored.width * 3).toBe(stored.height * 4);
  await page.locator('#lld_photo').setInputFiles(sized('photo.png', png, 0));
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  await page.locator('#lld_photo').setInputFiles(makeJpeg('photo-big.jpg', 10.1 * 1024 * 1024));
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('10 MB or smaller')).toBeVisible();
  await page.locator('#lld_photo').setInputFiles({ name: 'photo.gif', mimeType: 'image/gif', buffer: Buffer.from('GIF89a') });
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Use a JPG, PNG or HEIC')).toBeVisible();
  const origin = new URL(page.url()).origin;
  const anon = await page.context().browser().newContext();
  const signedOut = await anon.request.get(`${origin}/members/photo/${created.member_id}/`, { maxRedirects: 0 });
  expect(signedOut.status()).toBeGreaterThanOrEqual(300);
  await anon.close();
  await signIn(page, other, password);
  const refused = await page.request.get(`/members/photo/${created.member_id}/`, { maxRedirects: 0 });
  expect(refused.status()).toBeGreaterThanOrEqual(300);
  await signIn(page, email, password);
  const own = await page.request.get(`/members/photo/${created.member_id}/`);
  expect(own.ok()).toBe(true);
  expect(jpegInfo(await own.body()).exif).toBe(false);
  await page.goto('/members/settings/');
  await page.locator('#lld_photo').setInputFiles(sized('photo.heic', heic, 0));
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  const fromHeic = jpegInfo(await (await page.request.get(`/members/photo/${created.member_id}/`)).body());
  expect(fromHeic).toEqual({ exif: false, width: 64, height: 48 });
  const photoNow = async () => jpegInfo(await (await page.request.get(`/members/photo/${created.member_id}/`)).body());

  // The file's own bytes decide: something that is no photo is refused whatever it is called.
  for (const name of ['fake.heic', 'fake.jpg', 'fake.png']) {
    await page.locator('#lld_photo').setInputFiles({ name, mimeType: 'application/octet-stream', buffer: Buffer.from('This is a text file, not a photo.') });
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Use a JPG, PNG or HEIC'), name).toBeVisible();
  }
  // A HEIC whose size reads as zero, and one claiming 9000 by 9000 pixels, are refused before decoding.
  for (const [width, height] of [[0, 0], [9000, 9000]]) {
    await page.locator('#lld_photo').setInputFiles({ name: 'claims.heic', mimeType: 'image/heic', buffer: heicClaiming(width, height) });
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('.lld-fail'), `${width}x${height}`).toContainText(/could not be read|too large to open/);
    await expect(page.getByText('Saved')).toHaveCount(0);
  }
  expect(await photoNow()).toEqual({ exif: false, width: 64, height: 48 });
  // A request too big for the server shows an error, never "Saved".
  await page.locator('#lld_photo').setInputFiles(sized('photo-huge.jpg', jpeg, 33 * 1024 * 1024));
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('10 MB or smaller')).toBeVisible();
  await expect(page.getByText('Saved')).toHaveCount(0);
  expect(await photoNow()).toEqual({ exif: false, width: 64, height: 48 });

  // Every EXIF orientation, 1 to 8, is turned upright: 64 by 32 with the red quarter top left.
  for (let orientation = 1; orientation <= 8; orientation += 1) {
    await page.goto('/members/settings/');
    await page.locator('#lld_photo').setInputFiles(orientedJpeg(orientation));
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Saved')).toBeVisible();
    const seen = quarters(await (await page.request.get(`/members/photo/${created.member_id}/`)).body());
    expect([seen.width, seen.height], `orientation ${orientation}`).toEqual([64, 32]);
    expect(seen.tl, `orientation ${orientation} top left`).toBeGreaterThan(150);
    for (const corner of ['tr', 'bl', 'br']) expect(seen[corner], `orientation ${orientation} ${corner}`).toBeLessThan(100);
  }
});

test('TC-FR2-02-AS-03', async ({ page }) => {
  const email = 'tm-03g@test.lifelinedaily.invalid';
  const next = 'tm-03g-new@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await reset(page.request, next);
  await createMember(page.request, { email, password, first_name: 'Sam', role: 'member' });
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_email').fill(next);
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText(`Check ${next} to confirm`)).toBeVisible();
  await page.context().clearCookies();
  await signIn(page, email, password);
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  await page.goto(linkFrom(await mailMatching(next)));
  await page.context().clearCookies();
  await signIn(page, next, password);
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  // The old address no longer signs in. (signIn() waits to leave the page, so try it directly.)
  await page.context().clearCookies();
  await page.goto('/members/sign-in/');
  await page.locator('#lld_email').fill(email);
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText("Those details don't match our records.")).toBeVisible();
});

test('TC-FR2-02-AS-04', async ({ page }) => {
  const free = 'tm-03h@test.lifelinedaily.invalid';
  const paid = 'tm-04h@test.lifelinedaily.invalid';
  await reset(page.request, free);
  await reset(page.request, paid);
  const today = new Date().toISOString().slice(0, 10);
  const freeMember = await createMember(page.request, { email: free, password, first_name: 'Sam', role: 'member', access_state: 'free' });
  const paidMember = await createMember(page.request, { email: paid, password, first_name: 'Tee', role: 'member', access_state: 'Premium', period_ends_on: '2027-02-01' });
  expect(freeMember.joined_on).toBe(today);
  await signIn(page, free, password);
  await page.goto('/members/settings/');
  await expect(page.getByText(`Joined on ${freeMember.joined_on}`)).toBeVisible();
  await expect(page.getByText('Next renewal: not applicable')).toBeVisible();
  await signIn(page, paid, password);
  await page.goto('/members/settings/');
  await expect(page.getByText(`Joined on ${paidMember.joined_on}`)).toBeVisible();
  await expect(page.getByText('Next renewal: 2027-02-01')).toBeVisible();
});

test('TC-FR2-02-AS-05', async ({ page }) => {
  const email = 'tm-03i@test.lifelinedaily.invalid';
  await reset(page.request, email);
  const created = await createMember(page.request, { email, password, first_name: 'Sam', role: 'member' });
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_age').fill('17');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Age cannot be under 18.')).toBeVisible();
  const later = new Date(`${created.joined_on}T00:00:00Z`);
  later.setUTCFullYear(later.getUTCFullYear() + 1);
  later.setUTCDate(later.getUTCDate() + 1);
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc: later.toISOString().slice(0, 19).replace('T', ' ') } });
  await page.reload();
  await expect(page.getByText('Age: 41')).toBeVisible();
});

test('TC-FR2-03-AS-01', async ({ page }) => {
  const email = 'tm-01e@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await page.goto('/members/join/');
  await page.locator('#lld_first').fill('Tina');
  await page.locator('#lld_last').fill('One');
  await page.locator('#lld_email').fill(email);
  await page.locator('#lld_password').fill('adult-pass');
  await page.locator('#lld_age').fill('30');
  await page.getByRole('button', { name: 'Create account' }).click();
  await page.goto(linkFrom(await mailMatching(email)));
  await expect(page.getByRole('heading', { name: 'Email confirmed' })).toBeVisible();
  await signIn(page, email, 'adult-pass');
  await page.goto('/members/goals/');
  await expect(page.getByRole('heading', { name: 'Agreements' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Choose up to 3 goals' })).toHaveCount(0);
});

test('TC-FR2-03-AS-02', async ({ page }) => {
  const email = 'tm-02b@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await createMember(page.request, { email, password, first_name: 'Sam', role: 'member' });
  await signIn(page, email, password);
  await page.goto('/members/agreements/');
  await expect(page.locator('input[name="lld_health"]')).not.toBeChecked();
  await expect(page.locator('input[name="lld_marketing"]')).not.toBeChecked();
});

test('TC-FR2-03-AS-03', async ({ page }) => {
  const email = 'tm-02@test.lifelinedaily.invalid';
  const allowed = 'tm-02-ok@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await reset(page.request, allowed);
  const created = await createMember(page.request, { email, password, first_name: 'No', role: 'member', consents: ['terms'] });
  const control = await createMember(page.request, { email: allowed, password, first_name: 'Yes', role: 'member', consents: ['terms', 'health'] });
  const kinds = ['goal', 'profile', 'check_in', 'diary', 'result'];
  const tables = { goal: 'goal_choice', profile: 'health_profile', check_in: 'check_in', diary: 'diary_day', result: 'calculator_result' };
  const save = async (memberId, kind) => {
    await page.goto('/members/goals/');
    const restNonce = await page.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
    return page.request.post(`/wp-json/lld-members/v1/members/${memberId}/health`, {
      data: { kind, goal_key: 'sleep', sort_order: 1, height_cm: 170 },
      headers: { 'X-WP-Nonce': restNonce },
    });
  };
  // Positive control: with health consent, the same five saves succeed and are stored.
  await signIn(page, allowed, password);
  for (const kind of kinds) expect((await save(control.member_id, kind)).status(), kind).toBe(200);
  const stored = await scanOf(page.request, { member_id: control.member_id });
  for (const kind of kinds) expect(stored.records[tables[kind]], kind).toBeGreaterThan(0);

  await signIn(page, email, password);
  await page.goto('/members/goals/');
  const nonce = await page.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
  for (const kind of kinds) {
    const res = await save(created.member_id, kind);
    expect(res.status(), kind).toBe(403);
  }
  // The direct save route is a test helper: without the test secret it does not answer at all.
  const open = await page.request.post(`/wp-json/lld-members/v1/members/${created.member_id}/health`, {
    data: { kind: 'check_in' },
    headers: { 'X-WP-Nonce': nonce, 'X-LLD-Test-Secret': 'wrong' },
  });
  expect([401, 403]).toContain(open.status());
  // Through the page: the health fields are not offered, and posting them anyway stores nothing.
  await page.goto('/members/settings/');
  for (const field of ['#lld_goal', '#lld_track', '#lld_height', '#lld_diary']) await expect(page.locator(field), field).toHaveCount(0);
  const settingsNonce = await page.locator('input[name="lld_settings_nonce"]').getAttribute('value');
  await page.request.post('/members/settings/', {
    multipart: {
      lld_settings_nonce: settingsNonce,
      lld_first: 'Nope',
      lld_age: '40',
      lld_goal: 'sleep',
      lld_track: 'sleep',
      lld_height: '170',
      lld_diary: { name: 'refused-diary.jpg', mimeType: 'image/jpeg', buffer: readFileSync(makeJpeg('refused-diary.jpg', 2048)) },
    },
  });
  await page.goto('/members/settings/');
  await expect(page.locator('#lld_first')).toHaveValue('Nope');
  const scan = await page.request.post('/wp-json/lld-members/v1/test/scan', { data: { member_id: created.member_id, sentinel: SENTINEL } });
  const refused = await scan.json();
  for (const name of [...Object.values(tables), 'enrolment', 'diary_meal']) expect(refused.records[name], name).toBe(0);
  const problems = refused.problems.join(' ');
  expect(problems).not.toContain('goal_choice');
  expect(problems).not.toContain('health_profile');
  expect(problems).not.toContain('check_in');
  expect(problems).not.toContain('diary');
  expect(problems).not.toContain('calculator_result');
});

test('TC-FR2-03-AS-04', async ({ page }) => {
  const email = 'tm-04@test.lifelinedaily.invalid';
  await reset(page.request, email);
  const created = await createMember(page.request, {
    email,
    password,
    first_name: 'Tee',
    role: 'member',
    health_consent: true,
    sentinel: SENTINEL,
    access_state: 'Premium',
    what_changed: SENTINEL,
    quote: SENTINEL,
  });
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_diary').setInputFiles(makeJpeg('diary.jpg', 2048));
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  // Before: the diary photo, answers, testimonial and consent are all there, so the check can fail.
  const before = await scanOf(page.request, { member_id: created.member_id, scope: 'health' });
  const found = before.problems.join(' ');
  for (const part of ['diary_meal', 'something_else', 'A diary photo remains.', 'A what-changed answer remains.', 'A testimonial remains.', 'Health consent is still on.']) {
    expect(found, part).toContain(part);
  }
  expect(before.diary_meals.length).toBe(1);
  expect(before.records.subscription).toBe(1);
  const photo = `/members/diary-photo/${created.member_id}/?meal=${before.diary_meals[0]}`;
  expect((await page.request.get(photo)).status()).toBe(200);

  await page.goto('/members/withdraw/');
  await expect(page.getByText('This deletes your goals, track progress, health profile, check-ins, food diary and diary photos, saved results and "what changed" survey answers.')).toBeVisible();
  await expect(page.getByText('Your subscription continues. To stop paying, cancel it separately.')).toBeVisible();
  await page.getByRole('button', { name: 'Confirm' }).click();
  await expect(page.getByRole('heading', { name: 'Health information deleted' })).toBeVisible();
  const body = await scanOf(page.request, { member_id: created.member_id, sentinel: SENTINEL, scope: 'health' });
  expect(body.problems, JSON.stringify(body)).toEqual([]);
  expect((await page.request.get(photo)).status()).toBe(404);
  expect(body.records.subscription).toBe(1);
  await page.goto('/members/settings/');
  await expect(page.getByText('Membership: Premium')).toBeVisible();
});

test('TC-FR2-03-AS-05', async ({ page }) => {
  const email = 'tm-03j@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await page.goto('/members/join/');
  await page.locator('#lld_first').fill('Sam');
  await page.locator('#lld_last').fill('Lee');
  await page.locator('#lld_email').fill(email);
  await page.locator('#lld_password').fill('adult-pass');
  await page.locator('#lld_age').fill('40');
  await page.getByRole('button', { name: 'Create account' }).click();
  await page.goto(linkFrom(await mailMatching(email)));
  await expect(page.getByRole('heading', { name: 'Email confirmed' })).toBeVisible();
  await signIn(page, email, 'adult-pass');
  await page.goto('/members/agreements/');
  await page.getByRole('checkbox', { name: /I accept the/ }).check();
  await page.getByRole('checkbox', { name: 'Allow Lifeline Daily to store my health information' }).check();
  await page.getByRole('checkbox', { name: 'Send me marketing emails' }).check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.goto('/members/settings/');
  const history = page.locator('.lld-consent-history li');
  await expect(page.locator('.lld-consent-history')).toContainText('terms given terms-2026-11');
  await expect(page.locator('.lld-consent-history')).toContainText('privacy given privacy-2026-11');
  await expect(page.locator('.lld-consent-history')).toContainText('health given');
  await expect(page.locator('.lld-consent-history')).toContainText('marketing given');
  const today = new Date().toISOString().slice(0, 10);
  const health = page.getByRole('checkbox', { name: 'Store my health information' });
  const marketing = page.getByRole('checkbox', { name: 'Marketing emails' });
  await expect(health).toBeChecked();
  await expect(marketing).toBeChecked();
  await expect(page.locator('#lld_consent_marketing_date')).toHaveText(`On since ${today}`);

  // Each later change, made with its switch on the profile page, adds one dated line.
  const change = async (expected) => {
    const lines = await history.count();
    await page.getByRole('button', { name: 'Save' }).click();
    await page.goto('/members/settings/');
    await expect(history).toHaveCount(lines + 1);
    await expect(history.last()).toContainText(expected);
    await expect(history.last()).toContainText(today);
  };
  await marketing.uncheck();
  await change('marketing withdrawn');
  await expect(marketing).not.toBeChecked();
  await expect(page.locator('#lld_consent_marketing_date')).toHaveText(`Off since ${today}`);
  await marketing.check();
  await change('marketing given');
  await expect(marketing).toBeChecked();

  // Switching health information off shows the warning first, and only Confirm withdraws it.
  const before = await history.count();
  await health.uncheck();
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByRole('heading', { name: 'Withdraw health consent' })).toBeVisible();
  await expect(page.getByText('This deletes your goals, track progress, health profile, check-ins, food diary and diary photos')).toBeVisible();
  await page.getByRole('button', { name: 'Confirm' }).click();
  await expect(page.getByRole('heading', { name: 'Health information deleted' })).toBeVisible();
  await page.goto('/members/settings/');
  await expect(history).toHaveCount(before + 1);
  await expect(history.last()).toContainText('health withdrawn');
  await expect(health).not.toBeChecked();
  await health.check();
  await change('health given');
  await expect(health).toBeChecked();
  await expect(page.locator('#lld_consent_health_date')).toHaveText(`On since ${today}`);
});

test('TC-FR2-03-AS-06', async ({ browser, page }) => {
  // The staging backup restore itself is run before launch (NFR2-06 AS-01). Here the restore is
  // simulated: the pre-withdrawal "given" consent line and health data are put back, then the
  // T6 procedure re-applies the erasure list taken from the owner's email in Mailpit.
  const email = 'tm-04r@test.lifelinedaily.invalid';
  const mark = `SENTINEL-RESTORE-${randomBytes(3).toString('hex')}`;
  const health = { email, password, first_name: 'Rhea', role: 'member', health_consent: true, sentinel: mark, what_changed: mark, quote: mark };
  await reset(page.request, email);
  const since = fromNow(-5000);
  const created = await createMember(page.request, health);
  await signIn(page, email, password);
  await page.goto('/members/withdraw/');
  await page.getByRole('button', { name: 'Confirm' }).click();
  await expect(page.getByRole('heading', { name: 'Health information deleted' })).toBeVisible();
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  const { body: list } = await erasureMail(created.member_id, 'health');

  await createMember(page.request, health);
  const restored = (await scanOf(page.request, { member_id: created.member_id, scope: 'health' })).problems.join(' ');
  expect(restored).toContain('Health consent is still on.');
  expect(restored).toContain('something_else');

  const member = await browser.newContext();
  const memberPage = await member.newPage();
  await signIn(memberPage, email, password);
  await asOwner(page);
  try {
    await erasure(page.request, { action: 'close' });
    await memberPage.goto('/members/home/');
    await expect(memberPage.getByRole('heading', { name: 'Lifeline Daily is closed' })).toBeVisible();
    const done = await erasure(page.request, { action: 'reapply', list, since });
    expect(done.problems, JSON.stringify(done)).toEqual([]);
    expect(done.reapplied).toBeGreaterThanOrEqual(1);
    expect(done.closed).toBe(false);
  } finally {
    await erasure(page.request, { action: 'open' });
  }
  const after = await scanOf(page.request, { member_id: created.member_id, scope: 'health', sentinel: mark });
  expect(after.problems, JSON.stringify(after)).toEqual([]);
  await memberPage.goto('/members/settings/');
  await expect(memberPage.locator('.lld-consent-history li').last()).toContainText('health withdrawn');
  await member.close();
});

// Data Model section 11: what the download holds (A1 to A3, C1 to C16, P2, P3, P5, E2, F3, F4).
const DOWNLOAD_TABLES = [
  'member', 'email_pref', 'consent',
  'goal_choice', 'something_else', 'health_profile', 'calculator_result', 'enrolment', 'enrolment_period', 'challenge',
  'workout', 'workout_change', 'result', 'check_in', 'reflection', 'weekly_plan', 'weekly_review', 'diary_meal',
  'diary_day', 'meal_plan', 'suggestion', 'badge',
  'subscription', 'order', 'referral', 'reward', 'contact_request', 'support', 'survey',
];
// Never in the download: A4 security records, unsubscribe codes, the test clock, operations records.
const NEVER_DOWNLOADED = ['security_code', 'sign_in_lock', 'session', 'app_key', 'unsubscribe_code', 'test_clock', 'erasure', 'admin_log', 'fb_removal', 'contact', 'contact_consent', 'error_log', 'stripe_notice'];

test('TC-FR2-04-AS-01', async ({ page }) => {
  const email = 'tm-04a@test.lifelinedaily.invalid';
  const referrer = 'tm-04a-ref@test.lifelinedaily.invalid';
  await reset(page.request, email);
  await reset(page.request, referrer);
  const created = await createMember(page.request, {
    email,
    password,
    first_name: 'Tee',
    role: 'member',
    health_consent: true,
    consents: ['marketing'],
    sentinel: SENTINEL,
    what_changed: 'Walking more',
    quote: 'Kinder to myself',
    referral: true,
    access_state: 'Premium',
    stripe_subscription: 'sub_test_c2a',
  });
  await createMember(page.request, { email: referrer, password, first_name: 'Ria', role: 'member', referral: true, friend_member_id: created.member_id });
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_photo').setInputFiles(makeJpeg('export.jpg', 2048));
  await page.locator('#lld_diary').setInputFiles(makeJpeg('export-diary.jpg', 2048));
  await page.getByRole('checkbox', { name: 'Weekly review' }).check();
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  // Records the download must leave out exist too: a reset code, a session and a test clock.
  await page.goto('/members/reset/');
  await page.locator('#lld_email').fill(email);
  await page.getByRole('button', { name: 'Send link' }).click();
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email, utc: fromNow(0) } });
  const db = await scanOf(page.request, { member_id: created.member_id, email });
  for (const name of ['security_code', 'session', 'test_clock']) expect(db.records[name], name).toBeGreaterThan(0);

  await page.goto('/members/data/');
  const downloadPromise = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Download all my data' }).click();
  const download = await downloadPromise;
  const file = join(tmpdir(), 'lld-data.zip');
  await download.saveAs(file);
  const packed = unzip(readFileSync(file));
  const data = JSON.parse(packed['data.json'].toString());
  expect(data.version).toBe(1);
  expect(data.member_number).toBe(created.member_id);
  // Table by table against the database, using the erasure checker's list.
  const meta = ['format', 'version', 'made_at_utc', 'member_number', 'photos'];
  expect(data.format).toBe('lifeline-daily-member-data');
  expect(Object.keys(data).filter((key) => !meta.includes(key)).sort()).toEqual(DOWNLOAD_TABLES.slice().sort());
  for (const name of DOWNLOAD_TABLES) {
    expect(data[name].length, name).toBe(db.records[name]);
  }
  for (const name of NEVER_DOWNLOADED) expect(data[name], name).toBeUndefined();
  for (const [name, least] of Object.entries({ member: 1, email_pref: 1, consent: 2, something_else: 1, survey: 1, referral: 2, reward: 2, subscription: 1, diary_meal: 1 })) {
    expect(data[name].length, name).toBeGreaterThanOrEqual(least);
  }
  expect(data.referral.some((row) => Number(row.friend_member_id) === created.member_id)).toBe(true);
  // Encrypted text is readable, and no ciphertext, password, code or key is included.
  expect(data.something_else[0].text).toBe(SENTINEL);
  expect(data.survey[0].quote_text).toBe('Kinder to myself');
  const everything = Object.values(packed).map((part) => part.toString('latin1')).join('\n');
  for (const secret of ['text_ciphertext', 'description_ciphertext', '$wp$', '$P$', '$2y$', 'code_hash', 'token_hash', 'key_hash', 'user_pass', 'data.key']) {
    expect(everything, secret).not.toContain(secret);
  }
  // The profile photo and the diary photo are included.
  expect(Object.keys(packed)).toContain('photos/profile.jpg');
  expect(Object.keys(packed).some((name) => name.startsWith('photos/diary-'))).toBe(true);
  expect(data.photos.length).toBe(2);
  // A readable summary of the same records.
  const summary = packed['summary.html'].toString();
  for (const part of ['Your data', 'Consent history', 'Food diary meals', 'Referral rewards', 'Tee', SENTINEL, 'photos/profile.jpg']) {
    expect(summary, part).toContain(part);
  }
  const stores = await page.request.get('/wp-json/lld-members/v1/test/stores');
  expect((await stores.json()).download_files).toBe(0);
  await reset(page.request, referrer);

  // Encrypted text is bound to its own record (S3): copied into another member's record, it is
  // not shown by the journey read or the download.
  const copyEmail = 'tm-04a-copy@test.lifelinedaily.invalid';
  await reset(page.request, copyEmail);
  const copy = await createMember(page.request, { email: copyEmail, password, first_name: 'Cy', role: 'member', health_consent: true, sentinel: 'Own words of Cy' });
  await signIn(page, copyEmail, password);
  const journey = async () => {
    await page.goto('/members/settings/');
    const restNonce = await page.locator('meta[name="lld-rest-nonce"]').getAttribute('content');
    return page.request.get(`/wp-json/lld-members/v1/members/${copy.member_id}/journey`, { headers: { 'X-WP-Nonce': restNonce } });
  };
  const before = await journey();
  expect(before.status()).toBe(200);
  expect((await before.json()).something_else).toBe('Own words of Cy');
  const copied = await page.request.post(`/wp-json/lld-members/v1/test/ciphertext?member_id=${created.member_id}&copy_to=${copy.member_id}`);
  expect(copied.ok()).toBe(true);
  const after = await journey();
  expect(after.status()).toBe(500);
  expect(await after.text()).not.toContain(SENTINEL);
  await reset(page.request, copyEmail);
});

test('TC-FR2-04-AS-02', async ({ page }) => {
  const email = 'tm-04b@test.lifelinedaily.invalid';
  const ref = `sub_test_c2b_${randomBytes(4).toString('hex')}`;
  await reset(page.request, email);
  await createMember(page.request, { email, password, first_name: 'Tee', role: 'member', access_state: 'Premium', stripe_subscription: ref });
  await signIn(page, email, password);
  await page.goto('/members/delete/');
  await page.locator('#lld_password').fill('not-the-password');
  await page.getByRole('button', { name: 'Delete my account' }).click();
  await expect(page.getByText("Those details don't match our records.")).toBeVisible();
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Delete my account' }).click();
  await expect(page.getByText('Your account is closed.')).toBeVisible();
  // No Stripe call is made yet (it comes with the Stripe package), so the page must not claim billing
  // stopped, and the owner is alerted with the subscription reference to cancel it by hand.
  test.info().annotations.push({ type: 'pending', description: 'The Stripe cancellation call itself arrives with the Stripe package.' });
  await expect(page.getByText("We're finishing cancelling your subscription")).toBeVisible();
  await page.goto('/members/home/');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  const stripe = await page.request.post('/wp-json/lld-members/v1/test/stripe', { data: {} });
  const last = (await stripe.json()).last_cancel;
  expect(last.ok).toBe(false);
  expect(last.subscription).toBe(ref);
  await expect(mailMatching(ref)).resolves.toContain('Stripe cancellation failed');
});

test('TC-FR2-04-AS-03', async ({ page }) => {
  const email = 'tm-04c@test.lifelinedaily.invalid';
  const referrer = 'tm-04c-ref@test.lifelinedaily.invalid';
  const mark = `SENTINEL-GONE-${randomBytes(3).toString('hex')}`;
  await reset(page.request, email);
  await reset(page.request, referrer);
  // The member refers a friend, and was referred by another member: four rewards, two of them theirs.
  const created = await createMember(page.request, {
    email,
    password,
    first_name: 'Tee',
    role: 'member',
    health_consent: true,
    sentinel: mark,
    what_changed: mark,
    referral: true,
  });
  const other = await createMember(page.request, { email: referrer, password, first_name: 'Ria', role: 'member', referral: true, friend_member_id: created.member_id });
  const friend = 800000 + created.member_id;
  await signIn(page, email, password);
  await page.goto('/members/settings/');
  await page.locator('#lld_photo').setInputFiles(makeJpeg('gone-profile.jpg', 2048));
  await page.locator('#lld_diary').setInputFiles(makeJpeg('gone-diary.jpg', 2048));
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(page.getByText('Saved')).toBeVisible();
  // A data download that is never collected still holds the member's data.
  await page.goto('/members/data/');
  const dataNonce = await page.locator('input[name="lld_data_nonce"]').getAttribute('value');
  const made = await page.request.post('/members/data/', { form: { lld_data_nonce: dataNonce }, maxRedirects: 0 });
  expect(made.status()).toBe(302);
  expect((await (await page.request.get('/wp-json/lld-members/v1/test/stores')).json()).download_files).toBeGreaterThan(0);
  // Before: the checker finds the records, photos and sentinel, so an empty answer later means something.
  const before = (await scanOf(page.request, { member_id: created.member_id, sentinel: mark, email })).problems.join(' ');
  for (const part of ['member still has 1', 'referral still has 2', 'reward still has 2', 'survey still has', 'diary_meal still has', 'Photo files remain.', 'A data download remains.', 'Sentinel remains']) {
    expect(before, part).toContain(part);
  }

  await page.goto('/members/delete/');
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Delete my account' }).click();
  await expect(page.getByText('Your account is closed.')).toBeVisible();
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { member_id: created.member_id, utc: fromNow(24 * HOUR) } });
  const body = await scanOf(page.request, { member_id: created.member_id, sentinel: mark, email });
  expect(body.problems, JSON.stringify(body)).toEqual([]);
  expect(body.user_id).toBe(0);
  const search = await page.request.post('/wp-json/lld-members/v1/test/search', { data: { q: mark } });
  expect(await search.json()).toEqual({ hits: [], log: false });
  // The other people keep their own reward and their referral, without this member's number.
  for (const id of [other.member_id, friend]) {
    const kept = await scanOf(page.request, { member_id: id });
    expect(kept.records.reward, String(id)).toBe(1);
    expect(kept.records.referral, String(id)).toBe(1);
  }
  await reset(page.request, referrer);
});

test('TC-FR2-04-AS-04', async ({ page }) => {
  const email = 'tm-04d@test.lifelinedaily.invalid';
  await reset(page.request, email);
  const ref = `sub_test_c2d_${randomBytes(4).toString('hex')}`;
  await createMember(page.request, { email, password, first_name: 'Tee', role: 'member', access_state: 'Premium', stripe_subscription: ref });
  await page.request.post('/wp-json/lld-members/v1/test/stripe', { data: { cancel_fail: true } });
  try {
    await signIn(page, email, password);
    await page.goto('/members/delete/');
    await page.locator('#lld_password').fill(password);
    await page.getByRole('button', { name: 'Delete my account' }).click();
    await expect(page.getByText("We're finishing cancelling your subscription")).toBeVisible();
    const alert = await mailMatching(ref);
    expect(alert).toContain('Stripe cancellation failed');
    expect(alert).toContain('within 1 working day');
  } finally {
    await page.request.post('/wp-json/lld-members/v1/test/stripe', { data: { cancel_fail: false } });
  }
});

test('TC-FR2-04-AS-05', async ({ browser, page }) => {
  // As TC-FR2-03-AS-06, the restore is simulated: the deleted account is put back with the same
  // email (under a new member number, so the scrambled-email match is what finds it).
  const email = 'tm-04s@test.lifelinedaily.invalid';
  await reset(page.request, email);
  const since = fromNow(-5000);
  const created = await createMember(page.request, { email, password, first_name: 'Sol', role: 'member' });
  await signIn(page, email, password);
  await page.goto('/members/delete/');
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Delete my account' }).click();
  await expect(page.getByText('Your account is closed.')).toBeVisible();
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  const { body: list } = await erasureMail(created.member_id, 'account');

  const back = await createMember(page.request, { email, password, first_name: 'Sol', role: 'member' });
  expect((await scanOf(page.request, { email })).user_id).toBeGreaterThan(0);
  const member = await browser.newContext();
  const memberPage = await member.newPage();
  await signIn(memberPage, email, password);
  await asOwner(page);
  try {
    await erasure(page.request, { action: 'close' });
    await memberPage.goto('/members/home/');
    await expect(memberPage.getByRole('heading', { name: 'Lifeline Daily is closed' })).toBeVisible();
    const done = await erasure(page.request, { action: 'reapply', list, since });
    expect(done.problems, JSON.stringify(done)).toEqual([]);
    expect(done.closed).toBe(false);
  } finally {
    await erasure(page.request, { action: 'open' });
  }
  const gone = await scanOf(page.request, { member_id: back.member_id, email });
  expect(gone.problems, JSON.stringify(gone)).toEqual([]);
  expect(gone.user_id).toBe(0);
  await memberPage.goto('/members/home/');
  await expect(memberPage.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await member.close();
});

test('TC-FR2-04-AS-06', async ({ page }) => {
  const email = 'tm-04e@test.lifelinedaily.invalid';
  const first = `Tee${randomBytes(3).toString('hex')}`;
  await reset(page.request, email);
  const created = await createMember(page.request, { email, password, first_name: first, role: 'member' });
  await signIn(page, email, password);
  await page.goto('/members/delete/');
  await page.locator('#lld_password').fill(password);
  await page.getByRole('button', { name: 'Delete my account' }).click();
  await expect(page.getByText('Your account is closed.')).toBeVisible();
  // The confirmation goes to this member, by name.
  await expect(mailTo(email, 'Your account is closed')).resolves.toContain(`Hello ${first},`);
  // The next erasure-list email goes to the owner, not the member, and holds this deletion.
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  const list = await erasureMail(created.member_id, 'account');
  expect(list.body).toContain('Lifeline Daily erasure list');
  expect(list.to).toContain('@');
  expect(list.to.toLowerCase()).not.toContain(email);
});

test('TC-NFR2-01-AS-01', async ({ page }) => {
  const stores = await page.request.get('/wp-json/lld-members/v1/test/stores');
  const body = await stores.json();
  expect(body.extra_tables).toEqual([]);
  expect(body.extra_files).toEqual([]);
  // Every live table and column, and every file store, is tied to a field the Data Model names
  // (docs/stage2/02_Data_Model_Stage2.md, read here, through tests/stage2/data-model-map.json).
  expect(columnDifferences(body.columns)).toEqual([]);
  expect(body.files.slice().sort()).toEqual(Object.keys(MAP.stores).sort());
  expect(mapProblems()).toEqual([]);
});

test('TC-NFR2-01-AS-03', async ({ page }) => {
  const gone = 'tm-19@test.lifelinedaily.invalid';
  const kept = 'tm-19b@test.lifelinedaily.invalid';
  await reset(page.request, gone);
  await reset(page.request, kept);
  const created = await createMember(page.request, { email: gone, password, first_name: 'Idle', role: 'member', access_state: 'free' });
  await createMember(page.request, { email: kept, password, first_name: 'Back', role: 'member', access_state: 'free' });
  const deleteOn = new Date(`${created.joined_on}T00:00:00Z`);
  deleteOn.setUTCMonth(deleteOn.getUTCMonth() + 24);
  const warn = new Date(deleteOn);
  warn.setUTCDate(warn.getUTCDate() - 30);
  const stamp = (date) => date.toISOString().slice(0, 19).replace('T', ' ');
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email: gone, utc: stamp(warn) } });
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  await expect(mailMatching('Sign in to keep your account')).resolves.toContain('due to be deleted');
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email: gone, utc: stamp(deleteOn) } });
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  const scan = await page.request.post('/wp-json/lld-members/v1/test/scan', { data: { member_id: created.member_id, email: gone } });
  expect((await scan.json()).user_id).toBe(0);
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email: kept, utc: stamp(warn) } });
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  await signIn(page, kept, password);
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
  await page.request.post('/wp-json/lld-members/v1/test/clock', { data: { email: kept, utc: stamp(deleteOn) } });
  await page.request.post('/wp-json/lld-members/v1/test/retain');
  const keptScan = await page.request.post('/wp-json/lld-members/v1/test/scan', { data: { email: kept } });
  expect((await keptScan.json()).user_id).toBeGreaterThan(0);
  await page.goto('/members/home/');
  await expect(page.getByRole('heading', { name: 'Today' })).toBeVisible();
});
