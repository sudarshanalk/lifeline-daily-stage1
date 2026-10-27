#!/usr/bin/env python3
"""ORC-02 sealed reference implementation: Lifeline Daily "Daily Protein Target", v2 (DEC-86).

SEALING RULE
    This program was written by the sealed oracle author (ACT-07) using ONLY:
      1. oracles/orc-02-protein-ref/rules-v2-DEC-86.md   (the rules; the only specification)
      2. tests/baseline/protein-cases.json               (fixture inputs; no expected values)
      3. schemas/oracle-result.schema.json               (output schema SCH-06)
    No calculator source (.html, .js, .mjs, .php), no fixtures/, no other tests/, site/ or
    evidence/ file, and no earlier protein_ref.py or results.json was opened, searched or
    consulted. Where the rules are ambiguous the most literal reading was taken and recorded
    in the "notes" array of the output.

SOURCE
    Rules: decision DEC-86, approved by the owner as clinical reviewer at HG-02 on 27 October 2026.

USAGE
    python -I protein_ref.py <cases.json> <results.json>

Standard library only. Rounding is round-half-up (floor(x + 0.5)), never Python's round().
"""

import hashlib
import json
import math
import os
import platform
import sys
from datetime import datetime, timezone

ORACLE_ID = "ORC-02"
IMPLEMENTATION = (
    "Sealed Python reference v2 from oracles/orc-02-protein-ref/rules-v2-DEC-86.md (DEC-86)"
)

CEILING = 2.0

# (name, bottom, top)
BAND_FLOOR = ("Floor", 0.83, 1.0)
BAND_MODERATE = ("Moderate", 1.0, 1.4)
BAND_VIGOROUS = ("Vigorous", 1.2, 1.6)
BAND_STRENGTH = ("Strength", 1.4, 2.0)
BAND_FATLOSS = ("Fat loss", 1.2, 1.6)
BAND_FATLOSS_TRAIN = ("Fat loss with training", 1.6, 2.0)
BAND_OLDER = ("Older", 1.0, 1.2)
BAND_OLDER_EX = ("Older, exercising", 1.2, 1.5)

PREG_ADDITION = {"1": 1.0, "2": 9.0, "3": 31.0}
BF_ADDITION = {"under6": 19.0, "6plus": 12.5}

NOTES = [
    "Rounding: round half up implemented as floor(x + 0.5) on IEEE doubles, matching JavaScript Math.round for positive numbers. No decimal-exact correction is applied, so a value that is a binary hair below .5 rounds down, as it would in JavaScript.",
    "gPerKg is rounded to 2 decimals (floor(x*100 + 0.5)/100) in both branches. In the pregnancy branch it is the UNROUNDED target divided by basisKg, then rounded to 2 decimals (the rules give the formula but not the rounding for that branch).",
    "basisKg is reported unrounded, as computed (the rules do not state any rounding for it). Floating-point noise in its last digits is therefore possible; compare it with a small tolerance.",
    "Pregnancy branch: targetG, lowG and highG are all the whole-gram rounding of 0.83 x basisKg + addition (lowG = highG = targetG). perMeal uses the same rule as the standard branch (unrounded target / meals, rounded to a whole gram) and capped is false, because the rules define perMeal and capped only under the standard outputs but the expected object lists them for every non-suppressed result.",
    "Validation precedes the suppression rules: an invalid fixture yields {\"error\": true} even if kidney, liver or plan would otherwise suppress it.",
    "Invalid-input ranges are inclusive (age 18-99, height 140-210 cm, weight 35-250 kg) and are applied after unit conversion, to the converted heightCm and weightKg, and also on the pregnancy branch (age is validated even though it is ignored there).",
    "'sex missing' is read as sex not equal to 'male' or 'female' (null, absent, empty or any other value). 'kidney missing' is read as kidney null, absent or empty; any non-empty value other than 'yes' (including 'unsure') is not suppressed on the kidney rule, so 'unsure' proceeds to a number.",
    "A missing or non-numeric age, height or weight (after conversion) is treated as invalid and gives {\"error\": true}.",
    "Height conversion: if heightFt is present (not null), heightCm = heightFt x 30.48 + heightIn x 2.54 (heightIn absent = 0); else if heightM is present, heightCm = heightM x 100; else heightCm is used. Presence of these fields decides, regardless of the 'units' or 'heightUnit' fields, which are ignored. heightFt takes priority over heightM if both are present.",
    "Weight conversion: if weightSt is present, weightKg = weightSt x 6.35029318 + weightLb x 0.45359237 (weightLb absent = 0), even when weightKg is also present. If weightSt is absent and weightKg is present, weightKg is used as given and weightLb is ignored (literal reading of 'only weightLb present and weightKg missing'); so FX-PR-UNIT-01 uses weightKg = 75, not 165.3 lb. weightLb alone is converted only when weightKg is missing.",
    "'preg' values other than 'pregnant' or 'breastfeeding' (including absent) are treated as 'no' and go to the standard branch. Sex plays no part in the calculation other than the validity check, so a male with preg set is not rejected.",
    "trimester and babyAge are compared as strings (so the number 3 equals '3'). A pregnant fixture whose trimester is not '1', '2' or '3', or a breastfeeding fixture whose babyAge is not 'under6' or '6plus', is treated as missing and gives {\"error\": true}.",
    "Exercising (for the Older bands) = resistance 'yes', or activity 'moderate' or 'vigorous'. 'Older' (age >= 65, not exercising) and 'Older, exercising' (age >= 65, exercising) are therefore mutually exclusive.",
    "Band selection: the winner is the applicable band with the highest top; a tie on top goes to the higher bottom; the winner's own bottom and top are used (no blending across bands). Goals 'maintain' and 'ageing' add no band. Diet and ethnicity are never used.",
    "Ceiling: capped is true only if the winning band's top is above 2.0. No band in the table exceeds 2.0, so capped is false in every standard result; the code still clamps top (and bottom) to 2.0 defensively. A band at exactly 2.0 is not capped.",
    "Suppressed results carry only {suppressed: true, reason}; they have no branch, numeric fields, basisKg or capped. Non-suppressed results carry suppressed: false and branch. Invalid results carry only {error: true}.",
    "Suppression order is kidney 'yes' (renal), then liver 'yes' (clinical), then plan 'yes' (plan); the first that applies wins. Suppression comes before the pregnancy branch.",
    "Each result's notes is the fixture's purpose string, as instructed. Every fixture of every file in the cases file is processed, in file order then fixture order.",
]


def rhu(x):
    """Round half up to a whole number (positive values)."""
    return int(math.floor(x + 0.5))


def rhu2(x):
    """Round half up to 2 decimals."""
    return math.floor(x * 100.0 + 0.5) / 100.0


def present(v):
    return v is not None and v != ""


def num(v):
    """Return float or None if missing / non-numeric."""
    if isinstance(v, bool) or not present(v):
        return None
    try:
        f = float(v)
    except (TypeError, ValueError):
        return None
    if math.isnan(f) or math.isinf(f):
        return None
    return f


def resolve_height(inp):
    if present(inp.get("heightFt")):
        ft = num(inp.get("heightFt"))
        inch = num(inp.get("heightIn")) if present(inp.get("heightIn")) else 0.0
        if ft is None or inch is None:
            return None
        return ft * 30.48 + inch * 2.54
    if present(inp.get("heightM")):
        m = num(inp.get("heightM"))
        return None if m is None else m * 100.0
    return num(inp.get("heightCm"))


def resolve_weight(inp):
    if present(inp.get("weightSt")):
        st = num(inp.get("weightSt"))
        lb = num(inp.get("weightLb")) if present(inp.get("weightLb")) else 0.0
        if st is None or lb is None:
            return None
        return st * 6.35029318 + lb * 0.45359237
    if present(inp.get("weightKg")):
        return num(inp.get("weightKg"))
    if present(inp.get("weightLb")):
        lb = num(inp.get("weightLb"))
        return None if lb is None else lb * 0.45359237
    return None


def compute(inp):
    age = num(inp.get("age"))
    height = resolve_height(inp)
    weight = resolve_weight(inp)
    sex = inp.get("sex")
    kidney = inp.get("kidney")
    preg = inp.get("preg")
    meals = num(inp.get("meals"))

    # ---- invalid input ----
    if age is None or not (18 <= age <= 99):
        return {"error": True}
    if height is None or not (140 <= height <= 210):
        return {"error": True}
    if weight is None or not (35 <= weight <= 250):
        return {"error": True}
    if sex not in ("male", "female"):
        return {"error": True}
    if not present(kidney):
        return {"error": True}
    trimester = None
    baby = None
    if preg == "pregnant":
        trimester = str(inp.get("trimester")) if present(inp.get("trimester")) else None
        if trimester not in PREG_ADDITION:
            return {"error": True}
    elif preg == "breastfeeding":
        baby = str(inp.get("babyAge")) if present(inp.get("babyAge")) else None
        if baby not in BF_ADDITION:
            return {"error": True}
    if meals is None or meals <= 0:
        return {"error": True}

    # ---- suppression, in order ----
    if kidney == "yes":
        return {"suppressed": True, "reason": "renal"}
    if inp.get("liver") == "yes":
        return {"suppressed": True, "reason": "clinical"}
    if inp.get("plan") == "yes":
        return {"suppressed": True, "reason": "plan"}

    # ---- basis weight ----
    w_ref = 24.9 * (height / 100.0) ** 2
    if weight > w_ref:
        basis = w_ref + 0.4 * (weight - w_ref)
    else:
        basis = weight

    # ---- pregnancy / breastfeeding branch ----
    if preg in ("pregnant", "breastfeeding"):
        addition = PREG_ADDITION[trimester] if preg == "pregnant" else BF_ADDITION[baby]
        target = 0.83 * basis + addition
        t = rhu(target)
        return {
            "suppressed": False,
            "branch": "pregnancy",
            "targetG": t,
            "lowG": t,
            "highG": t,
            "gPerKg": rhu2(target / basis),
            "basisKg": basis,
            "perMeal": rhu(target / meals),
            "capped": False,
        }

    # ---- standard branch ----
    activity = inp.get("activity")
    resistance = inp.get("resistance")
    goal = inp.get("goal")
    bands = []
    if activity in ("sedentary", "light"):
        bands.append(BAND_FLOOR)
    if activity == "moderate":
        bands.append(BAND_MODERATE)
    if activity == "vigorous":
        bands.append(BAND_VIGOROUS)
    if resistance == "yes" or goal == "build":
        bands.append(BAND_STRENGTH)
    if goal == "fatloss" and resistance == "no":
        bands.append(BAND_FATLOSS)
    if goal == "fatloss" and resistance == "yes":
        bands.append(BAND_FATLOSS_TRAIN)
    exercising = resistance == "yes" or activity in ("moderate", "vigorous")
    if age >= 65 and not exercising:
        bands.append(BAND_OLDER)
    if age >= 65 and exercising:
        bands.append(BAND_OLDER_EX)
    if not bands:
        # activity value outside the allowed set: no band applies, so the input is unusable
        return {"error": True}

    winner = max(bands, key=lambda b: (b[2], b[1]))
    bottom, top = winner[1], winner[2]
    capped = top > CEILING
    if capped:
        top = CEILING
        if bottom > top:
            bottom = top

    mid = (bottom + top) / 2.0
    target = mid * basis
    return {
        "suppressed": False,
        "branch": "standard",
        "targetG": rhu(target),
        "lowG": rhu(bottom * basis),
        "highG": rhu(top * basis),
        "gPerKg": rhu2(mid),
        "basisKg": basis,
        "perMeal": rhu(target / meals),
        "capped": capped,
    }


def main(argv):
    if len(argv) != 3:
        sys.stderr.write("usage: python protein_ref.py <cases.json> <results.json>\n")
        return 2
    cases_path, results_path = argv[1], argv[2]
    with open(cases_path, "r", encoding="utf-8") as fh:
        cases = json.load(fh)

    results = []
    for _fname, spec in cases.get("files", {}).items():
        for fx in spec.get("fixtures", []):
            results.append(
                {
                    "fixture_id": fx["id"],
                    "expected": compute(fx.get("inputs", {})),
                    "notes": fx.get("purpose", ""),
                }
            )

    with open(os.path.abspath(__file__), "rb") as fh:
        source_hash = hashlib.sha256(fh.read()).hexdigest()

    out = {
        "oracle_id": ORACLE_ID,
        "implementation": IMPLEMENTATION,
        "versions": {"python": platform.python_version()},
        "source_hash": source_hash,
        "run_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "results": results,
        "notes": NOTES,
    }
    with open(results_path, "w", encoding="utf-8", newline="\n") as fh:
        json.dump(out, fh, indent=2)
        fh.write("\n")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
