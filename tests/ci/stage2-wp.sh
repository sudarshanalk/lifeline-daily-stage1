#!/usr/bin/env bash
# stage2-wp: throwaway WordPress for the Stage 2 browser journeys in CI (stage2-e2e job). Not for staging or production.
# Needs: wp-cli, PHP 8.1 with mysqli, MariaDB on 127.0.0.1:3306 (root / ci-only, database wp), Mailpit on 127.0.0.1:1025.
# Copies the lld-members plugin from this checkout and activates it (Paid Memberships Pro was retired on 6 Nov 2026, FR3-96),
# runs the plugin database update, then serves the site on 127.0.0.1:8090 in the background.
set -euo pipefail
DIR=${1:?target directory}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
URL=${LLD_WP_URL:-http://127.0.0.1:8090}
mkdir -p "$DIR"
wp core download --path="$DIR" --quiet
wp config create --path="$DIR" --dbname=wp --dbuser=root --dbpass=ci-only --dbhost=127.0.0.1:3306 --skip-check --quiet
# Environment flags the plugin reads (Build Method §9): CI environment, test clock on, mail to Mailpit, Stripe test mode only.
wp config set --path="$DIR" LLD_ENV ci --quiet
wp config set --path="$DIR" LLD_TEST_CLOCK true --raw --quiet
wp config set --path="$DIR" LLD_SMTP_HOST 127.0.0.1 --quiet
wp config set --path="$DIR" LLD_SMTP_PORT 1025 --raw --quiet
# Stripe: CI points the plugin at stripe-mock (LLD_STRIPE_API_BASE); keys come from the environment, never the repository
if [ -n "${LLD_STRIPE_API_BASE:-}" ]; then wp config set --path="$DIR" LLD_STRIPE_API_BASE "$LLD_STRIPE_API_BASE" --quiet; fi
if [ -n "${LLD_STRIPE_SECRET:-}" ]; then wp config set --path="$DIR" LLD_STRIPE_SECRET "$LLD_STRIPE_SECRET" --quiet; fi
ADMIN_PW=$(openssl rand -hex 16)   # throwaway admin, never printed
wp core install --path="$DIR" --url="$URL" --title="LLD CI" --admin_user=ci-admin --admin_password="$ADMIN_PW" --admin_email=ci-admin@example.test --skip-email --quiet
wp rewrite structure '/%postname%/' --path="$DIR" --quiet
cp -R "$REPO/site/public_html/wp-content/plugins/lld-members" "$DIR/wp-content/plugins/lld-members"
# the member layout points its @font-face rules at lld-tools' self-hosted fonts (S2-L1): copy them so CI matches staging
mkdir -p "$DIR/wp-content/mu-plugins/lld-tools/assets"
cp -R "$REPO/site/public_html/wp-content/mu-plugins/lld-tools/assets/fonts" "$DIR/wp-content/mu-plugins/lld-tools/assets/fonts"
wp plugin activate lld-members --path="$DIR" --quiet
wp lld-members db-update --path="$DIR"
# Stage 3 (NFR3-09 live tests): the interface's switches at test_accounts, so only test accounts see the Stage 3
# screens: the two listed test members and the owner admin (an admin is always a test account, so it sees every
# Stage 3 screen whose switch is at test_accounts). Every other Stage 2 member stays exactly as Stage 2
# (content_new stays off here on purpose; only the Stage 3 browser job puts it at test_accounts, with its test content, below).
for f in api account_hub goals today progress insights food_tracker barcode health_logging calculators_new reminders sign_in_social sign_in_code; do wp lld-members feature "$f" test_accounts --path="$DIR" --quiet; done
# P3-44: one made-up packaged food with a barcode (GS1 prefix 2, never a real product) for the scan screen's live tests
wp lld-members food import --source=off --file="$REPO/tests/stage3/e2e/screens/barcode-foods.jsonl" --path="$DIR" --quiet
wp lld-members test-accounts p3-api-a@test.lifelinedaily.invalid p3-api-b@test.lifelinedaily.invalid p3-signin-a@test.lifelinedaily.invalid p3-signin-b@test.lifelinedaily.invalid p3-signin-c@test.lifelinedaily.invalid p3-signin-d@test.lifelinedaily.invalid p3-signin-e@test.lifelinedaily.invalid p3-signin-f@test.lifelinedaily.invalid p3-signin-g@test.lifelinedaily.invalid p3-signin-h@test.lifelinedaily.invalid p3-signin-i@test.lifelinedaily.invalid p3-signin-relay@privaterelay.appleid.test p3-calc-a@test.lifelinedaily.invalid p3-calc-b@test.lifelinedaily.invalid --path="$DIR" --quiet
# P3-20: a Google client that exists only here, so the Continue with Google button and the start route can be checked;
# the callback is never reached (the tests complete provider sign-ins through the test route instead)
wp config set --path="$DIR" LLD_GOOGLE_CLIENT_ID ci-google-client.apps.googleusercontent.com --quiet
wp config set --path="$DIR" LLD_GOOGLE_CLIENT_SECRET ci-only --quiet
# P3-83: the Stage 3 browser job (LLD_CI_STAGE1_TOOLS=1) also loads the Stage 1 mu-plugin and the Health Tools hub page
# ([lld_tools_hub], as `wp lld pages` makes it on staging), so the hub and its Stage 3 cards are checked as the live code
# renders them. The Stage 2 job keeps its plain site, as before.
if [ "${LLD_CI_STAGE1_TOOLS:-}" = "1" ]; then
  cp -R "$REPO/site/public_html/wp-content/mu-plugins/lld-tools/." "$DIR/wp-content/mu-plugins/lld-tools/"
  cp "$REPO/site/public_html/wp-content/mu-plugins/lld-tools-loader.php" "$DIR/wp-content/mu-plugins/lld-tools-loader.php"
  wp post create --path="$DIR" --post_type=page --post_name=health-tools --post_title="Health Tools" --post_status=publish --post_content='[lld_tools_hub]' --quiet
  # P3-87b: the standalone tools and their tools/status.json switch, copied into this throwaway site so the admin
  # Tools page can turn one off and on (FR3-89) without touching the repository's files.
  cp -R "$REPO/site/public_html/tools" "$DIR/tools"
  # P3-35: the synthetic Healthy Weight test content (made-up questions, notes, focus blocks and festival calendar)
  # through the real content loader, for the hw-*.spec.mjs journeys; shown only to the test accounts (content_new).
  bash "$REPO/tests/ci/stage3-hw-content.sh" "$DIR"
fi
# Router: without one, PHP's built-in server answers 404 itself for any missing path with a dot (/sitemap.xml)
# instead of passing it to WordPress, unlike Apache/LiteSpeed with the WordPress .htaccess on staging and production
nohup php -S 127.0.0.1:8090 -t "$DIR" "$REPO/tools/local/router.php" > "$DIR/../php-server.log" 2>&1 &
for i in $(seq 1 30); do curl -fsS -o /dev/null "$URL/" && { echo "stage2-wp: WordPress ready at $URL"; exit 0; }; sleep 1; done
echo "stage2-wp: WordPress did not start"; cat "$DIR/../php-server.log"; exit 1
