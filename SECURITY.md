# Security Policy

## System and Scope

This policy covers the whole PortalWeave SSO repository, maintained at
[RobotHanzo/PortalWeaveSSO](https://github.com/RobotHanzo/PortalWeaveSSO).
It defines assessment context and required security properties, not evidence that
the implementation satisfies them.

Scope includes the WordPress plugin entry points, `includes/`, `assets/`,
`uninstall.php`, optional integrations, and security-relevant test fixtures,
packaging scripts and GitHub workflows. Public browser authentication, signed
logout, enabled SCIM endpoints and media delivery are exposed to remote callers.
Administration, abilities and local CLI have distinct authorization boundaries.

The [architecture](docs/architecture.md), [provider guide](docs/provider-setup.md),
[integration contracts](docs/integrations.md) and [privacy guide](docs/privacy.md)
describe the supported behavior. Source and fresh verification evidence establish
actual behavior; historical test counts and scan summaries do not prove safety.

## Threat Model and Trust Boundaries

Assets include WordPress accounts, roles and sessions; provider links and groups;
credentials, signing secrets and tokens; configuration and audit data; and
restricted content, private media and recovery metadata.

Assume unauthenticated visitors, ordinary users and malicious provider account
holders can submit requests, replay captured protocol inputs and influence their
profile claims. Requests, headers, cookies, URLs, uploaded configuration and
provider payloads require validation at the boundary where they are consumed.
A valid provider signature establishes provenance, not permission to grant
WordPress privileges or overwrite protected metadata.

Site administrators, network administrators, provisioning clients and automation
identities have only their explicitly authorized scope. A site's administrator or
SCIM credential must not acquire another site's or network's authority. Installed
PHP hooks and local CLI operators execute with server authority; configured
providers and shared-domain peers have the documented authentication authority.
These trust assumptions do not excuse plugin-origin authorization bypasses.

## Security Invariants

- **Authentication transactions:** OAuth state and JWT browser challenges remain
  unpredictable, expiring, browser-bound, purpose-bound and blog-bound, with
  atomic single-use consumption. Configuration changes invalidate affected flows.
  Custom state supplements randomness. Callbacks use the canonical site origin;
  final navigation separately passes the redirect allowlist.
- **Protocol validation:** Pin algorithms, issuer, audience and trusted key
  sources; validate signatures, applicable timestamps and profile-specific
  subject, session and other claims. Token-supplied keys or key URLs cannot establish trust.
  OIDC browser flows require applicable nonce and subject binding; OAuth2 uses the
  configured immutable subject from HTTPS provider responses. Enforce PKCE where
  required, explicit legacy-grant enablement, and implicit-flow access-token hash
  checks. Signed logout and JWT/shared handoffs retain their replay protections.
- **Accounts and privileges:** Resolve identity using blog, provider, issuer,
  client and immutable subject. Existing-account linking requires explicit opt-in
  and a unique verified mailbox; rewriting a domain cannot prove ownership.
  Administrator SSO and privileged role mappings require per-provider enablement
  and verification. Claim mappings cannot write privileged or internal metadata.
  Apply account policy before mutation or session creation.
- **Operation-specific authorization:** Administrative and moderation forms need
  appropriate capabilities and nonces. Network operations require network
  authority. OAuth callbacks, signed logout and SCIM use their protocol
  authorization; a missing browser-form nonce alone does not demonstrate a flaw.
  Abilities recheck enablement and permissions at execution; audit output stays
  administrator-only. Configuration exports and ability outputs use
  `Config::redacted()` and exclude client identifiers. The support ability sends
  only with explicit `send=true` and a configured recipient. Provider tests stay
  bound to the initiating administrator/session and cannot authenticate the test
  subject. Scoped saves preserve hidden providers, settings and stored secrets.
- **Secrets and input handling:** Use authenticated encryption for stored credentials
  and tokens. Keep them out of diagnostics, logs, audit rows, structured exports and
  unauthorized browser output. Required OIDC logout hints retain only their
  narrowly documented protocol handling. Bound parsing and preserve opaque
  credential bytes. Prepare SQL values/identifiers and escape rendered output.
- **Network and navigation:** Identity HTTP requests use the shared bounded HTTPS
  client with certificate verification, unsafe-destination rejection and no
  redirects. Validate browser destinations independently. Forwarded client IPs
  are audit attribution only, trusted through explicit server proxy configuration.
- **Multisite and provisioning:** Scope plugin links, tokens, groups, audit data,
  provisioning ownership and policy to the appropriate blog/network. SCIM cannot
  adopt existing local accounts, grant WordPress privileges, mutate privileged
  users or change global user/session state for accounts shared across blogs.
  WordPress core profiles, tracked custom profile values and sessions can be
  global; assess each operation's documented cross-site effects.
- **Optional authentication and account state:** JWT/shared SSO must enforce their
  configured issuer, audience, expiry, browser binding where applicable and
  atomic replay controls, then apply local account policy. Shared handoffs cannot
  propagate themselves or contain OAuth tokens. Guest identities cannot take
  over permanent accounts. Applicable moderation, provisioning and guest-expiry
  blocks cover native and Application Password authentication.
- **Content and private files:** Enforce configured access rules across supported
  frontend, listing, REST and attachment delivery paths. Private originals,
  derivatives and backup sizes remain outside the document root, with authorized
  delivery and private cache headers. File operations retain containment, exact
  stored-file selection, failure detection and recoverability. Failed relocation
  must not silently leave a supposedly protected file publicly available.
- **Privacy and removal:** Exports exclude credentials/tokens. Erasure preserves
  blocking and expiry policies and does not delete accounts, roles, authored
  content or vault files. Deactivation preserves data; uninstall cleanup requires
  explicit site/network consent and preserves public profile values, private
  files and recovery metadata without clearing unrelated sites' data.
- **Distribution and automation:** Production packages use the explicit allowlist,
  portable paths and verified file hashes, excluding fixtures, development tools,
  secrets and private runtime data. Untrusted pull-request code must not receive
  publication credentials. Publication requires the verified immutable release
  tag, matching metadata, required checks for that commit and the exact tested
  artifact. Missing or incomplete security-check output cannot count as success.

## Reportable Findings and Severity Context

Report reachable violations with concrete confidentiality, integrity or
availability impact. Establish attacker-controlled input, required authority,
supported configuration, affected assets and the control actually bypassed.
Account takeover, privilege escalation, cross-site authority violations, token or
private-content disclosure, SSRF, injection, unsafe file operations and meaningful
resource exhaustion are relevant finding classes, not an exhaustive list.

Severity depends on realistic reachability, privileges gained, data sensitivity,
tenant scope and operational impact. Disabled-by-default modules remain in scope
when enabled in supported configurations. Administrative access does not
automatically suppress a finding involving CSRF, secrets or a wider trust boundary.
Missing heuristic patterns, test names and documented controls alone establish
neither a vulnerability nor its absence.

## Out of Scope, Exclusions, and Accepted Risk

The owner has specified no excluded surfaces or finding classes and no accepted
risks. Deployment dependencies and documented limitations below are assessment
context, not suppression authority.

## Known Limitations and Compensating Controls

- WordPress core owns global accounts and sessions. Some authorized logout,
  blocking and erasure operations revoke shared sessions; erasure can remove
  tracked global custom fields. Site scoping does not promise complete isolation.
- Shared-domain SSO requires trust in every application on the parent cookie
  domain. Its signed claims are readable, and logout does not universally revoke
  sessions already created on other sites. Local account rules still apply.
- Vault protection cannot retract prior CDN/browser/backup copies. Document-root
  placement, cache purging, offloaded storage, regeneration and custom content
  endpoints need deployment checks; custom endpoints must enforce access rules.
- WordPress cron/cache behavior affects physical cleanup, but expired
  authentication records must fail even before deletion. Salt-derived encryption
  does not protect against compromise of both the database and server secrets.
- The separate WordPress MCP Adapter owns transport/authentication; the plugin
  retains execution permissions and output safety. Remote image hosts and
  explicit support submissions have the data flows described in
  [external services](docs/external-services.md). Arbitrary support text and
  screenshots need review beyond structured configuration redaction.

Tests in `tests/` document intended controls and regressions. Real provider,
web-server, cache and multisite behavior requires relevant fresh evidence; this
policy does not certify those deployments.
