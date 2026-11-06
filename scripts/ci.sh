#!/usr/bin/env bash
# Local and GitHub runners execute the same disposable WordPress checks.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-wpsso-ci}"
plugin=/var/www/html/wp-content/plugins/wordpress-sso
compose=(docker compose -f compose.test.yml)
task="${1:-integration}"

case "$task" in
  integration|multisite) ;;
  *) printf 'Usage: bash scripts/ci.sh integration|multisite\n' >&2; exit 2 ;;
esac

mkdir -p reports
# The multisite CLI uses the image's www-data UID; grant only this disposable output directory write access.
chmod 0777 reports
"${compose[@]}" up -d --wait --wait-timeout 180 wordpress

if [[ "$task" == multisite ]]; then
  "${compose[@]}" run --rm cli wp core multisite-install \
    --url=https://network.example.test --title='WPSSO Multisite Integration' \
    --admin_user=wpsso_network_admin --admin_password=local-test-network-password \
    --admin_email=network@example.org --skip-email
  "${compose[@]}" run --rm cli wp plugin activate wordpress-sso --network --url=https://network.example.test
  "${compose[@]}" run --rm \
    -e "WPSSO_REPORT_DIR=$plugin/reports" cli php "$plugin/tests/multisite.php"
else
  "${compose[@]}" run --rm cli wp core install \
    --url=http://localhost:8098 --title='WPSSO Integration' \
    --admin_user=wpsso_test_admin --admin_password=local-test-password-only \
    --admin_email=admin@example.org --skip-email
  "${compose[@]}" run --rm cli wp plugin activate wordpress-sso
  "${compose[@]}" exec -T -e "WPSSO_REPORT_DIR=$plugin/reports" wordpress php "$plugin/tests/run.php"
  "${compose[@]}" exec -T wordpress bash "$plugin/tests/setup-tls.sh"
  "${compose[@]}" exec -T wordpress php "$plugin/tests/setup-e2e.php"
  restore_fixture() {
    "${compose[@]}" exec -T wordpress php "$plugin/tests/setup-e2e.php" --restore
  }
  trap restore_fixture EXIT
  curl --insecure --fail --silent --show-error --retry 10 --retry-connrefused --retry-delay 1 \
    https://localhost:8448/wp-login.php > /dev/null
  WPSSO_REPORT_DIR=reports node tests/e2e.mjs
fi
