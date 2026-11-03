"""ORC-10 - Metabolic Health Check reference (P3-59, FR3-51, FR3-45), written from the owner-signed method sheet
(04 Health Tools/Stage 3/P3-59-Metabolic-Health-Check-Method-Sheet.md, decisions D3-22 to D3-25), not from the engine.
Exact decimal comparisons, in the unit entered (no conversion before comparison).

  Central obesity : waist (cm; inches x 2.54) >= cut-off. Women and "prefer not to say" 80 cm (D3-23). Men 90 cm for
                    South Asian (Indian, Pakistani, Bangladeshi, other South Asian), Chinese, Japanese and ethnic
                    South and Central American; 94 cm for every other background.
  Criteria        : triglycerides >= 1.7 mmol/L / 150 mg/dL, or treated;
                    HDL < 1.03 mmol/L / 40 mg/dL (men), < 1.29 / 50 (women and "prefer not to say"), or treated;
                    blood pressure systolic >= 130 or diastolic >= 85, or treated;
                    fasting glucose >= 5.6 mmol/L / 100 mg/dL, or type 2 diabetes. Not given -> unknown.
  Result          : waist below -> results.notMet.waistBelow; >= 2 met -> results.meets; met + unknown >= 2 ->
                    results.partial (missing = the unknown criteria); else results.notMet.
  Flags           : BP >= 180 or >= 120 -> flags.bpUrgent; glucose >= 15 mmol/L / 270 mg/dL or < 3.0 / 54 ->
                    flags.glucoseUrgent; else >= 11.1 / 200 -> flags.glucoseDiagnostic; else < 3.9 / 70 ->
                    flags.glucoseLow; triglycerides >= 10 / 885 -> flags.triglyceridesHigh; waist below with >= 2
                    raised -> notes.talkToDoctor (sheet section 3; owner, 3 Nov 2026, Owner_Questions 90);
                    "prefer not to say" -> notes.sex.
  Stops           : age < 18 -> stops.under18; pregnant or breastfeeding yes / not sure -> stops.pregnancy.
  Lifestyle       : the waist (weight-loss) suggestion shows only with central obesity and no eating-disorder answer.

Reads fixtures/metabolic/*.json (inputs only), writes oracles/orc-10-metabolic/results.json with the expected values
it derives, and prints every fixture whose recorded expected differs. Fixtures are never written here (oracle-guard).
Run: python -I oracles/orc-10-metabolic/met_ref.py [repo-root]
"""
from __future__ import annotations
import hashlib, json, platform, sys
from datetime import datetime, timezone
from decimal import Decimal as D
from pathlib import Path

FILES = ("criteria-counts.json", "partial.json", "safety.json", "units.json")
MEN_90 = {"south asian", "indian", "pakistani", "bangladeshi", "other south asian", "chinese", "japanese",
          "ethnic south and central american", "south and central american"}
TG = {"mmol/L": D("1.7"), "mg/dL": D("150")}
HDL = {"M": {"mmol/L": D("1.03"), "mg/dL": D("40")}, "F": {"mmol/L": D("1.29"), "mg/dL": D("50")}}
FPG = {"mmol/L": D("5.6"), "mg/dL": D("100")}
G_URG_HI = {"mmol/L": D("15"), "mg/dL": D("270")}
G_URG_LO = {"mmol/L": D("3.0"), "mg/dL": D("54")}
G_DIAG = {"mmol/L": D("11.1"), "mg/dL": D("200")}
G_LOW = {"mmol/L": D("3.9"), "mg/dL": D("70")}
TG_HIGH = {"mmol/L": D("10"), "mg/dL": D("885")}


def num(v) -> D | None:
    return None if v is None or v == "" else D(str(v))


def assess(i: dict) -> dict:
    out = {"stop": None, "central": None, "criteria": None, "count": None, "result": None, "missing": [], "flags": []}
    sex = {"male": "M", "m": "M", "female": "F", "f": "F"}.get(str(i.get("sex", "")).lower(), "PNTS")
    age = num(i.get("age"))
    if age is not None and age < 18:
        out["stop"] = "stops.under18"; return out
    if str(i.get("pregnantOrBreastfeeding") or "no").lower() != "no":
        out["stop"] = "stops.pregnancy"; return out
    waist = num(i.get("waistCm"))
    if waist is None:
        waist = num(i.get("waistIn")) * D("2.54")
    bg = str(i.get("background") or "").strip().lower()
    cutoff = (D(90) if bg in MEN_90 else D(94)) if sex == "M" else D(80)
    central = waist >= cutoff

    def lab(name, table, below=False, treated=False):
        if treated: return "met"
        v = num(i.get(name))
        if v is None: return "unknown"
        t = table[i[name + "Unit"]]
        return "met" if ((v < t) if below else (v >= t)) else "not-met"

    sys_, dia = num(i.get("bpSystolic")), num(i.get("bpDiastolic"))
    crit = {
        "triglycerides": lab("triglycerides", TG, treated=bool(i.get("triglyceridesMedicine"))),
        "hdl": lab("hdl", HDL["M" if sex == "M" else "F"], below=True, treated=bool(i.get("hdlMedicine"))),
        "bloodPressure": "met" if i.get("bpMedicine") or (sys_ is not None and (sys_ >= 130 or dia >= 85)) else "not-met",
        "fastingGlucose": lab("fastingGlucose", FPG, treated=bool(i.get("type2Diabetes"))),
    }
    met = sum(1 for v in crit.values() if v == "met")
    unknown = [k for k, v in crit.items() if v == "unknown"]
    if not central:
        result = "results.notMet.waistBelow"
    elif met >= 2:
        result = "results.meets"
    elif met + len(unknown) >= 2:
        result = "results.partial"; out["missing"] = unknown
    else:
        result = "results.notMet"

    flags = []
    if sys_ is not None and (sys_ >= 180 or dia >= 120): flags.append("flags.bpUrgent")
    g = num(i.get("fastingGlucose"))
    if g is not None:
        u = i["fastingGlucoseUnit"]
        if g >= G_URG_HI[u] or g < G_URG_LO[u]: flags.append("flags.glucoseUrgent")
        elif g >= G_DIAG[u]: flags.append("flags.glucoseDiagnostic")
        elif g < G_LOW[u]: flags.append("flags.glucoseLow")
    t = num(i.get("triglycerides"))
    if t is not None and t >= TG_HIGH[i["triglyceridesUnit"]]: flags.append("flags.triglyceridesHigh")
    if not central and met >= 2: flags.append("notes.talkToDoctor")
    if sex == "PNTS": flags.append("notes.sex")

    out.update({"central": central, "criteria": crit, "count": met, "result": result, "flags": flags,
                "lifestyleWaistShown": central and str(i.get("eatingDisorder") or "no").lower() == "no"})
    return out


def main(root: Path) -> int:
    me = Path(__file__).resolve()
    results, mismatches = [], []
    for name in FILES:
        doc = json.loads((root / "fixtures" / "metabolic" / name).read_text(encoding="utf-8"))
        for c in doc["fixtures"]:
            expected = assess(c["inputs"])
            if expected["stop"] is not None:
                expected.pop("lifestyleWaistShown", None)
            results.append({"fixture_id": c["id"], "expected": expected})
            for k, v in (c.get("expected") or {}).items():
                if k in expected and expected[k] != v:
                    mismatches.append(f"{c['id']}.{k}: oracle {expected[k]!r} != fixture {v!r}")
    out = {
        "oracle_id": "ORC-10",
        "implementation": "Python decimal reference for the Metabolic Health Check (FR3-51, FR3-45): the IDF 2006 definition with background- and sex-specific waist cut-offs, the four criteria applied in the unit entered, treatment counting as met, the partial result, and the safety flags and stops, from the signed sheet of 3 November 2026",
        "versions": {"python": platform.python_version(), "sheet": "P3-59 v1.0 signed 2026-11-03"},
        "source_hash": hashlib.sha256(me.read_bytes()).hexdigest(),
        "run_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "results": results,
        "notes": ["Fixtures are read, never written; differences are printed and listed here."] + mismatches,
    }
    (me.parent / "results.json").write_text(json.dumps(out, indent=2) + "\n", encoding="utf-8")
    print(f"ORC-10: {len(results)} fixtures -> {me.parent / 'results.json'}; {len(mismatches)} difference(s)")
    for m in mismatches: print("  " + m)
    return 0


if __name__ == "__main__":
    sys.exit(main(Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).resolve().parents[2]))
