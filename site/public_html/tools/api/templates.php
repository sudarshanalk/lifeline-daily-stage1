<?php
/* ============================================================================
 * templates.php  —  EDITABLE EMAIL COPY  (this is your edit surface)
 * ----------------------------------------------------------------------------
 * Reword any subject/body freely. Keep the {{tokens}} intact — render.php
 * fills them in. Do NOT put logic here; this file is text only.
 *
 * Placeholders available in every body:
 *   {{firstName}}    first name, or "there" if none given
 *   {{riskPercent}}  e.g. "7.2"          {{riskBand}}  e.g. "High"
 *   {{bmi}}          e.g. "27.8"
 *   {{crossChecks}}  auto-built waist-to-height + IDRS block ("" if no waist)
 *   {{disclaimer}}   the shared disclaimer text below
 *   {{year}}         current year
 *
 * One block per result state. Editing one never affects the others or the logic.
 *
 * SAFETY — please preserve the meaning of these when rewording:
 *   • emergency  MUST tell the reader to seek SAME-DAY urgent care / A&E.
 *   • symptomatic MUST NOT state a diagnosis — say "can be caused by",
 *     never "you have". The % is secondary to seeing a doctor.
 *   • no block may claim the tool diagnoses diabetes.
 * ==========================================================================*/

return [

  '_shared' => [
    'disclaimer' =>
      'This email is general screening information from Lifeline Daily. It is not a '.
      'diagnosis and not a substitute for professional medical advice. Always speak to '.
      'a qualified clinician about your health.',
  ],

  // ---- normal bands --------------------------------------------------------
  'low' => [
    'subject' => 'Your Lifeline Daily diabetes screening result',
    'body' =>
      '<p>Hi {{firstName}},</p>'.
      '<p>Your estimated <strong>10-year risk of type 2 diabetes is {{riskPercent}}% — Low</strong>.</p>'.
      '<p>That\'s good news, and mostly comes down to habits worth keeping up. A re-check every '.
      '1–3 years (or sooner if your weight or health changes) is all that\'s usually needed.</p>'.
      '{{crossChecks}}'.
      '<p>Your BMI at the time of this check was {{bmi}}.</p>',
  ],

  'moderate' => [
    'subject' => 'Your Lifeline Daily diabetes screening result',
    'body' =>
      '<p>Hi {{firstName}},</p>'.
      '<p>Your estimated <strong>10-year risk of type 2 diabetes is {{riskPercent}}% — Moderate</strong>.</p>'.
      '<p>Small, sustained changes now make a real difference at this stage. It\'s worth a lifestyle '.
      'review, and asking your doctor about a blood test in the coming months.</p>'.
      '{{crossChecks}}'.
      '<p>Your BMI at the time of this check was {{bmi}}.</p>',
  ],

  'high' => [
    'subject' => 'Your Lifeline Daily diabetes screening result — worth a check',
    'body' =>
      '<p>Hi {{firstName}},</p>'.
      '<p>Your estimated <strong>10-year risk of type 2 diabetes is {{riskPercent}}% — High</strong>.</p>'.
      '<p>This is the level at which health services recommend a check. Please arrange a blood test '.
      '(fasting glucose or HbA1c) with your doctor soon.</p>'.
      '{{crossChecks}}'.
      '<p>Your BMI at the time of this check was {{bmi}}.</p>',
  ],

  'veryhigh' => [
    'subject' => 'Your Lifeline Daily diabetes screening result — please read',
    'body' =>
      '<p>Hi {{firstName}},</p>'.
      '<p>Your estimated <strong>10-year risk of type 2 diabetes is {{riskPercent}}% — Very high</strong>.</p>'.
      '<p>Please don\'t be alarmed — it means action now is especially worthwhile. See your doctor soon '.
      'to arrange a blood test and discuss next steps.</p>'.
      '{{crossChecks}}'.
      '<p>Your BMI at the time of this check was {{bmi}}.</p>',
  ],

  // ---- overrides -----------------------------------------------------------
  // SAFETY: symptomatic MUST NOT state a diagnosis. Keep "can be caused by".
  'symptomatic' => [
    'subject' => 'Your Lifeline Daily result — some symptoms worth checking',
    'body' =>
      '<p>Hi {{firstName}},</p>'.
      '<p>You mentioned symptoms that <strong>can be caused by high blood sugar</strong>. Whatever your '.
      'screening result, it\'s worth seeing a doctor soon for a simple blood test. This is not a diagnosis.</p>'.
      '<p>For reference, your estimated 10-year screening result was {{riskPercent}}% ({{riskBand}}).</p>'.
      '{{crossChecks}}',
  ],

  // SAFETY: emergency MUST direct to same-day urgent care. No risk % shown.
  'emergency' => [
    'subject' => 'Your Lifeline Daily result — please seek urgent care today',
    'body' =>
      '<p>Hi {{firstName}},</p>'.
      '<p>You told us you feel very unwell right now — with symptoms such as vomiting, drowsiness, fast '.
      'or deep breathing, or stomach pain. These can signal a serious problem with blood sugar.</p>'.
      '<p><strong>Please seek a same-day medical assessment — urgent care or A&amp;E.</strong> We haven\'t included a risk '.
      'estimate, because getting checked comes first.</p>'.
      '<p><strong>In an emergency, call your local emergency number now</strong>, for example 999 in the UK, 911 in the US '.
      'and Canada, 112 in the EU and India, or 000 in Australia. In the UK you can also call NHS 111 for urgent advice.</p>',
  ],
];
