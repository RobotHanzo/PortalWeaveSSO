# Verification and rollout

## November 2026 reviewer follow-up

On 2026-11-11, disposable WordPress 7.1.4 / PHP 8.3.36 fixtures passed 154 single-site integration tests, 18 HTTPS protocol tests and 42 multisite tests. Linux tooling passed all 100 tests without skips. New regressions cover retired/imported CSS, the prefixed shortcode, scoped media notices, persistent salt-independent vault identity, corrupt identifiers, legacy recovery, document-root rejection and multisite cleanup retention. The official checker with all categories, runtime and experimental checks passed the strict error/warning parser with zero findings. These are local candidate measurements; required CI remains authoritative for a merged commit.

Playwright confirmed the Appearance screen has no custom-CSS input, renders the prefixed placement hint, and retains working shared and WordPress button controls. Refreshed screenshots use the fictional built-in Example provider preview; no external provider was authenticated. Provider legal resources were reviewed separately, with the documented One Identity retrieval and ClassLink agreement limitations.

## Version 1.2.0 administrator workflow checks

The combined administrator changes were exercised on 2026-11-07 in a new disposable WordPress 7.1.4 / PHP 8.3 fixture with fictional providers and audit history. Playwright verified the following behavior in the running plugin:

- Visual mappings retain exact JSON through mode switches and restored values. Unsupported JSON stays editable in advanced mode; incomplete rows trigger the reminder and block submission, including when their provider panel is hidden.
- The floating Save action completes real provider-mapping, provider-logo and integration form submissions, retains the selected section, and returns to a clean form. Revert restores all values, added rows and image previews.
- The native WordPress media dialog uploads and selects an image, previews its HTTPS URL, and supports removal and reversion.
- All eight integration panels support keyboard navigation. Invalid hidden controls reveal their panel; navigation alone does not create an unsaved draft.
- Audit pagination displays at most 50 events, carries a stable snapshot, advances through Next/Last/First, and disables unavailable directions.
- Integrations and audit views fit a 320px viewport. Reduced motion removes the banner transition. With JavaScript disabled, all provider and integration sections and the JSON fallback remain reachable.

The existing HTTPS protocol suite also passed, including canonical sidebar routes and redirects from earlier Settings bookmarks. The complete official Plugin Check, including runtime and experimental checks, passed the strict parser with no errors or warnings after request-method validation was corrected. Its initial failing report was retained alongside the final local report. Packaging validated the production allowlist and hashes; product screenshots remain excluded. These are local evidence snapshots; the required CI checks and immutable-tag release artifacts remain authoritative for the final merged commit.

## Historical local evidence

The earlier source was exercised with WordPress 7.1.4, PHP 8.3.36 and MariaDB 11.4 in disposable Docker environments. These counts predate the full namespace replacement. Current runners and reports are authoritative for the current source; this historical report does not establish that every provider or hosting stack was exercised.

| Check | Evidence |
| --- | --- |
| PHP syntax | The shared `scripts/lint.php` engine checks plugin, scripts and test files; the current entrypoint is `bash scripts/lint.sh` |
| JavaScript syntax | `node --check` on both production scripts and protocol harness |
| Real WordPress integration | `php tests/run.php`: 48 checks covering crypto, state, refresh, mappings, registration/linking, administrator policy, domains, duplicate emails, REST/content/oEmbed, media vault/restore, audit/privacy, backups, SCIM, moderation, native abilities and lifecycle cleanup |
| HTTPS authorization flow | `node tests/e2e.mjs`: 7 protocol checks using actual HTTPS WordPress requests and cookies with a guarded local test IdP; PKCE, initiating redirect, bad state/browser/verifier, replay, external redirect rejection and admin test isolation |
| Real WordPress multisite | `php tests/multisite.php`: 9 checks covering new-site schema, independent networks, enforced network configuration, global privileged identities, provider groups, OAuth token isolation/refresh, scoped back-channel logout, SCIM ownership, moderation and avatar isolation |
| Packaging | All 25 historical packaged files were compared with their source. The extracted ZIP activated and deactivated successfully in WordPress and passed the same 48 integration checks. It used a single plugin directory and excluded git history, tests, Docker fixtures, credentials and development caches. Current packages use the `portalweave-sso` directory |

Current helper commands use Bash entrypoints with shared Node.js release/reporting engines and the PHP lint engine. Build with `bash scripts/build.sh --slug portalweave-sso` using Bash, Node.js 20+, `zip` and `unzip`; CI uses that builder without PowerShell. The optional Windows builder remains available. These entrypoints preserve production staging, ZIP entry and file-hash checks without changing the historical results above.

## Reproduce the current checks

Use the [CI local reproduction procedure](ci.md#local-reproduction) for `bash scripts/ci.sh integration` and `bash scripts/ci.sh multisite`. It covers the current single-site/HTTPS and multisite setup rather than a hand-maintained sequence of install commands. The fixtures bind only to loopback and require Docker Compose, Bash, curl and Node.js 20+. Run them separately with fresh disposable volumes; both use host ports 8098/8448. Check port/project ownership first and never run fixture setup against a real site or an unrelated database. Keep the exact integration site and network titles required by the runners.

The HTTPS harness creates a short-lived self-signed certificate and installs a guarded local test identity provider. These fixtures and their sample credentials stay outside the distribution ZIP. For a manual HTTPS run in an already initialized disposable fixture, always invoke `tests/setup-e2e.php --restore` in a `finally` block or shell trap, including when `node tests/e2e.mjs` fails. Follow [AGENTS.md](../AGENTS.md#verification) for those manual-run safeguards. Reuse initialized fixtures through their test runners rather than repeating the fresh-install procedure.

## Deployment-dependent checks

- Register and test each real IdP, including issuer/audience, client authentication, scopes, UserInfo subject, verification claims, group pagination/overage behavior, PKCE, refresh rotation and callback URIs. Provider presets are templates. Group-info JSON can be mapped with `_portalweave_sso_groupinfo.<path>`; provider-specific pagination/Graph group-overage expansion is not automatic.
- Validate the host's HTTPS/reverse-proxy setup and PHP OpenSSL support. Stable WordPress salts are necessary for encrypted-secret recovery. Confirm the vault is outside the actual document root, writable and covered by backups. Purge pre-existing CDN media copies; test image optimizers and offloaded storage.
- Run regular-user and explicitly enabled administrator login, popup behavior, logout confirmation, RP logout and signed back-channel logout against the provider. Confirm that Go back retains the session, site-only sign-out does not visit the provider, and the combined action names the linked provider and appears only with a valid end-session endpoint. Verify the provider return rejects missing browser state and replay. Back-channel logout revokes every WordPress session for each matched local user. Provider front-channel iframe logout is not implemented.
- Check native browser appearance, widget placement, WooCommerce theme integration and site-specific CSP/custom CSS. The automated HTTPS harness models browser cookie/state behavior; it does not render a browser or prove visual appearance.
- Connect MCP clients through an authenticated WordPress MCP Adapter and test an administrator identity. Local tests prove native ability registration, execution and permissions; external Claude/Cursor/ChatGPT transport was not exercised. WordPress before 6.9 runs the plugin without native abilities.
- Configure the site's support email/links and confirm delivery with the site's mail transport. No support messages were sent during implementation. Scheduling requires working WordPress cron. Audit rows include personal data; use retention and WordPress privacy tools to match site policy.
- For shared SSO, test each trusted HTTPS origin and domain-cookie policy. A signed handoff lasts one minute and creates independent WordPress sessions. It is not continuous central session revocation. Every sibling subdomain that can set cookies must be trusted.
- Verify other plugins' APIs and caches. Core REST, archives, feeds, search and oEmbed are covered. XML-RPC authentication is disabled for whole-site restriction; per-object restrictions do not replace external API permissions. Avoid public caches for restricted content and do not expose protected data through third-party endpoints.

GitHub CI now also executes the full integration and HTTPS suite on WordPress 6.5.5/PHP 8.1 alongside the current PHP 8.3 WordPress image, with downloadable JSON/JUnit reports. The exact WordPress 6.5.0 patch release and every external provider have not been exercised. Use a staging installation before enforcing login on an existing site. Local recovery is available through `wp portalweave-sso recover` or the server-only `PORTALWEAVE_SSO_ALLOW_LOCAL_LOGIN` constant. See [ci.md](ci.md) for hosted workflows and reports.
