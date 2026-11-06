# PortalWeave SSO: pre-submission review

Reviewed 6 November 2026 for the initial 1.0.0 candidate, proposed directory slug `portalweave-sso`. This records engineering work and evidence. WordPress.org has not approved the plugin or assigned the slug; its reviewers make that decision.

## Directory guidelines

The numbers below refer to the official [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/). Evidence refers to the submitted implementation, not a claim of certification.

| Guideline | Implementation and evidence |
| --- | --- |
| 1 | GPL-2.0-or-later plugin header and complete GPL license; no bundled runtime dependency or copied provider logo. Human-readable PHP, JS and CSS are shipped. |
| 2 | Reviewed all shipped source and service boundaries; regression checks cover malformed inputs, authorization, account takeover, role escalation, media integrity and privacy isolation. Maintainer remains responsible for operation and future updates. |
| 3 | A complete 1.0.0 package is prepared. After approval, the release workflow deploys the same verified package to SVN and attaches it to the corresponding GitHub release. |
| 4 | No obfuscated assets, binary runtime or minified-only source. The public repository includes maintained source, reproducible package tools and test harnesses; readme.txt links the repository. |
| 5 | All bundled modules can be enabled without payment, activation keys, quotas or plugin trial expiry. Guest expiry is a configured account policy. |
| 6 | OAuth/OIDC identity providers supply the necessary authentication service. Administrators choose their actual endpoints and registration. Plugin use is independent of a developer-operated subscription. |
| 7 | No developer telemetry. Providers/images/support/MCP destinations and data are documented. No provider is pre-enabled; optional features need configuration. IP recording defaults off; audit collection can be disabled. Site operators must provide notices and authorization matching their deployment. |
| 8 | No remote executable loading, embedded updater or downloaded plugin code. Tokens/profiles/JWKS are bounded data handled through the WordPress HTTP API. JS/CSS execute from local plugin assets. |
| 9 | Listing describes actual supported protocols and limits; no approval, affiliation or legal-compliance promise. No copied competitor materials, review incentives or hidden collection. |
| 10 | No injected public developer credits or advertisements. Site owners choose button content and external links. Authentication redirects are part of the requested login operation. |
| 11 | Settings use their own contextual screen. No takeover of unrelated dashboard pages or automatic review/upgrade advertising. A missing required PHP extension produces an actionable administrator notice. |
| 12 | Concise functional description, five relevant tags, matching compatibility/version data, no keyword/provider repetition or unprovided screenshots. |
| 13 | Core HTTP, settings, escaping, roles, sessions, cron, mail, REST and privacy APIs are used. No replacement copies of core libraries. Local browser code has no framework dependency. |
| 14 | Git commits track development. SVN deployment is limited to verified, immutable version tags after approval; CI does not mirror every source commit into SVN. |
| 15 | Release validation binds plugin header, bootstrap version, readme stable tag and changelog to an exact stable version. Release publication requires a matching tag on main and rejects replacing a directory release. |
| 16 | Installable ZIP includes every runtime module, local assets, translation template, license, readme and four user guides. Build/test fixtures and developer tooling are excluded. |
| 17 | Renamed to PortalWeave SSO to avoid the existing WPSSO product name. Public bootstrap constants use PORTALWEAVE_SSO_. Provider names describe editable integrations without endorsement. Proposed slug availability is not guaranteed. |
| 18 | Initial manual review remains required. Deployment is disabled. The maintainer must respond to review requests and maintain an accurate WordPress.org contact account. |

## Additional engineering checks

The [common-issues checklist](https://developer.wordpress.org/plugins/wordpress-org/common-issues/) informed changes to contextual output escaping, strict scalar/type validation, capability plus nonce checks, HTTP/enqueue APIs, SQL preparation, direct-access guards and production packaging. Provider callbacks use protocol authentication, with rationale at the boundary; they do not bypass authentication.

Settings/support/configuration actions enforce administrator authority. The eight abilities recheck `manage_options` and their disabled-by-default switch even on direct execution, and validate JSON primitive types before changes. Network configuration uses the current network. SCIM bearer credentials cannot confer privileged WordPress roles or mutate shared/privileged accounts. Redirect allowlists use exact origins and path boundaries. JWT validation pins trusted inputs and rejects replay. No token or credential is copied into the personal-data export or structured support configuration.

The [privacy handbook](https://developer.wordpress.org/plugins/privacy/), [exporter API](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-exporter-to-your-plugin/), [eraser API](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-eraser-to-your-plugin/) and [privacy-policy API](https://developer.wordpress.org/plugins/privacy/suggesting-text-for-the-site-privacy-policy/) informed the linked-profile/audit handlers and suggested text. Tests exercise literal-email matching, duplicate-email accounts, token exclusion, session revocation, retained blocking policies and site isolation. Audit queries avoid a secondary cache holding erased personal data. Provider and local storage details are included in docs/privacy.md and docs/external-services.md.

The [uninstall guidance](https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/) informed the guarded uninstall entry point. Data removal requires explicit configuration and applies site/network consent. Private files and recovery metadata are retained even when cleanup is selected. Uninstall never deletes users or authored content; private-file recovery is documented. Scheduled tasks stop on deactivation.

The [internationalization guidance](https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/) informed the matching slug/text domain, literal translated UI strings, contextual escaping and POT template in `languages/portalweave-sso.pot`. WordPress manages loading directory translations. Machine-readable protocol fields are intentionally stable identifiers. The [readme guidance](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/) informed version headers, changelog and service disclosures. No contributor's WordPress.org identity is invented. [Directory images/screenshots](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/) are optional listing materials and are not required to activate the plugin; none are claimed in this candidate.

## Automated findings and deliberate exceptions

The final raw Plugin Check report and JSON/JUnit conversion accompany this document in the reviewer-materials ZIP. The checker runs against the staged production directory with the intended slug, without ignored errors or skipped categories. The initial hosted baseline had 89 errors and 142 warnings; subsequent code, domain, rendering and packaging fixes removed the error findings. The final exact counts and package checksum are recorded in BUILD-INFO.txt and the reports, rather than inferred from the checker's native exit status.

The official checker's CLI can exit zero with findings. `scripts/plugin-check.mjs` retains every native FILE/JSON finding, fails on errors or missing/malformed output and writes compatible JSON/JUnit. CI preserves the native action's failure as well. Warnings remain visible and are assessed by category in REVIEWER-NOTES.txt. A clean automated gate does not establish that a plugin is free from vulnerabilities or will be approved.

Narrow source annotations explain operations that generic analysis cannot model:

| Boundary | Reason and protection |
| --- | --- |
| OAuth/JWT return and server provisioning endpoints | Browser-bound one-use OAuth state, signed replay-protected JWT, or explicit bearer authorization replaces an unrelated WordPress admin-form nonce. Test flows add their own administrator nonce/session. |
| Atomic state, replay and account lock operations | Prepared SQL performs compare/delete or one-use claims atomically. Relevant option caches are invalidated. Stale account workers cannot release a newer owner's lock. |
| Audit/privacy and uninstall SQL | Plugin-owned tables require explicit database operations. Parameters and identifiers are prepared. Privacy reads are current; no secondary PII cache outlives erasure. Schema deletion requires explicit uninstall consent. |
| Binary private-media delivery | Local private-vault mapping, containment and access checks precede streaming. Reading the entire file into memory would break large-file delivery. File-deletion vetoes cannot falsely report media as protected. |
| CLI backup creation | Exclusive local-file creation prevents replacement or symlink following, and restrictive POSIX permissions apply immediately. The standard filesystem overwrite mode cannot satisfy this contract. |
| Opaque protocol values | Codes/tokens/passwords retain their bytes; bounded scalar and protocol validation is used instead of text sanitizers that would corrupt credentials. |

These are not whole-file or workflow-wide security exclusions. The submission does not ask reviewers to ignore them without examining their authorization path.

## Verification and scope limits

Fresh local verification includes 68 PHP integration checks, 12 independent multisite/multinetwork checks and seven real HTTPS login/cookie/redirect/PKCE checks. Node tooling covers release safety, deployment contracts, result reporting, native checker parsing and auth-page DOM boundaries. GitHub CI repeats integration/HTTPS on the minimum and current WordPress/PHP combinations and separately runs multisite, syntax, tooling and the directory gate. Exact hosted run links and build metadata accompany the final material.

No suite establishes that every provider edition/claim policy is interoperable. The docs describe strict legacy/JWT constraints, lack of iframe front-channel logout, bounded SCIM functionality, one-minute shared-domain handoff, external cache limits and site-specific media/storage requirements. Real deployments must test their configured provider, group rules, reverse proxy, vault and backup process.

The remaining submission step is the owner's authenticated upload at [Add your Plugin](https://wordpress.org/plugins/developers/add/). Approval and the final slug are external decisions. See SUBMISSION-FIELDS.txt for account fields and the post-approval deployment setup.
