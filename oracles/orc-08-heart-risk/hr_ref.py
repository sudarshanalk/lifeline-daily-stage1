"""ORC-08 - Heart Attack and Stroke Risk Check reference (P3-57, FR3-49), written from the owner-signed method sheet
(04 Health Tools/Stage 3/P3-57-Heart-Attack-Stroke-Risk-Method-Sheet.md, decisions D3-10 to D3-15), not from the
engine. Exact decimal arithmetic, so rounding ties are decided exactly.

  Stops    : age < 40 (under40); age > 74 (over74); existing CVD yes (cvd); pregnant yes / not sure (pregnancy);
             sex "prefer not to say" cannot calculate (sex, D3-11). No number with any stop.
  Region   : `region` as given (the sweep), else the country's GBD region from who2019-country-to-region.json
  Model    : total cholesterol given -> laboratory chart; "don't know" -> non-laboratory (BMI) chart (D3-15)
  Bands    : age 40-44 ... 70-74; SBP (whole mmHg, half-up) <120, 120-139, 140-159, 160-179, >=180;
             TC mmol/L (mg/dL / 38.67 unrounded, then 1 dp half-up) <4, 4-4.9, 5-5.9, 6-6.9, >=7;
             BMI (1 dp half-up) <20, 20-24, 25-29, 30-35, >=35 (35.0 is >=35)
  Result   : the printed whole number in that cell of who2019-chart-cells.json; band on that number (D3-13):
             <5 low, 5-9 moderate, 10-19 high, >=20 very-high. Urgent: SBP >= 180.
  What-if  : smoker -> non-smoker; SBP band above <120 -> the band below; lab and TC band above <4 -> the band below;
             non-lab and BMI band above 20-24 -> the band below, hidden with an eating-disorder answer.
             Ranked by the fall (current - new), ties smoking, blood pressure, cholesterol, weight.

Reads fixtures/heart-risk/*.json (inputs only; the two who2019-* files are the data), writes
oracles/orc-08-heart-risk/results.json with the expected values it derives, and prints every fixture whose recorded
expected differs. Fixtures are never written here (oracle-guard).
Run: python -I oracles/orc-08-heart-risk/hr_ref.py [repo-root]
"""
from __future__ import annotations
import hashlib, json, platform, sys
from datetime import datetime, timezone
from decimal import Decimal as D, ROUND_HALF_UP
from pathlib import Path

AGES = ["40-44", "45-49", "50-54", "55-59", "60-64", "65-69", "70-74"]
SBP = [(D(120), "<120"), (D(140), "120-139"), (D(160), "140-159"), (D(180), "160-179"), (None, ">=180")]
TC = [(D(4), "<4"), (D(5), "4-4.9"), (D(6), "5-5.9"), (D(7), "6-6.9"), (None, ">=7")]
BMI = [(D(20), "<20"), (D(25), "20-24"), (D(30), "25-29"), (D(35), "30-35"), (None, ">=35")]
TIE = ["smoking", "blood-pressure", "cholesterol", "weight"]


def q(x: D, places: str) -> D:
    return x.quantize(D(places), rounding=ROUND_HALF_UP)


def band_index(value: D, bands) -> int:
    """Index of the band whose upper (exclusive) limit is the first one above the value."""
    for i, (limit, _) in enumerate(bands):
        if limit is None or value < limit:
            return i
    raise AssertionError


def yes(v) -> bool:
    return str(v).strip().lower() in ("yes", "y", "true", "1")


def risk_band(r: int) -> str:
    return "low" if r < 5 else "moderate" if r < 10 else "high" if r < 20 else "very-high"


def assess(i: dict, charts: dict, countries: dict) -> dict:
    age = int(i["age"])
    sex = str(i["sex"]).strip().lower()
    if age < 40: return {"stop": "under40"}
    if age > 74: return {"stop": "over74"}
    if yes(i.get("cvd", "no")): return {"stop": "cvd"}
    if str(i.get("preg", "no")).strip().lower() != "no": return {"stop": "pregnancy"}
    if sex not in ("male", "female", "m", "f"): return {"stop": "sex"}
    region = i.get("region") or countries[i["country"]]["gbd_region"]
    men = sex in ("male", "m")
    smoker = yes(i["smoke"])
    sbp = q(D(str(i["sbp"])), "1")
    tc = None
    if i.get("tc") is not None:
        tc = D(str(i["tc"]))
        if (i.get("tcUnit") or "mmol/L").lower() == "mg/dl":
            tc = tc / D("38.67")
        tc = q(tc, "0.1")
    bmi = q(D(str(i["bmi"])), "0.1") if i.get("bmi") is not None else None
    lab = tc is not None

    age_b = AGES[(age - 40) // 5]
    sbp_i = band_index(sbp, SBP)
    third = band_index(tc, TC) if lab else band_index(bmi, BMI)
    third_bands = TC if lab else BMI

    def cell_value(smk: bool, si: int, ti: int) -> int:
        node = charts[region]["laboratory"]["yes" if yes(i["diabetes"]) else "no"] if lab else charts[region]["non-laboratory"]["n/a"]
        return node["men" if men else "women"]["smoker" if smk else "non-smoker"][age_b][SBP[si][1]][third_bands[ti][1]]

    risk = cell_value(smoker, sbp_i, third)
    cell = {"sex": "men" if men else "women", "smoking": "smoker" if smoker else "non-smoker", "age": age_b, "sbp": SBP[sbp_i][1]}
    if lab:
        cell = {"diabetes": "yes" if yes(i["diabetes"]) else "no", **cell, "tc": TC[third][1]}
    else:
        cell["bmi"] = BMI[third][1]

    options = []
    if smoker:
        options.append(("smoking", "non-smoker", cell_value(False, sbp_i, third)))
    if sbp_i > 0:
        options.append(("blood-pressure", SBP[sbp_i - 1][1], cell_value(smoker, sbp_i - 1, third)))
    hidden = False
    if lab and third > 0:
        options.append(("cholesterol", TC[third - 1][1], cell_value(smoker, sbp_i, third - 1)))
    if not lab and third > 1:
        if str(i.get("eat", "no")).strip().lower() != "no":
            hidden = True
        else:
            options.append(("weight", BMI[third - 1][1], cell_value(smoker, sbp_i, third - 1)))
    options.sort(key=lambda o: (-(risk - o[2]), TIE.index(o[0])))
    whatif = [{"factor": f, "to": to, "risk": r, "fall": risk - r} for f, to, r in options]

    return {
        "stop": None, "model": "lab" if lab else "nolab", "region": region, "cell": cell,
        "risk": risk, "band": risk_band(risk), "urgent": sbp >= 180,
        "tcMmolL": float(tc) if lab else None, "bmiShown": float(bmi) if bmi is not None else None,
        "whatif": whatif, "first": whatif[0]["factor"] if whatif else None, "weightWhatifHidden": hidden,
    }


def main(root: Path) -> int:
    me = Path(__file__).resolve()
    fx = root / "fixtures" / "heart-risk"
    charts = json.loads((fx / "who2019-chart-cells.json").read_text(encoding="utf-8"))["charts"]
    countries = json.loads((fx / "who2019-country-to-region.json").read_text(encoding="utf-8"))["countries"]
    results, mismatches = [], []
    for path in sorted(fx.glob("*.json")):
        if path.name.startswith("who2019-"):
            continue
        doc = json.loads(path.read_text(encoding="utf-8"))
        for c in doc["fixtures"]:
            expected = assess(c["inputs"], charts, countries)
            results.append({"fixture_id": c["id"], "expected": expected})
            for k, v in (c.get("expected") or {}).items():
                if k in expected and expected[k] != v:
                    mismatches.append(f"{c['id']}.{k}: oracle {expected[k]!r} != fixture {v!r}")
    out = {
        "oracle_id": "ORC-08",
        "implementation": "Python decimal reference for the Heart Attack and Stroke Risk Check (FR3-49): WHO 2019 chart look-up (D3-10 = B), cell boundaries and rounding, mg/dL conversion, the safety stops, the urgent level and the adjacent-cell what-if ranking, from the signed sheet of 3 November 2026",
        "versions": {"python": platform.python_version(), "sheet": "P3-57 v1.0 signed 2026-11-03"},
        "source_hash": hashlib.sha256(me.read_bytes()).hexdigest(),
        "run_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "results": results,
        "notes": ["Fixtures are read, never written; differences are printed and listed here."] + mismatches,
    }
    (me.parent / "results.json").write_text(json.dumps(out, indent=2) + "\n", encoding="utf-8")
    print(f"ORC-08: {len(results)} fixtures -> {me.parent / 'results.json'}; {len(mismatches)} difference(s)")
    for m in mismatches: print("  " + m)
    return 0


if __name__ == "__main__":
    sys.exit(main(Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).resolve().parents[2]))
