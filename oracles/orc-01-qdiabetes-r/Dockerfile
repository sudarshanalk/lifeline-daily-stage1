# ORC-01 — R container for the QDiabetes-2018 oracle (DEC-02, DEC-33).
# Pinned base image: rocker/r-ver 4.4.1. Record the image digest in pins.json at the first successful build
# (`docker inspect --format '{{index .RepoDigests 0}}' rocker/r-ver:4.4.1`) and switch the FROM line to that digest.
FROM rocker/r-ver:4.4.1

# The QDiabetes package was archived on CRAN in June 2026; install the exact tarball from the CRAN Archive and verify its
# SHA-256 against pins.json before installing (install.sh refuses to continue on a mismatch or an unrecorded pin).
ARG QDIABETES_VERSION=1.0-2
ENV QDIABETES_VERSION=${QDIABETES_VERSION}
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl openssl && rm -rf /var/lib/apt/lists/*
RUN install2.r --error jsonlite
WORKDIR /oracle
COPY pins.json install.sh qdr2018a.R ./
RUN bash install.sh
# run.sh mounts the repository at /repo (read-write); results are written to /repo/oracles/orc-01-qdiabetes-r/results.json
ENTRYPOINT ["Rscript", "/oracle/qdr2018a.R", "/repo"]
