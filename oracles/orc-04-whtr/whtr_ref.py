"""ORC-04 — waist-to-height ratio with published cut-offs (Ashwell & Gibson 2016; NICE NG246 2022: 0.5 "increased",
0.6 "further increased"). DEC-06: ratio = waist_cm / height_cm; < 0.5 healthy · 0.5–<0.6 raised · ≥ 0.6 high.

Scope (registry oracles[ORC-04].scope): FX-DM-WHTR-*. Writes oracles/orc-04-whtr/results.json (SCH-06).
Run: python -I oracles/orc-04-whtr/whtr_ref.py [repo-root]
"""
from __future__ import annotations
import hashlib, json, platform, sys
from datetime import datetime, timezone
from pathlib import Path


def whtr(waist_cm: float, height_cm: float) -> dict | None:
    if not waist_cm or not height_cm:
        return None
    r = waist_cm / height_cm
    band = "healthy" if r < 0.5 else "raised" if r < 0.6 else "high"
    return {"ratio": round(r, 4), "band": band}


def main(root: Path) -> int:
    results = []
    for f in sorted((root / "fixtures" / "diabetes").glob("*.json")):
        doc = json.loads(f.read_text(encoding="utf-8"))
        for fx in doc.get("fixtures", []):
            if not fx["id"].startswith("FX-DM-WHTR-"):
                continue
            i = fx["inputs"]
            results.append({"fixture_id": fx["id"], "expected": {"whtr": whtr(i.get("waistCm"), i.get("heightCm"))},
                            "notes": f"waist {i.get('waistCm')} / height {i.get('heightCm')}"})
    me = Path(__file__).resolve()
    out = {
        "oracle_id": "ORC-04",
        "implementation": "Python, waist-to-height ratio with cut-offs 0.5 and 0.6 (Ashwell & Gibson 2016; NICE NG246) per DEC-06",
        "versions": {"python": platform.python_version()},
        "source_hash": hashlib.sha256(me.read_bytes()).hexdigest(),
        "run_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "results": results,
        "notes": [
            "Ratio is reported to 4 dp; the deployed tool displays 2 dp. Band classification uses the unrounded ratio.",
            "Boundaries are inclusive at the upper cut-off: 0.5 -> raised, 0.6 -> high (DEC-06).",
        ],
    }
    (me.parent / "results.json").write_text(json.dumps(out, indent=2) + "\n", encoding="utf-8")
    print(f"ORC-04: {len(results)} fixtures -> {me.parent / 'results.json'}")
    return 0


if __name__ == "__main__":
    sys.exit(main(Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[2]))
