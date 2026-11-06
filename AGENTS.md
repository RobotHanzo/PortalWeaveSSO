# PortalWeave SSO repository guide

This guide applies to the whole repository. PortalWeave SSO is a WordPress OAuth/OIDC plugin maintained at <https://github.com/RobotHanzo/PortalWeaveSSO>.

## Project layout and compatibility

- `portalweave-sso.php` is the plugin entry point. Runtime requirements are PHP 8.1+, WordPress 6.5+ and OpenSSL. Native abilities additionally need WordPress 6.9+; MCP transport comes from the separately installed WordPress MCP Adapter.
- `includes/` contains the namespaced PHP modules. Configuration, cryptography, HTTP and redirects share APIs; authentication resolves accounts before creating sessions. Admin, display, access, extensions, privacy and CLI code should use those APIs rather than duplicate policy.
- `assets/` contains readable production JavaScript and CSS. There are no production Composer or npm dependencies and no required asset compilation step.
- `tests/` contains PHP integration/multisite suites, HTTPS IdP fixtures and Node tooling tests. `scripts/` owns validation, reporting, staging and packaging. `.github/workflows/` is the executable CI/release contract.
- `readme.txt` is the WordPress.org listing. `README.md`, `docs/` and `submission/` cover development, deployment and reviewer information. Treat recorded test counts and scan results as historical snapshots; current runners and workflow artifacts are authoritative.

Keep the public name, main filename, directory slug and literal text domain aligned: **PortalWeave SSO**, `portalweave-sso.php`, `portalweave-sso`. Bootstrap constants use `PORTALWEAVE_SSO_*`.

Preserve the existing `WPSSO` PHP namespace, `wpsso_*` hooks/options/errors, `[wpsso_oauth_login]`, `wp wpsso` commands and documented `WPSSO_*` server configuration constants. They are compatibility interfaces. The Docker source mount remains `/var/www/html/wp-content/plugins/wordpress-sso`; its directory name is distinct from the distribution slug.

## Working practices

- Inspect `git status` and nearby source before editing. Preserve unrelated work, follow existing module conventions and keep changes within the requested scope.
- Commit meaningful increments. Use a branch and pull request for changes to protected `main`; do not bypass the required `CI required` check. Merge, tag or publish only within the user's authorization.
- Keep PHP entry points guarded with `defined('ABSPATH') || exit`. Use WordPress APIs for HTTP, settings, metadata, sessions, mail, scheduling and assets where they meet the operation's contract.
- Validate types and permissions before mutations, prepare SQL values and identifiers, escape at the output boundary, and translate user-facing strings with the literal `portalweave-sso` domain. Keep stable protocol identifiers separate from display text.
- Keep code source-readable. Do not introduce remote executable downloads, developer telemetry, hidden external services or paid unlocks. Update service/privacy disclosures when data flows change.

## Security and data boundaries

- Preserve random, expiring, single-use OAuth state bound to the browser, purpose and blog. Callbacks use the canonical site origin; post-login navigation is separately validated against the redirect allowlist.
- Pin JWT algorithms, issuer, audience and trusted keys. Never trust token-supplied key URLs or weaken signature, nonce, time, subject or replay checks to accommodate a provider.
- Account linking is explicitly enabled and requires a verified unique mailbox. Administrator SSO is off by default and requires per-provider verification. Claim mappings must not write protected metadata or silently grant privileged roles.
- Settings and moderation writes require capability checks plus appropriate nonces. OAuth callbacks, signed OIDC logout and SCIM use their protocol authorization. Do not substitute a browser-form nonce for state/signature/bearer validation. Explain any necessary static-analysis exception narrowly at the relevant operation.
- Treat codes, tokens and passwords as opaque bytes: enforce scalar types, size limits and protocol validation without text sanitizers that corrupt credentials. Use the shared bounded HTTPS client and redirect validator.
- Keep stored secrets and tokens out of diagnostic/configuration output, logs, audit records and structured exports. Retain the existing narrowly scoped handling of required protocol payloads, such as OIDC logout hints. Use the redacted configuration API. Do not commit real credentials, signing keys, salts, test runtime data or private-media files.
- Scope provider links, tokens, groups and provisioning ownership to the current blog/network. WordPress users, public profile fields and sessions can be global; test cross-site effects before changing them.
- Preserve explicit privacy/uninstall choices and blocking policies. Do not publish or destroy private vault files during deactivation, erasure or uninstall. File operations must retain containment, authorization, failure detection and recovery guarantees.

See [architecture and hooks](docs/architecture.md), [privacy behavior](docs/privacy.md), [external services](docs/external-services.md) and [integration contracts](docs/integrations.md) for details.

## Verification

Choose checks appropriate to the change. Documentation-only edits normally need link/command review, `git diff --check` and any affected packaging checks; they do not require destructive database tests. Authentication, authorization, storage or privacy changes need the relevant regression suite and realistic WordPress integration evidence. Use existing checks; add tests when they demonstrate meaningful new behavior or a defect.

Useful commands from the repository root:

```sh
git diff --check
node --test tests/*.test.mjs
node scripts/release.mjs validate
php scripts/lint.php
```

If PHP is available only in a running disposable WordPress container:

```sh
docker compose -f compose.test.yml exec -T wordpress php /var/www/html/wp-content/plugins/wordpress-sso/scripts/lint.php
docker compose -f compose.test.yml exec -T wordpress php /var/www/html/wp-content/plugins/wordpress-sso/tests/run.php
```

For a fresh POSIX shell environment with Docker and Node, the same scripts used by CI run integration/HTTPS and multisite checks:

```sh
COMPOSE_PROJECT_NAME=wpsso-agent-integration bash scripts/ci.sh integration
COMPOSE_PROJECT_NAME=wpsso-agent-multisite bash scripts/ci.sh multisite
```

These are separate runs, not commands to execute concurrently. Each requires fresh disposable volumes, and both bind host ports 8098/8448. Check existing containers and port ownership first; stop only a verified task-owned project before starting the other. Never delete volumes or run fixtures against a real site's database. The harnesses intentionally require the exact `WPSSO Integration` site title and `WPSSO Multisite Integration` network fixture naming; do not remove those guards. Reuse an initialized fixture only through its appropriate test runner, rather than repeating a fresh-install script against it.

For manual HTTPS testing, run `tests/setup-tls.sh` and `tests/setup-e2e.php` in the disposable WordPress container, then `node tests/e2e.mjs`. Always run the container's `tests/setup-e2e.php --restore` in a `finally` block or shell trap, including after failure. Test IdP routes, local test credentials and self-signed TLS belong only in disposable fixtures.

CI verifies minimum/current WordPress/PHP combinations, multisite, syntax, tooling, directory compliance and the package. Preserve failure exits, complete JSON/JUnit reports and summaries. Native Plugin Check can return exit zero despite error findings; process its complete output with:

```sh
node scripts/plugin-check.mjs RAW_OUTPUT_FILE --output reports/plugin-check
node scripts/report-results.mjs reports/plugin-check --expect plugin-check
```

Do not ignore errors or skip categories to make CI green. Review warnings in context and retain their original findings. See [CI guidance](docs/ci.md) and [directory review](docs/directory-review.md).

## Packaging and publishing

```powershell
./scripts/build.ps1 -Slug portalweave-sso
```

The builder validates metadata, stages an explicit production allowlist and verifies portable ZIP entry names and file hashes. The plugin version, `PORTALWEAVE_SSO_VERSION`, readme stable tag/changelog, translation template and any release tag must agree. Use a fresh empty directory for manual staging; the staging tool deliberately refuses to overwrite existing contents.

Do not hand-build an archive or expand the allowlist to include this guide, tests, local IdPs, reports, reviewer materials or development tools. Runtime user guides and translations are included deliberately. `.distignore` is a secondary safeguard for manual tooling, not a replacement for the production manifest.

WordPress.org approval and slug assignment are external steps. Keep deployment disabled until approval, the actual slug and protected SVN credentials are configured. Credentials belong in the `wordpress-org` environment's `WPORG_USERNAME`/`WPORG_PASSWORD` secrets. Publication uses verified immutable `vX.Y.Z` tags and the exact CI artifact; never move a published tag or overwrite an SVN release. See [release instructions](docs/releasing.md) and [submission materials](submission/SUBMISSION-FIELDS.txt).
