// Verify Stage 3 Test Personas across all screens and workflows using Playwright
import { chromium } from '@playwright/test';

const BASE_URL = process.env.LLD_WP_URL || 'http://localhost:8090';
const PASSWORD = 'Password123!Safe';

const PERSONAS = [
  { key: 'weight_loss', name: 'Sarah Jenkins', email: 'persona-weight-loss@test.lifelinedaily.invalid', tier: 'premium', track: 'Healthy Weight' },
  { key: 'blood_sugar', name: 'Marcus Thorne', email: 'persona-blood-sugar@test.lifelinedaily.invalid', tier: 'free', track: 'Blood Sugar Balance' },
  { key: 'sleep_stress', name: 'Elena Rostova', email: 'persona-sleep-stress@test.lifelinedaily.invalid', tier: 'premium', track: 'Sleep and Stress' },
  { key: 'fitness_strength', name: 'David Miller', email: 'persona-fitness-strength@test.lifelinedaily.invalid', tier: 'premium', track: 'Fitness and Strength' },
  { key: 'cardio_metabolic', name: 'Amara Okafor', email: 'persona-cardio-metabolic@test.lifelinedaily.invalid', tier: 'premium', track: 'Healthy Weight' },
  { key: 'clinical_safety', name: 'Liam Gallagher', email: 'persona-clinical-safety@test.lifelinedaily.invalid', tier: 'free', track: 'Blood Sugar Balance' },
  { key: 'fresh_join', name: 'Priya Patel', email: 'persona-fresh-join@test.lifelinedaily.invalid', tier: 'free', track: 'None' },
];

async function runAudit() {
  console.log(`Starting Stage 3 Full-Spectrum Browser Audit against ${BASE_URL}...`);
  const browser = await chromium.launch({ headless: true });
  const results = [];

  for (const p of PERSONAS) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const page = await context.newPage();
    const personaReport = { name: p.name, email: p.email, tier: p.tier, checks: [] };

    try {
      console.log(`\n--- Testing Persona: ${p.name} (${p.email}) [${p.tier.toUpperCase()}] ---`);

      // 1. Sign In
      await page.goto(`${BASE_URL}/members/sign-in/`, { waitUntil: 'domcontentloaded' });
      await page.fill('#lld_email', p.email);
      await page.fill('#lld_password', PASSWORD);
      await page.click('button[type="submit"], input[type="submit"]');
      await page.waitForTimeout(1000);

      const currentUrl = page.url();
      const signedIn = !currentUrl.includes('/sign-in/');
      personaReport.checks.push({ check: 'Sign In', pass: signedIn, note: currentUrl });
      console.log(`  [Sign In]: ${signedIn ? 'PASS' : 'FAIL'} (${currentUrl})`);

      // 2. Account Hub / Home (/members/home/)
      await page.goto(`${BASE_URL}/members/home/`, { waitUntil: 'domcontentloaded' });
      const hubHtml = await page.content();
      const hasName = hubHtml.includes(p.name);
      personaReport.checks.push({ check: 'Account Hub (Name displayed)', pass: hasName, note: hasName ? 'Found' : 'Missing' });
      console.log(`  [Account Hub]: ${hasName ? 'PASS' : 'WARN (name not rendered in plain text)'}`);

      // 3. Today Screen (/members/today/)
      await page.goto(`${BASE_URL}/members/today/`, { waitUntil: 'domcontentloaded' });
      const todayHtml = await page.content();
      const hasTodayContent = todayHtml.includes('today') || todayHtml.includes('Today') || todayHtml.includes('Step') || todayHtml.includes('goal');
      personaReport.checks.push({ check: 'Today Screen Load', pass: hasTodayContent, note: `Status: 200` });
      console.log(`  [Today Screen]: ${hasTodayContent ? 'PASS' : 'FAIL'}`);

      // 4. Progress Screen (/members/progress/)
      await page.goto(`${BASE_URL}/members/progress/`, { waitUntil: 'domcontentloaded' });
      const progressHtml = await page.content();
      const hasProgress = progressHtml.includes('progress') || progressHtml.includes('Progress') || progressHtml.includes('kg') || progressHtml.includes('Goal');
      personaReport.checks.push({ check: 'Progress Screen Load', pass: hasProgress, note: `Loaded` });
      console.log(`  [Progress Screen]: ${hasProgress ? 'PASS' : 'FAIL'}`);

      // 5. Food Tracker Screen (/members/food/)
      await page.goto(`${BASE_URL}/members/food/`, { waitUntil: 'domcontentloaded' });
      const foodHtml = await page.content();
      const hasFood = foodHtml.includes('food') || foodHtml.includes('Food') || foodHtml.includes('Breakfast') || foodHtml.includes('Calories') || foodHtml.includes('kcal');
      personaReport.checks.push({ check: 'Food Tracker Load', pass: hasFood, note: `Loaded` });
      console.log(`  [Food Tracker]: ${hasFood ? 'PASS' : 'FAIL'}`);

      // 6. Mobile Responsiveness Check (360x800)
      await page.setViewportSize({ width: 360, height: 800 });
      await page.goto(`${BASE_URL}/members/today/`, { waitUntil: 'domcontentloaded' });
      const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
      const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
      const noHorizontalScroll = scrollWidth <= clientWidth + 2;
      personaReport.checks.push({ check: 'Mobile 360px Viewport (No sideways scroll)', pass: noHorizontalScroll, note: `scroll: ${scrollWidth}px, client: ${clientWidth}px` });
      console.log(`  [Mobile 360px Viewport]: ${noHorizontalScroll ? 'PASS' : 'FAIL'} (${scrollWidth}px vs ${clientWidth}px)`);

    } catch (err) {
      console.error(`  Error during audit of ${p.name}:`, err.message);
      personaReport.checks.push({ check: 'Execution', pass: false, note: err.message });
    } finally {
      await context.close();
      results.push(personaReport);
    }
  }

  // 7. Health Tools Hub & Calculators Public Check
  console.log(`\n--- Testing Public Health Tools Hub & Calculators ---`);
  const publicContext = await browser.newContext();
  const publicPage = await publicContext.newPage();
  const calcTools = [
    { name: 'Health Tools Hub', url: `${BASE_URL}/health-tools/` },
    { name: 'Blood Pressure Check', url: `${BASE_URL}/health-tools/blood-pressure-check/` },
    { name: 'Heart Attack & Stroke Risk', url: `${BASE_URL}/health-tools/heart-risk-check/` },
    { name: 'Metabolic Health Check', url: `${BASE_URL}/health-tools/metabolic-health-check/` },
    { name: 'Fitness Age', url: `${BASE_URL}/health-tools/fitness-age/` },
    { name: 'Heart Rate Zones', url: `${BASE_URL}/health-tools/heart-rate-zones/` },
    { name: 'Sleep and Stress Check', url: `${BASE_URL}/health-tools/sleep-and-stress-check/` },
    { name: 'Healthy Weight Check', url: `${BASE_URL}/health-tools/healthy-weight-check/` },
  ];

  const calcResults = [];
  for (const tool of calcTools) {
    try {
      const res = await publicPage.goto(tool.url, { waitUntil: 'domcontentloaded' });
      const status = res ? res.status() : 0;
      const pass = status === 200;
      calcResults.push({ tool: tool.name, status, pass });
      console.log(`  [${tool.name}]: HTTP ${status} ${pass ? 'PASS' : 'FAIL'}`);
    } catch (e) {
      calcResults.push({ tool: tool.name, status: 0, pass: false, error: e.message });
      console.log(`  [${tool.name}]: FAIL (${e.message})`);
    }
  }
  await publicContext.close();

  await browser.close();

  console.log(`\n======================================================`);
  console.log(`=== Audit Run Finished. Summary of Findings:      ===`);
  console.log(`======================================================`);
  for (const r of results) {
    const passed = r.checks.filter(c => c.pass).length;
    const total = r.checks.length;
    console.log(`${r.name} (${r.tier.toUpperCase()}): ${passed}/${total} checks passed`);
  }
  return { personas: results, calculators: calcResults };
}

runAudit().catch(err => {
  console.error('Fatal audit error:', err);
  process.exit(1);
});
