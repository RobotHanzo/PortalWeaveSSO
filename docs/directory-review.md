# WordPress.org directory review

Functional CI and WordPress.org eligibility are separate checks. The release workflow must require a clean Plugin Check result before publishing. Passing automated checks does not replace the WordPress.org review team's initial approval or guarantee acceptance.

## Recorded local scan

Plugin Check 2.1.0 was run against the initial source tree on WordPress 7.1.4. That scan reported **91 errors and 144 warnings across 17 files**. This was a source-tree scan with development directories excluded, before the verified `Tested up to` header was added, rather than a scan of the final staged package. The native output and structured findings were saved locally in ignored `reports/wordpress-plugin-check.txt` and `reports/wordpress-plugin-check.json`.

| Error category | Count | Required review |
| --- | ---: | --- |
| Text domain mismatch | 71 | The checked folder was `wordpress-sso`, while the plugin uses `wpsso`. Choose the approved WordPress.org slug first, then make the text domain and package consistent with it. |
| Output escaping | 9 | Review the HTML renderer, fragment-relay markup and widget wrappers. The scanner does not infer every renderer's escaping contract; verify each result before fixing or documenting a narrowly justified suppression. |
| Direct filesystem operations | 8 | Review private-media streaming/deletion and CLI export operations against WordPress filesystem guidance. Preserve error detection, private-file integrity and streaming behavior when adapting code. |
| Missing translator comment | 1 | Add a translator explanation for the placeholder-bearing translated string. |
| Hidden source file | 1 | `.gitattributes` appeared in the source scan. The production staging allowlist excludes it; verify the staged package rather than suppress the check globally. |
| Missing readme compatibility header | 1 | Resolved by adding `Tested up to: 7.1` after verifying WordPress 7.1.4. |

Warnings include direct-query/caching advice, nonce and input-sanitization analysis, externally allowed redirects, WordPress core hook names, and frontend asset loading. They require review in context. OAuth callbacks and SCIM requests use their own protocol authentication, so adding a WordPress browser nonce indiscriminately would break those protocols. Existing functional/security tests remain necessary when addressing findings.

This snapshot may differ from later staged-package scans or a newer Plugin Check release. Treat the current workflow artifact as authoritative for a release candidate.

The first [hosted staged-package review](https://github.com/RobotHanzo/WordPressSSO/actions/runs/42169165192) ran successfully as a checker and correctly failed its review gate with **89 errors and 142 warnings**. Its artifact is available from the run. The source-only hidden-file finding and missing compatibility header are absent. Remaining errors comprise 71 text-domain mismatches, nine output-escaping findings, eight filesystem-operation findings and one translator-comment finding. These require a follow-up plugin review before enabling publication; they are not suppressed by the workflow.

## Run the check locally

In the disposable WordPress environment:

```sh
docker compose -f compose.test.yml run --rm cli wp plugin install plugin-check
docker compose -f compose.test.yml run --rm cli wp plugin check wordpress-sso --format=json --exclude-directories=tests,scripts,docs,.git,.github,.test-runtime,dist,reports --require=./wp-content/plugins/plugin-check/cli.php
```

**The CLI command returned exit code zero even with error findings.** Do not use its exit status alone as the release gate. Its JSON format consists of repeated `FILE: path` lines followed by a JSON array of findings, rather than one JSON document. Each finding includes `line`, `column`, `type`, `code`, `message` and `docs`.

The official Plugin Check action processes those findings, emits file annotations and returns failure for `ERROR` findings. Its default `strict: false` keeps warnings as warnings; `strict: true` treats all findings as errors. Do not enable `ignore-errors` to make deployment green.

The action uploads the raw report as `plugin-check-results` (or `plugin-check-results-<slug>` when a slug override is supplied). The raw file is `${{ runner.temp }}/plugin-check-results.txt`. Stage the production package first and pass its directory to `build-dir`; use a directory whose basename is the intended plugin slug.

## References

- [Official Plugin Check action and inputs](https://github.com/WordPress/plugin-check-action)
- [Official result processor](https://github.com/WordPress/plugin-check-action/blob/10857da14b6c2246d15402b3e69f777edcf8c12e/src/main.ts)
- [WordPress.org SVN release workflow](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)
- [WordPress internationalization and text domains](https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/)
