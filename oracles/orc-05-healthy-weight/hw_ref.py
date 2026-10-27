"""ORC-05 — Healthy Weight Check reference (requirements FR2-39, decisions D50, D52, D55), written from the requirement,
not from the calculator's code. Exact decimal arithmetic, so rounding ties are decided exactly.

  BMI      = weight kg / (height m)^2, rounded half-up to 1 dp; the category follows the rounded value (D55).
  Standard  : <18.5 underweight · <25 healthy · <30 overweight · <35 obese1 · <40 obese2 · else obese3
  Adjusted  : <18.5 underweight · <23 healthy · <27.5 overweight · <32.5 obese1 · <37.5 obese2 · else obese3
              (South Asian, Chinese, other Asian, Middle Eastern, Black African, African-Caribbean; Mixed + "yes")
  WHtR     = waist / height, rounded half-up to 2 dp: not shown at BMI >= 35.0 · <0.40 below (no band) ·
             <0.50 healthy · <0.60 increased · else high
  Range    = every weight whose shown BMI is healthy (BMI 18.45 up to, not including, 24.95 or 22.95), rounded inwards
             to 0.1 kg and to whole pounds.
  Order    : validation of age, pregnancy and eating answers; stops (under 18, pregnancy yes/not sure, eating yes);
             then validation of the measurements and remaining answers; then the result.

Reads the hand-written test inputs in oracles/orc-05-healthy-weight/cases.json, writes fixtures/healthy-weight/<group>.json
with the expected values (oracle "clinical") and oracles/orc-05-healthy-weight/results.json. Only the oracle runner commits
the fixture files (oracle-guard).
Run: python -I oracles/orc-05-healthy-weight/hw_ref.py [repo-root]
"""
from __future__ import annotations
import hashlib, json, platform, sys
from datetime import datetime, timezone
from decimal import Decimal as D, ROUND_HALF_UP, ROUND_CEILING
from pathlib import Path

ADJUSTED = {"Indian", "Pakistani", "Bangladeshi", "OtherSouthAsian", "Chinese", "OtherAsian", "MiddleEastern", "BlackAfrican", "BlackCaribbean"}
CUTS = {False: [D("18.5"), D("25"), D("30"), D("35"), D("40")], True: [D("18.5"), D("23"), D("27.5"), D("32.5"), D("37.5")]}
NAMES = ["underweight", "healthy", "overweight", "obese1", "obese2", "obese3"]
IN_CM, FT_CM, LB_KG, ST_KG = D("2.54"), D("30.48"), D("0.45359237"), D("6.35029318")


def q(x: D, places: str) -> D:
    return x.quantize(D(places), rounding=ROUND_HALF_UP)


def ceil_int(x: D) -> int:
    return int(x.to_integral_value(rounding=ROUND_CEILING))


def num(v):
    return None if v is None else D(str(v))


def assess(i: dict) -> dict:
    errors = []
    age = num(i.get("age"))
    if age is None or age < 1 or age != age.to_integral_value():
        errors.append("age"); age = None
    elif age > 99:
        errors.append("age"); age = None
    if not i.get("preg"): errors.append("preg")
    if not i.get("eat"): errors.append("eat")
    if age is not None and i.get("preg") and i.get("eat"):
        if age < 18: return {"stop": "under18"}
        if i["preg"] in ("yes", "notsure"): return {"stop": "pregnancy"}
        if i["eat"] == "yes": return {"stop": "eating"}
    ht = num(i.get("heightCm")) if i.get("heightFt") is None else num(i["heightFt"]) * FT_CM + num(i.get("heightIn", 0)) * IN_CM
    wt = num(i.get("weightKg")) if i.get("weightSt") is None else num(i["weightSt"]) * ST_KG + num(i.get("weightLb", 0)) * LB_KG
    waist = num(i.get("waistCm")) if i.get("waistIn") is None else num(i["waistIn"]) * IN_CM
    if ht is None or not (120 <= ht <= 230): errors.append("ht")
    if wt is None or not (25 <= wt <= 300): errors.append("wt")
    if waist is not None and not (50 <= waist <= 200): errors.append("waist")
    if not i.get("bg"): errors.append("bg")
    if i.get("bg") == "Mixed" and not i.get("mixed"): errors.append("mixed")
    if not i.get("loss"): errors.append("loss")
    if errors:
        return {"errors": errors}
    adj = i["bg"] in ADJUSTED or (i["bg"] == "Mixed" and i.get("mixed") == "yes")
    bmi = q(wt * 10000 / (ht * ht), "0.1")
    k = 0
    while k < 5 and bmi >= CUTS[adj][k]: k += 1
    cat = NAMES[k]
    m2 = (ht / 100) ** 2
    lo, hi = D("18.45") * m2, (D("22.95") if adj else D("24.95")) * m2
    rng = {"lowKg": float(D(ceil_int(lo * 10)) / 10), "highKg": float(D(ceil_int(hi * 10) - 1) / 10),
           "lowLb": ceil_int(lo / LB_KG), "highLb": ceil_int(hi / LB_KG) - 1}
    w = None
    if waist is not None:
        r = q(waist / ht, "0.01")
        band = "notShown" if bmi >= 35 else "below" if r < D("0.40") else "healthy" if r < D("0.50") else "increased" if r < D("0.60") else "high"
        w = {"ratio": float(r), "band": band}
    notes = []
    if cat == "underweight": notes.append("underweight")
    if cat == "obese3": notes.append("class3")
    if age >= 65: notes.append("older")
    loss = i["loss"] == "yes"
    return {"stop": None, "bmi": float(bmi), "category": cat, "adjusted": adj, "range": rng, "waist": w,
            "weightLoss": loss, "notes": notes, "onward": (not loss) and cat != "underweight"}


def main(root: Path) -> int:
    me = Path(__file__).resolve()
    cases = json.loads((me.parent / "cases.json").read_text(encoding="utf-8"))["cases"]
    out_dir = root / "fixtures" / "healthy-weight"
    out_dir.mkdir(parents=True, exist_ok=True)
    results = []
    for group, items in cases.items():
        doc = {"group": group, "tool": "healthy-weight", "fixtures": []}
        for c in items:
            expected = assess(c["inputs"])
            doc["fixtures"].append({"id": c["id"], "purpose": c["purpose"], "inputs": c["inputs"], "expected": expected, "oracle": "clinical", "status": "recorded"})
            results.append({"fixture_id": c["id"], "expected": expected})
        (out_dir / f"{group.lower()}.json").write_text(json.dumps(doc, indent=2) + "\n", encoding="utf-8")
    out = {
        "oracle_id": "ORC-05",
        "implementation": "Python decimal reference for the Healthy Weight Check (FR2-39): NICE NG246 BMI categories with lower thresholds for listed family backgrounds, waist-to-height ratio bands, healthy weight range, rounding (D55) and the safety order (D52)",
        "versions": {"python": platform.python_version()},
        "source_hash": hashlib.sha256(me.read_bytes()).hexdigest(),
        "run_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "results": results,
        "notes": ["Written from requirements FR2-39, not from the calculator's code; exact decimal arithmetic with half-up rounding."],
    }
    (me.parent / "results.json").write_text(json.dumps(out, indent=2) + "\n", encoding="utf-8")
    print(f"ORC-05: {len(results)} fixtures -> {me.parent / 'results.json'}")
    return 0


if __name__ == "__main__":
    sys.exit(main(Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[2]))
