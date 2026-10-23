#!/usr/bin/env bash
# Install QDiabetes <version> from the CRAN Archive with a verified SHA-256 (DEC-02/DEC-33). Runs inside the container.
set -euo pipefail
VERSION="${QDIABETES_VERSION:-1.0-2}"
TARBALL="QDiabetes_${VERSION}.tar.gz"
URL="https://cran.r-project.org/src/contrib/Archive/QDiabetes/${TARBALL}"
PIN=$(python3 -c "import json;print(json.load(open('pins.json'))['tarball_sha256'] or '')" 2>/dev/null || Rscript -e "cat(jsonlite::fromJSON('pins.json')\$tarball_sha256)")
curl -fsSL -o "$TARBALL" "$URL"
GOT=$(sha256sum "$TARBALL" | cut -d' ' -f1)
echo "QDiabetes ${VERSION} tarball sha256: $GOT"
if [ -z "$PIN" ] || [ "$PIN" = "null" ]; then
  echo "pins.json has no tarball_sha256 yet. Record this value in pins.json (and the ORC-01 report) before the oracle result counts." >&2
  [ "${RECORD_PIN:-0}" = "1" ] || exit 4
elif [ "$PIN" != "$GOT" ]; then
  echo "ABORT: tarball sha256 $GOT != pinned $PIN" >&2; exit 2
fi
R CMD INSTALL "$TARBALL"
Rscript -e 'library(QDiabetes); cat("QDiabetes", as.character(packageVersion("QDiabetes")), "installed; licence:", packageDescription("QDiabetes")$License, "\n")'
