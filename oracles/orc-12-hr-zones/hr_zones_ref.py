"""ORC-12 - Heart-Rate Training Zones reference (P3-5A part 2, FR3-53), written from the owner-signed method sheet
(04 Health Tools/Stage 3/P3-5A-Fitness-Age-HR-Zones-Sleep-Stress-Method-Sheet.md, decision D3-31), not from the
engine. Exact decimal arithmetic, so rounding ties are decided exactly.

  HRmax  = round half-up (208 - 0.7 x age) (Tanaka 2001), or the measured maximum when given
  Zones  : with resting HR, RHR + f x (HRmax - RHR) (Karvonen); without, f x HRmax; each bound whole bpm half-up
           Easy 0.30-0.39 HRR / 0.57-0.63 HRmax; Moderate 0.40-0.59 / 0.64-0.76; Hard 0.60-0.89 / 0.77-0.95
  Stops  : a heart-rate-medicine, heart-condition or pregnancy answer missing -> refused (error); age < 18; pregnant; heart condition; heart-rate medicine yes / not sure (FR3-53 AS-02);
           measured HRmax less than 30 above resting -> refused

Reads fixtures/hr-zones/*.json (inputs only), writes oracles/orc-12-hr-zones/results.json and prints every fixture
whose recorded expected differs. Fixtures are never written here (oracle-guard).
Run: python -I oracles/orc-12-hr-zones/hr_zones_ref.py [repo-root]
"""
from __future__ import annotations
import hashlib, json, platform, sys
from datetime import datetime, timezone
from decimal import Decimal as D, ROUND_HALF_UP
from pathlib import Path

HRR = {"easy": ("0.30", "0.39"), "moderate": ("0.40", "0.59"), "hard": ("0.60", "0.89")}
PCT = {"easy": ("0.57", "0.63"), "moderate": ("0.64", "0.76"), "hard": ("0.77", "0.95")}


def r0(x: D) -> int:
    return int(x.quantize(D("1"), rounding=ROUND_HALF_UP))


def assess(i: dict) -> dict:
    out = {"stop": None, "hrMax": None, "method": None, "easy": None, "moderate": None, "hard": None}
    age = D(str(i["age"]))
    rhr = None if i.get("rhr") is None else D(str(i["rhr"]))
    measured = None if i.get("measuredHrMax") is None else D(str(i["measuredHrMax"]))
    for field in ("heartMedicine", "heartCondition", "pregnant"):
        if i.get(field) is None:
            return {"error": field}
    if age < 18:
        out["stop"] = "under18"; return out
    if i["pregnant"] != "no":
        out["stop"] = "pregnancy"; out["notes"] = ["safety.pregnancy"]; return out
    if i["heartCondition"] != "no":
        out["stop"] = "heartCondition"; out["notes"] = ["safety.heartCondition"]; return out
    if i["heartMedicine"] != "no":
        out["stop"] = "medicine"; out["notes"] = ["safety.medicine"]; return out
    if measured is not None and rhr is not None and measured - rhr < 30:
        out["stop"] = "refused"; return out
    hrmax = r0(measured) if measured is not None else r0(D("208") - D("0.7") * age)
    out["hrMax"] = hrmax
    if rhr is None:
        out["method"] = "pctHrMax"
        for z, (lo, hi) in PCT.items():
            out[z] = [r0(D(lo) * hrmax), r0(D(hi) * hrmax)]
    else:
        out["method"] = "hrr"
        for z, (lo, hi) in HRR.items():
            out[z] = [r0(rhr + D(lo) * (hrmax - rhr)), r0(rhr + D(hi) * (hrmax - rhr))]
    return out


def main(root: Path) -> int:
    me = Path(__file__).resolve()
    results, mismatches = [], []
    for path in sorted((root / "fixtures" / "hr-zones").glob("*.json")):
        for c in json.loads(path.read_text(encoding="utf-8"))["fixtures"]:
            expected = assess(c["inputs"])
            results.append({"fixture_id": c["id"], "expected": expected})
            for k, v in (c.get("expected") or {}).items():
                if k in expected and expected[k] != v:
                    mismatches.append(f"{c['id']}.{k}: oracle {expected[k]!r} != fixture {v!r}")
    out = {
        "oracle_id": "ORC-12",
        "implementation": "Python decimal reference for Heart-Rate Training Zones (FR3-53): Tanaka maximum heart rate, Karvonen and % HRmax zones on the ACSM 11th ed. bands, rounding and the safety order, from the signed sheet of 3 November 2026",
        "versions": {"python": platform.python_version(), "sheet": "P3-5A v1.0 signed 2026-11-03"},
        "source_hash": hashlib.sha256(me.read_bytes()).hexdigest(),
        "run_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "results": results,
        "notes": ["Fixtures are read, never written; differences are printed and listed here."] + mismatches,
    }
    (me.parent / "results.json").write_text(json.dumps(out, indent=2) + "\n", encoding="utf-8")
    print(f"ORC-12: {len(results)} fixtures -> {me.parent / 'results.json'}; {len(mismatches)} difference(s)")
    for m in mismatches:
        print("  " + m)
    return 0


if __name__ == "__main__":
    sys.exit(main(Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).resolve().parents[2]))
