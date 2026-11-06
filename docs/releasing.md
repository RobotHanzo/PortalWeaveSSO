# PortalWeave SSO CI and WordPress.org releases

The workflows run checks and prepare release files before a publishing job can use WordPress.org credentials. A normal code push never publishes a plugin update. The repository has not yet been approved for WordPress.org; leave deployment disabled until the directory review is complete and an SVN repository has been assigned.

The proposed name is **PortalWeave SSO**, with slug/text domain `portalweave-sso`. The submission candidate is `dist/portalweave-sso-1.2.1.zip`. This proposal is not a directory assignment or publication. If reviewers assign a different slug, update the main filename, literal text domains, metadata, translation filenames and packaging configuration together before rebuilding.

## One-time WordPress.org setup

Submit the installable plugin ZIP through [WordPress.org's plugin submission page](https://wordpress.org/plugins/developers/add/). Review, approval and the assigned plugin slug are external steps; this repository cannot create or approve its own directory entry. Resolve the review team's findings and confirm the approved slug and the account with commit access before enabling deployment. Check the release's `Tested up to`, contributors, text domain, licensing, external-service disclosures and directory assets as part of submission readiness. A green CI run does not grant directory approval.

After approval, set these GitHub **repository variables** under **Settings → Secrets and variables → Actions → Variables**:

| Variable | Value |
| --- | --- |
| `WORDPRESS_PLUGIN_SLUG` | The exact slug assigned by WordPress.org, without a URL or slashes |
| `WORDPRESS_DEPLOY_ENABLED` | `true` only after the approved SVN repository and release process are ready; keep `false` beforehand |

Create a GitHub environment named `wordpress-org`. Store `WPORG_USERNAME` and `WPORG_PASSWORD` as **environment secrets**. The username must be the exact WordPress.org account name with plugin commit access. Generate a dedicated SVN password in the [WordPress.org account settings](https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password); do not use an IdP client secret or paste credentials into issues, logs, repository files or chat. The workflow maps those secrets to the deploy action's `SVN_USERNAME`/`SVN_PASSWORD` environment variables. See the official [SVN publishing guide](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/).

Environment protection is configurable in GitHub. Restrict publishing to version tags and choose required reviewers if appropriate for the team and GitHub plan. A referenced environment enforces the rules configured on that environment; adding its name to YAML does not create reviewer approval rules. [GitHub environment documentation](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/manage-environments).

## Release preparation

1. Make the plugin header `Version`, the `PORTALWEAVE_SSO_VERSION` constant and `readme.txt` `Stable tag` the same stable `X.Y.Z` version. Update the matching changelog entry, `Tested up to`, minimum runtime headers and translation template consistently. Prerelease suffixes and `Stable tag: trunk` are rejected for directory releases.
2. Run CI and inspect the test summaries, downloadable JUnit/JSON reports and package artifacts. Resolve failed or missing suites. Run live IdP and deployment-specific checks described in [verification.md](verification.md).
3. Run the release workflow manually with its default dry-run setting. This prepares and validates the release artifact without publishing or requiring a WordPress.org account. Before approval this remains the usable release path.
4. Once the approved slug is configured, inspect the ZIP's top-level directory and contents. Review the exact commit that will be tagged; the release workflow must validate the tag against the release metadata and require that commit to belong to the default release branch.
5. Create a matching version tag such as `v1.2.3` on the reviewed commit and push it. Publishing is available only when the repository's deployment switch, tag checks, CI checks, clean official Plugin Check, configured WordPress.org slug, environment rules and secrets permit it. A manual non-dry-run release must be dispatched on the matching tag ref, name that existing tag, and pass the same checks. For example, `gh workflow run release.yml --ref v1.2.3 -f tag=v1.2.3 -f dry_run=false`.

Use a new version for each directory update. Never move a published Git tag or overwrite an existing SVN version tag. If a release job fails, inspect whether SVN actually committed before retrying; a duplicate SVN tag must fail rather than replace an already published version. WordPress.org reads release files from its SVN `trunk` and `tags/<version>` directories. Directory indexing and update propagation can take time. [WordPress release structure](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/).

If SVN publication succeeds and only the GitHub release job fails, rerun that failed job rather than the full workflow: the duplicate-version guard intentionally prevents publishing the same SVN tag again. Release publishing is serialized to prevent two jobs committing to the same plugin concurrently. The deployed files come from the artifact verified by CI, not from a fresh unverified build.

The remote release tag is checked again against the tested commit immediately before SVN and GitHub publication. The repository's active `Immutable release tags` ruleset blocks updates and deletion of `v*` tags to keep release history immutable as well.

Before WordPress.org approval, keep `WORDPRESS_DEPLOY_ENABLED=false` and the `wordpress-org` environment restricted to `v*` tags. SVN credentials belong only in its environment secrets. To prepare a candidate, use `gh workflow run release.yml --ref main -f dry_run=true`; the optional tag input may remain empty. To inspect marketplace-specific findings, run `gh workflow run wordpress-review.yml --ref main -f checkout_ref=main -f slug=portalweave-sso`. Substitute the approved slug only after the source metadata and text domain have been aligned with it.

Before approval, a GitHub-only release can attach the exact verified package without enabling WordPress.org deployment. The existing `github-release` job depends on successful SVN publication, so it is skipped while deployment is disabled. Use a successful Release workflow run for the matching immutable version tag, download that run's `plugin-package` artifact into a fresh empty directory, and verify the checksum and remote tag against its tested commit before publishing. Replace `RELEASE_RUN_ID` and `TESTED_COMMIT_SHA` below with values from that same successful run:

```sh
gh run download RELEASE_RUN_ID --name plugin-package --dir .test-runtime/github-release-v1.2.1
(cd .test-runtime/github-release-v1.2.1 && sha256sum --check portalweave-sso-1.2.1.zip.sha256)
bash scripts/check-release-ref.sh v1.2.1 TESTED_COMMIT_SHA
gh release create v1.2.1 .test-runtime/github-release-v1.2.1/portalweave-sso-1.2.1.zip .test-runtime/github-release-v1.2.1/portalweave-sso-1.2.1.zip.sha256 --verify-tag --target TESTED_COMMIT_SHA --generate-notes
```

Publish those downloaded files directly; do not rebuild or substitute another run's artifact. `--verify-tag` requires an existing remote tag; the separate tag guard checks its exact commit. Keep `WORDPRESS_DEPLOY_ENABLED=false` until directory approval. See the GitHub CLI documentation for [artifact download](https://cli.github.com/manual/gh_run_download) and [release creation](https://cli.github.com/manual/gh_release_create).

## Local validation and packaging

Bash and Node.js 20 or later are required for the release entrypoints. They use shared validation/reporting engines with no npm dependencies. `bash scripts/lint.sh` uses PHP. Building the ZIP uses Bash, Node.js, `zip`, `unzip` and standard shell utilities including `mktemp`.

```sh
bash scripts/release.sh validate
bash scripts/release.sh validate --tag v1.2.1
node --test tests/release.test.mjs
bash scripts/release.sh stage --output .test-runtime/release-stage --slug portalweave-sso
bash scripts/release.sh verify --output .test-runtime/release-stage
```

`portalweave-sso` is the proposed package directory and text domain, pending WordPress.org approval. Staging rejects a package slug that disagrees with the plugin metadata. A stage contains production files directly, ready for the deploy action's `BUILD_DIR`; a ZIP adds the slug as the containing directory. Staging refuses a nonempty output and never recursively deletes an existing destination. Use a fresh output path for each staging attempt.

Build the installable ZIP and SHA-256 file with the same Bash entrypoint used by CI:

```sh
bash scripts/build.sh --slug portalweave-sso
```

The slug and version come from validated plugin metadata. Explicit `--slug` or `--version` values must match that metadata. Version 1.2.1 produces `dist/portalweave-sso-1.2.1.zip` and its `.zip.sha256` checksum. Packaging includes `portalweave-sso.php`, `uninstall.php`, `readme.txt`, `LICENSE`, `includes/`, runtime `assets/`, `languages/` and exactly four user guides: `docs/provider-setup.md`, `docs/integrations.md`, `docs/external-services.md` and `docs/privacy.md`. The main filename, containing directory and text domain match the proposed `portalweave-sso` slug, and the release validator enforces that relationship.

`scripts/build.ps1` remains an optional Windows builder with its existing `-Slug` and `-Version` parameters. CI does not require PowerShell. Both builders use the same Node.js metadata and production-staging engine, and retain the same ZIP entry, manifest and hash verification requirements.

Repository `README.md`, architecture/verification/CI/release/review documents, development code, fixture IdPs, Git configuration, environment files, logs and private key material are excluded or rejected. Symlinks are refused. Verification compares both the file set and SHA-256 digest of every staged file against source. Fresh package extraction is checked independently before using its contents.

The deploy action's `BUILD_DIR` contains everything it will publish; `.distignore` is ignored by that action when `BUILD_DIR` is supplied. The checked staging allowlist is therefore the primary boundary. `.distignore` provides a second boundary for manual tooling that deploys the repository directly. Production runtime assets in `assets/` belong inside the plugin; WordPress.org listing icons, banners and screenshots belong in the separate SVN-level assets directory and must be reviewed before adding them. [Deploy action configuration](https://github.com/10up/action-wordpress-plugin-deploy).

The action's own SVN dry-run still contacts the assigned SVN repository. The repository's credential-free release dry-run prepares the artifact before calling any SVN deployment action, so it works before directory approval. A real SVN deployment is intentionally not exercised as a CI test.

Review [provider service disclosures](external-services.md), [privacy behavior and removal](privacy.md), the [administrator guide](provider-setup.md), and current [directory-review evidence](directory-review.md) for each submission. The installable plugin ZIP is distinct from reviewer-only notes and test evidence; keep those separate from production runtime files.
