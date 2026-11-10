// Fixture arrays for the Healthy Weight screen tests (P3-35, package P4), in the shapes the parallel packages return:
// P2 LLD_Members_HW_Programme::plan_view() and ::today(), P3 LLD_Members_HW_Content::question() and ::block(). The
// question wording is the approved HW-P3-35-11 (10 Nov 2026) and the blocks' titles the approved focus block names;
// every number is made up. Not a test file itself.

const choice = (pairs) => pairs.map(([value, label]) => ({ value, label }));

export const questions = {
  activity_level: { text: 'How active are you in a normal week?', why: 'This decides how many calories your body uses each day.', choices: choice([['low', 'Mostly sitting; little planned exercise'], ['some', 'Light activity or exercise 1 to 3 days a week'], ['active', 'Moderate exercise 3 to 5 days a week'], ['very_active', 'Hard exercise 6 to 7 days a week']]) },
  bmi_background: { text: 'Your family background', why: 'Some backgrounds have health risks at a lower BMI, so this sets which ranges we use.', choices: [] },
  reason: { text: "Is there something you're aiming for?", why: "Your reason keeps you going on the hard days. We'll show it on your Today screen.", choices: choice([['health', 'My health'], ['confidence', 'Feeling more confident'], ['family', 'Keeping up with my family'], ['wedding', 'A wedding'], ['holiday', 'A holiday'], ['event', 'Another special event'], ['other', 'Something else']]), follow_up: { key: 'event_date', text: 'When is it?', type: 'date', when: ['wedding', 'holiday', 'event'] } },
  barrier: { text: 'What usually gets in the way?', why: "We'll put the steps that help with this first.", choices: choice([['evening_snacking', 'Snacking in the evening'], ['stress_eating', 'Eating when stressed'], ['eating_out', 'Eating out and takeaways'], ['no_time', 'Not having time'], ['alcohol', 'Drinking'], ['not_sure', 'Not sure']]) },
  time: { text: 'How much time do you have for your plan on a normal day?', why: 'So each day\'s step fits the time you really have.', choices: choice([['under_10', 'Under 10 minutes'], ['10_30', '10 to 30 minutes'], ['over_30', 'Over 30 minutes']]) },
  work: { text: 'How do you work?', why: 'So your steps and reminders fit your day.', choices: choice([['days', 'Regular days, including at home or not working'], ['shifts', 'Shifts'], ['nights', 'Nights'], ['irregular', 'Hours that change a lot']]) },
  wake_time: { text: 'When do you usually wake up from your main sleep?', why: 'So reminders arrive after you wake, never while you sleep.', type: 'time' },
  confidence: { text: 'How confident do you feel about making changes?', why: 'So we suggest a pace you can keep.', type: 'scale', min: 1, max: 10, low: '1 = not at all confident', high: '10 = very confident' },
  sleep: { text: 'How long do you usually sleep?', why: 'Short sleep makes us hungrier, so it shapes your plan from day one.', choices: choice([['under_6', 'Under 6 hours'], ['6_7', '6 to 7 hours'], ['7_9', '7 to 9 hours'], ['over_9', 'Over 9 hours']]) },
  stress: { text: 'How stressed have you felt over the last two weeks?', why: "Stress can lead to eating when we're not hungry, so it shapes your plan.", type: 'scale', min: 1, max: 10, low: '1 = very calm', high: '10 = extremely stressed' },
  alcohol_freq: { text: 'How often do you have an alcoholic drink?', why: 'Drinks count towards your calorie target, and they affect appetite and sleep.', choices: choice([['never', 'Never'], ['less_than_weekly', 'Less than once a week'], ['1_2_days', '1 to 2 days a week'], ['3_4_days', '3 to 4 days a week'], ['most_days', 'Most days']]) },
  alcohol_amount: { text: 'On a day you drink, how many drinks do you have?', why: 'So we can show what your usual drinks add to your week.', note: 'One drink = a glass of wine · a bottle of beer · a single spirit', choices: choice([['1_2', '1 to 2'], ['3_4', '3 to 4'], ['5_plus', '5 or more']]) },
  eating_pattern: { text: 'Do you usually eat breakfast?', why: 'So we skip steps you already do.', choices: choice([['most_days', 'Most days'], ['some_days', 'Some days'], ['rarely', 'Rarely']]) },
  snack_time: { text: 'When do you usually snack?', why: 'So a gentle reminder can come before your usual snack time.', choices: choice([['10:30', 'Mid-morning'], ['15:30', 'Afternoon'], ['18:00', 'Early evening'], ['20:00', 'After my evening meal'], ['after 9 pm', 'Late, after 9 pm'], ['none', 'I hardly snack']]) },
  sugary_drinks: { text: 'How often do you have sugary drinks?', why: "If you rarely have them, we'll skip the drink-swap steps.", choices: choice([['never', 'Never'], ['less_than_weekly', 'Less than once a week']]) },
};

export const blocksContent = {
  'HW-FB01': { title: 'Evening and night eating' },
  'HW-FB02': { title: 'Stress and emotional eating' },
  'HW-FB04': { title: 'Breaking a plateau' },
  'HW-FB07': { title: 'Getting stronger' },
  'HW-FB08': { title: 'Festivals and fasting' },
  'HW-MS-03': 'A weight in this range is linked with a lower risk of type 2 diabetes, high blood pressure and heart disease.',
  'HW-MS-03-older': 'At 65 and over, keeping your muscle strength and staying active matter more than a number.',
  'HW-MS-04': "Keeping your weight steady from here helps you hold on to the health benefits you've gained.",
  urgent_help: 'If you are struggling or feel unsafe, find urgent help in your country at findahelpline.com. If you are in danger now, call your local emergency number.',
};

/** A plan view for a 45-year-old woman, 163 cm, 80 kg, goal 60 kg (the prototype's example member A). */
export const planView = {
  where: { bmi: 30.1, category: 'obese1', adjusted: false, range: { lowKg: 49.1, highKg: 66.2 }, waist: { ratio: 0.6, band: 'high' } },
  activity: 'active',
  tdee: 2134,
  target_kcal: 1310,
  pace: { recommended: 0.75, chosen: 0.75, rule: 'R-HW-PACE-01', reasons: [{ key: 'hw.T-HW-PACE-01.reason.faster', values: {} }], paces: [0.25, 0.5, 0.75] },
  drinks: { weekly: 290, daily: 40 },
  milestones: [
    { kind: '5pct', kg: 76, date: '2026-12-01' },
    { kind: '10pct', kg: 72, date: '2027-01-05' },
    { kind: 'healthy', kg: 66.2, date: '2027-02-23' },
    { kind: 'goal', kg: 60, date: '2027-04-13' },
  ],
  cards: [],
  reasons: [{ rule: 'R-HW-STEP-04', template: 'hw.T-HW-STEP-04' }, { rule: 'R-HW-NUM-05', template: 'hw.T-HW-NUM-05', values: { target: '1,310', weekly: '290', daily: '40' } }],
};

export const todayView = {
  note: { key: 'HW-MN-W1-3', title: 'Small beats big', body: 'Big changes feel exciting on day one and exhausting by day ten.\n\nSmall ones are easy to repeat.', try_this: 'Try this today: shrink one goal until it feels almost too easy, then do it.', support: false },
  step: { activity_key: 'HW1-W1-A3', version: 'standard', reason: { template: 'hw.T-HW-STEP-01', values: { barrier: 'evening snacking' } } },
  qod: ['eating_pattern', 'snack_time', 'sugary_drinks'],
  drink_shortcut: { show: true, label: 'Add a drink', from: '18:00' },
  repeat_meal: null,
  reason_line: { template: 'hw.T-HW-MOT-01', values: { reason: 'your health', weeks: 20 } },
};

export const activities = { 'HW1-W1-A3': { title: 'Notice your hunger', text: 'Before you eat, rate your hunger from 1 to 10.' } };
