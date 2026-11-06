# Verification and rollout

## Fresh local evidence

The combined source was exercised with WordPress 7.1.4, PHP 8.3.36 and MariaDB 11.4 in disposable Docker environments. This is a verification report, not a claim that every provider or hosting stack was exercised.

| Check | Evidence |
| --- | --- |
| PHP syntax | `php scripts/lint.php` checks plugin, scripts and test files |
| JavaScript syntax | `node --check` on both production scripts and protocol harness |
| Real WordPress integration | `php tests/run.php`: 48 checks covering crypto, state, refresh, mappings, registration/linking, administrator policy, domains, duplicate emails, REST/content/oEmbed, media vault/restore, audit/privacy, backups, SCIM, moderation, native abilities and lifecycle cleanup |
| HTTPS authorization flow | `node tests/e2e.mjs`: 7 protocol checks using actual HTTPS WordPress requests and cookies with a guarded local test IdP; PKCE, initiating redirect, bad state/browser/verifier, replay, external redirect rejection and admin test isolation |
| Real WordPress multisite | `php tests/multisite.php`: 9 checks covering new-site schema, independent networks, enforced network configuration, global privileged identities, provider groups, OAuth token isolation/refresh, scoped back-channel logout, SCIM ownership, moderation and avatar isolation |
| Packaging | Installable ZIP contains a single wordpress-sso directory, production code, assets, license and docs; excludes git history, tests, Docker fixtures, credentials and development caches |

## Run the multisite checks

Use a separate disposable database/WordPress volume, network-install WordPress with title `WPSSO Multisite Integration`, network-activate the plugin and run `tests/multisite.php` through PHP CLI. The runner refuses unrelated networks. It creates a second fixture site and cleans up fixture users and settings. The local validation environment used a `wpsso-multisite` Docker Compose project and the same plugin bind mount; the single-site test database remained independent.

## Deployment-dependent checks

- Register and test each real IdP, including issuer/audience, client authentication, scopes, UserInfo subject, verification claims, group pagination/overage behavior, PKCE, refresh rotation and callback URIs. Provider presets are templates. Group-info JSON can be mapped with `_wpsso_groupinfo.<path>`; provider-specific pagination/Graph group-overage expansion is not automatic.
- Validate the host's HTTPS/reverse-proxy setup and PHP OpenSSL support. Stable WordPress salts are necessary for encrypted-secret recovery. Confirm the vault is outside the actual document root, writable and covered by backups. Purge pre-existing CDN media copies; test image optimizers and offloaded storage.
- Run regular-user and explicitly enabled administrator login, popup behavior, logout confirmation, RP logout and signed back-channel logout against the provider. Back-channel logout revokes every WordPress session for each matched local user. Provider front-channel iframe logout is not implemented.
- Check native browser appearance, widget placement, WooCommerce theme integration and site-specific CSP/custom CSS. The automated HTTPS harness models browser cookie/state behavior; it does not render a browser or prove visual appearance.
- Connect MCP clients through an authenticated WordPress MCP Adapter and test an administrator identity. Local tests prove native ability registration, execution and permissions; external Claude/Cursor/ChatGPT transport was not exercised. WordPress before 6.9 runs the plugin without native abilities.
- Configure the site's support email/links and confirm delivery with the site's mail transport. No support messages were sent during implementation. Scheduling requires working WordPress cron. Audit rows include personal data; use retention and WordPress privacy tools to match site policy.
- For shared SSO, test each trusted HTTPS origin and domain-cookie policy. A signed handoff lasts one minute and creates independent WordPress sessions. It is not continuous central session revocation. Every sibling subdomain that can set cookies must be trusted.
- Verify other plugins' APIs and caches. Core REST, archives, feeds, search and oEmbed are covered. XML-RPC authentication is disabled for whole-site restriction; per-object restrictions do not replace external API permissions. Avoid public caches for restricted content and do not expose protected data through third-party endpoints.

PHP 8.1 and WordPress 6.5 are declared minimums based on the APIs used; the minimum-version matrix and every external provider have not been executed locally. Use a staging installation before enforcing login on an existing site. Local recovery is available through `wp wpsso recover` or the server-only `WPSSO_ALLOW_LOCAL_LOGIN` constant.
