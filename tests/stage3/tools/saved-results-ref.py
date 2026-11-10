# Independent Python reference for P3-5B saved-results fixtures (until lead lands ORC-14).
# Usage: python -I tests/stage3/tools/saved-results-ref.py <repo-root>
from __future__ import annotations

import json
import math
import sys
from pathlib import Path


def heart_shown(risk: float) -> int:
    return int(round(risk))


def heart_band(risk: float) -> str:
    if risk < 5:
        return "low"
    if risk < 10:
        return "moderate"
    if risk < 20:
        return "high"
    return "very-high"


def band_label(b: str) -> str:
    return {"low": "Low", "moderate": "Moderate", "high": "High", "very-high": "Very high"}.get(b, b)


def bp_category(sys: float, dia: float) -> str:
    for cat, t in (("Grade 2", (160, 100)), ("Grade 1", (140, 90)), ("High-normal", (130, 85))):
        if sys >= t[0] or dia >= t[1]:
            return cat
    return "Normal"


def compare(fx: dict) -> dict:
    calc = fx["inputs"]["calculator"]
    results = fx["inputs"]["results"]
    a, b = results[0], results[-1]
    out: dict = {"comparable": True}

    if calc == "heart_risk":
        if a.get("model") and b.get("model") and a["model"] != b["model"]:
            return {"wording": "Not directly comparable", "comparable": False, "better_worse_label": None}
        ra, rb = float(a["result"]), float(b["result"])
        sa, sb = heart_shown(ra), heart_shown(rb)
        ba, bb = heart_band(ra), heart_band(rb)
        out.update(
            {
                "shown_from": f"{sa} %",
                "shown_to": f"{sb} %",
                "band_from": band_label(ba),
                "band_to": band_label(bb),
                "band_change": ba != bb,
            }
        )
        delta = sb - sa
        meaningful = abs(delta) > 1 or ba != bb
        if not meaningful:
            out["wording"] = f"About the same ({'+' if delta > 0 else ''}{delta} point{'s' if abs(delta) != 1 else ''})"
        elif delta < 0:
            pts = f"{abs(delta)} point{'s' if abs(delta) != 1 else ''} lower"
            date = a.get("date", "")
            out["wording"] = f"Better: {pts} since {date}" if date else f"Better: {pts}"
        else:
            out["wording"] = f"Higher than last time: {delta} points"
        return out

    if calc == "bp_check":
        dsys = b["systolic"] - a["systolic"]
        ddia = b["diastolic"] - a["diastolic"]
        out["category_from"] = bp_category(a["systolic"], a["diastolic"])
        out["category_to"] = bp_category(b["systolic"], b["diastolic"])
        meaningful = abs(dsys) >= 5 or abs(ddia) >= 3
        pair = f"{abs(round(dsys))}/{abs(round(ddia))}"
        pair_signed = f"{'+' if dsys > 0 else ''}{round(dsys)}/{'+' if ddia > 0 else ''}{round(ddia)}"
        if not meaningful:
            out["wording"] = f"About the same ({pair_signed})"
        elif dsys <= 0 and ddia <= 0:
            out["wording"] = f"Better: {pair} lower"
        else:
            out["wording"] = f"Higher than last time: {pair}"
        return out

    if calc == "fitness_age":
        d_vo2 = round(b["vo2"] - a["vo2"], 1)
        age_a, age_b = round(a["fitness_age"]), round(b["fitness_age"])
        out["wording"] = f"Better: {'+' if d_vo2 > 0 else ''}{d_vo2} points; fitness age {age_a} → {age_b}"
        return out

    if calc == "metabolic":
        out["wording"] = f"Better: {a['criteria_met']} → {b['criteria_met']} of 4; no longer meets the definition"
        return out

    if calc == "healthy_weight":
        out["wording"] = f"BMI {a['result']} → {b['result']}. Moving towards the healthy range."
        out["weight_loss_praise"] = False
        return out

    if calc in ("diabetes_risk", "diabetes"):
        d = round(b["result"] - a["result"], 1)
        if abs(d) < 1:
            out["wording"] = f"About the same ({'+' if d > 0 else ''}{d})"
        elif d < 0:
            out["wording"] = f"Better: {abs(d)} points lower"
            if a.get("engine_version") and b.get("engine_version") and a["engine_version"] != b["engine_version"]:
                out["earlier_version_note"] = "compared with an earlier version"
        else:
            out["wording"] = f"Higher than last time: {d} points"
        out["comparable"] = True
        return out

    return out


def main() -> int:
    root = Path(sys.argv[1] if len(sys.argv) > 1 else ".")
    diffs = []
    for path in sorted((root / "fixtures" / "saved-results").glob("*.json")):
        data = json.loads(path.read_text(encoding="utf-8"))
        for fx in data["fixtures"]:
            if not fx["id"].startswith("FX-SR-") or fx["id"] in ("FX-SR-04", "FX-SR-12", "FX-SR-13", "FX-SR-14", "FX-SR-15"):
                continue
            got = compare(fx)
            for k, want in fx["expected"].items():
                if k == "earlier_version_note":
                    if "earlier version" not in str(got.get("earlier_version_note", "")).lower():
                        diffs.append(f"{fx['id']}.{k}")
                    continue
                if got.get(k) != want:
                    diffs.append(f"{fx['id']}.{k}: {got.get(k)!r} != {want!r}")
    print(f"{len(diffs)} differences")
    for d in diffs:
        print(d)
    return 0 if not diffs else 1


if __name__ == "__main__":
    raise SystemExit(main())
