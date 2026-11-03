"""ORC-06 - Daily Calorie Target reference (P3-55, FR3-47, FR3-28), written from the owner-signed method sheet
(04 Health Tools/Stage 3/P3-55-Daily-Calorie-Target-Method-Sheet.md, decisions D3-01 to D3-06), not from the engine.
Exact decimal arithmetic, so rounding ties are decided exactly.

  RMR      = 10 kg + 6.25 cm - 5 age + 5 (male) / - 161 (female and "prefer not to say", D3-03), unrounded
  TDEE     = RMR x factor (low 1.2, some 1.375, active 1.55, very active 1.725), unrounded
  Rate     = the highest of 0.25, 0.5, 0.75, 1.0 that is <= the rate asked for and <= min(1.0, round2(1 % of weight))
             (P3-56 cap, owner decision Owner_Questions 62) and <= 0.5 from age 65
  Loss     : raw = TDEE - 1100 x rate; target = round10(max(raw, floor)); floor 1,200 (F, PNTS) / 1,500 (M);
             floored -> achievable = round2((TDEE - floor) / 1100); TDEE <= floor -> maintenance only, target
             round10(TDEE), achievable 0; BMI < 18.5 or eating "prefer not to say" -> maintenance only, no raw
  Gain     : only below BMI 25 with eating "no"; rate 0.25 or 0.5; raw = TDEE + 1100 x rate; no floor
  Maintain : target = round10(TDEE)
  Stops    : age < 18; pregnant yes / not sure; eating yes. Own target below the floor: refused.
  Macros   : fat = round(kcal x 0.3 / 9); carbs = round((kcal - 4 protein - 9 fat) / 4); fibre 30 g

Reads fixtures/calorie-target/*.json (inputs only), writes oracles/orc-06-calorie-target/results.json with the
expected values it derives, and prints every fixture whose recorded expected differs. Fixtures are never written
here (oracle-guard).
Run: python -I oracles/orc-06-calorie-target/cal_ref.py [repo-root]
"""
from __future__ import annotations
import hashlib, json, platform, sys
from datetime import datetime, timezone
from decimal import Decimal as D, ROUND_HALF_UP
from pathlib import Path

FACTORS = {"low": D("1.2"), "some": D("1.375"), "active": D("1.55"), "very active": D("1.725")}
RATES = [D("0.25"), D("0.5"), D("0.75"), D("1.0")]
KCAL = D("1100")
IN_CM, LB_KG = D("2.54"), D("0.45359237")


def q(x: D, places: str) -> D:
    return x.quantize(D(places), rounding=ROUND_HALF_UP)


def r10(x: D) -> int:
    return int(q(x / 10, "1")) * 10


def num(v) -> D | None:
    return None if v is None else D(str(v))


def sex_of(v: str) -> str:
    v = (v or "").strip().lower()
    if v in ("f", "female"): return "F"
    if v in ("m", "male"): return "M"
    return "PNTS"


def height(i: dict) -> D:
    if i.get("heightCm") is not None: return D(str(i["heightCm"]))
    h = i["heightFtIn"]; return (D(str(h["ft"])) * 12 + D(str(h["in"]))) * IN_CM


def weight(i: dict) -> D:
    if i.get("weightKg") is not None: return D(str(i["weightKg"]))
    if i.get("weightStLb") is not None:
        w = i["weightStLb"]; return (D(str(w["st"])) * 14 + D(str(w["lb"]))) * LB_KG
    return D(str(i["weightLb"])) * LB_KG


def offered(requested: D, kg: D, over65: bool) -> D:
    cap = min(D("1.0"), q(kg / 100, "0.01"))
    if over65: cap = min(cap, D("0.5"))
    limit = min(requested, cap)
    out = D("0.25")
    for r in RATES:
        if r <= limit: out = r
    return out


def assess(i: dict) -> dict:
    out = {"stop": None, "rmr": None, "tdee": None, "raw": None, "targetKcal": None, "achievableRate": None, "notes": []}
    sex = sex_of(i.get("sex", ""))
    floor = D("1500") if sex == "M" else D("1200")
    age = int(i["age"])
    pregnant = (i.get("pregnant") or "no").lower()
    eating = (i.get("eatingDisorder") or "no").lower()
    if age < 18: out["stop"] = "under18"; return out
    if pregnant != "no": out["stop"] = "pregnancy"; return out
    if eating == "yes": out["stop"] = "eating"; return out
    if i.get("memberTarget") is not None:
        if D(str(i["memberTarget"])) < floor:
            out.update({"refused": True, "notes": ["floor"]}); return out
        out["targetKcal"] = int(q(D(str(i["memberTarget"])), "1")); return out
    h, w = height(i), weight(i)
    rmr = 10 * w + D("6.25") * h - 5 * age + (D("5") if sex == "M" else D("-161"))
    tdee = rmr * FACTORS[i["activity"].replace("_", " ")]
    bmi = w / ((h / 100) ** 2)
    out["rmr"], out["tdee"] = float(q(rmr, "0.01")), float(q(tdee, "0.01"))
    underweight = bmi < D("18.5")
    maint_only = eating == "pnts" or underweight
    over65 = age >= 65
    goal = i.get("goal", "maintain")
    if goal == "gain" and (bmi >= 25 or eating != "no"):
        goal, maint_only = "maintain", True
    if goal == "lose":
        rate = offered(num(i.get("rateKgWeek")) or D("0.5"), w, over65)
        if maint_only:
            out["targetKcal"] = r10(tdee); out["maintenanceOnly"] = True
            out["notes"].append("underweight" if underweight else "eating_not_said")
        elif tdee <= floor:
            out["raw"] = float(q(tdee - KCAL * rate, "0.01")); out["targetKcal"] = r10(tdee)
            out["achievableRate"] = 0; out["maintenanceOnly"] = True; out["notes"].append("no_loss")
        else:
            raw = tdee - KCAL * rate
            out["raw"] = float(q(raw, "0.01"))
            if raw < floor:
                out["targetKcal"] = r10(floor); out["achievableRate"] = float(q((tdee - floor) / KCAL, "0.01")); out["notes"].append("floor")
            else:
                out["targetKcal"] = r10(raw); out["achievableRate"] = float(rate)
    elif goal == "gain":
        rate = D("0.5") if (num(i.get("rateKgWeek")) or D("0.25")) >= D("0.5") else D("0.25")
        raw = tdee + KCAL * rate
        out["raw"] = float(q(raw, "0.01")); out["targetKcal"] = r10(raw)
    else:
        out["raw"] = float(q(tdee, "0.01")); out["targetKcal"] = r10(tdee)
        if maint_only:
            out["maintenanceOnly"] = True
            out["notes"].append("underweight" if underweight else "eating_not_said")
    if over65: out["notes"].append("over65")
    if sex == "PNTS": out["notes"].append("sex_not_said")
    if i.get("type2Diabetes"): out["notes"].append("diabetes")
    return out


def macros(i: dict) -> dict:
    kcal, protein = D(str(i["targetKcal"])), D(str(i["proteinG"]))
    fat = int(q(kcal * D("0.3") / 9, "1"))
    carb = int(q((kcal - 4 * protein - 9 * fat) / 4, "1"))
    return {"fatG": fat, "carbG": carb, "fibreG": 30}


def main(root: Path) -> int:
    me = Path(__file__).resolve()
    results, mismatches = [], []
    for name in ("target.json", "macros.json"):
        doc = json.loads((root / "fixtures" / "calorie-target" / name).read_text(encoding="utf-8"))
        for c in doc["fixtures"]:
            expected = macros(c["inputs"]) if name == "macros.json" else assess(c["inputs"])
            results.append({"fixture_id": c["id"], "expected": expected})
            rec = c.get("expected") or {}
            for k, v in rec.items():
                if k in expected and expected[k] != v and not (isinstance(v, (int, float)) and isinstance(expected[k], (int, float)) and abs(D(str(v)) - D(str(expected[k]))) < D("0.001")):
                    mismatches.append(f"{c['id']}.{k}: oracle {expected[k]!r} != fixture {v!r}")
    out = {
        "oracle_id": "ORC-06",
        "implementation": "Python decimal reference for the Daily Calorie Target (FR3-47, FR3-28): Mifflin-St Jeor, activity factors, the P3-56 rate cap, the 1,100 kcal per kg a week deficit, the safe minimum, rounding and the safety order, from the signed sheet of 3 November 2026",
        "versions": {"python": platform.python_version(), "sheet": "P3-55 v1.0 signed 2026-11-03"},
        "source_hash": hashlib.sha256(me.read_bytes()).hexdigest(),
        "run_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "results": results,
        "notes": ["Fixtures are read, never written; differences are printed and listed here."] + mismatches,
    }
    (me.parent / "results.json").write_text(json.dumps(out, indent=2) + "\n", encoding="utf-8")
    print(f"ORC-06: {len(results)} fixtures -> {me.parent / 'results.json'}; {len(mismatches)} difference(s)")
    for m in mismatches: print("  " + m)
    return 0


if __name__ == "__main__":
    sys.exit(main(Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).resolve().parents[2]))
