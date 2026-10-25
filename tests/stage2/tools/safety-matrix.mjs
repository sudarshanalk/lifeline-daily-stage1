/**
 * Launch rows from the safety matrix. Each row names one tag that must disappear
 * and, where the row excludes nothing, one tag that must stay available.
 */
export const matrixRows = [
  { name: 'unknown', profile: null, hidden: ['weight-loss'], visible: '' },
  { name: 'none', profile: { conditions_answered: 'none', conditions: '' }, hidden: [], visible: 'weight-loss' },
  { name: 'kidney', profile: { conditions_answered: 'declared', conditions: ['kidney'] }, hidden: ['high-protein'], visible: 'weight-loss' },
  { name: 'pregnancy', profile: { conditions_answered: 'declared', conditions: ['pregnancy'] }, hidden: ['weight-loss'], visible: 'high-protein', track: true },
  { name: 'eating-disorder', profile: { conditions_answered: 'declared', conditions: ['eating-disorder'] }, hidden: ['calorie-mention'], visible: 'high-protein', track: true },
  { name: 'diabetes', profile: { conditions_answered: 'declared', conditions: ['type-2-diabetes'] }, hidden: ['fasting'], visible: 'weight-loss' },
  { name: 'prediabetes', profile: { conditions_answered: 'declared', conditions: ['prediabetes'] }, hidden: [], visible: 'fasting' },
  { name: 'blood-pressure', profile: { conditions_answered: 'declared', conditions: ['high-blood-pressure'] }, hidden: ['high-salt'], visible: 'weight-loss' },
  { name: 'cholesterol', profile: { conditions_answered: 'declared', conditions: ['high-cholesterol'] }, hidden: ['high-saturated-fat'], visible: 'weight-loss' },
  { name: 'combined', profile: { conditions_answered: 'declared', conditions: ['pregnancy', 'kidney'] }, hidden: ['weight-loss', 'high-protein'], visible: '' },
];
