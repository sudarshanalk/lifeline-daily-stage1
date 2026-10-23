<?php
/* ============================================================================
 * templates-protein.php  —  EDITABLE EMAIL COPY for the protein tool
 * ----------------------------------------------------------------------------
 * Upload to: public_html/tools/api/templates-protein.php
 *
 * Reword freely. Keep {{tokens}} intact. No logic in this file — text only.
 *
 * Placeholders:
 *   {{firstName}}   first name, or "there"
 *   {{targetG}}     daily target in grams, e.g. "135"
 *   {{perMeal}}     grams per meal, e.g. "40"
 *   {{meals}}       meals per day, e.g. "3"
 *   {{gPerKg}}      intensity, e.g. "1.8"
 *   {{rangeBlock}}  auto-built range paragraph ("" if target is a single figure)
 *   {{sources}}     auto-built list of guidelines applied
 *   {{disclaimer}}  shared disclaimer
 *   {{year}}        current year
 *
 * SAFETY — please preserve the meaning of these when rewording:
 *   • protein_renal MUST NOT contain any g/day figure, any target, or any
 *     number the reader could act on. Not even "around" or "roughly". Kidney
 *     guidelines RESTRICT protein — the opposite of general advice — so a
 *     population number could cause harm. If you add a number here, you undo
 *     the safeguard the tool was built around.
 *   • No block may claim to diagnose anything.
 *   • Neither block may frame the target as a prescription.
 * ==========================================================================*/

return [

  '_shared' => [
    'disclaimer' =>
      'This email is general screening information from Lifeline Daily. It is not a '.
      'diagnosis and not a substitute for professional medical advice. Always speak to '.
      'a qualified clinician before making significant changes to your diet, particularly '.
      'if you have a medical condition.',
  ],

  // ---- normal result -------------------------------------------------------
  'protein_standard' => [
    'subject' => 'Your daily protein target from Lifeline Daily',
    'body' =>
      '<p>Hi {{firstName}},</p>'.
      '<p>Based on what you told us, your <strong>daily protein target is about '.
      '{{targetG}} g</strong> — that works out at roughly {{gPerKg}} g per kilogram '.
      'of body weight.</p>'.
      '{{rangeBlock}}'.
      '<p><strong>Spread it out.</strong> Aim for around {{perMeal}} g at each of your '.
      '{{meals}} meals. Your body uses protein better in even portions across the day '.
      'than in one large evening serving — and that matters more as you get older, not less.</p>'.
      '<p>As a rough guide: a palm-sized portion of chicken, fish or tofu is about 25–30 g; '.
      'two eggs about 12 g; a pot of Greek yoghurt about 17 g; a cup of cooked lentils about 18 g.</p>'.
      '{{sources}}'.
      '<p>This is a target, not a prescription — and protein is only one part of the picture. '.
      'If food or your weight is a source of worry, that is worth talking to a GP or a '.
      'registered dietitian about.</p>',
  ],

  // ---- renal override ------------------------------------------------------
  // SAFETY: NO NUMBERS. NONE. See the note at the top of this file.
  'protein_renal' => [
    'subject' => 'Your Lifeline Daily protein check — please read',
    'body' =>
      '<p>Hi {{firstName}},</p>'.
      '<p>You told us you have kidney disease or reduced kidney function, so our tool '.
      '<strong>deliberately did not give you a protein target</strong> — and this email '.
      'will not give you one either.</p>'.
      '<p>Here is why. In kidney disease, protein guidance runs in the <strong>opposite '.
      'direction</strong> to general advice. National kidney guidelines recommend a '.
      '<em>reduced</em>, closely supervised intake, set individually against your kidney '.
      'function — and the right figure depends on your stage, whether you are on dialysis, '.
      'and whether you also have diabetes. A general-population number could do real harm here.</p>'.
      '<p>So we are not going to guess. Please ask your kidney team or a renal dietitian '.
      'for your personal target. That is the right answer for you, and it is not something '.
      'a public calculator can safely replace.</p>'.
      '<p>If you were told you have reduced kidney function but have never had it followed up, '.
      'that is worth raising at your next appointment.</p>',
  ],
];
