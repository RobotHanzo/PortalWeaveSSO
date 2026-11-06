# PortalWeave SSO GitHub CI and reports

[CI runs](https://github.com/RobotHanzo/PortalWeaveSSO/actions/workflows/verify.yml) run on pushes to `main`, pull requests, merge-queue checks and manual dispatch. The [release workflow](https://github.com/RobotHanzo/PortalWeaveSSO/actions/workflows/release.yml) calls the same CI workflow with the exact release commit; a previously green unrelated commit cannot authorize publication.

## Checks

| Job | Coverage |
| --- | --- |
| Syntax, tooling and release metadata | PHP 8.1/8.3 syntax, JavaScript syntax, release/reporting/deployment-tool regressions, shell syntax, actionlint and matching plugin/readme/tag versions |
| WordPress integration (minimum) | WordPress 6.5.5 with PHP 8.1; actual account, database and HTTPS OAuth tests |
| WordPress integration (current) | Current official WordPress PHP 8.3 image; the same integration and HTTPS tests |
| Multisite and multinetwork | Independent WordPress database, network policy, token/identity/SCIM isolation and multiple-network configuration |
| WordPress.org directory compliance | Stages the production package as `portalweave-sso`, runs every official static/runtime category including experimental checks, fails on errors or warnings, and publishes complete native/JSON/JUnit evidence |
| Build verified plugin ZIP | Runs only after every verification job succeeds; stages allowlisted files, verifies hashes, builds the version-matched ZIP and checksum |
| CI required | Stable branch-protection check that fails if any preceding required job fails, is cancelled or is skipped |

The integration and multisite harness downloads all three service images before starting a fixture. Registry failures get at most three attempts with 5- and 10-second waits; successful cached images are reused. Startup and one-off commands use `--pull never`, so later steps cannot trigger another registry download. Exhausted downloads, container startup, fixture setup and test failures remain failures; setup and tests are never retried.

Repository helper steps use Bash entrypoints, including `scripts/build.sh` for packaging; CI does not require PowerShell. Release validation and reporting share their dependency-free Node.js engines, and `scripts/lint.sh` invokes the PHP syntax checker. The Bash builder requires Node.js 20+, `zip` and `unzip`, and verifies the same production manifest, portable entry names and file hashes as the optional Windows builder.

The helper entrypoints are `release.sh` for metadata/staging, `plugin-check.sh` for complete native-output parsing, `report-results.sh` for suite summaries, `report-tooling.sh` for tooling JUnit summaries, and `check-wordpress-release.sh` for the read-only WordPress.org repository/version preflight. The existing `check-release-ref.sh` retains the remote-tag guard. All are under `scripts/`; invoking the wrappers does not change their verification or publication requirements.

WordPress containers have readiness checks before WP-CLI runs. The minimum and current versions run independently with `fail-fast: false`, so both produce evidence if either fails. Each job uses a disposable database and collects logs before cleanup. Actions are pinned to full commit hashes.

## Automatic dependency updates

[Dependabot configuration](../.github/dependabot.yml) checks GitHub Actions and the root Docker Compose images every Monday at 09:00 Asia/Singapore. It opens update pull requests against the default branch, using the repository's `ci:` commit prefix and a limit of five open version-update PRs per ecosystem. Minor/patch Actions updates are grouped separately from minor/patch test-image updates; major updates receive individual PRs. Full action commit pins and their inline version comments are maintained by the Actions updater. The plugin has no npm or Composer dependency manifests, so those ecosystems are not configured.

The WordPress Plugin Check action stays outside the routine Actions group. Its exact reviewed SHA is asserted in `tests/deployment.test.mjs`; review the upstream activation/early-CLI bootstrap and retained runtime/experimental coverage before changing that assertion alongside a dependency update. All update PRs use the existing read-only `pull_request` CI and required gate. Dependency automation does not merge PRs or authorize a release.

Dependabot alerts, the dependency graph and automatic security-update PRs are enabled in the repository's GitHub security settings. Security updates run independently of the weekly version-update schedule for supported ecosystems. Docker Compose coverage includes `compose.test.yml` and its environment-variable image defaults; floating image tags can also receive upstream image refreshes without a Git diff. The deliberately fixed minimum/current WordPress/PHP matrix, Node runtime inputs, shell-command image references and actionlint's release URL/checksum still need deliberate maintainer updates; they are not ordinary Action or Compose dependency references. See [GitHub's supported ecosystems](https://docs.github.com/en/code-security/reference/supply-chain-security/supported-ecosystems-and-repositories) and [Dependabot options](https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference).

## Read test results

Open a run's **Summary** for per-suite passed/failed counts and failure details. Individual failures appear as GitHub annotations. Download these artifacts from the same run:

- `tests-minimum`: integration and HTTPS JUnit XML, JSON and service logs for the compatibility baseline.
- `tests-current`: the same reports for the current runtime.
- `tests-multisite`: multisite/multinetwork JUnit XML, JSON and service logs.
- `tests-tooling`: native Node.js JUnit report for release validation, report handling and deployment safeguards, with its own job summary.
- `tests-plugin-check`: native Plugin Check output plus validated JSON and JUnit results, including checker/reporting failures.
- `plugin-package`: installable ZIP and SHA-256 checksum; available only after required verification succeeds.

Test artifacts are retained for 14 days and packages for 30 days. Test-report steps and artifact collection run after failures as well as success. Empty, malformed, incomplete, duplicate and missing expected reports fail the job. PHP bootstrap/early termination and unfinished Node runners produce explicit failures instead of silently returning success. Reports use fixture accounts and do not require live IdP or SVN credentials.

The package artifact contains `portalweave-sso-1.3.0.zip` and its checksum for version 1.3.0. The PHP entry filename is `portalweave-sso.php`; the Docker source bind and production package both use the `portalweave-sso` directory.

The workflow uses read-only repository permission. Native job summaries and annotations do not require `checks: write` or PR comments, so fork PRs can run without publishing permissions. It does not use `pull_request_target` to execute untrusted code. Deployment credentials are never provided to CI jobs.

## Local reproduction

Docker Compose, Bash, curl and Node.js 20+ are needed; the GitHub runner uses Node.js 24. Run each disposable project in isolation because HTTPS fixtures use loopback ports 8098 and 8448:

```sh
COMPOSE_PROJECT_NAME=portalweave-sso-local-ci bash scripts/ci.sh integration
bash scripts/report-results.sh reports --expect integration,https
COMPOSE_PROJECT_NAME=portalweave-sso-local-ci docker compose -f compose.test.yml down --volumes
```

For multisite use a fresh project after freeing the loopback ports:

```sh
COMPOSE_PROJECT_NAME=portalweave-sso-local-network bash scripts/ci.sh multisite
bash scripts/report-results.sh reports --expect multisite
COMPOSE_PROJECT_NAME=portalweave-sso-local-network docker compose -f compose.test.yml down --volumes
```

Set `PORTALWEAVE_SSO_WORDPRESS_IMAGE` and `PORTALWEAVE_SSO_CLI_IMAGE` to reproduce a matrix variant. `PORTALWEAVE_SSO_REPORT_DIR` controls JSON/JUnit output for the PHP/HTTPS runners and reporting entrypoints. Use a clean reports directory when reproducing jobs: the summarizer rejects unrelated JSON and duplicate suites instead of assuming they passed.

The fixture titles `PortalWeave SSO Integration` and `PortalWeave SSO Multisite Integration`, fixture account names and `/wp-content/plugins/portalweave-sso` test bind are guards used by the runners. Keep setup commands and runner guards synchronized without weakening their refusal to run against unrelated sites. Bootstrap metadata uses `PORTALWEAVE_SSO_VERSION`; CLI, shortcode, namespace and server configuration names are documented in the [administrator guide](provider-setup.md).

## WordPress.org directory checks and approval

The [WordPress.org directory review workflow](https://github.com/RobotHanzo/PortalWeaveSSO/actions/workflows/wordpress-review.yml) stages production files under the intended directory slug and runs the official Plugin Check action. CI calls this workflow before it can publish a package artifact; it can also be dispatched manually. The action activates the target and enables runtime checks through the checker CLI bootstrap. No category/check filters are supplied, and `include-experimental: true` includes experimental checks as well. The complete native findings are annotated, parsed through `scripts/plugin-check.sh` with `--strict`, and retained with JSON/JUnit reports. Every error or warning fails this gate; empty, truncated, unfamiliar or missing output also fails. There are no ignored codes or report allowlists.

Review legitimate protocol, custom-table, atomic-lock and private-file operations at their source. A necessary line-specific static-analysis exception must name the precise check and explain the actual authorization, validation or consistency guarantee. Do not add a browser-form nonce to OAuth callbacks, sanitize opaque credentials as text, or cache personal data solely to satisfy a heuristic. The functional suites verify these boundaries separately from static analysis.

Directory checks can fail while functional test jobs pass, because coding and packaging requirements extend beyond runtime behavior. Initial WordPress.org approval is a separate external process even when all automated gates pass. Historical findings and current evidence requirements are recorded in [directory-review.md](directory-review.md).

Credential-free release dry runs verify the code and candidate package. They do not claim directory acceptance or contact SVN. Follow [releasing.md](releasing.md) after WordPress.org assigns an approved listing and credentials.
