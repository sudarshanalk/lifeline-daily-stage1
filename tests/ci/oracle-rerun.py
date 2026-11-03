"""oracle-rerun (Build Method Stage 3 §7.4): every Stage 3 oracle (oracles/orc-06 onwards, one Python script and a
results.json per folder) is run again from its committed script, and the derived values must equal the committed
results.json (run_at and notes aside) and its source_hash must be the script's. A fixture whose expected value was
changed to "the oracle's value" (tests/ci/oracle-guard.mjs) is therefore backed by a script CI itself executed.
Run: python tests/ci/oracle-rerun.py [repo-root]"""
import hashlib, json, re, subprocess, sys
from pathlib import Path

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[2]
bad = 0; n = 0
for folder in sorted((ROOT / "oracles").glob("orc-*")):
    m = re.match(r"orc-(\d{2})-", folder.name)
    if not m or int(m.group(1)) < 6 or not (folder / "results.json").exists():
        continue
    scripts = sorted(p for p in folder.glob("*.py"))
    if len(scripts) != 1:
        print(f"INVALID {folder.name}: expected one oracle script, found {len(scripts)}"); bad += 1; continue
    script = scripts[0]
    committed = json.loads((folder / "results.json").read_text(encoding="utf-8"))
    expected_hash = hashlib.sha256(script.read_bytes()).hexdigest()
    if committed.get("source_hash") != expected_hash:
        print(f"INVALID {folder.name}: results.json source_hash {committed.get('source_hash')} is not the script's {expected_hash}"); bad += 1
    before = (folder / "results.json").read_bytes()
    try:
        subprocess.run([sys.executable, "-I", str(script), str(ROOT)], check=True, capture_output=True, text=True, timeout=300)
        fresh = json.loads((folder / "results.json").read_text(encoding="utf-8"))
    finally:
        (folder / "results.json").write_bytes(before)  # CI never commits; keep the tree as it was
    strip = lambda d: {r["fixture_id"]: r["expected"] for r in d.get("results", [])}
    if strip(fresh) != strip(committed):
        diff = [k for k in set(strip(fresh)) | set(strip(committed)) if strip(fresh).get(k) != strip(committed).get(k)]
        print(f"INVALID {folder.name}: re-run differs from the committed results.json for {sorted(diff)}"); bad += 1
    else:
        print(f"ok {folder.name}: {len(strip(fresh))} fixtures reproduced")
    n += 1
print(f"oracle-rerun: {n} oracle(s), {bad} invalid")
sys.exit(1 if bad else 0)
