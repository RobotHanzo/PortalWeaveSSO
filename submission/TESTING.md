# Testing PortalWeave SSO 1.0.0

The uploadable archive contains a single `portalweave-sso` directory. Install it through **Plugins → Add New → Upload Plugin**, activate **PortalWeave SSO**, then open **Settings → PortalWeave SSO**. Use WordPress 6.5+, PHP 8.1+ with OpenSSL, and HTTPS. No developer account, activation key or paid module unlock is required.

## Provider login

1. Register an application at your own OAuth/OIDC provider. A self-hosted Keycloak or authentik deployment also works. Configure the exact issuer, authorization/token endpoints, user-info endpoint if needed, JWKS URL or signing key, client ID and supported credentials method.
2. Copy the callback shown by the plugin into the provider's registered redirects. Leave Authorization Code, PKCE and verified-email checks enabled. Use an ordinary test identity with `sub`, `email` and a truthful verified-email claim. Configure other claim paths to match the provider.
3. Save and run **Test SSO Config** from the administrator session. The returned attributes should appear only to that administrator. The action tests configuration without creating a new login session for the provider identity.
4. In a separate browser session, use `[portalweave_sso_oauth_login]` or the login-screen button. Confirm first-login registration, repeat-login identity reuse and the chosen navigation destination. Test existing-account restrictions, verified linking and role rules before enabling whole-site enforcement.
5. Confirm that administrator SSO remains rejected until explicitly enabled for that provider with its verified-account field. Check logout confirmation and, when supported by your provider, RP-initiated and signed back-channel logout.

Provider claims and registrations differ. `docs/provider-setup.md` explains mappings, verification and recovery. There is no automatic discovery fetch. The legacy Implicit flow requires a signed ID token with an access-token hash. The standalone JWT module has a strict five-minute assertion and unique `jti` requirement. Password grant is explicitly legacy and requires provider support. Front-channel iframe logout is not implemented.

## Privacy and safe removal

Check **Settings → Privacy** for suggested policy text. Verify that client IP collection is initially off and that disabling auditing stops new records. Use **Tools → Export Personal Data** and **Erase Personal Data** for a linked ordinary account. Exports must contain no token ciphertext or credentials. Erasure removes local links and known mapped custom fields and revokes sessions, while retaining account/content/roles and blocking policies.

Test uninstall behavior on a disposable copy. Deactivation preserves data; uninstall preserves settings by default. With explicit cleanup enabled, plugin records are removed while users, public profile values and private media/recovery metadata remain. On multisite, confirm a site's privacy or cleanup operation preserves other sites' links, tokens, audits and settings. Public custom WordPress usermeta can be shared globally; the documentation describes that limit.

Media protection needs a writable private path outside the actual document root. Protect an attachment, confirm its public original/registered sizes are gone and authenticated delivery enforces its rule, then restore it intentionally. Back up the vault. Custom image generation, offloaded storage, web-server aliases and CDN copies need deployment-specific checks.

## Reproducible repository checks

The complete test source is public in the repository and excluded from the uploadable ZIP. Docker and Node.js are development tools only. The commands below mutate a dedicated disposable test installation; the harness refuses to run its destructive fixtures unless the exact documented test-site title is present.

```sh
docker compose -f compose.test.yml up -d wordpress
docker compose -f compose.test.yml run --rm cli wp core install --url=http://localhost:8098 --title='PortalWeave SSO Integration' --admin_user=portalweave_sso_test_admin --admin_password=local-test-password-only --admin_email=admin@example.org --skip-email
docker compose -f compose.test.yml run --rm cli wp plugin activate portalweave-sso
docker compose -f compose.test.yml exec -T wordpress php /var/www/html/wp-content/plugins/portalweave-sso/tests/run.php
node --test tests/*.test.mjs
```

The password above is only for the loopback-only disposable fixture. Never use it on a real site. The source mount and distribution both use `portalweave-sso`; fixture titles must match the runner guards. PHP classes use `PortalWeaveSSO`, hooks use `portalweave_sso_*`, and the login shortcode is `[portalweave_sso_oauth_login]`. Existing installations require the controlled replacement procedure in `docs/provider-setup.md`.

On a POSIX host, `bash scripts/ci.sh integration` provisions the fixture and runs PHP integration plus real HTTPS redirect/cookie/PKCE tests against the repository's local IdP. `bash scripts/ci.sh multisite` verifies independent site/network settings, identity ownership, privacy and cleanup on a separate database. GitHub Actions runs both the minimum WordPress/PHP combination and the current supported combination, preserves JUnit/JSON results even after failure, and checks the staged package with official WordPress Plugin Check.

The test IdP and test setup routes are development fixtures. They must never be deployed to a production WordPress installation. No automated suite establishes interoperability with every vendor configuration or replaces review of an actual site's identity and private-media deployment.
