#!/usr/bin/env bash
# Stage 2 key journeys after a deploy (NFR3-03 AS-02): the pages each journey starts on answer as Stage 2 built them.
# Sign up, sign in, choose a goal, start a track, open approved content, food diary, Healthy Weight Check, account
# and privacy pages. Without an account this checks the public page of each journey and that the member pages send
# a visitor to sign in; the full signed-in journeys run in CI (tests/stage3/key-journeys).
set -euo pipefail
BASE=${1:?base url}; fail=0
CURL=(curl --silent --show-error --location --connect-timeout 10 --max-time 30 -A lld-key-journeys)
chk() {
 local want=$1 path=$2 needle=${3:-} got body
 body=$("${CURL[@]}" -o - -w '\n%{http_code}' "$BASE$path") || { echo "FAIL $path: network error"; fail=1; return; }
 got=${body##*$'\n'}; body=${body%$'\n'*}
 if [[ "$got" != "$want" ]]; then echo "FAIL $path: $got (expected $want)"; fail=1; return; fi
 if [[ -n "$needle" ]] && ! grep -qi -- "$needle" <<<"$body"; then echo "FAIL $path: '$needle' not found"; fail=1; return; fi
 echo "ok $got $path"
}
chk 200 /members/join/ 'join'                       # sign up
chk 200 /members/sign-in/ 'sign in'                 # sign in
chk 200 /pricing/ 'Premium'                         # plans
chk 200 /programmes/ 'track'                        # start a track
chk 200 /health-tools/healthy-weight-check/ 'Healthy Weight'   # Healthy Weight Check
chk 200 /tools/healthy-weight-tool.html 'Healthy Weight'
chk 200 /safety/                                    # safety wording public
# Member pages: a visitor is sent to sign in, never shown another state (curl follows the redirect).
for path in /members/home/ /members/goals/ /members/library/ /members/diary/ /members/settings/ /members/data/; do
 final=$("${CURL[@]}" -o /dev/null -w '%{url_effective}' "$BASE$path") || final=network-error
 if [[ "$final" == *"/members/sign-in"* || "$final" == *"/members/join"* ]]; then echo "ok visitor->sign-in $path"; else echo "FAIL $path: visitor landed on $final"; fail=1; fi
done
exit "$fail"
