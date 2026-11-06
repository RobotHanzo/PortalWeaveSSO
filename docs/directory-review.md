# PortalWeave SSO WordPress.org directory review

PortalWeave SSO proposes the directory slug and text domain `portalweave-sso`. It has not yet received initial WordPress.org approval. Functional tests, Plugin Check and the directory review team's approval answer different questions; passing automated checks does not guarantee acceptance.

## Current candidate evidence

Run the checks against the exact candidate commit and its staged production files. The current report artifacts and associated commit identify the applicable findings. Historical counts below are a baseline, not the final candidate's result. Do not claim zero findings without a fresh complete staged-package report.

An earlier rebuilt Windows ZIP was installed and activated in disposable WordPress, then passed **68 integration checks and seven real HTTPS flow checks**. Plugin Check 2.1.0 on that historical package reported **zero errors and 17 warnings**, matching the [first successful hosted compliance run](https://github.com/RobotHanzo/PortalWeaveSSO/actions/runs/42172566475). Those warnings were accepted by the earlier errors-only gate; the current gate fails warnings too. Preserve the historical reports rather than presenting them as current results. The Windows builder verifies canonical ZIP entry paths and every file hash; this caught and corrected a Windows backslash-path incompatibility before the earlier upload was prepared.

The candidate ZIP is `dist/portalweave-sso-1.1.0.zip` for version 1.1.0. It contains the `portalweave-sso/` top-level directory and matching `portalweave-sso.php` entry filename, runtime code/assets, `readme.txt`, `LICENSE`, translations, and the four bundled user guides. Repository-only tests, build tools, README and development/review documents are excluded.

The remediation addresses literal text domains and public naming; late output escaping for buttons, widgets and protocol pages; conditional/deferred frontend assets; nonce and input-type boundaries; privacy collection controls, export/erasure and uninstall choices; translated strings; and documented security-sensitive filesystem operations. Each relevant change still needs its functional regression evidence and fresh scanner result. Narrow comments explain specific operations where a generic static rule cannot express the required protocol, database or atomic-file behavior. They are not blanket exemptions from review.

## Historical baseline

Plugin Check 2.1.0 was initially run on WordPress 7.1.4 against the source tree with development directories excluded. It reported **91 errors and 144 warnings across 17 files**, before the verified `Tested up to` header and final distribution staging were applied. Ignored local reports recorded that baseline.

| Initial error category | Count | Review performed or required |
| --- | ---: | --- |
| Text domain mismatch | 71 | The baseline used an earlier folder/domain combination. The candidate uses literal `portalweave-sso` domains and the matching staged directory. Runtime namespace, options, shortcode, CLI and development mounts now use PortalWeave naming throughout. Earlier identifiers are unsupported. |
| Output escaping | 9 | Review renderers, fragment/popup relay output and widget wrappers at their output boundaries; retain regression tests for hostile markup and protocol behavior. |
| Direct filesystem operations | 8 | Review private-media delivery/deletion and CLI export. Preserve private-file integrity, failure detection, streaming and exclusive-create behavior instead of replacing them with an unsafe abstraction. |
| Missing translator comment | 1 | Explain translated placeholders and retain the translation template in the package. |
| Hidden source file | 1 | `.gitattributes` was included in the source scan. The explicit production allowlist excludes it. |
| Missing compatibility header | 1 | The readme gained `Tested up to: 7.1` after verification on WordPress 7.1.4. |

The first [hosted staged-package review](https://github.com/RobotHanzo/PortalWeaveSSO/actions/runs/42169165192) correctly failed with **89 errors and 142 warnings**. Its errors were the same 71 text-domain, nine escaping, eight filesystem and one translator-comment findings; the source-only hidden-file and missing-header findings were absent. Those historical numbers must not be presented as fresh measurements of the renamed candidate.

Warnings covered direct-query/caching advice, nonce/input analysis, externally allowed redirects, WordPress hook names and asset scope. Review each in context. OAuth/OIDC callbacks and authenticated SCIM requests use their protocol-specific authentication; indiscriminately adding a WordPress browser nonce would break those endpoints. Tests must demonstrate their actual state/signature/bearer and authorization boundaries.

## Reproduce and retain the check

Dispatch the [directory review workflow](https://github.com/RobotHanzo/PortalWeaveSSO/actions/workflows/wordpress-review.yml) for the candidate commit:

```sh
gh workflow run wordpress-review.yml --ref main -f checkout_ref=CANDIDATE_COMMIT -f slug=portalweave-sso
```

The workflow stages only production files and passes that directory to the official Plugin Check action. Functional CI also calls it before building its package artifact. Scan the correctly named stage: a raw-source scan in the Docker bind can include development files that are excluded from the distribution.

**The Plugin Check CLI has returned exit code zero despite error findings.** Do not treat its exit status alone as a release gate. Its `--format=json --no-color` output can contain repeated `FILE: path` headers followed by finding arrays rather than one JSON document. Each finding contains line, column, severity, code, message and documentation fields. A genuine clean run emits an explicit completion message.

The official action annotates findings and retains its native report. The repository additionally validates that complete native output, fails on errors or invalid/missing output, and creates structured JSON/JUnit results:

```sh
bash scripts/plugin-check.sh reports/plugin-check/plugin-check.txt --output reports/plugin-check --strict
bash scripts/report-results.sh reports/plugin-check --expect plugin-check
```

CI always supplies `--strict`: every error or warning fails. The parser's non-strict mode remains available for historical report inspection, but does not establish a passing CI gate. The action runs all static/runtime categories and includes experimental checks, with no ignored codes or category exclusions. The `tests-plugin-check` artifact contains the complete native report plus `plugin-check.json` and `plugin-check.xml`, including reporting failures. The official action can also upload its own `plugin-check-results` artifact. Never enable `ignore-errors` or filter a category to make a release pass.

These Bash entrypoints invoke the shared Node.js parser and report engine; they process the native output rather than replacing the official checker. CI stages and builds through Bash as well. `bash scripts/build.sh --slug portalweave-sso` requires Bash, Node.js 20+, `zip` and `unzip`, and retains manifest, canonical ZIP entry and per-file hash verification. `scripts/build.ps1` is an optional Windows entrypoint; the Windows measurements below remain historical evidence.

## Supplied advisories and security review

The supplied initial report contained 21 warnings: three nonce heuristics, six identity/session metadata-query advisories, seven custom audit-table operations, one uninstall schema operation and four bootstrap-prefix warnings. A fresh Plugin Check 2.1.0 baseline of commit `b121129`, staged under the correct slug with runtime and experimental checks enabled, reproduced 17 warnings and no errors. The four prefix warnings did not reproduce; the current `PORTALWEAVE_SSO_*` constants already match the product name.

On 2026-11-06, the updated production stage returned the official clean-result message: **zero errors and zero warnings**, with every category, runtime checks and experimental checks enabled. The strict parser and report summarizer both passed. Source hashes were rechecked against the scanned stage, and the Windows builder verified the ZIP paths and hashes. The local WordPress suite passed 78 tests, including two new cleanup regressions; Node tooling passed 51 tests with one existing Windows-only skip, and all 37 PHP files passed syntax validation. These are local measurements of the advisory-fix candidate; its associated CI run supplies the minimum/current WordPress, HTTPS, multisite and directory artifacts.

With the official checker and staged candidate installed and activated in disposable WordPress, the equivalent native command is:

```sh
wp plugin check portalweave-sso --include-experimental --format=json --no-color --require=wp-content/plugins/plugin-check/cli.php
```

Save the complete native output and process it with the strict parser shown above. Loading the checker CLI bootstrap before WordPress enables its runtime checks. Do not treat the native process's exit status alone as a passing result.

The reported nonce reads are followed by provider-specific administrator authorization or a verified logout nonce. Exact site/provider digest queries remain necessary for immutable account resolution and signed logout; their result bounds do not guarantee constant-time queries on large directories. The plugin-owned audit table has no equivalent core domain API. Use formatted `wpdb` helpers for exact updates, prepared identifiers/values for range operations, and current authoritative reads for privacy export rather than a secondary personal-data cache. Consent remains mandatory before dropping only the current blog's audit table. Source comments explain these individual analysis exceptions.

Review also found that bulk deletion of expired account locks left non-autoloaded WordPress option-cache entries readable. Cleanup now discovers locks in bounded batches, removes only the exact observed owner and evicts the corresponding option cache after successful deletion. Regressions cover multiple batches and an owner renewed between discovery and deletion.

| WordPress guidance | Implementation and regression evidence |
| --- | --- |
| [Escape at output](https://developer.wordpress.org/apis/security/escaping/) | HTML attributes, URLs, text and permitted markup use their matching escaping functions. Widget/session markup and shortcode rendering are exercised by `tests/compliance-ui.php` and `tests/smoke.php`; protocol relay values use DOM text/input APIs. |
| [Validate or sanitize input](https://developer.wordpress.org/apis/security/sanitizing/) | Settings validate scalar types and bounded JSON, allowlist fields and preserve opaque credentials. Malformed token fields, structured JWT claims, endpoint types and scoped setting writes are covered by the core/auth/admin suites. |
| [Verify nonces and authorization](https://developer.wordpress.org/apis/security/nonces/) | Administrative writes require capabilities and action-specific nonces. OAuth/OIDC and SCIM retain browser-bound state, signatures or HTTPS bearer authorization. Missing/nested/forged form tokens and protocol replay are tested separately. |
| [Plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) | Production staging excludes test tools and credentials. Shipped code remains readable and local; privacy/service disclosures, consented uninstall and retained private vault recovery are checked with packaging, privacy and multisite regressions. |

## Submission review

Before submission, read the current [detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) and [common issues](https://developer.wordpress.org/plugins/wordpress-org/common-issues/), then inspect the actual ZIP. Confirm the license/readme/slug, supported runtime versions, translations, complete local executable assets, absence of credentials/development fixtures, provider/service disclosures and the account/contact information supplied to reviewers. Directory assets such as listing icons, banners and screenshots are separate from runtime `assets/`.

The bundled [external-services guide](external-services.md) identifies when configured services receive data and links official provider privacy/terms resources. The [privacy guide](privacy.md) documents local storage, cookies, collection choices, retention, WordPress personal-data handling, deliberate uninstall cleanup and retained vault recovery data. Keep both consistent with code and the user-facing settings. Default retention is not a guarantee about offline cron, backups or third-party logs.

Initial review is external: upload the candidate through [WordPress.org's submission page](https://wordpress.org/plugins/developers/add/) and provide the separately prepared reviewer information. Keep automated SVN deployment disabled until approval and assignment of the exact slug/repository. Follow [release automation](releasing.md) for the credential and tag gates after approval.

## References

- [Official Plugin Check action and inputs](https://github.com/WordPress/plugin-check-action)
- [WordPress readme requirements](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
- [WordPress internationalization and text domains](https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/)
- [WordPress plugin privacy tools](https://developer.wordpress.org/plugins/privacy/)
- [WordPress.org SVN publishing](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)
