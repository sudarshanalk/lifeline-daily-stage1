# Daily Protein Target v2: rules for the ORC-02 reference implementation

Source: decision DEC-86, approved by the owner as clinical reviewer at HG-02 on 27 October 2026. This file is the only description of the rules you may use.

## Inputs (one fixture = one person)

| Field | Values |
|---|---|
| age | years, integer |
| sex | "male" / "female" |
| preg | "no" / "pregnant" / "breastfeeding" |
| trimester | "1" / "2" / "3" (only when preg = "pregnant") |
| babyAge | "under6" / "6plus" (only when preg = "breastfeeding") |
| heightCm, weightKg | metric height and weight |
| heightFt + heightIn, heightM | when present, height comes from these instead: heightCm = heightFt × 30.48 + heightIn × 2.54, or heightM × 100 |
| weightSt + weightLb | when weightSt is present, weightKg = weightSt × 6.35029318 + weightLb × 0.45359237; when only weightLb is present and weightKg is missing, weightKg = weightLb × 0.45359237 |
| ethnicity | free text such as "White", "Indian", "Black African" |
| activity | "sedentary" / "light" / "moderate" / "vigorous" |
| resistance | "yes" (strength training at least twice a week) / "no" |
| goal | "maintain" / "fatloss" / "build" / "ageing" |
| diet | "omnivore" / "vegetarian" / "vegan" |
| meals | 2 to 5 |
| kidney | "no" / "yes" / "unsure" |
| liver | "no" / "yes" (liver disease, or recovery from a serious illness, injury or surgery) |
| plan | "no" / "yes" (following a diet or protein plan from a doctor or dietitian) |

**Invalid input (no result):** age outside 18–99, height outside 140–210 cm, weight outside 35–250 kg, sex missing, kidney missing, pregnant without a trimester, or breastfeeding without a baby age. Output `{"error": true}`.

## Basis weight (everyone)

Let W = 24.9 × (heightCm / 100)². If weightKg > W, basisKg = W + 0.4 × (weightKg − W); otherwise basisKg = weightKg. Ethnicity never changes basisKg.

## Order of rules

1. kidney = "yes" → no number. `suppressed: true`, `reason: "renal"`.
2. liver = "yes" → no number. `suppressed: true`, `reason: "clinical"`.
3. plan = "yes" → no number. `suppressed: true`, `reason: "plan"`.
4. Pregnant or breastfeeding → own branch (`branch: "pregnancy"`): target (g/day) = 0.83 × basisKg + addition. Additions: pregnant trimester 1 → 1, trimester 2 → 9, trimester 3 → 31; breastfeeding baby under 6 months → 19, 6 months or older → 12.5. Activity, strength training, goal, age and diet are ignored. lowG = highG = targetG. gPerKg = target ÷ basisKg.
5. Everyone else (`branch: "standard"`): collect the bands that apply, then use **one winning band**: the one with the highest top; if two have the same top, the one with the higher bottom. Use the winner's own bottom and top.

## Bands (g per kg of basisKg per day)

| Band | Bottom–top | Applies when |
|---|---|---|
| Floor | 0.83–1.0 | activity is "sedentary" or "light" |
| Moderate | 1.0–1.4 | activity is "moderate" |
| Vigorous | 1.2–1.6 | activity is "vigorous" |
| Strength | 1.4–2.0 | resistance = "yes", or goal = "build" |
| Fat loss | 1.2–1.6 | goal = "fatloss" and resistance = "no" |
| Fat loss with training | 1.6–2.0 | goal = "fatloss" and resistance = "yes" |
| Older | 1.0–1.2 | age ≥ 65 and not exercising |
| Older, exercising | 1.2–1.5 | age ≥ 65 and exercising (resistance = "yes", or activity "moderate" or "vigorous") |

Exactly one activity band always applies. The goal "maintain" and the goal "ageing" add no band of their own. Diet never changes anything.

No band may exceed 2.0 g/kg (the ceiling). `capped` is true only if the winning band's top was above 2.0.

## Outputs (standard branch)

- gPerKg = (bottom + top) ÷ 2, rounded to 2 decimals.
- lowG = bottom × basisKg, highG = top × basisKg, targetG = (bottom + top) ÷ 2 × basisKg, each rounded to a whole gram.
- perMeal = unrounded target ÷ meals, rounded to a whole gram. No minimum or maximum per meal.

Rounding: round half up (2.5 → 3), as in JavaScript's `Math.round` for positive numbers. Python's `round` rounds half to even, so do not use it for this.

## What to produce

For every fixture: `fixture_id` and `expected` = `{suppressed, reason (when suppressed), branch (when not), targetG, lowG, highG, gPerKg, basisKg, perMeal, capped}`. Suppressed fixtures carry no numeric fields. Invalid fixtures carry `{"error": true}`.
