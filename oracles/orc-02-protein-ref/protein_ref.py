#!/usr/bin/env python3
"""ORC-02 sealed reference engine for the Lifeline Daily Daily Protein Target tool.

Implemented ONLY from the clinical reference document
"Daily Protein Target - Engine Logic & Technical Reference" sections 2-5
(Protein-Tool-Engine-Reference). The deployed HTML/JS/PHP engine was NOT
consulted (sealing rule). Every interpretive choice is listed in NOTES.

Usage:
    python protein_ref.py <fixtures_dir> <results.json>
"""
import glob
import hashlib
import json
import math
import os
import platform
import sys
from datetime import datetime, timezone

# ---------------------------------------------------------------------------
# §4.1 basis weight constants
# ---------------------------------------------------------------------------
ASIAN_GROUPS = {"indian", "pakistani", "bangladeshi", "other asian", "chinese"}
BMI_UPPER_ASIAN = 22.9
BMI_UPPER_STD = 24.9
OBESE_BMI = 30.0
UNDERWEIGHT_BMI = 18.5

# §4.2 diet multiplier and ceiling
DIET_MULT = {"omnivore": 1.0, "vegetarian": 1.1, "vegan": 1.2}
CEILING = 2.0

# §4.3 pregnancy / lactation RDA (own branch, on entered weight)
PREG_GKG = {"pregnant": 1.1, "breastfeeding": 1.3}

# ISSN per-meal window
MEAL_MIN, MEAL_MAX = 20, 40

# ---------------------------------------------------------------------------
# §4.2 band table, verbatim order from the document
# (name, lo, hi, source)
# ---------------------------------------------------------------------------
BAND_ACT_SED = ("Activity: sedentary", 0.8, 1.0, "IOM RDA (floor)")
BAND_ACT_LM = ("Activity: light/moderate", 1.0, 1.4, "IOM AMDR / ISSN lower")
BAND_ACT_VA = ("Activity: very active/endurance", 1.2, 1.6, "ACSM/AND/DC 2016")
BAND_RES_12 = ("Resistance 1-2/wk", 1.2, 1.6, "ISSN 2017 lower")
BAND_RES_34 = ("Resistance 3-4/wk", 1.4, 2.0, "ISSN 2017")
BAND_RES_5 = ("Resistance 5+/wk", 1.6, 2.0, "ISSN 2017")
BAND_GOAL_BUILD = ("Goal: build muscle", 1.6, 2.0, "ISSN 2017")
BAND_GOAL_FL_TR = ("Goal: fat loss + training", 1.6, 2.0, "ISSN (lean-mass retention)")
BAND_GOAL_FL_NT = ("Goal: fat loss, no training", 1.2, 1.5, "ISSN (lean-mass retention)")
BAND_AGE_NT = ("Age >=65, not training", 1.0, 1.2, "PROT-AGE / ESPEN")
BAND_AGE_TR = ("Age >=65, training/active", 1.2, 1.5, "ESPEN")
BAND_RECOV = ("Goal: recovery, or liver=yes", 1.2, 1.5, "ESPEN (illness)")

ACTIVITY_VALUES = {"sedentary", "light", "moderate", "vigorous"}
RESISTANCE_VALUES = {"0", "1-2", "3-4", "5+"}
GOAL_VALUES = {"maintain", "fatloss", "build", "ageing", "recovery"}
DIET_VALUES = set(DIET_MULT)
KIDNEY_VALUES = {"no", "yes", "unsure"}
LIVER_VALUES = {"no", "yes"}
PREG_VALUES = {"no", "pregnant", "breastfeeding"}
SEX_VALUES = {"male", "female"}

IN_PER_FT = 12
CM_PER_IN = 2.54
KG_PER_LB = 0.45359237
LB_PER_ST = 14


def round_half_up(x):
    """Round to nearest integer, halves away from zero (not banker's)."""
    return int(math.floor(x + 0.5)) if x >= 0 else -int(math.floor(-x + 0.5))


def _num(v):
    if v is None or isinstance(v, bool):
        return None
    if isinstance(v, (int, float)):
        return float(v)
    return None


def canonical_measurements(inp):
    """Convert fixture height/weight to canonical cm / kg. Returns (cm, kg) or (None, None)."""
    units = inp.get("units", "metric")
    if units == "imperial":
        ft = _num(inp.get("heightFt"))
        inch = _num(inp.get("heightIn"))
        if ft is None and inch is None:
            return None, None
        cm = ((ft or 0.0) * IN_PER_FT + (inch or 0.0)) * CM_PER_IN
        st = _num(inp.get("weightSt"))
        lb = _num(inp.get("weightLb"))
        if st is None and lb is None:
            return None, None
        kg = ((st or 0.0) * LB_PER_ST + (lb or 0.0)) * KG_PER_LB
        return cm, kg
    # metric
    if inp.get("heightUnit") == "m":
        m = _num(inp.get("heightM"))
        cm = None if m is None else m * 100.0
    else:
        cm = _num(inp.get("heightCm"))
    kg = _num(inp.get("weightKg"))
    return cm, kg


def validate(inp):
    """Return a canonical input dict, or None if the fixture is invalid (§3 ranges / required fields)."""
    required = ["age", "sex", "activity", "resistance", "goal", "diet", "meals", "kidney", "liver"]
    for k in required:
        if inp.get(k) is None:
            return None
    age = _num(inp.get("age"))
    if age is None or age < 18 or age > 99:
        return None
    cm, kg = canonical_measurements(inp)
    if cm is None or kg is None:
        return None
    if cm < 140 or cm > 210:
        return None
    if kg < 35 or kg > 250:
        return None
    meals = _num(inp.get("meals"))
    if meals is None or meals < 2 or meals > 5:
        return None
    sex = str(inp["sex"]).lower()
    activity = str(inp["activity"]).lower()
    resistance = str(inp["resistance"])
    goal = str(inp["goal"]).lower()
    diet = str(inp["diet"]).lower()
    kidney = str(inp["kidney"]).lower()
    liver = str(inp["liver"]).lower()
    preg = inp.get("preg")
    preg = "no" if preg is None else str(preg).lower()
    if sex not in SEX_VALUES or activity not in ACTIVITY_VALUES or resistance not in RESISTANCE_VALUES:
        return None
    if goal not in GOAL_VALUES or diet not in DIET_VALUES or kidney not in KIDNEY_VALUES:
        return None
    if liver not in LIVER_VALUES or preg not in PREG_VALUES:
        return None
    ethnicity = inp.get("ethnicity")
    return {
        "age": age,
        "sex": sex,
        "heightCm": cm,
        "weightKg": kg,
        "ethnicity": "" if ethnicity is None else str(ethnicity),
        "activity": activity,
        "resistance": resistance,
        "goal": goal,
        "diet": diet,
        "meals": int(meals),
        "kidney": kidney,
        "liver": liver,
        "preg": preg,
    }


def upper_bmi(ethnicity):
    return BMI_UPPER_ASIAN if ethnicity.strip().lower() in ASIAN_GROUPS else BMI_UPPER_STD


def basis_weight(weight_kg, height_cm, ethnicity):
    """§4.1: BMI<30 -> actual; BMI>=30 -> Wupper + 0.4*(actual - Wupper)."""
    h_m = height_cm / 100.0
    bmi = weight_kg / (h_m * h_m)
    w_upper = upper_bmi(ethnicity) * h_m * h_m
    if bmi >= OBESE_BMI and weight_kg > w_upper:
        return bmi, w_upper + 0.4 * (weight_kg - w_upper)
    return bmi, weight_kg


def per_meal(target_g, meals):
    """§4.2: target / meals bounded to 20-40 g, then rounded."""
    raw = target_g / meals
    return round_half_up(min(MEAL_MAX, max(MEAL_MIN, raw)))


def collect_bands(c):
    """Return every §4.2 band row that applies, in table order."""
    training = c["resistance"] != "0" or c["activity"] == "vigorous"
    bands = []
    if c["activity"] == "sedentary":
        bands.append(BAND_ACT_SED)
    elif c["activity"] in ("light", "moderate"):
        bands.append(BAND_ACT_LM)
    else:
        bands.append(BAND_ACT_VA)
    if c["resistance"] == "1-2":
        bands.append(BAND_RES_12)
    elif c["resistance"] == "3-4":
        bands.append(BAND_RES_34)
    elif c["resistance"] == "5+":
        bands.append(BAND_RES_5)
    if c["goal"] == "build":
        bands.append(BAND_GOAL_BUILD)
    elif c["goal"] == "fatloss":
        bands.append(BAND_GOAL_FL_TR if training else BAND_GOAL_FL_NT)
    if c["age"] >= 65:
        bands.append(BAND_AGE_TR if training else BAND_AGE_NT)
    if c["goal"] == "recovery" or c["liver"] == "yes":
        bands.append(BAND_RECOV)
    return bands


def highest_band(bands):
    """Highest 'hi'; tie -> highest 'lo'; still tied -> first in table order."""
    best = bands[0]
    for b in bands[1:]:
        if b[2] > best[2] or (b[2] == best[2] and b[1] > best[1]):
            best = b
    return best


def compute(inp):
    c = validate(inp)
    if c is None:
        return {"error": True}

    # P0 renal override (§5) - before everything, including pregnancy.
    if c["kidney"] == "yes":
        return {"suppressed": True, "branch": "renal"}

    renal_unsure = c["kidney"] == "unsure"
    bmi, basis = basis_weight(c["weightKg"], c["heightCm"], c["ethnicity"])
    underweight = bmi < UNDERWEIGHT_BMI
    clinical = c["liver"] == "yes" or c["goal"] == "recovery"

    # §4.3 pregnancy / lactation: own RDA on the entered weight, returned before
    # the standard path. No band stacking, no diet multiplier, no basis-weight adjustment.
    if c["sex"] == "female" and c["preg"] in PREG_GKG:
        gkg = PREG_GKG[c["preg"]]
        target = round_half_up(gkg * c["weightKg"])
        label = "Pregnancy RDA" if c["preg"] == "pregnant" else "Lactation RDA"
        return {
            "suppressed": False,
            "branch": "pregnancy",
            "targetG": target,
            "lowG": target,
            "highG": target,
            "gPerKg": round(gkg, 2),
            "basisKg": round(c["weightKg"], 1),
            "perMeal": per_meal(target, c["meals"]),
            "capped": False,
            "winningBand": label,
            "sources": ["IOM / DRI (%s)" % label],
            "renalUnsure": renal_unsure,
            "underweight": underweight,
            "clinical": clinical,
        }

    # P3 normal path: collect -> highest -> x diet -> cap 2.0 -> x basis weight.
    bands = collect_bands(c)
    win = collect_winner = highest_band(bands)
    name, lo, hi, _src = win
    mult = DIET_MULT[c["diet"]]
    lo_m, hi_m = lo * mult, hi * mult
    mid_m = (lo + hi) / 2.0 * mult
    capped = hi_m > CEILING or lo_m > CEILING or mid_m > CEILING
    lo_c, hi_c, mid_c = min(lo_m, CEILING), min(hi_m, CEILING), min(mid_m, CEILING)

    target = round_half_up(mid_c * basis)
    sources = []
    for b in bands:
        if b[3] not in sources:
            sources.append(b[3])
    return {
        "suppressed": False,
        "branch": "normal",
        "targetG": target,
        "lowG": round_half_up(lo_c * basis),
        "highG": round_half_up(hi_c * basis),
        "gPerKg": round(mid_c + 1e-9, 2),
        "basisKg": round(basis + 1e-9, 1),
        "perMeal": per_meal(target, c["meals"]),
        "capped": capped,
        "winningBand": collect_winner[0],
        "sources": sources,
        "renalUnsure": renal_unsure,
        "underweight": underweight,
        "clinical": clinical,
    }


NOTES = [
    "Target g/kg: the document says 'take the highest [band], apply the diet multiplier, cap at 2.0, then multiply by basis weight' but never says which point of the winning lo-hi band is the headline target. Chosen reading: gPerKg/targetG use the MIDPOINT of the winning band ((lo+hi)/2) x diet multiplier, capped at 2.0; lowG/highG use lo and hi x multiplier, each capped at 2.0.",
    "Highest-band tie-break: the document says only 'take the highest'. Chosen reading: highest 'hi' wins; if 'hi' ties, highest 'lo' wins; if both tie, the first row in §4.2 table order wins (e.g. Resistance 5+/wk beats Goal: build muscle at 1.6-2.0).",
    "'capped' flag: set true when any of lo, hi or midpoint x multiplier exceeds 2.0 g/kg (in practice when hi x multiplier > 2.0), i.e. the reported range was truncated by the ceiling, even if the midpoint target itself was not.",
    "Diet multiplier on the pregnancy/lactation branch: §4.3 says the branch is 'computed as their own RDA ... and returned before the standard path runs' and 'never stacked'. Chosen reading: the multiplier is NOT applied; 1.1 / 1.3 g/kg x entered weight is returned as-is (PRG-02 vegan pregnant -> 1.1 g/kg).",
    "Pregnancy branch basis weight: §4.3 says 'on pre-pregnancy weight'; the tool only has the entered weight. Chosen reading: entered weight, no BMI>=30 adjusted-body-weight substitution. basisKg reports the entered weight.",
    "Pregnancy branch low/high: the RDA is 'a minimum floor'; no range is given. Chosen reading: lowG = highG = targetG, winningBand 'Pregnancy RDA' / 'Lactation RDA', capped=false.",
    "Pregnancy branch gating: §3 lists the pregnancy input as 'if female'. Chosen reading: the branch is taken only when sex == female AND preg in {pregnant, breastfeeding}; a male fixture with preg != 'no' falls through to the normal path (no fixture exercises this).",
    "Priority: renal (kidney=yes) is P0 and is evaluated BEFORE pregnancy (REN-03 pregnant + kidney yes -> suppressed). The suppressed result contains no numeric fields at all.",
    "'Training' flag: defined here as resistance != '0' OR activity == 'vigorous' (document's 'training/active'). It drives both 'Goal: fat loss + training' vs 'no training' and 'Age >=65 training/active' vs 'not training'. So fat loss + vigorous activity with no resistance work counts as 'fat loss + training'. Light/moderate activity does NOT count as 'active' for the >=65 row.",
    "Age >=65 band is ADDED to the collection alongside the activity/resistance/goal bands (not substituted for them); the normal highest-band rule then decides. AGE-03 (65 sedentary) therefore wins with 1.0-1.2 over 0.8-1.0.",
    "Goal 'ageing' adds no band by itself (there is no such row in §4.2); the age >=65 rows are triggered by age only. Goal 'recovery' adds the ESPEN illness row 1.2-1.5, the same row as liver=yes.",
    "Ethnicity -> upper-healthy BMI: Indian, Pakistani, Bangladeshi, Other Asian, Chinese -> 22.9; every other value including 'White', 'Black', 'Mixed', 'Other' and a missing/null ethnicity -> 24.9 (standard). Ethnicity is not treated as a required field.",
    "Basis weight: adjusted body weight is used only when BMI >= 30.0 (unrounded BMI from canonical cm/kg) AND actual weight > Wupper. Wupper = upperBMI x (height_m)^2. The gram figures are computed from the UNROUNDED basis; basisKg is reported rounded to 1 dp.",
    "Rounding: targetG, lowG, highG and perMeal use round-half-up (floor(x+0.5)), not banker's rounding; gPerKg is rounded to 2 dp after a 1e-9 nudge to avoid binary float artefacts (e.g. 1.2*1.1).",
    "Per-meal split: target / meals is clamped to 20-40 g FIRST and then rounded (clamp then round). The document's order ('target / meals, bounded to 20-40 g') does not say whether the clamp precedes rounding; the two orders differ only at boundaries.",
    "Underweight (BMI < 18.5): per the P2 priority row, the engine computes on actual weight with no numeric change; only the 'underweight' flag is set. A fat-loss goal is still honoured numerically (UWT-02 gets the fat-loss, no-training band).",
    "'clinical' flag = liver == yes OR goal == recovery (the P1 CLINICAL row minus pregnancy, which has its own branch value). 'renalUnsure' = kidney == unsure; computation proceeds normally.",
    "sources[]: the Source column of EVERY applicable band row (deduplicated, table order), not just the winning row, since the payload describes it as 'guidelines applied'. The plant-diet uplift is not listed as a source because the document labels it an extrapolation, not a guideline.",
    "Validation: age outside 18-99, canonical height outside 140-210 cm, canonical weight outside 35-250 kg, meals outside 2-5, any required field null (age, sex, activity, resistance, goal, diet, meals, kidney, liver, height, weight) or any enumerated value outside its documented set -> {error: true}. 'preg' null is treated as 'no' rather than an error.",
    "Unit conversion: imperial height = (ft*12 + in) * 2.54 cm; imperial weight = (st*14 + lb) * 0.45359237 kg; heightUnit 'm' -> heightM * 100. No intermediate rounding, so UNIT-02 (14 st 6.8 lb, 5 ft 8.9 in) lands at BMI 30.04 and takes the adjusted-weight path like BMI-02.",
]


def main(argv):
    fixtures_dir = argv[1]
    out_path = argv[2]
    results = []
    counts = {"normal": 0, "pregnancy": 0, "renal": 0, "error": 0}
    for path in sorted(glob.glob(os.path.join(fixtures_dir, "*.json"))):
        with open(path, "r", encoding="utf-8") as fh:
            doc = json.load(fh)
        for fx in doc.get("fixtures", []):
            res = compute(fx["inputs"])
            if res.get("error"):
                counts["error"] += 1
            else:
                counts[res["branch"]] += 1
            results.append({"fixture_id": fx["id"], "expected": res, "notes": fx.get("purpose", "")})
    with open(os.path.abspath(__file__), "rb") as fh:
        src_hash = hashlib.sha256(fh.read()).hexdigest()
    out = {
        "oracle_id": "ORC-02",
        "implementation": "Sealed Python reference from Protein-Tool-Engine-Reference.docx §2–§5",
        "versions": {"python": platform.python_version()},
        "source_hash": src_hash,
        "run_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "results": results,
        "notes": NOTES,
    }
    with open(out_path, "w", encoding="utf-8") as fh:
        json.dump(out, fh, indent=2, ensure_ascii=False)
        fh.write("\n")
    print(json.dumps({"processed": len(results), **counts}))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
