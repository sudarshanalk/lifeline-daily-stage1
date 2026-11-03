"""ORC-11 - Fitness Age reference (P3-5A part 1, FR3-52), written from the owner-signed method sheet
(04 Health Tools/Stage 3/P3-5A-Fitness-Age-HR-Zones-Sleep-Stress-Method-Sheet.md, decisions D3-26, D3-27, D3-32),
not from the engine. Exact decimal arithmetic, so rounding ties are decided exactly.

  VO2 men   = 100.27 - 0.296 age - 0.369 waist - 0.155 RHR + 0.226 PA
  VO2 women = 74.736 - 0.247 age - 0.259 waist - 0.114 RHR + 0.198 PA      (Nes 2011 as reproduced in JAHA 2020)
  PA index  = frequency (never 0, < once 0, once 1, 2-3 times 2.5, almost daily 5)
              x intensity (easy 1, sweating 2, near exhaustion 3) x duration (< 15 min 0.10, 15-29 0.38, 30-60 0.75,
              > 1 h 1.0); frequency 0 -> 0
  Fitness age (D3-26): (VO2_ref(0) - VO2) / b_age, VO2_ref(0) = intercept - b_waist ref_waist - b_RHR 70 + b_PA 3.75,
              reference waist 94 cm men / 80 cm women; shown = round half-up to whole years, clamped 18..90
  VO2 1 dp half-up; raw fitness age 1 dp half-up; waist in inches x 2.54
  Stops     : age < 18; pregnant yes / not sure; heart condition; heart-rate medicine yes / not sure; sex not said
  Notes     : clampedLow / clampedHigh; RHR > 100 safety.rhrOver100; RHR < 40 safety.rhrUnder40; age 18-19 age18to19

Reads fixtures/fitness-age/*.json (inputs only), writes oracles/orc-11-fitness-age/results.json and prints every
fixture whose recorded expected differs. Fixtures are never written here (oracle-guard).
Run: python -I oracles/orc-11-fitness-age/fitness_age_ref.py [repo-root]
"""
from __future__ import annotations
import hashlib, json, platform, re, sys
from datetime import datetime, timezone
from decimal import Decimal as D, ROUND_HALF_UP
from pathlib import Path

COEF = {
    "M": dict(c=D("100.27"), age=D("0.296"), waist=D("0.369"), rhr=D("0.155"), pa=D("0.226"), ref_waist=D("94")),
    "F": dict(c=D("74.736"), age=D("0.247"), waist=D("0.259"), rhr=D("0.114"), pa=D("0.198"), ref_waist=D("80")),
}
FREQ = {"never": D("0"), "less than once a week": D("0"), "once a week": D("1"), "2-3 times a week": D("2.5"), "almost every day": D("5")}
HOW_HARD = {"easy, no sweating": D("1"), "sweating, heavier breathing": D("2"), "near exhaustion": D("3")}
HOW_LONG = {"under 15 min": D("0.10"), "15-29 min": D("0.38"), "30-60 min": D("0.75"), "over 1 hour": D("1.0")}


def q(x: D, places: str) -> D:
    return x.quantize(D(places), rounding=ROUND_HALF_UP)


def norm(s):
    return None if s is None else re.sub(r"\s+", " ", str(s).replace("–", "-").strip().lower())


def assess(i: dict) -> dict:
    out = {"stop": None, "paIndex": None, "vo2": None, "fitnessAgeRaw": None, "fitnessAge": None, "notes": [], "clamped": None}
    age = D(str(i["age"]))
    sex = {"male": "M", "m": "M", "female": "F", "f": "F"}.get(norm(i.get("sex")), "PNTS")
    if age < 18:
        out["stop"] = "under18"; return out
    if norm(i.get("pregnant") or "no") != "no":
        out["stop"] = "pregnancy"; return out
    if norm(i.get("heartCondition") or "no") != "no":
        out["stop"] = "heartCondition"; out["notes"] = ["safety.heartCondition"]; return out
    if norm(i.get("heartMedicine") or "no") != "no":
        out["stop"] = "medicine"; out["notes"] = ["safety.medicine"]; return out
    if sex == "PNTS":
        out["stop"] = "sexNotSaid"; return out
    waist = D(str(i["waistCm"])) if i.get("waistCm") is not None else D(str(i["waistIn"])) * D("2.54")
    rhr = D(str(i["rhr"]))
    f = FREQ[norm(i["frequency"])]
    pa = D("0") if f == 0 else f * HOW_HARD[norm(i["intensity"])] * HOW_LONG[norm(i["duration"])]
    k = COEF[sex]
    vo2 = k["c"] - k["age"] * age - k["waist"] * waist - k["rhr"] * rhr + k["pa"] * pa
    ref0 = k["c"] - k["waist"] * k["ref_waist"] - k["rhr"] * 70 + k["pa"] * D("3.75")
    raw = (ref0 - vo2) / k["age"]
    shown = int(q(raw, "1"))
    out["paIndex"] = float(q(pa, "0.01"))
    out["vo2"] = float(q(vo2, "0.1"))
    out["fitnessAgeRaw"] = float(q(raw, "0.1"))
    if shown < 18:
        shown, out["clamped"] = 18, "low"; out["notes"].append("clampedLow")
    elif shown > 90:
        shown, out["clamped"] = 90, "high"; out["notes"].append("clampedHigh")
    out["fitnessAge"] = shown
    if rhr > 100:
        out["notes"].append("safety.rhrOver100")
    elif rhr < 40:
        out["notes"].append("safety.rhrUnder40")
    if age < 20:
        out["notes"].append("age18to19")
    return out


def main(root: Path) -> int:
    me = Path(__file__).resolve()
    results, mismatches = [], []
    for path in sorted((root / "fixtures" / "fitness-age").glob("*.json")):
        for c in json.loads(path.read_text(encoding="utf-8"))["fixtures"]:
            expected = assess(c["inputs"])
            results.append({"fixture_id": c["id"], "expected": expected})
            for k, v in (c.get("expected") or {}).items():
                if k in expected and expected[k] != v:
                    mismatches.append(f"{c['id']}.{k}: oracle {expected[k]!r} != fixture {v!r}")
    out = {
        "oracle_id": "ORC-11",
        "implementation": "Python decimal reference for Fitness Age (FR3-52): the HUNT non-exercise VO2peak model (Nes 2011, JAHA 2020 reproduction), the HUNT activity index, the reference-person fitness age with its clamp, rounding and the safety order, from the signed sheet of 3 November 2026",
        "versions": {"python": platform.python_version(), "sheet": "P3-5A v1.0 signed 2026-11-03"},
        "source_hash": hashlib.sha256(me.read_bytes()).hexdigest(),
        "run_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "results": results,
        "notes": ["Fixtures are read, never written; differences are printed and listed here."] + mismatches,
    }
    (me.parent / "results.json").write_text(json.dumps(out, indent=2) + "\n", encoding="utf-8")
    print(f"ORC-11: {len(results)} fixtures -> {me.parent / 'results.json'}; {len(mismatches)} difference(s)")
    for m in mismatches:
        print("  " + m)
    return 0


if __name__ == "__main__":
    sys.exit(main(Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).resolve().parents[2]))
