// Stage 3 sign-in (P3-20 to P3-22: FR3-01 to FR3-05, NFR3-18): the pure parts run in PHP CLI without WordPress —
// the ID-token claim checks, Apple's ES256 client secret, the DER-to-raw signature conversion — and the code
// policy is read from the source. The live journeys are tests/stage3/key-journeys/sign-in-code.spec.mjs and
// provider-sign-in.spec.mjs.
import { execFileSync } from 'node:child_process';
import { generateKeyPairSync, verify } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const plugin = resolve(root, 'site/public_html/wp-content/plugins/lld-members');
const signin = resolve(plugin, 'includes/stage3/signin');
const toPhpPath = (p) => JSON.stringify(p.split('\\').join('/'));
const php = (code, defines = '') => execFileSync('php', ['-d', 'display_errors=stderr', '-r',
  `define('ABSPATH', '/'); define('MINUTE_IN_SECONDS', 60); define('HOUR_IN_SECONDS', 3600); ${defines} require ${toPhpPath(resolve(signin, 'class-identity-provider.php'))}; ${code}`],
{ encoding: 'utf8', timeout: 120000 }).trim();
const b64url = (s) => Buffer.from(s).toString('base64url');
const jwt = (payload) => `${b64url(JSON.stringify({ alg: 'RS256' }))}.${b64url(JSON.stringify(payload))}.sig`;
const GOOGLE = "define('LLD_GOOGLE_CLIENT_ID', 'client-1.apps.googleusercontent.com');";
const claims = (provider, token, nonce, now) => JSON.parse(php(`echo json_encode(LLD_Members_Identity_Provider::claims(${JSON.stringify(provider)}, ${JSON.stringify(token)}, ${JSON.stringify(nonce)}, ${now}));`, GOOGLE));

const good = { iss: 'https://accounts.google.com', aud: 'client-1.apps.googleusercontent.com', exp: 2000000000, nonce: 'n1', sub: 'g-123', email: 'Member@Example.test', email_verified: true, given_name: 'Ada', family_name: 'Lovelace' };

test('TC-FR3-01-AS-03', () => {
  // Only the account ID, email (and whether it is verified) and name are read from the token: nothing else survives.
  const out = claims('google', jwt({ ...good, picture: 'https://x/y.png', locale: 'en', hd: 'example.test' }), 'n1', 1900000000);
  expect(out).toEqual({ sub: 'g-123', email: 'member@example.test', email_verified: true, first_name: 'Ada', last_name: 'Lovelace' });
  // And the stored row has only those columns plus the link dates (data model: Linked sign-in).
  const schema = readFileSync(resolve(plugin, 'includes/stage3/class-schema-stage3.php'), 'utf8');
  const identities = schema.match(/'identities' => '([\s\S]*?)'/)[1];
  const columns = [...identities.matchAll(/^\s*([a-z_]+) [A-Z]/gm)].map((m) => m[1]);
  expect(columns).toEqual(['id', 'member_id', 'provider', 'provider_user_id', 'provider_email', 'linked_at', 'last_used_at']);
});

test('TC-FR3-05-AS-02', () => {
  // A token without a verified email says so, so no automatic match by email can follow.
  expect(claims('google', jwt({ ...good, email_verified: false }), 'n1', 1900000000).email_verified).toBe(false);
  expect(claims('google', jwt({ ...good, email_verified: 'false' }), 'n1', 1900000000).email_verified).toBe(false);
  expect(claims('apple', jwt({ ...good, iss: 'https://appleid.apple.com', aud: 'ignored', email_verified: 'true' }), 'n1', 1900000000).error).toBe('lld_token_audience');
});

test('TC-FR3-01-AS-02', () => {
  // The checks that make the token trustworthy: issuer, audience, expiry, nonce and subject.
  expect(claims('google', jwt({ ...good, iss: 'https://evil.test' }), 'n1', 1900000000).error).toBe('lld_token_issuer');
  expect(claims('google', jwt({ ...good, aud: 'someone-else' }), 'n1', 1900000000).error).toBe('lld_token_audience');
  expect(claims('google', jwt(good), 'n1', 2000000001).error).toBe('lld_token_expired');
  expect(claims('google', jwt(good), 'other', 1900000000).error).toBe('lld_token_nonce');
  expect(claims('google', jwt({ ...good, sub: '' }), 'n1', 1900000000).error).toBe('lld_token_subject');
  expect(claims('google', 'not.a.jwt', 'n1', 1900000000).error).toBe('lld_token_shape');
  expect(claims('google', jwt({ ...good, iss: 'accounts.google.com' }), 'n1', 1900000000).sub).toBe('g-123');
});

test('TC-FR3-02-AS-01', () => {
  // Apple: the client secret is an ES256 JWT with the team, key and services IDs. The key pair is made here in
  // Node, and Node verifies the signature (IEEE P1363, the raw r||s form JOSE uses), independently of PHP's OpenSSL;
  // and the name arrives separately, once, as the `user` field.
  const { privateKey, publicKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' });
  const pem = privateKey.export({ type: 'pkcs8', format: 'pem' });
  const out = JSON.parse(php(`
    define('LLD_APPLE_PRIVATE_KEY', ${JSON.stringify(pem)}); define('LLD_APPLE_KEY_ID', 'KEY1'); define('LLD_APPLE_TEAM_ID', 'TEAM1'); define('LLD_APPLE_SERVICES_ID', 'com.example.signin');
    $jwt = LLD_Members_Identity_Provider::apple_client_secret(1900000000);
    echo json_encode(array(
      'jwt' => $jwt,
      'named' => LLD_Members_Identity_Provider::with_apple_name(array('first_name' => '', 'last_name' => ''), '{"name":{"firstName":"Grace","lastName":"Hopper"},"email":"relay@privaterelay.appleid.com"}'),
      'configured' => LLD_Members_Identity_Provider::configured('apple'),
    ));
  `));
  const [h, p, s] = out.jwt.split('.');
  expect(JSON.parse(Buffer.from(h, 'base64url').toString())).toEqual({ alg: 'ES256', kid: 'KEY1', typ: 'JWT' });
  expect(JSON.parse(Buffer.from(p, 'base64url').toString())).toEqual({ iss: 'TEAM1', iat: 1900000000, exp: 1900000600, aud: 'https://appleid.apple.com', sub: 'com.example.signin' });
  const signature = Buffer.from(s, 'base64url');
  expect(signature.length).toBe(64);
  expect(verify('sha256', Buffer.from(`${h}.${p}`), { key: publicKey, dsaEncoding: 'ieee-p1363' }, signature)).toBe(true);
  expect(out.named).toEqual({ first_name: 'Grace', last_name: 'Hopper' });
  expect(out.configured).toBe(true);
});

test('TC-FR3-03-AS-04', () => {
  // The code policy in the source: 10 minutes, five tries, five an hour, hashed with bcrypt, never stored in clear.
  const codes = readFileSync(resolve(signin, 'class-email-codes.php'), 'utf8');
  expect(codes).toMatch(/const MINUTES\s+= 10;/);
  expect(codes).toMatch(/const ATTEMPTS\s+= 5;/);
  expect(codes).toMatch(/const HOURLY_LIMIT = 5;/);
  expect(codes).toMatch(/password_hash\( \$code, PASSWORD_BCRYPT \)/);
  expect(codes).toMatch(/used_at = %s WHERE id = %d AND used_at IS NULL/);
  expect(codes).not.toMatch(/'code'\s*=>\s*\$code/);
  // The approved email wording (emails/stage3-emails.md EM-22, EM-23) is quoted exactly.
  expect(codes).toContain('Your Lifeline Daily sign-up code');
  expect(codes).toContain("Enter it on the page where you signed up. It works once and expires in 10 minutes. After 5 wrong tries you'll need to ask for a new one.");
  expect(codes).toContain("If you didn't try to sign in, you can ignore this email. If it keeps happening, change your password from the sign-in page.");
  expect(codes).toContain('Your Lifeline Daily confirmation code');
  expect(codes).toContain("You've asked for several codes in the last hour. Please wait a while and try again, or sign in with your password.");
});

test('TC-FR3-04-AS-04', () => {
  // The Stage 2 sign-in classes are untouched: the password path goes through their public methods.
  const signIn = readFileSync(resolve(signin, 'class-sign-in.php'), 'utf8');
  expect(signIn).toMatch(/LLD_Members_Auth::check_password\( \$email, \$password \)/);
  expect(signIn).toMatch(/LLD_Members_Auth::start_session\(/);
  expect(signIn).toMatch(/LLD_Members_Auth::needs_two_step\( \$user \)/);
  // A code alone never opens the owner admin's account or a two-step member's: no code is emailed to them, and a
  // verified code is still refused for them.
  expect(signIn).toMatch(/\$member && 'owner_admin' !== \(string\) \$member->role && '1' !== \(string\) \$member->two_step/);
  expect(signIn).toMatch(/elseif \( 'owner_admin' === \(string\) \$member->role \|\| '1' === \(string\) \$member->two_step \)/);
  // Google's registered redirect address (tracker P3-20) is the callback route.
  const provider = readFileSync(resolve(signin, 'class-identity-provider.php'), 'utf8');
  expect(provider).toMatch(/rest_url\( 'lld-members\/v1\/sign-in\/' \. \$provider \. '\/callback' \)/);
  expect(signIn).toMatch(/'\/sign-in\/\(\?P<provider>google\|apple\)\/callback'/);
  // Secrets come from wp-config.php constants only.
  for (const name of ['LLD_GOOGLE_CLIENT_SECRET', 'LLD_APPLE_PRIVATE_KEY']) expect(provider).toMatch(new RegExp(`constant\\( '${name}' \\)`));
  expect(provider).not.toMatch(/sk_|whsec_|GOCSPX/);
});
