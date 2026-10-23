"""ORC-03 — Indian Diabetes Risk Score (Mohan V, Deepa R, Deepa M, Somannavar S, Datta M. JAPI 2005;53:759-763).

Independent Python implementation of the published points table, written from the paper, not from the tool:
  age        <35: 0 · 35–49: 20 · ≥50: 30
  waist      men   <90 cm: 0 · 90–99: 10 · ≥100: 20
             women <80 cm: 0 · 80–89: 10 · ≥90: 20
  family     no diabetic parent 0 · one parent 10 · both parents 20   (siblings are not part of the published item)
  activity   published 3-level item: vigorous exercise / strenuous work 0 · moderate 20 · none (sedentary) 30.
             The deployed tool uses a 4-level adaptation vigorous 0 / moderate 10 / mild 20 / none 30 (DEC-05). ORC-03 encodes
             the LIVE mapping, so for this one item it is a characterisation check, not an independent one, and the clinician
             ratifies the mapping at HG-02. The published mapping is kept alongside (ACTIVITY_PUBLISHED) and reported in notes.
  tiers      <30 low · 30–59 moderate · ≥60 high (DEC-05; scores are multiples of 10)

Scope (registry oracles[ORC-03].scope): FX-DM-IDRS-*, FX-DM-CONC-*. Writes oracles/orc-03-idrs/results.json (SCH-06).
Run: python -I oracles/orc-03-idrs/idrs_ref.py [repo-root]
"""
from __future__ import annotations
import hashlib, json, platform, sys
from datetime import datetime, timezone
from pathlib import Path

ACTIVITY_LIVE = {"vigorous": 0, "moderate": 10, "mild": 20, "none": 30}
ACTIVITY_PUBLISHED = {"vigorous": 0, "moderate": 20, "none": 30}


def age_points(age: float) -> int:
    return 0 if age < 35 else 20 if age < 50 else 30


def waist_points(sex: str, waist_cm: float) -> int:
    if sex == "Male":
        return 0 if waist_cm < 90 else 10 if waist_cm < 100 else 20
    return 0 if waist_cm < 80 else 10 if waist_cm < 90 else 20


def family_points(family: list[str]) -> int:
    parents = sum(1 for f in family if f in ("mother", "father"))
    return {0: 0, 1: 10, 2: 20}[parents]


def activity_points(activity: str | None, mapping=ACTIVITY_LIVE) -> int:
    return mapping.get(activity or "moderate", 0)


def tier(score: int) -> str:
    return "low" if score < 30 else "moderate" if score < 60 else "high"


def idrs(inputs: dict) -> dict | None:
    """Returns {"score", "tier"} or None when the waist is not given (the indicator is not computed)."""
    if not inputs.get("waistCm"):
        return None
    s = (age_points(inputs["ageBand"]) + waist_points(inputs["sex"], inputs["waistCm"])
         + family_points(inputs.get("family") or []) + activity_points(inputs.get("activity")))
    return {"score": s, "tier": tier(s)}


def in_scope(fid: str) -> bool:
    return fid.startswith("FX-DM-IDRS-") or fid.startswith("FX-DM-CONC-")


def main(root: Path) -> int:
    results = []
    for f in sorted((root / "fixtures" / "diabetes").glob("*.json")):
        doc = json.loads(f.read_text(encoding="utf-8"))
        for fx in doc.get("fixtures", []):
            if not in_scope(fx["id"]):
                continue
            r = idrs(fx["inputs"])
            results.append({"fixture_id": fx["id"], "expected": {"idrs": r},
                            "notes": "waist not given: IDRS not computed" if r is None else f"age {fx['inputs']['ageBand']}, waist {fx['inputs']['waistCm']} cm, activity {fx['inputs'].get('activity')}, family {fx['inputs'].get('family')}"})
    me = Path(__file__).resolve()
    out = {
        "oracle_id": "ORC-03",
        "implementation": "Python, IDRS points table from Mohan et al. 2005 (JAPI 53:759-763) with the live 4-level activity mapping (DEC-05)",
        "versions": {"python": platform.python_version(), "activity_mapping": "live 4-level (vigorous 0 / moderate 10 / mild 20 / none 30)", "activity_mapping_published": "vigorous 0 / moderate 20 / none 30"},
        "source_hash": hashlib.sha256(me.read_bytes()).hexdigest(),
        "run_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "results": results,
        "notes": [
            "Family history: only diabetic parents score (one 10, both 20); a diabetic sibling scores 0, as in the published item.",
            "Activity: the live tool's 4-level mapping is an adaptation of the published 3-level item; ORC-03 reproduces the live mapping (DEC-05), so this item is characterisation until HG-02 ratifies it. 'mild' has no published equivalent.",
            "Tiers <30 / 30-59 / >=60 per DEC-05 (attainable scores are multiples of 10, so 30-50 and 30-59 are the same set).",
            "Expected object is {idrs: {score, tier}} or {idrs: null} when no waist measurement was entered.",
        ],
    }
    (me.parent / "results.json").write_text(json.dumps(out, indent=2) + "\n", encoding="utf-8")
    print(f"ORC-03: {len(results)} fixtures -> {me.parent / 'results.json'}")
    return 0


if __name__ == "__main__":
    sys.exit(main(Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[2]))
