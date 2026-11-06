# PortalWeave SSO WordPress.org directory review

PortalWeave SSO proposes the directory slug and text domain `portalweave-sso`. It has not yet received initial WordPress.org approval. Functional tests, Plugin Check and the directory review team's approval answer different questions; passing automated checks does not guarantee acceptance.

## Current candidate evidence

Run the checks against the exact candidate commit and its staged production files. The current report artifacts and associated commit identify the applicable findings. Historical counts below are a baseline, not the final candidate's result. Do not claim zero findings without a fresh complete staged-package report.

The rebuilt Windows ZIP was installed and activated in disposable WordPress, then passed **68 integration checks and seven real HTTPS flow checks**. Plugin Check 2.1.0 on that installed package reported **zero errors and 17 warnings**, matching the [first successful hosted compliance run](https://github.com/RobotHanzo/WordPressSSO/actions/runs/42172566475). The warnings concern nonce-flow analysis (3), exact usermeta identity/session lookups (6), prepared operations on the plugin audit table (7), and deliberate uninstall schema removal (1). See `submission/REVIEWER-NOTES.txt` and the complete raw/JSON/JUnit reports in the reviewer-materials archive for the contextual assessment. No error category was ignored. The Windows builder also verifies canonical ZIP entry paths and every file hash; this caught and corrected a Windows backslash-path incompatibility before the upload was prepared.

The candidate ZIP is `dist/portalweave-sso-1.0.0.zip` for version 1.0.0. It contains the `portalweave-sso/` top-level directory and matching `portalweave-sso.php` entry filename, runtime code/assets, `readme.txt`, `LICENSE`, translations, and the four bundled user guides. Repository-only tests, build tools, README and development/review documents are excluded.

The remediation addresses literal text domains and public naming; late output escaping for buttons, widgets and protocol pages; conditional/deferred frontend assets; nonce and input-type boundaries; privacy collection controls, export/erasure and uninstall choices; translated strings; and documented security-sensitive filesystem operations. Each relevant change still needs its functional regression evidence and fresh scanner result. Narrow comments explain specific operations where a generic static rule cannot express the required protocol, database or atomic-file behavior. They are not blanket exemptions from review.

## Historical baseline

Plugin Check 2.1.0 was initially run on WordPress 7.1.4 against the source tree with development directories excluded. It reported **91 errors and 144 warnings across 17 files**, before the verified `Tested up to` header and final distribution staging were applied. Ignored local reports recorded that baseline.

| Initial error category | Count | Review performed or required |
| --- | ---: | --- |
| Text domain mismatch | 71 | Baseline used folder `wordpress-sso` and domain `wpsso`. The candidate now uses literal `portalweave-sso` domains and the matching staged directory. Legacy namespace, options, shortcode and CLI names remain compatible. |
| Output escaping | 9 | Review renderers, fragment/popup relay output and widget wrappers at their output boundaries; retain regression tests for hostile markup and protocol behavior. |
| Direct filesystem operations | 8 | Review private-media delivery/deletion and CLI export. Preserve private-file integrity, failure detection, streaming and exclusive-create behavior instead of replacing them with an unsafe abstraction. |
| Missing translator comment | 1 | Explain translated placeholders and retain the translation template in the package. |
| Hidden source file | 1 | `.gitattributes` was included in the source scan. The explicit production allowlist excludes it. |
| Missing compatibility header | 1 | The readme gained `Tested up to: 7.1` after verification on WordPress 7.1.4. |

The first [hosted staged-package review](https://github.com/RobotHanzo/WordPressSSO/actions/runs/42169165192) correctly failed with **89 errors and 142 warnings**. Its errors were the same 71 text-domain, nine escaping, eight filesystem and one translator-comment findings; the source-only hidden-file and missing-header findings were absent. Those historical numbers must not be presented as fresh measurements of the renamed candidate.

Warnings covered direct-query/caching advice, nonce/input analysis, externally allowed redirects, WordPress hook names and asset scope. Review each in context. OAuth/OIDC callbacks and authenticated SCIM requests use their protocol-specific authentication; indiscriminately adding a WordPress browser nonce would break those endpoints. Tests must demonstrate their actual state/signature/bearer and authorization boundaries.

## Reproduce and retain the check

Dispatch the [directory review workflow](https://github.com/RobotHanzo/WordPressSSO/actions/workflows/wordpress-review.yml) for the candidate commit:

```sh
gh workflow run wordpress-review.yml --ref main -f checkout_ref=CANDIDATE_COMMIT -f slug=portalweave-sso
```

The workflow stages only production files and passes that directory to the official Plugin Check action. Functional CI also calls it before building its package artifact. Scan the correctly named stage: a raw-source scan in the legacy Docker `wordpress-sso` bind can give a different expected text domain and can include development files.

**The Plugin Check CLI has returned exit code zero despite error findings.** Do not treat its exit status alone as a release gate. Its `--format=json --no-color` output can contain repeated `FILE: path` headers followed by finding arrays rather than one JSON document. Each finding contains line, column, severity, code, message and documentation fields. A genuine clean run emits an explicit completion message.

The official action annotates findings and retains its native report. The repository additionally validates that complete native output, fails on errors or invalid/missing output, and creates structured JSON/JUnit results:

```sh
node scripts/plugin-check.mjs reports/plugin-check/plugin-check.txt --output reports/plugin-check
node scripts/report-results.mjs reports/plugin-check --expect plugin-check
```

Use `--strict` on the parser to make warnings fail as well. The default policy reports warnings and fails errors; warnings still require manual assessment. The `tests-plugin-check` artifact contains the native report plus `plugin-check.json` and `plugin-check.xml`, including reporting failures. The official action can also upload its own `plugin-check-results` artifact. Never enable `ignore-errors` to make a release pass.

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
