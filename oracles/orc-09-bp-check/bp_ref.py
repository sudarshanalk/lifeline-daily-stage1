"""ORC-09 - Blood Pressure Reading Check reference (P3-58, FR3-50, FR3-38, FR3-46), written from the owner-signed method
sheet (04 Health Tools/Stage 3/P3-58-Blood-Pressure-Reading-Check-Method-Sheet.md, decisions D3-16 to D3-21), not
from the engine or the safety matrix code. Exact fractions, so rounding ties are decided exactly.

  Rounding : every reading and pulse to whole numbers, half-up, before any level or category
  Answers  : pregnant yes / not sure (or true / "true" / 1) is pregnant; dizzy and medicine are yes only for yes /
             true / "true" / 1 / "on", so "no" and "false" never count as yes
  Refused  : systolic outside 60-260, diastolic outside 30-160, diastolic not at least 10 below systolic, pulse
             outside 30-220 (sheet 4.4)
  Urgent   : not pregnant, systolic >= 180 or diastolic >= 120 on any single reading (4.1), shown first
  Pregnancy: yes or not sure; >= 160 or >= 110 urgent; >= 140 or >= 90 same day; else the neutral line; no category
             (4.2, D3-19)
  Office   : Grade 2 >= 160 or >= 100; Grade 1 >= 140 or >= 90; High-normal >= 130 or >= 85; else Normal (1)
  Low      : systolic < 90 or diastolic < 60 -> the low note (3, 4.3, D3-17); with the dizzy tick, the dizzy line
  Pulse    : < 40 or > 120 -> the pulse note (4.3, D3-20); never changes the category
  Home     : the 7 calendar days ending today; daily means first, then the mean of the days, at least 3 days (D3-21),
             rounded half-up; well above >= 155 or >= 95; above >= 135 or >= 85; else within (3, 5, D3-18). Any single
             reading at the urgent level -> the urgent wording, the average shown with it (BP-32)
  80 plus  : age 80 or over -> the 80-plus note after the category (BP-34)

Reads fixtures/bp-check/*.json (inputs only), writes oracles/orc-09-bp-check/results.json with the expected values it
derives, and prints every fixture whose recorded expected differs. Fixtures are never written here (oracle-guard).
Run: python -I oracles/orc-09-bp-check/bp_ref.py [repo-root]
"""
from __future__ import annotations
import hashlib, json, platform, sys
from datetime import date, datetime, timedelta, timezone
from fractions import Fraction as F
from pathlib import Path


def half_up(x: F) -> int:
    # Readings are positive: floor(x + 1/2)
    return int((x + F(1, 2)) // 1)


def whole(v) -> F:
    return F(half_up(F(str(v))))


def truthy(v) -> bool:
    if isinstance(v, bool): return v
    return str(v).strip().lower() in ("yes", "true", "1", "on") if v is not None else False


def is_pregnant(v) -> bool:
    if isinstance(v, bool): return v
    t = str(v if v is not None else "no").strip().lower().replace(" ", "").replace("-", "").replace("_", "")
    return t in ("yes", "notsure", "true", "1")


def impossible(s, d, pulse=None) -> bool:
    if not (60 <= s <= 260 and 30 <= d <= 160): return True
    if d > s - 10: return True
    return pulse is not None and not (30 <= pulse <= 220)


def office(s, d) -> str:
    if s >= 160 or d >= 100: return "grade2"
    if s >= 140 or d >= 90: return "grade1"
    if s >= 130 or d >= 85: return "high-normal"
    return "normal"


def home(s, d) -> str:
    if s >= 155 or d >= 95: return "well-above"
    if s >= 135 or d >= 85: return "above"
    return "within"


def preg_level(s, d) -> str:
    if s >= 160 or d >= 110: return "pregnancy_urgent"
    if s >= 140 or d >= 90: return "pregnancy_same_day"
    return "pregnancy_neutral"


def is_low(s, d) -> bool:
    return s < 90 or d < 60


def result(refused=False, category=None, level="none", urgent=False, keys=(), average=None, unrounded=None):
    out = {"refused": refused, "category": category, "warning_level": level, "urgent": urgent, "wording_keys": list(keys),
           "average": average, "average_shown": average is not None}
    if unrounded is not None:
        out["average_unrounded"] = unrounded
    return out


def single(i: dict) -> dict:
    s, d = whole(i["systolic"]), whole(i["diastolic"])
    pulse = whole(i["pulse"]) if i.get("pulse") is not None else None
    pregnant = is_pregnant(i.get("pregnant", "no"))
    dizzy = truthy(i.get("dizzy"))
    age80 = i.get("age") is not None and int(i["age"]) >= 80
    if impossible(s, d, pulse):
        return result(refused=True, level="refused", keys=["refused.impossible"])
    keys, level, urgent, category = [], "none", False, None
    if pregnant:
        level = preg_level(s, d)
        urgent = level == "pregnancy_urgent"
        keys.append({"pregnancy_urgent": "pregnancy.urgent", "pregnancy_same_day": "pregnancy.same-day", "pregnancy_neutral": "pregnancy.neutral"}[level])
        if level == "pregnancy_neutral" and is_low(s, d) and dizzy:
            level = "low_dizzy"; keys.append("low.dizzy")
    else:
        category = office(s, d)
        if s >= 180 or d >= 120:
            urgent, level = True, "urgent"
            keys.append("urgent.alert")
        low = is_low(s, d) and not urgent
        if category == "normal" and low:
            keys += ["category.normal-low.headline", "category.normal-low.body"]
        else:
            keys += [f"category.{category}.headline", f"category.{category}.body"]
            if low: keys.append("category.normal-low.body")
        if low:
            level = "low_dizzy" if dizzy else "low_note"
            if dizzy: keys.append("low.dizzy")
    if pulse is not None and (pulse < 40 or pulse > 120):
        keys.append("pulse.note")
        if level in ("none", "pregnancy_neutral"): level = "pulse_high" if pulse > 120 else "pulse_low"
    if age80 and not pregnant:
        keys.append("age80.note")
        if level == "none": level = "age80_note"
    if truthy(i.get("medicine")):
        keys.append("medicine.note")
    return result(category=category, level=level, urgent=urgent, keys=keys)


def seven_day(i: dict) -> dict:
    today = date.fromisoformat(i["today"])
    first = today - timedelta(days=6)
    pregnant = is_pregnant(i.get("pregnant", "no"))
    by_day: dict[str, list] = {}
    any_urgent, worst_preg = False, "pregnancy_neutral"
    order = ["pregnancy_neutral", "pregnancy_same_day", "pregnancy_urgent"]
    for r in i["readings"]:
        day = date.fromisoformat(r["date"][:10])
        if not (first <= day <= today): continue
        s, d = whole(r["systolic"]), whole(r["diastolic"])
        if impossible(s, d): continue
        by_day.setdefault(day.isoformat(), []).append((s, d))
        if s >= 180 or d >= 120: any_urgent = True
        p = preg_level(s, d)
        if order.index(p) > order.index(worst_preg): worst_preg = p
    average = unrounded = None
    if len(by_day) >= 3:
        means = [(sum(x[0] for x in v) / len(v), sum(x[1] for x in v) / len(v)) for v in by_day.values()]
        rs, rd = sum(m[0] for m in means) / len(means), sum(m[1] for m in means) / len(means)
        average = {"systolic": half_up(rs), "diastolic": half_up(rd)}
        unrounded = {"systolic": round(float(rs), 3), "diastolic": round(float(rd), 3)}
    keys, level, urgent, category = [], "none", False, None
    if pregnant:
        level = worst_preg
        if average is not None and order.index(preg_level(average["systolic"], average["diastolic"])) > order.index(level):
            level = preg_level(average["systolic"], average["diastolic"])
        urgent = level == "pregnancy_urgent"
        keys.append({"pregnancy_urgent": "pregnancy.urgent", "pregnancy_same_day": "pregnancy.same-day", "pregnancy_neutral": "pregnancy.neutral"}[level])
        if level == "pregnancy_neutral":
            if average is not None and is_low(average["systolic"], average["diastolic"]) and truthy(i.get("dizzy")):
                level = "low_dizzy"; keys.append("low.dizzy")
    elif any_urgent:
        urgent, level = True, "urgent"
        keys.append("urgent.alert")
    elif average is not None:
        category = home(average["systolic"], average["diastolic"])
        keys += [f"home.{category}.headline", f"home.{category}.body"]
        if is_low(average["systolic"], average["diastolic"]):
            keys.append("category.normal-low.body")
            level = "low_dizzy" if truthy(i.get("dizzy")) else "low_note"
            if truthy(i.get("dizzy")): keys.append("low.dizzy")
    if average is None:
        keys.append("home.min-days")
    if i.get("age") is not None and int(i["age"]) >= 80 and not pregnant:
        keys.append("age80.note")
        if level == "none": level = "age80_note"
    if truthy(i.get("medicine")):
        keys.append("medicine.note")
    return result(category=category, level=level, urgent=urgent, keys=keys, average=average, unrounded=unrounded)


def same(a, b) -> bool:
    # A recorded unrounded average is given to two or three decimals: equal at the recorded precision.
    if isinstance(a, dict) and isinstance(b, dict):
        return a.keys() == b.keys() and all(same(a[k], b[k]) for k in a)
    if isinstance(a, float) or isinstance(b, float):
        return isinstance(a, (int, float)) and isinstance(b, (int, float)) and abs(a - b) < 0.005
    return a == b


def assess(i: dict) -> dict:
    return seven_day(i) if i.get("mode") == "7-day" else single(i)


def main(root: Path) -> int:
    me = Path(__file__).resolve()
    results, mismatches = [], []
    for path in sorted((root / "fixtures" / "bp-check").glob("*.json")):
        doc = json.loads(path.read_text(encoding="utf-8"))
        for c in doc["fixtures"]:
            expected = assess(c["inputs"])
            results.append({"fixture_id": c["id"], "expected": expected})
            rec = c.get("expected") or {}
            for k, v in rec.items():
                if k == "average" and v is None and rec.get("average_shown"):
                    continue  # BP-32: the average is shown, but the sheet does not state its value
                if k in expected and not same(expected[k], v):
                    mismatches.append(f"{c['id']}.{k}: oracle {expected[k]!r} != fixture {v!r}")
    out = {
        "oracle_id": "ORC-09",
        "implementation": "Python exact-fraction reference for the Blood Pressure Reading Check (FR3-50, FR3-38, FR3-46): ISH 2020 office categories, the home-average categories and the 7-day average of daily means, the urgent, pregnancy, low and pulse levels and refused readings, from the signed sheet of 3 November 2026",
        "versions": {"python": platform.python_version(), "sheet": "P3-58 v1.0 signed 2026-11-03"},
        "source_hash": hashlib.sha256(me.read_bytes()).hexdigest(),
        "run_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "results": results,
        "notes": ["Fixtures are read, never written; differences are printed and listed here."] + mismatches,
    }
    (me.parent / "results.json").write_text(json.dumps(out, indent=2) + "\n", encoding="utf-8")
    print(f"ORC-09: {len(results)} fixtures -> {me.parent / 'results.json'}; {len(mismatches)} difference(s)")
    for m in mismatches: print("  " + m)
    return 0


if __name__ == "__main__":
    sys.exit(main(Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).resolve().parents[2]))
