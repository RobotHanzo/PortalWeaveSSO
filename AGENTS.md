# PortalWeave SSO repository guide

This guide applies to the whole repository. PortalWeave SSO is a WordPress OAuth/OIDC plugin maintained at <https://github.com/RobotHanzo/PortalWeaveSSO>.

## Project layout and compatibility

- `portalweave-sso.php` is the plugin entry point. Runtime requirements are PHP 8.1+, WordPress 6.5+ and OpenSSL. Native abilities additionally need WordPress 6.9+; MCP transport comes from the separately installed WordPress MCP Adapter.
- `includes/` contains the namespaced PHP modules. Configuration, cryptography, HTTP and redirects share APIs; authentication resolves accounts before creating sessions. Admin, display, access, extensions, privacy and CLI code should use those APIs rather than duplicate policy.
- `assets/` contains readable production JavaScript and CSS. There are no production Composer or npm dependencies and no required asset compilation step.
- `tests/` contains PHP integration/multisite suites, HTTPS IdP fixtures and Node tooling tests. `scripts/` owns validation, reporting, staging and packaging. `.github/workflows/` is the executable CI/release contract.
- `readme.txt` is the WordPress.org listing. `README.md`, `docs/` and `submission/` cover development, deployment and reviewer information. Treat recorded test counts and scan results as historical snapshots; current runners and workflow artifacts are authoritative.

Keep the public name, main filename, directory slug and literal text domain aligned: **PortalWeave SSO**, `portalweave-sso.php`, `portalweave-sso`. Bootstrap constants use `PORTALWEAVE_SSO_*`.

Use the `PortalWeaveSSO` PHP namespace, `portalweave_sso_*` hooks/options/errors, `[portalweave_sso_login]`, `wp portalweave-sso` commands and documented `PORTALWEAVE_SSO_*` server configuration constants. Routes, abilities, asset handles and the distribution directory use `portalweave-sso`. The Docker source mount is `/var/www/html/wp-content/plugins/portalweave-sso`. Do not reintroduce earlier naming aliases. This is a breaking namespace replacement; existing installations require the controlled transition described in [provider setup](docs/provider-setup.md#replacing-an-earlier-installation).

## Working practices

- Inspect `git status` and nearby source before editing. Preserve unrelated work, follow existing module conventions and keep changes within the requested scope.
- Commit meaningful increments. Use a branch and pull request for changes to protected `main`; do not bypass the required `CI required` check. Merge, tag or publish only within the user's authorization.
- Split large features into coherent, reviewable commits when appropriate. Keep each commit focused on one logical chunk and working on its own; keep tightly coupled changes together when splitting would leave a broken intermediate state.
- Follow the earlier non-merge commit convention: `<type>: <concise imperative summary>`, starting the summary with a lowercase verb and omitting a trailing period. Choose `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `build`, `ci` or `chore` to match the change; preserve the proper case of product names and acronyms. Examples: `fix: preserve hidden provider credentials` and `ci: retry image downloads before fixture setup`. Standard GitHub-generated `Merge pull request #...` messages are exempt, as in the existing history.
- Keep PHP entry points guarded with `defined('ABSPATH') || exit`. Use WordPress APIs for HTTP, settings, metadata, sessions, mail, scheduling and assets where they meet the operation's contract.
- Validate types and permissions before mutations, prepare SQL values and identifiers, escape at the output boundary, and translate user-facing strings with the literal `portalweave-sso` domain. Keep stable protocol identifiers separate from display text.
- Keep code source-readable. Do not introduce remote executable downloads, developer telemetry, hidden external services or paid unlocks. Update service/privacy disclosures when data flows change.
- Admin navigation renders one task screen at a time. Keep scoped saving through `AdminSettings::prepare()`; a global-screen save must not replace hidden providers or forward stored secrets as newly submitted credentials. See [admin screen design](docs/admin-design.md).

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

## Documentation and screenshots

Keep `README.md` focused on installation, important features and a small screenshot gallery. Maintain protocol and deployment instructions in the [provider guide](docs/provider-setup.md), [integration contracts](docs/integrations.md) and [privacy guide](docs/privacy.md). Maintainer details belong in [architecture](docs/architecture.md), [admin design](docs/admin-design.md), [verification](docs/verification.md), [CI](docs/ci.md) and [release instructions](docs/releasing.md). Link to those sources rather than copying commands or security policy into the README.

README screenshots must show the current plugin running in a disposable WordPress fixture with clearly fictional sample providers and accounts. Review each image for credentials, personal data, private attributes, nonces and server paths before saving the selected product screenshots in `docs/screenshots/`. Use descriptive filenames, useful alternative text and captions that distinguish sample configuration from a verified provider connection. Refresh affected images when their UI changes. Raw browser captures, traces, authenticated profiles and storage state remain ignored under the existing local artifact paths.

Screenshots are repository documentation and must remain outside the production ZIP. The production manifest includes only four runtime guides from `docs/`: `provider-setup.md`, `integrations.md`, `external-services.md` and `privacy.md`. Keep `docs/screenshots/` excluded from secondary manual distribution tooling too; do not expand the production allowlist for the README gallery.

## Verification

Choose checks appropriate to the change. Documentation-only edits normally need link/command review, `git diff --check` and any affected packaging checks; they do not require destructive database tests. Authentication, authorization, storage or privacy changes need the relevant regression suite and realistic WordPress integration evidence. Use existing checks; add tests when they demonstrate meaningful new behavior or a defect.

Useful commands from the repository root:

```sh
git diff --check
node --test tests/*.test.mjs
bash scripts/release.sh validate
bash scripts/lint.sh
```

If PHP is available only in a running disposable WordPress container:

```sh
docker compose -f compose.test.yml exec -T wordpress bash /var/www/html/wp-content/plugins/portalweave-sso/scripts/lint.sh
docker compose -f compose.test.yml exec -T wordpress php /var/www/html/wp-content/plugins/portalweave-sso/tests/run.php
```

For a fresh POSIX shell environment with Docker and Node, the same scripts used by CI run integration/HTTPS and multisite checks:

```sh
COMPOSE_PROJECT_NAME=portalweave-sso-agent-integration bash scripts/ci.sh integration
COMPOSE_PROJECT_NAME=portalweave-sso-agent-multisite bash scripts/ci.sh multisite
```

These are separate runs, not commands to execute concurrently. Each requires fresh disposable volumes, and both bind host ports 8098/8448. Check existing containers and port ownership first; stop only a verified task-owned project before starting the other. Never delete volumes or run fixtures against a real site's database. The harnesses intentionally require the exact `PortalWeave SSO Integration` site title and `PortalWeave SSO Multisite Integration` network fixture naming; do not remove those guards. Reuse an initialized fixture only through its appropriate test runner, rather than repeating a fresh-install script against it.

For manual HTTPS testing, run `tests/setup-tls.sh` and `tests/setup-e2e.php` in the disposable WordPress container, then `node tests/e2e.mjs`. Always run the container's `tests/setup-e2e.php --restore` in a `finally` block or shell trap, including after failure. Test IdP routes, local test credentials and self-signed TLS belong only in disposable fixtures.

CI verifies minimum/current WordPress/PHP combinations, multisite, syntax, tooling, directory compliance and the package. Preserve failure exits, complete JSON/JUnit reports and summaries. Native Plugin Check can return exit zero despite error findings; process its complete output with:

```sh
bash scripts/plugin-check.sh RAW_OUTPUT_FILE --output reports/plugin-check --strict
bash scripts/report-results.sh reports/plugin-check --expect plugin-check
```

CI runs all official Plugin Check categories and runtime checks, includes experimental checks, and treats warnings/advisories as failures through the strict parser. Do not ignore findings or skip categories to make CI green. Review warnings in context and retain complete raw findings and JSON/JUnit reports, including failing scans. Explain any necessary operation-specific static-analysis exception at the source; never use a file-wide security exemption. See [CI guidance](docs/ci.md) and [directory review](docs/directory-review.md).

## Packaging and publishing

```sh
bash scripts/build.sh --slug portalweave-sso
```

Use the Bash entrypoints in `scripts/` for repository helpers and CI. They share the dependency-free Node.js release/reporting engines and PHP syntax checker. Packaging requires Bash, Node.js 20+, `zip` and `unzip`; local lint requires PHP, or the disposable container command above. `scripts/build.ps1` remains available for optional Windows use, while CI packaging uses `scripts/build.sh`.

The builder validates metadata, stages an explicit production allowlist and verifies portable ZIP entry names and file hashes. The plugin version, `PORTALWEAVE_SSO_VERSION`, readme stable tag/changelog, translation template and any release tag must agree. Use a fresh empty directory for manual staging; the staging tool deliberately refuses to overwrite existing contents.

Do not hand-build an archive or expand the allowlist to include this guide, tests, local IdPs, reports, reviewer materials or development tools. Runtime user guides and translations are included deliberately. `.distignore` is a secondary safeguard for manual tooling, not a replacement for the production manifest.

WordPress.org approval and slug assignment are external steps. Keep deployment disabled until approval, the actual slug and protected SVN credentials are configured. Credentials belong in the `wordpress-org` environment's `WPORG_USERNAME`/`WPORG_PASSWORD` secrets. Publication uses verified immutable `vX.Y.Z` tags and the exact CI artifact; never move a published tag or overwrite an SVN release. See [release instructions](docs/releasing.md) and [submission materials](submission/SUBMISSION-FIELDS.txt).
