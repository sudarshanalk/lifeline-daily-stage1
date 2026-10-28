# ORC-01 — QDiabetes-2018 Model A via the CRAN QDiabetes package (DEC-02, DEC-33).
# Reads fixtures/diabetes/*.json in scope (FX-DM-QD-*, FX-DM-QD-CLAMP-*, FX-DM-BAND-*, FX-DM-CONC-*), maps each
# fixture's inputs to QDR2018A() named arguments and writes oracles/orc-01-qdiabetes-r/results.json (SCH-06) with the
# UNROUNDED percentage. Comparison rule (TC-FR1-10-AS-01): abs(js - r) <= 0.05 percentage points.
# Usage: Rscript qdr2018a.R <repo-root>
suppressPackageStartupMessages({ library(QDiabetes); library(jsonlite) })
args <- commandArgs(trailingOnly = TRUE); root <- if (length(args)) args[1] else "."

in_scope <- function(id) grepl("^FX-DM-(QD|QD-CLAMP|BAND|CONC)-[0-9]+$", id)
ethn_map <- c(WhiteNA = "WhiteNA", Indian = "Indian", Pakistani = "Pakistani", Bangladeshi = "Bangladeshi",
              OtherAsian = "OtherAsian", BlackCaribbean = "BlackCaribbean", BlackAfrican = "BlackAfrican",
              Chinese = "Chinese", Other = "Other")
smoke_map <- c(Non = "Non", Ex = "Ex", Light = "Light", Moderate = "Moderate", Heavy = "Heavy")

risk_for <- function(i) {
  # DEC-33: age = band midpoint (fixture ageBand), BMI from height/weight then clamped 20-40, fhdm = any(parent/sibling),
  # Townsend fixed at 0, named arguments only; bmi passed, not ht/wt.
  if (!is.numeric(i$weightKg) || !is.numeric(i$heightCm)) return(NULL)   # BAND fixtures carry a search placeholder until filled
  bmi <- i$weightKg / (i$heightCm / 100)^2
  bmi <- min(40, max(20, bmi))
  fam <- if (is.null(i$family)) character(0) else unlist(i$family)
  QDR2018A(sex = i$sex, age = i$ageBand, bmi = bmi, ethn = ethn_map[[i$ethn]], smoke = smoke_map[[if (is.null(i$smoke)) "Non" else i$smoke]],
           tds = 0, fhdm = length(fam) > 0, htn = isTRUE(i$htn == 1), cvd = isTRUE(i$cvd == 1), gdm = isTRUE(i$gdm == 1),
           pcos = isTRUE(i$pcos == 1), learn = isTRUE(i$learn == 1), psy = isTRUE(i$psy == 1), ster = isTRUE(i$ster == 1),
           stat = isTRUE(i$stat == 1), apsy = isTRUE(i$apsy == 1))
}

results <- list()
for (f in sort(list.files(file.path(root, "fixtures", "diabetes"), "\\.json$", full.names = TRUE))) {
  doc <- fromJSON(f, simplifyVector = FALSE)
  for (fx in doc$fixtures) {
    if (!in_scope(fx$id)) next
    pct <- tryCatch(risk_for(fx$inputs), error = function(e) { message(fx$id, ": ", conditionMessage(e)); NA })
    results[[length(results) + 1]] <- list(fixture_id = fx$id,
      expected = if (is.null(pct)) list(pct = NULL) else list(pct = unname(as.numeric(pct))),
      notes = if (is.null(pct)) "inputs not numeric (BAND fixture pending weight search)" else if (is.na(pct)) "QDR2018A error (see run log)" else "unrounded QDR2018A percentage")
  }
}
pins <- fromJSON(file.path(root, "oracles", "orc-01-qdiabetes-r", "pins.json"))
out <- list(
  oracle_id = "ORC-01",
  implementation = "R QDiabetes::QDR2018A (CRAN Archive 1.0-2, AGPL-3; research-use disclaimer applies) in a pinned rocker/r-ver container",
  versions = list(R = paste(R.version$major, R.version$minor, sep = "."), QDiabetes = packageDescription("QDiabetes")$Version,
                  tarball_sha256 = pins$tarball_sha256, image = pins$image, image_digest = pins$image_digest, licence = packageDescription("QDiabetes")$License),
  source_hash = trimws(sub(".*= ", "", system2("openssl", c("dgst", "-sha256", file.path(root, "oracles", "orc-01-qdiabetes-r", "qdr2018a.R")), stdout = TRUE))),
  run_at = format(Sys.time(), "%Y-%m-%dT%H:%M:%SZ", tz = "UTC"),
  results = results,
  notes = list("Comparison: abs(js - r) <= 0.05 pp on unrounded values (DEC-02).",
               "BAND fixtures without a numeric weight are reported with pct null; search-weight.R fills them (TC-FR1-12-AS-01 inputs).",
               "source_hash is the sha256 of this script (openssl inside the container).")
)
write_json(out, file.path(root, "oracles", "orc-01-qdiabetes-r", "results.json"), auto_unbox = TRUE, pretty = TRUE, digits = NA, null = "null", na = "null")
cat("ORC-01:", length(results), "fixtures written\n")
