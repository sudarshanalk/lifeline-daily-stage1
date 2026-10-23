"""Validate one JSON document against one JSON schema. Exit 0 on success, 1 with messages otherwise."""
import json, sys
import jsonschema

schema_path, doc_path = sys.argv[1], sys.argv[2]
with open(schema_path, encoding="utf-8") as f:
    schema = json.load(f)
with open(doc_path, encoding="utf-8") as f:
    doc = json.load(f)
validator = jsonschema.Draft7Validator(schema, format_checker=jsonschema.FormatChecker())
errors = sorted(validator.iter_errors(doc), key=lambda e: list(e.path))
for e in errors:
    print(f"{'/'.join(str(p) for p in e.path) or '<root>'}: {e.message}")
sys.exit(1 if errors else 0)
