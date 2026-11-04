"""ORC-13 - Sleep and Stress Check reference (P3-5A part 3, FR3-54), written from the owner-signed method sheet
(04 Health Tools/Stage 3/P3-5A-Fitness-Age-HR-Zones-Sleep-Stress-Method-Sheet.md sections 3.2 to 3.3, decisions
D3-28 to D3-30), not from the engine.

  Q0 self-harm yes -> Stage 2 Response 1 only: no scores, no results, no track offer
  Stress (GAD-2) = Q1 + Q2, 0..6; >= 3 worth attention (+ doctor line); else in the usual range
  Mood (PHQ-2)   = Q3 + Q4, 0..6; >= 3 worth attention; below 3 no mood result is worded
  Sleep count    = [Q5 yes] + [Q5 yes and Q6 yes] + [Q7 yes] + [Q8 < 7 h]; 0-1 none, 2 some, 3-4 needs attention
                   (+ insomnia / CBT-I line; + short-sleep note when Q8 < 5 h)
  Help line      : shown whenever anything is "worth attention" or "needs attention" (FR3-54 AS-02)

Reads fixtures/sleep-stress/*.json (inputs only), writes oracles/orc-13-sleep-stress/results.json and prints every
fixture whose recorded expected differs. Fixtures are never written here (oracle-guard).
Run: python -I oracles/orc-13-sleep-stress/sleep_stress_ref.py [repo-root]
"""
from __future__ import annotations
import hashlib, json, platform, sys
from datetime import datetime, timezone
from pathlib import Path


def assess(i: dict) -> dict:
    out = {"response1": False, "stressScore": None, "moodScore": None, "sleepCount": None, "stressResult": None,
           "moodResult": None, "sleepResult": None, "stressDoctorLine": False, "insomniaLine": False,
           "shortSleepNote": False, "helpline": False, "trackOffer": False}
    if i["q0"] == "yes":
        out["response1"] = True
        return out
    stress, mood = i["q1"] + i["q2"], i["q3"] + i["q4"]
    q5 = i["q5"] == "yes"
    sleep = int(q5) + int(q5 and i.get("q6") == "yes") + int(i["q7"] == "yes") + int(i["q8"] < 7)
    level = 2 if sleep >= 3 else 1 if sleep == 2 else 0
    out.update({
        "stressScore": stress, "moodScore": mood, "sleepCount": sleep,
        "stressResult": "result.stress.attention" if stress >= 3 else "result.stress.usual",
        "moodResult": "result.mood.attention" if mood >= 3 else None,
        "sleepResult": ["result.sleep.none", "result.sleep.some", "result.sleep.needs"][level],
        "stressDoctorLine": stress >= 3, "insomniaLine": level == 2, "shortSleepNote": level == 2 and i["q8"] < 5,
        "helpline": stress >= 3 or mood >= 3 or level == 2, "trackOffer": True,
    })
    return out


def main(root: Path) -> int:
    me = Path(__file__).resolve()
    results, mismatches = [], []
    for path in sorted((root / "fixtures" / "sleep-stress").glob("*.json")):
        for c in json.loads(path.read_text(encoding="utf-8"))["fixtures"]:
            expected = assess(c["inputs"])
            results.append({"fixture_id": c["id"], "expected": expected})
            for k, v in (c.get("expected") or {}).items():
                if k in expected and expected[k] != v:
                    mismatches.append(f"{c['id']}.{k}: oracle {expected[k]!r} != fixture {v!r}")
    out = {
        "oracle_id": "ORC-13",
        "implementation": "Python reference for the Sleep and Stress Check (FR3-54): the self-harm question first, GAD-2 and PHQ-2 scored as published, the 4-item insomnia-symptom count, the result bands and the help-line rule, from the signed sheet of 3 November 2026",
        "versions": {"python": platform.python_version(), "sheet": "P3-5A v1.0 signed 2026-11-03"},
        "source_hash": hashlib.sha256(me.read_bytes()).hexdigest(),
        "run_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "results": results,
        "notes": ["Fixtures are read, never written; differences are printed and listed here."] + mismatches,
    }
    (me.parent / "results.json").write_text(json.dumps(out, indent=2) + "\n", encoding="utf-8")
    print(f"ORC-13: {len(results)} fixtures -> {me.parent / 'results.json'}; {len(mismatches)} difference(s)")
    for m in mismatches:
        print("  " + m)
    return 0


if __name__ == "__main__":
    sys.exit(main(Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).resolve().parents[2]))
