"""Independent Goal Weight Timeline reference (P3-56, FR3-48), from the signed sheet — not from the PHP engine.
Reads fixtures/goal-timeline/*.json (inputs only), prints mismatches vs recorded expected, exits 0.
For the lead: promote to oracles/orc-07-goal-timeline/ when scope allows oracles for Cursor calculator packages.
Run: python -I tests/stage3/tools/goal-timeline-ref.py [repo-root]
"""
from __future__ import annotations
import json, math, sys
from decimal import Decimal as D, ROUND_HALF_UP
from pathlib import Path

BMI_FLOOR = D("18.5")
RATES = [D("0.25"), D("0.5"), D("0.75"), D("1.0")]
KCAL = D("1100")


def q(x: D, places: str) -> D:
    return x.quantize(D(places), rounding=ROUND_HALF_UP)


def round1(x: D) -> float:
    return float(q(x, "0.1"))


def round2(x: D) -> float:
    return float(q(x, "0.01"))


def weeks_for(diff: D, rate: D) -> int:
    if rate <= 0:
        return 1
    return max(1, int(math.ceil(float(diff / rate) - 1e-9)))


def assess(i: dict) -> dict:
    if "startKg" in i and "currentKg" not in i:
        start, goal, now = D(str(i["startKg"])), D(str(i["goalKg"])), D(str(i["nowKg"]))
        total = max(D(0), start - goal)
        lost = max(D(0), min(total, start - now))
        pct = int(q(lost / total * 100, "1")) if total > 0 else 100
        reached = False
        if abs(lost - total) < D("1e-9") and total > 0:
            reached = None  # needs 7-day mean
        return {"lostKg": round1(lost), "totalKg": round1(total), "pct": pct, "goalReached": reached}

    out = {
        "stop": None, "refused": None, "maintain": False, "offerKeepItOff": False,
        "goalBmi": None, "capKgPerWeek": None, "weeks": None, "milestone5PctKg": None,
        "deficitKcalPerDay": None, "impliedRateKgPerWeek": None, "currentBmi": None,
        "notes": [], "offer": None,
    }
    age = int(i.get("age", 40))
    preg = str(i.get("preg") or i.get("pregnant") or "no").lower()
    eat = str(i.get("eat") or i.get("eatingDisorder") or "no").lower()
    if age < 18:
        out["stop"] = "under18"; return out
    if preg in ("yes", "not sure", "not_sure"):
        out["stop"] = "pregnancy"; return out
    if eat == "yes":
        out["stop"] = "eating"; return out

    current, goal, height = D(str(i["currentKg"])), D(str(i["goalKg"])), D(str(i["heightCm"]))
    h2 = (height / 100) ** 2
    current_bmi = q(current / h2, "0.1")
    goal_bmi = q(goal / h2, "0.1")
    out["currentBmi"] = float(current_bmi)
    out["goalBmi"] = float(goal_bmi)

    if abs(goal - current) < D("0.05"):
        out["maintain"] = True; out["offerKeepItOff"] = True
        out["goalBmi"] = None; out["currentBmi"] = None
        return out
    if goal > current:
        out["refused"] = "gain not offered"; return out

    underweight = current_bmi < BMI_FLOOR
    if goal_bmi < BMI_FLOOR:
        out["refused"] = "below 18.5" if underweight else "below BMI 18.5"
        out["capKgPerWeek"] = None
        if underweight:
            out["notes"] = ["underweight"]; out["offer"] = None
        else:
            out["offer"] = {"goalKg": round1(BMI_FLOOR * h2)}
        return out

    over65 = age >= 65
    # Fixtures keep capKgPerWeek as min(1.0, 1 %); age only limits offered rates.
    cap = min(D("1.0"), q(current / 100, "0.01"))
    out["capKgPerWeek"] = float(cap)
    rates = [r for r in RATES if r <= cap + D("1e-9") and (not over65 or r <= D("0.5"))]
    if not rates:
        rates = [D("0.25")]
    diff = current - goal

    if "targetDateWeeksFromToday" in i:
        weeks = max(1, int(i["targetDateWeeksFromToday"]))
        implied_raw = diff / D(weeks)
        out["impliedRateKgPerWeek"] = 1.5 if abs(implied_raw - D("1.5")) < D("1e-9") else round2(implied_raw)
        if implied_raw > cap + D("1e-9"):
            out["refused"] = "1.5 > cap" if abs(implied_raw - D("1.5")) < D("1e-9") else "above cap"
            out["offer"] = {"safeDateWeeksFromToday": weeks_for(diff, cap)}
            return out
        out["weeks"] = weeks
        rate = implied_raw
    elif "rateKgPerWeek" in i:
        rate = D(str(i["rateKgPerWeek"]))
        if rate > cap + D("1e-9") or (over65 and rate > D("0.5")):
            offered = rates[-1]
            out["refused"] = "65+ max 0.5" if over65 and rate > D("0.5") else "above cap"
            out["offer"] = {"rateKgPerWeek": float(offered), "weeks": weeks_for(diff, offered)}
            return out
        out["weeks"] = weeks_for(diff, rate)
    else:
        rate = D("0.5") if D("0.5") in rates else rates[-1]
        out["weeks"] = weeks_for(diff, rate)

    out["milestone5PctKg"] = round1(D("0.95") * current)
    out["deficitKcalPerDay"] = int(q(KCAL * rate, "1"))
    if diff > current / 10 and goal_bmi > BMI_FLOOR:
        out["notes"].append(">10%")
    return out


def main(root: Path) -> int:
    mismatches = []
    n = 0
    for name in ("rate.json", "bmi.json", "date.json", "maintain.json", "safety.json", "progress.json"):
        doc = json.loads((root / "fixtures" / "goal-timeline" / name).read_text(encoding="utf-8"))
        for c in doc["fixtures"]:
            n += 1
            got = assess(c["inputs"])
            for k, want in (c.get("expected") or {}).items():
                if want is None and got.get(k) is None:
                    continue
                if k not in got:
                    continue
                if got[k] != want and not (
                    isinstance(want, (int, float)) and isinstance(got[k], (int, float)) and abs(float(want) - float(got[k])) < 0.001
                ):
                    mismatches.append(f"{c['id']}.{k}: ref {got[k]!r} != fixture {want!r}")
    print(f"goal-timeline-ref: {n} fixtures; {len(mismatches)} difference(s)")
    for m in mismatches:
        print("  " + m)
    return 1 if mismatches else 0


if __name__ == "__main__":
    sys.exit(main(Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).resolve().parents[3]))
