# ORC-01 — QDiabetes-2018 Model A oracle (R, CRAN `QDiabetes` 1.0-2)

Status: **first run 25 Oct 2026 on a GitHub Actions Linux runner: 78/78 fixtures agree with the deployed engine (fidelity-gate).** Earlier note: The build machine has the Docker CLI but no daemon (WSL 2 not installed, owner
input OI-4). HG-01 (`approvals/hg-01.json`) explicitly deferred the ORC-01 run and the FX-DM-BAND inputs to HG-02.

What is here (DEC-02, DEC-33):
- `Dockerfile` — pinned `rocker/r-ver:4.4.1` (switch to the digest recorded in `pins.json` after the first build).
- `install.sh` — downloads `QDiabetes_1.0-2.tar.gz` from the CRAN Archive (package archived June 2026), verifies its SHA-256
  against `pins.json` (refuses on mismatch or missing pin unless `RECORD_PIN=1`), installs it and prints the AGPL-3 licence.
- `qdr2018a.R` — maps every in-scope fixture (FX-DM-QD-*, QD-CLAMP-*, BAND-*, CONC-*) to `QDR2018A()` with **named
  arguments only**: `age` = band midpoint, `bmi` computed then clamped 20–40, `tds = 0`, `fhdm = any(mother, father, sibling)`,
  `gdm` etc. as logicals; writes `results.json` (SCH-06) with the **unrounded** percentage.
- `run.sh` — `make oracle ORC=01`: build, run with the repository mounted at `/repo`, fix the SCH-06 `source_hash`.
- `pins.json` — the pins (tarball SHA-256, image digest) to fill at the first run; recorded in the ORC-01 report.

Comparison (TC-FR1-10-AS-01): pass when `abs(js − r) ≤ 0.05` percentage points on unrounded values. Disagreement on the
deployed hash follows DEC-08 (staging withdrawal + DEC row). Research-use disclaimer of the package applies and is recorded in
`results.json.versions.licence`.

First run checklist (after `wsl --install` and Docker Desktop):
1. `RECORD_PIN=1 docker build -t lld-orc-01 oracles/orc-01-qdiabetes-r` → copy the printed tarball sha256 into `pins.json`.
2. `docker inspect --format '{{index .RepoDigests 0}}' rocker/r-ver:4.4.1` → `pins.json.image_digest`, then pin `FROM` by digest.
3. `python -I oracles/run.py 01` (or `make oracle ORC=01`) → `results.json`; commit as an `FR1-22:`/`ORC-01:` commit so
   `oracle-guard` accepts the later `expected` fills for the clinical fixtures (FX-DM-BAND-*).
