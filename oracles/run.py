"""Oracle dispatcher — the body of `make oracle ORC=01|02|03|04` (docs/04, TC-FR1-22-AS-01).

  python -I oracles/run.py 03          run ORC-03 and refresh oracles/orc-03-idrs/results.json
  python -I oracles/run.py all         run every oracle that can run on this machine

Exit codes: 0 ran · 3 ORC-01 cannot run here (no usable Docker daemon; the known HG-01 deferral, OI-4) · 1 failure.
ORC-02 is the sealed reference (P-O2); its results.json is re-generated from the committed module, never re-authored here.
"""
from __future__ import annotations
import shutil, subprocess, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PY = [sys.executable, "-I"]
ORACLES = {
    "01": ("orc-01-qdiabetes-r", None),
    "02": ("orc-02-protein-ref", "protein_ref.py"),
    "03": ("orc-03-idrs", "idrs_ref.py"),
    "04": ("orc-04-whtr", "whtr_ref.py"),
}


def docker_ready() -> bool:
    if not shutil.which("docker"):
        return False
    try:
        return subprocess.run(["docker", "info"], capture_output=True, text=True, timeout=20).returncode == 0
    except Exception:
        return False


def run(num: str) -> int:
    folder, script = ORACLES[num]
    d = ROOT / "oracles" / folder
    if num == "01":
        if not docker_ready():
            print("ORC-01: Docker daemon not available on this machine (WSL 2 / Docker Desktop pending, OI-4) — "
                  "scaffold only; see oracles/orc-01-qdiabetes-r/README.md. Deferred to HG-02 per approvals/hg-01.json.")
            return 3
        return subprocess.run(["bash", str(d / "run.sh"), str(ROOT)], cwd=ROOT).returncode
    if num == "02":  # sealed v2 module (DEC-86) CLI: <cases.json> <results.json>; it never sees fixture expected values
        r = subprocess.run(PY + [str(d / script), str(ROOT / "tests" / "baseline" / "protein-cases.json"), str(d / "results.json")], cwd=ROOT)
    else:
        r = subprocess.run(PY + [str(d / script), str(ROOT)], cwd=ROOT)
    return r.returncode


def main(argv: list[str]) -> int:
    which = (argv[1] if len(argv) > 1 else "all").lower().replace("orc-", "").replace("orc", "")
    nums = list(ORACLES) if which == "all" else [which.zfill(2)]
    worst = 0
    for n in nums:
        if n not in ORACLES:
            print(f"unknown oracle {n}"); return 1
        rc = run(n)
        worst = max(worst, rc) if rc != 3 else (worst or 3)
    return worst


if __name__ == "__main__":
    sys.exit(main(sys.argv))
