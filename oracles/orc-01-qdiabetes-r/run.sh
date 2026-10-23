#!/usr/bin/env bash
# Build and run the ORC-01 container from a clean checkout (`make oracle ORC=01`). Usage: bash run.sh <repo-root>
set -euo pipefail
ROOT="$(cd "${1:-.}" && pwd)"; HERE="$ROOT/oracles/orc-01-qdiabetes-r"
docker info >/dev/null 2>&1 || { echo "ORC-01: Docker daemon unavailable (OI-4)"; exit 3; }
docker build -t lld-orc-01 "$HERE"
# the repository is mounted read-write: results.json is written under oracles/orc-01-qdiabetes-r/
docker run --rm -v "$ROOT:/repo" lld-orc-01
echo "ORC-01 done: $HERE/results.json"
