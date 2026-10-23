# Runbook: withdraw or restore a tool (FR1-15, DEC-08, DEC-10, DEC-31)

Applies to staging and production. Production actions are performed by the release owner (ACT-04) only; CI may act on staging.

## Withdraw
1. Open an incident record `incidents/<id>.json` (SCH-11) with the tool, affected version and reason.
2. On the server: edit `public_html/tools/status.json`, set `tools.<slug>.status` to `"withdrawn"`, `message` to `"unavailable.<slug>"`, `since` to now (UTC). Or from a checkout with SSH configured: `wp lld status set <slug> withdrawn --message=unavailable.<slug>` once the plugin exists.
3. Purge LiteSpeed: `wp litespeed-purge all` on the host, or hPanel → LiteSpeed Cache → Purge All. Without this the change can take up to 7 days to appear.
4. Verify within 60 s: hub card shows "Temporarily unavailable"; landing page has no iframe; `/tools/<file>.html` shows the unavailable state; `POST /tools/<endpoint>` → 503; the other tool still works (run `deploy/smoke.sh` and the SAF-WITHDRAW-01 test on staging).
5. Record the action and the verification in the incident record.

## Restore
1. Confirm the version to restore equals a `version_hash` in `approvals/<slug>.json` with `decision: approved` (CI `provenance-check` enforces this when done through `wp lld status set ... --version=`).
2. Set `status` back to `"active"`, `approved_version` to that hash, clear `message` and `since`.
3. Purge and verify as above. Close the incident record with `restored_at` and `restored_version`.

## SLA
Production withdrawal within 4 hours of an alert from the staging fidelity gate (DEC-08).
