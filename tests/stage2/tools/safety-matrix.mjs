/**
 * Launch rows of the safety matrix (requirements section 8.2). Each row lists every tag it excludes, the
 * wording it must show, and whether it hides weight-loss badges and the Healthy Weight track. Tags a row does
 * not exclude must stay available, so a row can neither hide too little nor too much.
 */
export const safetyTags = [
  'weight-loss', 'fasting', 'high-intensity', 'high-protein', 'high-salt', 'high-saturated-fat',
  'pregnancy-unsafe-food', 'alcohol', 'calorie-mention', 'portion-question',
];

const unknownTags = ['weight-loss', 'fasting', 'high-intensity', 'high-protein', 'high-salt', 'high-saturated-fat', 'pregnancy-unsafe-food', 'alcohol'];
const pregnancyTags = ['weight-loss', 'fasting', 'high-intensity', 'alcohol', 'pregnancy-unsafe-food'];
const eatingTags = ['weight-loss', 'fasting', 'calorie-mention', 'portion-question'];

const pregnancyNote = 'Talk to your midwife or doctor about weight and exercise during pregnancy';
const kidneyNote = 'Your kidney team should set your protein target';

export const matrixRows = [
  { name: 'unknown', profile: null, hidden: unknownTags, note: 'Complete your health profile for more suggestions', empty: 'Complete your health profile for more suggestions' },
  { name: 'none', profile: { conditions_answered: 'none', conditions: '' }, hidden: [], note: '', empty: 'Nothing suitable is available right now.' },
  { name: 'kidney', profile: { conditions_answered: 'declared', conditions: ['kidney'] }, hidden: ['high-protein'], note: kidneyNote, empty: kidneyNote },
  { name: 'pregnancy', profile: { conditions_answered: 'declared', conditions: ['pregnancy'] }, hidden: pregnancyTags, badges: true, track: true, note: pregnancyNote, empty: pregnancyNote },
  {
    name: 'eating-disorder',
    profile: { conditions_answered: 'declared', conditions: ['eating-disorder'] },
    hidden: eatingTags,
    badges: true,
    track: true,
    note: 'For support with eating, talk to your doctor',
    empty: 'For support with eating, talk to your doctor',
    helpline: 'https://findahelpline.com',
  },
  {
    name: 'diabetes',
    profile: { conditions_answered: 'declared', conditions: ['type-2-diabetes'] },
    hidden: ['fasting'],
    note: 'Talk to your diabetes team',
    empty: 'Talk to your diabetes team',
    exercise: 'If you take insulin or medicine that can cause low blood sugar, check with your doctor',
  },
  { name: 'prediabetes', profile: { conditions_answered: 'declared', conditions: ['prediabetes'] }, hidden: [], note: '', empty: 'Nothing suitable is available right now.' },
  {
    name: 'blood-pressure',
    profile: { conditions_answered: 'declared', conditions: ['high-blood-pressure'] },
    hidden: ['high-salt'],
    note: 'Talk to your doctor before starting intense exercise',
    empty: 'Talk to your doctor before starting intense exercise',
    intense: 'Check with your doctor first',
  },
  { name: 'cholesterol', profile: { conditions_answered: 'declared', conditions: ['high-cholesterol'] }, hidden: ['high-saturated-fat'], note: '', empty: 'Nothing suitable is available right now.' },
  {
    name: 'combined',
    profile: { conditions_answered: 'declared', conditions: ['kidney', 'pregnancy'] },
    hidden: [...new Set([...pregnancyTags, 'high-protein'])],
    badges: true,
    track: true,
    note: pregnancyNote,
    // The first applicable message in matrix order: kidney comes before pregnancy.
    empty: kidneyNote,
  },
];
