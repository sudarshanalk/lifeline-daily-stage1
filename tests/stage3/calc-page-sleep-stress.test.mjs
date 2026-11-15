// Sleep and stress check page (P3-5A part 3, FR3-54): the page class's form, inputs and result, run in PHP CLI with
// the real engine. Q0 (self-harm) Yes shows Stage 2 Response 1 alone; the answers are never saved (sheet 3.4).
import { expect, test } from 'vitest';
import { run, textOf, php } from './tools/calc-page-php.mjs';

const P = 'LLD_Members_Calc_Page_Sleep_Stress';
const E = 'LLD_Members_Sleep_Stress';
const low = { q0: 'no', q1: '0', q2: '0', q3: '0', q4: '0', q5: 'no', q7: 'no', q8: '8' };
const high = { q0: 'no', q1: '3', q2: '3', q3: '3', q4: '3', q5: 'yes', q6: 'yes', q7: 'yes', q8: '4' };
const signed = (key) => JSON.parse(php(`echo json_encode(${E}::wording(['${key}']));`))[key];
const leftovers = /\{|\}|\||\*\*|\[Continue\]|\(button, opens/;

test('TC-FR3-54-AS-02 (page): a Yes to the self-harm question shows only Response 1, with no score and no track', () => {
  const out = run(P, E, { ...high, q0: 'yes' });
  expect(out.result.response1).toBe(true);
  const text = textOf(out.html);
  expect(text.startsWith('Please get support now')).toBe(true);
  expect(text).toContain('for example 999 in the UK, 911 in the US and Canada, 112 in the EU and India, or 000 in Australia');
  expect(out.html).toContain('<strong>If you are in danger now, call your local emergency number</strong>');
  expect(text).toContain('Find support in your country');
  expect(text).toContain('Back to my dashboard');
  expect(out.html).toContain('role="alert"');
  expect(out.html).toContain('data-lld-key="quoted.response1"');
  expect(out.html).toContain('href="https://findahelpline.com"');
  // Nothing else: one alert block, no scores, no headline, no other wording, no track.
  expect(out.html.match(/^<div class="lld-safety lld-calc-urgent"[^>]*>[\s\S]*<\/div>$/)).not.toBeNull();
  expect(out.html).not.toMatch(/lld-calc-headline|data-lld-stress|lld-calc-track|programmes\/sleep-stress|Stress and worry|Sleep:/);
  expect(out.html).not.toMatch(leftovers);
  expect(out.saved).toBeNull();
});

test('TC-FR3-54-AS-02 (page): a high score shows the support wording and the help line', () => {
  const out = run(P, E, high);
  expect(out.result).toMatchObject({ stressScore: 6, moodScore: 6, sleepCount: 4, helpline: true });
  const text = textOf(out.html);
  expect(text).toContain(signed('result.stress.attention'));
  expect(text).toContain(signed('result.stress.doctor'));
  expect(text).toContain(signed('result.mood.attention'));
  expect(text).toContain('Please talk to a doctor about your mood');
  expect(text).toContain(signed('result.sleep.needs'));
  expect(text).toContain(signed('result.sleep.insomnia'));
  expect(text).toContain(signed('result.sleep.shortSleep'));
  expect(text).toContain('Need help now? If you are in danger now, call your local emergency number');
  expect(out.html).toContain('data-lld-key="quoted.helpFooter"');
  expect(out.html).toContain('data-lld-key="quoted.response3"');
  expect(out.html).toContain('<a href="https://findahelpline.com" data-lld-helpline>findahelpline.com</a>');
  // The insomnia line comes before the short-sleep note, and the help line after both.
  expect(text.indexOf(signed('result.sleep.insomnia'))).toBeLessThan(text.indexOf(signed('result.sleep.shortSleep')));
  expect(text.indexOf(signed('result.sleep.shortSleep'))).toBeLessThan(text.indexOf('Need help now?'));
  expect(out.html).toContain('data-lld-track="1,2,3"');
  expect(out.html).not.toMatch(leftovers);
  expect(text).not.toMatch(/you have (anxiety|depression|insomnia)/i);
});

test('TC-FR3-54-AS-01 (page): a low score shows the usual range, no concern, no help line', () => {
  const out = run(P, E, low);
  expect(out.result).toMatchObject({ stressScore: 0, moodScore: 0, sleepCount: 0, helpline: false });
  const text = textOf(out.html);
  expect(text).toContain(signed('result.stress.usual'));
  expect(text).toContain(signed('result.sleep.none'));
  expect(text).not.toContain('Need help now?');
  expect(text).not.toContain(signed('result.stress.doctor'));
  expect(out.html).toContain('data-lld-track="1,2"');
  expect(out.html).not.toMatch(leftovers);
  // Q6 is not sent without a Yes to Q5, even if answered.
  expect(run(P, E, { ...low, q6: 'yes' }).inputs.q6).toBeNull();
});

test('TC-FR3-55-AS-01 (sleep and stress page): the saved row holds the scores only, never the answers', () => {
  const out = run(P, E, { ...low, q1: '2', q2: '1' });
  // The saved headline is short (the save path keeps 64 characters): the three scores; the signed headline key is in
  // the result, and the history list shows its words.
  expect(out.saved).toMatchObject({ calculator: 'sleep_stress', headline: '3/0/0', unit: 'scores', band: 'attention',
    result: { stressScore: 3, moodScore: 0, sleepCount: 0, headline: 'result.stress.attention' } });
  expect(JSON.parse(php(`echo json_encode(${P}::history_line(['headline' => '3/0/0', 'result' => json_encode(['stressScore' => 3, 'moodScore' => 0, 'sleepCount' => 0, 'headline' => 'result.stress.attention'])]));`))).toBe(signed('result.stress.attention'));
  // A Yes to the self-harm question keeps the page quiet: no save and no saved results beside Response 1.
  expect(JSON.parse(php(`echo json_encode(${P}::quiet(['response1' => true]));`))).toBe(true);
  // And the save path itself never stores answers for this check, whatever a caller sends.
  expect(JSON.parse(php(`require_once ${JSON.stringify((process.cwd() + '/site/public_html/wp-content/plugins/lld-members/includes/stage3/class-saved-results.php').split('\\').join('/'))}; echo json_encode(LLD_Members_Saved_Results::stores_inputs('sleep_stress'));`))).toBe(false);
  expect('inputs' in out.saved).toBe(false);
  expect(JSON.stringify(out.saved)).not.toMatch(/q[0-8]/);
  // The P3-5B save path stores no inputs for this row.
  const row = JSON.parse(php(`require_once ${JSON.stringify((process.cwd() + '/site/public_html/wp-content/plugins/lld-members/includes/stage3/class-saved-results.php').split('\\').join('/'))};
    echo json_encode(LLD_Members_Saved_Results::for_save(${P}::saved(${E}::compute(${P}::inputs(['q0'=>'no','q1'=>'2','q2'=>'1','q3'=>'0','q4'=>'0','q5'=>'no','q7'=>'no','q8'=>'8'], [])), [])));`));
  expect(row.calculator).toBe('sleep_stress');
  expect(row.inputs).toBeUndefined();
});

test('Sleep and stress page: errors name their answer, the form asks Q0 first, and nothing is pre-filled', () => {
  expect(run(P, E, { ...low, q0: '' }).error).toBe('Please check the first question.');
  expect(run(P, E, { ...low, q3: '' }).error).toBe('Please check the question about interest or pleasure.');
  expect(run(P, E, { ...low, q5: 'yes' }).error).toBe('Please check how long the sleep trouble has gone on.');
  expect(run(P, E, { ...low, q8: '17' }).error).toBe('Please check your hours of sleep.');
  expect(run(P, E, { ...low, q8: '' }).error).toBe('Please check your hours of sleep.');
  const blank = run(P, E, {});
  expect(blank.form).not.toContain('checked');
  expect(blank.form).toContain('name="lld_c[q8]" type="number" inputmode="decimal" value=""');
  expect(blank.form.indexOf('lld_c[q0]')).toBeLessThan(blank.form.indexOf('lld_c[q1]'));
  expect(textOf(blank.form)).toContain(signed('q0'));
  expect(textOf(blank.form)).toContain('Over the last 2 weeks, how often have you been bothered by:');
  expect(textOf(blank.form)).toContain(signed('scale.2'));
  expect(blank.form).not.toMatch(/\{|\}|\|/);
  expect(JSON.parse(php(`echo json_encode(${P}::defaults(['member'=>5,'facts'=>['age'=>40]]));`))).toEqual([]);
});

test('TC-FR3-54-AS-02 (page, D3-52): page versions of Responses 1 and 3, and the track link below the result', () => {
  // Response 1 on the page has no track sentence; the Stage 2 text it quotes still has it (the track-start screen).
  const yes = run(P, E, { ...high, q0: 'yes' });
  const yesText = textOf(yes.html);
  expect(yesText).not.toContain("We won't start Sleep and Stress today");
  expect(yesText).toContain("You don't have to cope with these thoughts alone");
  expect(yes.result.wording['quoted.response1']).toContain("We won't start Sleep and Stress today.");
  expect(JSON.parse(php(`echo json_encode(${P}::after_result(['response1' => true, 'trackOffer' => false, 'trackWeeks' => []], []));`))).toBe('');
  // Response 3 on the page has no "You can still start the track" and no Continue.
  const hi = run(P, E, high);
  const hiText = textOf(hi.html);
  expect(hiText).toContain('Please talk to a doctor about your mood');
  expect(hiText).not.toContain("You can still start the track");
  expect(hi.result.wording['quoted.response3']).toContain("You can still start the track if you'd like to.");
  // The weeks are text in the result; the link is only below it, with no price.
  expect(hi.html).toContain('data-lld-track="1,2,3"');
  expect(hi.html).not.toContain('programmes/sleep-stress');
  const below = JSON.parse(php(`$r = ${E}::compute(${P}::inputs(json_decode(${JSON.stringify(JSON.stringify(high))}, true), [])); echo json_encode(${P}::after_result($r, []));`));
  expect(below).toContain('href="https://example.test/programmes/sleep-stress/"');
  expect(textOf(below)).toBe('The Sleep and Stress track');
  expect(below).not.toMatch(/\$|£|€|price|Premium|month|year/i);
});
