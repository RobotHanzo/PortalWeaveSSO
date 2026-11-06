#!/usr/bin/env bash
# Local and GitHub runners execute the same disposable WordPress checks.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-portalweave-sso-ci}"
plugin=/var/www/html/wp-content/plugins/portalweave-sso
compose=(docker compose -f compose.test.yml)
task="${1:-integration}"

case "$task" in
  integration|multisite) ;;
  *) printf 'Usage: bash scripts/ci.sh integration|multisite\n' >&2; exit 2 ;;
esac

mkdir -p reports
# The multisite CLI uses the image's www-data UID; grant only this disposable output directory write access.
chmod 0777 reports
# Retry registry downloads before any fixture exists; never retry setup or tests.
for attempt in 1 2 3; do
  if "${compose[@]}" pull --policy missing db wordpress cli; then
    break
  else
    pull_status=$?
  fi
  if [[ "$attempt" -eq 3 ]]; then
    printf 'Image download failed after %s attempts.\n' "$attempt" >&2
    exit "$pull_status"
  fi
  printf 'Image download failed; retrying in %s seconds (%s/3).\n' "$((attempt * 5))" "$attempt" >&2
  sleep "$((attempt * 5))"
done
"${compose[@]}" up --pull never -d --wait --wait-timeout 180 wordpress

if [[ "$task" == multisite ]]; then
  "${compose[@]}" run --pull never --rm cli wp core multisite-install \
    --url=https://network.example.test --title='PortalWeave SSO Multisite Integration' \
    --admin_user=portalweave_sso_network_admin --admin_password=local-test-network-password \
    --admin_email=network@example.org --skip-email
  "${compose[@]}" run --pull never --rm cli wp plugin activate portalweave-sso --network --url=https://network.example.test
  "${compose[@]}" run --pull never --rm cli wp portalweave-sso providers --format=json --url=https://network.example.test
  "${compose[@]}" run --pull never --rm \
    -e "PORTALWEAVE_SSO_REPORT_DIR=$plugin/reports" cli php "$plugin/tests/multisite.php"
else
  "${compose[@]}" run --pull never --rm cli wp core install \
    --url=http://localhost:8098 --title='PortalWeave SSO Integration' \
    --admin_user=portalweave_sso_test_admin --admin_password=local-test-password-only \
    --admin_email=admin@example.org --skip-email
  "${compose[@]}" run --pull never --rm cli wp plugin activate portalweave-sso
  "${compose[@]}" run --pull never --rm cli wp portalweave-sso providers --format=json
  "${compose[@]}" exec -T -e "PORTALWEAVE_SSO_REPORT_DIR=$plugin/reports" wordpress php "$plugin/tests/run.php"
  "${compose[@]}" exec -T wordpress bash "$plugin/tests/setup-tls.sh"
  "${compose[@]}" exec -T wordpress php "$plugin/tests/setup-e2e.php"
  restore_fixture() {
    "${compose[@]}" exec -T wordpress php "$plugin/tests/setup-e2e.php" --restore
  }
  trap restore_fixture EXIT
  curl --insecure --fail --silent --show-error --retry 10 --retry-connrefused --retry-delay 1 \
    https://localhost:8448/wp-login.php > /dev/null
  PORTALWEAVE_SSO_REPORT_DIR=reports node tests/e2e.mjs
fi
