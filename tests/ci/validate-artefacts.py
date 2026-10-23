"""validate-artefacts (docs/04 §1): every JSON artefact against its schema (`make validate`, CI job of the same name).
Exit 1 on the first schema violation; prints each file checked."""
import glob, json, sys
from pathlib import Path
import jsonschema

ROOT = Path(__file__).resolve().parents[2]
S = lambda n: json.load(open(ROOT / "schemas" / n, encoding="utf-8"))
TARGETS = [
    ("registry/Stage1_Requirements_Registry_v1_2.json", "registry.schema.json"),
    ("fixtures/*/*.json", "fixture.schema.json"),
    ("manifests/*.json", "tool-manifest.schema.json"),
    ("oracles/*/results.json", "oracle-result.schema.json"),
    ("approvals/protein.json", "methodology-approval.schema.json"),
    ("approvals/diabetes.json", "methodology-approval.schema.json"),
    ("approvals/copy.json", "copy-approval.schema.json"),
    ("approvals/hg-0*.json", "gate-record.schema.json"),
    ("evidence/*/*.json", "evidence.schema.json"),
    ("site/public_html/tools/status.json", "tool-status.schema.json"),
    ("content/tool-cards.json", "tool-card.schema.json"),
    ("content/article-tool-links.json", "article-tool-link.schema.json"),
    ("content/planned-categories.json", "planned-categories.schema.json"),
    ("privacy/data-flow.json", "data-flow.schema.json"),
    ("releases/*.json", "release-record.schema.json"),
    ("incidents/*.json", "clinical-incident.schema.json"),
]
bad = 0; n = 0
for pattern, schema_name in TARGETS:
    schema = S(schema_name)
    for f in sorted(glob.glob(str(ROOT / pattern))):
        doc = json.load(open(f, encoding="utf-8"))
        if pattern.startswith("fixtures/") and "fixtures" not in doc:
            continue  # shared data files (sentinel values, phrases) are not SCH-05
        errors = sorted(jsonschema.Draft7Validator(schema, format_checker=jsonschema.FormatChecker()).iter_errors(doc), key=lambda e: list(e.path))
        n += 1
        rel = Path(f).relative_to(ROOT).as_posix()
        if errors:
            bad += 1
            for e in errors:
                print(f"INVALID {rel} [{schema_name}] {'/'.join(map(str, e.path)) or '<root>'}: {e.message}")
        else:
            print(f"ok {rel} [{schema_name}]")
print(f"validate-artefacts: {n} files, {bad} invalid")
sys.exit(1 if bad else 0)
