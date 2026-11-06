# PortalWeave SSO GitHub CI and reports

[CI runs](https://github.com/RobotHanzo/WordPressSSO/actions/workflows/verify.yml) run on pushes to `main`, pull requests, merge-queue checks and manual dispatch. The [release workflow](https://github.com/RobotHanzo/WordPressSSO/actions/workflows/release.yml) calls the same CI workflow with the exact release commit; a previously green unrelated commit cannot authorize publication.

## Checks

| Job | Coverage |
| --- | --- |
| Syntax, tooling and release metadata | PHP 8.1/8.3 syntax, JavaScript syntax, release/reporting/deployment-tool regressions, shell syntax, actionlint and matching plugin/readme/tag versions |
| WordPress integration (minimum) | WordPress 6.5.5 with PHP 8.1; actual account, database and HTTPS OAuth tests |
| WordPress integration (current) | Current official WordPress PHP 8.3 image; the same integration and HTTPS tests |
| Multisite and multinetwork | Independent WordPress database, network policy, token/identity/SCIM isolation and multiple-network configuration |
| WordPress.org directory compliance | Stages the production package as `portalweave-sso`, runs official Plugin Check, validates the complete native report and publishes JSON/JUnit evidence |
| Build verified plugin ZIP | Runs only after every verification job succeeds; stages allowlisted files, verifies hashes, builds the version-matched ZIP and checksum |
| CI required | Stable branch-protection check that fails if any preceding required job fails, is cancelled or is skipped |

WordPress containers have readiness checks before WP-CLI runs. The minimum and current versions run independently with `fail-fast: false`, so both produce evidence if either fails. Each job uses a disposable database and collects logs before cleanup. Actions are pinned to full commit hashes, and Dependabot proposes updates weekly.

## Read test results

Open a run's **Summary** for per-suite passed/failed counts and failure details. Individual failures appear as GitHub annotations. Download these artifacts from the same run:

- `tests-minimum`: integration and HTTPS JUnit XML, JSON and service logs for the compatibility baseline.
- `tests-current`: the same reports for the current runtime.
- `tests-multisite`: multisite/multinetwork JUnit XML, JSON and service logs.
- `tests-tooling`: native Node.js JUnit report for release validation, report handling and deployment safeguards, with its own job summary.
- `tests-plugin-check`: native Plugin Check output plus validated JSON and JUnit results, including checker/reporting failures.
- `plugin-package`: installable ZIP and SHA-256 checksum; available only after required verification succeeds.

Test artifacts are retained for 14 days and packages for 30 days. Test-report steps and artifact collection run after failures as well as success. Empty, malformed, incomplete, duplicate and missing expected reports fail the job. PHP bootstrap/early termination and unfinished Node runners produce explicit failures instead of silently returning success. Reports use fixture accounts and do not require live IdP or SVN credentials.

The package artifact contains `portalweave-sso-1.0.0.zip` and its checksum for version 1.0.0. The source PHP entry filename and Docker bind remain `wordpress-sso`; those legacy development paths are deliberately separate from the proposed distribution slug/text domain `portalweave-sso`.

The workflow uses read-only repository permission. Native job summaries and annotations do not require `checks: write` or PR comments, so fork PRs can run without publishing permissions. It does not use `pull_request_target` to execute untrusted code. Deployment credentials are never provided to CI jobs.

## Local reproduction

Docker Compose, Bash, curl and Node.js 20+ are needed; the GitHub runner uses Node.js 24. Run each disposable project in isolation because HTTPS fixtures use loopback ports 8098 and 8448:

```sh
COMPOSE_PROJECT_NAME=wpsso-local-ci bash scripts/ci.sh integration
node scripts/report-results.mjs reports --expect integration,https
COMPOSE_PROJECT_NAME=wpsso-local-ci docker compose -f compose.test.yml down --volumes
```

For multisite use a fresh project after freeing the loopback ports:

```sh
COMPOSE_PROJECT_NAME=wpsso-local-network bash scripts/ci.sh multisite
node scripts/report-results.mjs reports --expect multisite
COMPOSE_PROJECT_NAME=wpsso-local-network docker compose -f compose.test.yml down --volumes
```

Set `WPSSO_WORDPRESS_IMAGE` and `WPSSO_CLI_IMAGE` to reproduce a matrix variant. `WPSSO_REPORT_DIR` controls JSON/JUnit output for direct PHP or Node runner invocation. Use a clean reports directory when reproducing jobs: the summarizer rejects unrelated JSON and duplicate suites instead of assuming they passed.

The fixture titles `WPSSO Integration` and `WPSSO Multisite Integration`, fixture account names and `/wp-content/plugins/wordpress-sso` test bind are guards used by the runners. Do not rename them to the public product name. Production bootstrap metadata uses `PORTALWEAVE_SSO_VERSION`, while compatibility CLI, shortcode, namespace and server configuration names remain documented in the [administrator guide](provider-setup.md).

## WordPress.org directory checks and approval

The [WordPress.org directory review workflow](https://github.com/RobotHanzo/WordPressSSO/actions/workflows/wordpress-review.yml) stages production files under the intended directory slug and runs the official Plugin Check action. CI calls this workflow before it can publish a package artifact; it can also be dispatched manually. Its native findings are annotated, parsed fail-closed by `scripts/plugin-check.mjs`, and retained with JSON/JUnit reports. Error findings are not ignored, and an empty, truncated or unfamiliar report cannot become a passing check. Warnings remain visible for manual review; the parser's optional `--strict` policy fails warnings as well.

Directory checks can fail while functional test jobs pass, because coding and packaging requirements extend beyond runtime behavior. Initial WordPress.org approval is a separate external process even when all automated gates pass. Historical findings and current evidence requirements are recorded in [directory-review.md](directory-review.md).

Credential-free release dry runs verify the code and candidate package. They do not claim directory acceptance or contact SVN. Follow [releasing.md](releasing.md) after WordPress.org assigns an approved listing and credentials.
