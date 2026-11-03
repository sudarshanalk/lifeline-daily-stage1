#!/usr/bin/env bash
# stage2-wp: throwaway WordPress for the Stage 2 browser journeys in CI (stage2-e2e job). Not for staging or production.
# Needs: wp-cli, PHP 8.1 with mysqli, MariaDB on 127.0.0.1:3306 (root / ci-only, database wp), Mailpit on 127.0.0.1:1025.
# Installs Paid Memberships Pro from wordpress.org, copies the lld-members plugin from this checkout, activates both,
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
# Paid Memberships Pro left wordpress.org in October 2024 (its "latest-stable" there is the old 3.2.2), so CI, local and
# staging all use the vendor's tagged release from GitHub, pinned to the same version
PMPRO_VERSION=3.8.9
curl -fsSL --retry 3 -o "$DIR/../pmpro.zip" "https://codeload.github.com/strangerstudios/paid-memberships-pro/zip/refs/tags/$PMPRO_VERSION"
unzip -q -o "$DIR/../pmpro.zip" -d "$DIR/../pmpro"
mv "$DIR/../pmpro/paid-memberships-pro-$PMPRO_VERSION" "$DIR/wp-content/plugins/paid-memberships-pro"
wp plugin activate paid-memberships-pro --path="$DIR" --quiet
cp -R "$REPO/site/public_html/wp-content/plugins/lld-members" "$DIR/wp-content/plugins/lld-members"
# the member layout points its @font-face rules at lld-tools' self-hosted fonts (S2-L1): copy them so CI matches staging
mkdir -p "$DIR/wp-content/mu-plugins/lld-tools/assets"
cp -R "$REPO/site/public_html/wp-content/mu-plugins/lld-tools/assets/fonts" "$DIR/wp-content/mu-plugins/lld-tools/assets/fonts"
wp plugin activate lld-members --path="$DIR" --quiet
wp lld-members db-update --path="$DIR"
# Stage 3 (NFR3-09 live tests): the interface's switches at test_accounts, so only test accounts see the Stage 3
# screens: the two listed test members and the owner admin (an admin is always a test account, so it sees every
# Stage 3 screen whose switch is at test_accounts). Every other Stage 2 member stays exactly as Stage 2
# (content_new stays off here on purpose).
for f in api account_hub goals today insights food_tracker health_logging calculators_new reminders sign_in_social sign_in_code; do wp lld-members feature "$f" test_accounts --path="$DIR" --quiet; done
wp lld-members test-accounts p3-api-a@test.lifelinedaily.invalid p3-api-b@test.lifelinedaily.invalid p3-signin-a@test.lifelinedaily.invalid p3-signin-b@test.lifelinedaily.invalid p3-signin-c@test.lifelinedaily.invalid p3-signin-d@test.lifelinedaily.invalid p3-signin-e@test.lifelinedaily.invalid p3-signin-f@test.lifelinedaily.invalid p3-signin-g@test.lifelinedaily.invalid p3-signin-h@test.lifelinedaily.invalid p3-signin-relay@privaterelay.appleid.test --path="$DIR" --quiet
# P3-20: a Google client that exists only here, so the Continue with Google button and the start route can be checked;
# the callback is never reached (the tests complete provider sign-ins through the test route instead)
wp config set --path="$DIR" LLD_GOOGLE_CLIENT_ID ci-google-client.apps.googleusercontent.com --quiet
wp config set --path="$DIR" LLD_GOOGLE_CLIENT_SECRET ci-only --quiet
# Router: without one, PHP's built-in server answers 404 itself for any missing path with a dot (/sitemap.xml)
# instead of passing it to WordPress, unlike Apache/LiteSpeed with the WordPress .htaccess on staging and production
nohup php -S 127.0.0.1:8090 -t "$DIR" "$REPO/tools/local/router.php" > "$DIR/../php-server.log" 2>&1 &
for i in $(seq 1 30); do curl -fsS -o /dev/null "$URL/" && { echo "stage2-wp: WordPress ready at $URL"; exit 0; }; sleep 1; done
echo "stage2-wp: WordPress did not start"; cat "$DIR/../php-server.log"; exit 1
