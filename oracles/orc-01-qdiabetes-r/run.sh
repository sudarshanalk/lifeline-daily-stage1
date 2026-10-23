#!/usr/bin/env bash
# Build and run the ORC-01 container from a clean checkout (`make oracle ORC=01`). Usage: bash run.sh <repo-root>
set -euo pipefail
ROOT="$(cd "${1:-.}" && pwd)"; HERE="$ROOT/oracles/orc-01-qdiabetes-r"
docker info >/dev/null 2>&1 || { echo "ORC-01: Docker daemon unavailable (OI-4)"; exit 3; }
docker build -t lld-orc-01 "$HERE"
docker run --rm -v "$ROOT:/repo" lld-orc-01
# SCH-06 wants a sha256 source hash; R wrote an md5 placeholder
SHA=$(sha256sum "$HERE/qdr2018a.R" | cut -d' ' -f1)
python3 - "$HERE/results.json" "$SHA" <<'EOF'
import json, sys
p, sha = sys.argv[1], sys.argv[2]
d = json.load(open(p)); d["source_hash"] = sha
json.dump(d, open(p, "w"), indent=2); open(p, "a").write("\n")
EOF
echo "ORC-01 done: $HERE/results.json"
