# PortalWeave SSO

[![CI](https://github.com/RobotHanzo/PortalWeaveSSO/actions/workflows/verify.yml/badge.svg)](https://github.com/RobotHanzo/PortalWeaveSSO/actions/workflows/verify.yml)

An installable WordPress plugin for multiple OAuth 2.0 and OpenID Connect providers. PHP 8.1+ with OpenSSL and WordPress 6.5+ are required. There are no Composer or production JavaScript dependencies. All modules are bundled.

## Installation

1. Build `dist/portalweave-sso-1.0.0.zip` with `./scripts/build.ps1 -Slug portalweave-sso`, then upload it through **Plugins → Add New → Upload Plugin**.
2. Activate **PortalWeave SSO** and open **Settings → PortalWeave SSO**.
3. Add a provider, copy its callback URL into the provider registration, and configure its issuer, credentials, endpoints and signing algorithms. The callback always uses the canonical WordPress origin; the initiating page is retained separately as the post-login destination.
4. Run **Test SSO Config** to inspect attributes. Configure username/email/group mappings and account policies, then test a regular user before enforcing SSO.
5. Add `[sso_oauth_login]`, the **PortalWeave SSO login** widget, or use the automatic WordPress/WooCommerce login buttons.

SSO requires HTTPS. Configure HTTPS detection correctly when WordPress is behind a trusted reverse proxy. Keep `AUTH_KEY`, `SECURE_AUTH_KEY` and WordPress salts stable: they protect stored secrets and tokens. Changing them requires re-entering credentials and signing in again.

`portalweave-sso` is the proposed WordPress.org slug and the plugin's text domain; directory approval is still required. PHP classes use `PortalWeaveSSO`, hooks/options use `portalweave_sso_*`, server configuration constants use `PORTALWEAVE_SSO_*`, and WP-CLI commands use `wp portalweave-sso`. Local Docker tests mount the same `portalweave-sso` directory.

This is a breaking namespace replacement with no aliases or automatic conversion of earlier stored data. Existing settings, identity links, access rules and vault metadata must be deliberately transitioned before deployment. Keep an existing site behind an external maintenance/access restriction until its policies and private-media delivery have been verified. Follow [replacing an earlier installation](docs/provider-setup.md#replacing-an-earlier-installation), including provider URL and integration updates.

## Features

| Area | Included behavior |
| --- | --- |
| Providers | Multiple generic OAuth/OIDC apps; presets for Entra ID/Azure AD, Azure B2C, Cognito, Keycloak, Okta, Office 365, Salesforce, Google, Auth0, OneLogin, Discord, ClassLink and authentik; custom endpoints and claim paths |
| Protocol | Authorization Code, S256 PKCE, opt-in legacy Password and signed OIDC Implicit flows, refresh-token exchange/rotation, HS256/384/512 and RS256/384/512 JWT validation, pinned JWKS, RP-initiated and signed back-channel logout |
| Configuration | Scope, header/body/public-client credentials, custom state data inside random state, dynamic same-origin callbacks, user-info/group-info endpoints, administrator-only raw attribute testing |
| Accounts | First-login registration or linked users only, verified email linking, standard/custom attribute mapping, groups and email-domain role maps, default role, explicit-match requirement, keep-existing attributes/email/roles, duplicate emails, domain rewrites, per-provider verified administrator policy |
| Login | Allowed redirect origins/path prefixes, login/logout redirects, logout confirmation and templates, popup completion, single flow, standard-login blocking, whole-site authentication |
| Access | Login/role/group restrictions on posts, pages, categories and attachment delivery; core archives, feeds, search, REST and oEmbed filtering; private media vault |
| Presentation | Shortcode, widget, WooCommerce and wp-login integration; five themes, four shapes, custom icons/CSS/text, spacing/radius/size/shadow/hover controls, keyboard focus and reduced-motion support |
| Operations | Transaction/audit report and CSV, optional IP recording, audit retention/disable control, bounded debug logs with seven-day expiry, privacy export/erasure and policy-guide text, opt-in uninstall cleanup, configuration backup/restore, support form and configurable support links, troubleshooting |
| Administration | Site and enforced network settings scoped to the current network, developer hooks, WP-CLI, eight opt-in administrator-only WordPress abilities compatible with MCP Adapter |
| Optional modules | Inbound SCIM Users/Groups, JWT SSO, shared subdomain handoff, temporary guest accounts, moderation, profile pictures, attribute redirects |

OAuth providers vary. Presets are editable templates; a working registration and provider-supported protocol/claims are required. OIDC discovery metadata can be copied into the endpoint fields; discovery is not fetched automatically. Email verification is required by default. A pure OAuth provider without a verification claim can use existing immutable linked identities or an explicitly configured less restrictive user policy; linking and administrator SSO still require the configured verification claim. Implicit login requires a signed OIDC ID token and access-token hash; arbitrary unsigned bearer-token browser login is rejected.

## Media protection

Protecting an attachment moves its original, registered thumbnails and backup sizes out of public uploads into a private vault. URLs are rewritten to authenticated PHP delivery. The default vault is a sibling of the WordPress directory; if this is inside the document root or is not writable, set an absolute private path in wp-config.php:

```php
define('PORTALWEAVE_SSO_PRIVATE_MEDIA_DIR', '/srv/private/wordpress-media');
```

The PHP worker needs read/write access. A path inside the detected document root is refused. Removing protection restores the files; restoration fails if a differing public file would be overwritten. Protecting an attachment cannot retract already-downloaded files or independently cached CDN copies. Purge existing public caches when enabling protection. Custom image optimizers, offloaded storage and regenerated sizes need deployment-specific checks; do not regenerate public derivatives of protected files. Protected delivery sends private/no-store headers. Include the vault in encrypted server backups.

Disabling or deleting the plugin leaves private media in the vault. Restore files to public delivery intentionally before removal, or retain/recover the vault and attachment metadata through a server backup. WordPress plugin deactivation does not silently make private files public.

## Network settings and recovery

On multisite, **Network Admin → PortalWeave SSO** controls the current network. Enable network enforcement to override site settings. Each network has its own configuration; user identities, back-channel sessions and SCIM ownership are scoped to the current site. Use individual content/media editors for site-local post, category and attachment rules; network settings cannot contain site-local IDs. WordPress users/cookies remain network-global. An account privileged on another site requires explicit verified administrator SSO, and SCIM cannot mutate a global account shared by multiple sites.

To restore local login from the server:

```sh
wp portalweave-sso recover
wp portalweave-sso recover --network
```

Alternatively define `PORTALWEAVE_SSO_ALLOW_LOCAL_LOGIN` as true in wp-config.php temporarily. There is no public query-string bypass. Login restrictions do not replace WordPress REST application-password authentication. External plugins that serve content through their own endpoints need their own integration with `PortalWeaveSSO\Access::can_post()`.

Configuration backups exclude client IDs and secrets. Same-site restores retain local credentials; a clean restore leaves incomplete providers and authentication enforcement disabled until credentials are entered. Backups do not include user accounts, audit records, vault files or WordPress itself. Use a database/files backup for full recovery. Support forms send only on explicit administrator submission and require a configured recipient and working WordPress mail. Booking, forum and demo links point to administrator-configured services; no external support service is bundled.

## Privacy and removal

Authentication auditing starts enabled with a 90-day retention setting; IP recording and diagnostic logging are off by default. Administrators can disable new audit collection, choose retention, and use WordPress personal-data export/erasure tools. Review the suggested policy text under **Settings → Privacy** and adapt it to the actual deployment. Provider requests, optional remote images, provisioning, support mail and authenticated MCP clients have different recipients; see [external-service disclosures](docs/external-services.md).

Deactivation retains data. Uninstall retains it unless **Delete plugin data when uninstalling** was explicitly enabled. Cleanup preserves WordPress accounts/content, public profile/custom values, private vault files and vault recovery metadata. Personal-data erasure has its own field-removal behavior and retains security blocks. Read the [privacy and retention guide](docs/privacy.md) before removal.

## Documentation

- [Provider setup and mapping](docs/provider-setup.md)
- [SCIM, JWT, subdomain handoff, AI/MCP and CLI](docs/integrations.md)
- [External services, transmitted data and provider policies](docs/external-services.md)
- [Local privacy, cookies, retention and removal](docs/privacy.md)
- [Architecture and developer hooks](docs/architecture.md)
- [Administrator screen design and safe saving](docs/admin-design.md)
- [Verification and deployment checklist](docs/verification.md)
- [GitHub CI and test reports](docs/ci.md)
- [WordPress.org release automation](docs/releasing.md)
- [WordPress.org review findings and evidence](docs/directory-review.md)

The installable ZIP contains the provider, integrations, external-services and privacy guides, plus translation files. Architecture, verification, CI, release and directory-review documents remain in this repository for maintainers.

MCP clients require WordPress 6.9+ and the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter). The plugin registers eight abilities; the adapter supplies authenticated MCP transport. Abilities and all optional modules are off by default.

## Development

The disposable local environment binds only to loopback. Docker and Node.js are needed for verification:

```sh
docker compose -f compose.test.yml up -d wordpress
docker compose -f compose.test.yml run --rm cli wp core install --url=http://localhost:8098 --title='PortalWeave SSO Integration' --admin_user=portalweave_sso_test_admin --admin_password=local-test-password-only --admin_email=admin@example.org --skip-email
docker compose -f compose.test.yml run --rm cli wp plugin activate portalweave-sso
docker compose -f compose.test.yml exec -T wordpress php /var/www/html/wp-content/plugins/portalweave-sso/tests/run.php
docker compose -f compose.test.yml exec -T wordpress bash /var/www/html/wp-content/plugins/portalweave-sso/tests/setup-tls.sh
docker compose -f compose.test.yml exec -T wordpress php /var/www/html/wp-content/plugins/portalweave-sso/tests/setup-e2e.php
node tests/e2e.mjs
```

The TLS fixture creates a short-lived self-signed certificate and installs a guarded test identity provider only in the disposable environment. Test fixtures and credentials are excluded from the distribution ZIP. Do not install test fixtures on a real site.

License: GPL-2.0-or-later.
