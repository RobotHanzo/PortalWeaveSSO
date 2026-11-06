# Optional integrations and operations

Every optional module starts disabled. Enabling a module does not disable the core account rules: provider identities, verified email, administrator protections, domain restrictions and redirect allowlists still apply.

## SCIM 2.0 inbound provisioning

Enable **SCIM** and set a dedicated random bearer secret of at least 32 characters. This secret is encrypted in the WordPress settings and excluded from exports. Configure the provisioning client with the base URL `https://your-site.example/wp-json/wpsso/v1/scim/v2` and `Authorization: Bearer YOUR_SECRET`. HTTPS is required. Configure trusted reverse-proxy HTTPS detection in WordPress when TLS terminates at a proxy; never trust arbitrary incoming forwarded headers.

The API implements Users and Groups collection/item GET, POST, PUT, PATCH and DELETE, plus ServiceProviderConfig, Schemas and ResourceTypes discovery. A User POST always creates a subscriber with a random unusable-in-practice password. SCIM cannot set passwords, WordPress roles or capabilities, take over existing local users, or mutate privileged users. Creating an already-used username, email or externalId returns a conflict. Changing a WordPress username returns a mutability error. Groups are separate provider groups; group provisioning does not grant WordPress roles.

Supported filters are `userName eq "value"`, `externalId eq "value"`, `id eq "value"`, `displayName eq "value"` and `emails.value eq "value"`. Other grammar returns a SCIM invalidFilter response. Pagination uses startIndex (one based) and count (0–200). Bulk, sorting, password changes and ETags are not advertised. Collection listing remains suitable for small and medium directories; equality filtering currently scans provisioned resources in batches, so benchmark large directories before rollout.

PATCH accepts add, replace and remove for userName, externalId, displayName, active, name, emails and members; `name.givenName`/`name.familyName` and `members[value eq "123"]` removal are supported. Unsupported paths fail explicitly. PUT replaces the supported mutable fields; absent optional attributes are cleared or use their standard active default. DELETE users deactivates the local account and revokes every WordPress session instead of deleting authored content. GET continues returning the retained resource with active=false. Group deletion removes memberships. Reprovision the user with active=true to restore access.

### Example

```http
POST /wp-json/wpsso/v1/scim/v2/Users
Authorization: Bearer YOUR_SECRET
Content-Type: application/scim+json

{"schemas":["urn:ietf:params:scim:schemas:core:2.0:User"],"externalId":"directory-123","userName":"ada","active":true,"emails":[{"value":"ada@example.org","primary":true}],"name":{"givenName":"Ada","familyName":"Lovelace"}}
```

Reference: [SCIM protocol, RFC 7644](https://www.rfc-editor.org/rfc/rfc7644.html).

## JWT single sign-on

Enable the JWT SSO module and the provider's JWT SSO switch. This uses an OpenID provider capable of returning a signed ID token with `response_type=id_token` and `response_mode=form_post`. Pin the provider's issuer, client ID, signing algorithms and signing key source exactly as with the normal OIDC flow.

Register an additional callback at the provider: `https://your-site.example/?wpsso_action=jwt_callback`. Initiate login through `https://your-site.example/?wpsso_action=jwt_start&app=PROVIDER_ID`. A safe `redirect_to` can be appended. The plugin creates a five-minute browser-bound challenge, nonce and state. The provider must POST state and id_token to the callback. Cross-site POST needs a Secure, HttpOnly, SameSite=None challenge cookie. A unique string jti and a token expiry at most five minutes from callback time are additionally required; some providers need a custom claim or token policy for this. The default Authorization Code flow is available when a provider cannot meet this contract.

ID tokens in query parameters and unsolicited bearer-token login are not accepted. State and token jti are consumed atomically. Validation uses the configured keys and issuer, never token-supplied key URLs. This module's five-minute assertion and replay policy may be stricter than an identity provider's defaults.

## Shared sign-in across trusted subdomains

Enable Shared SSO globally and on the corresponding provider at every participating site. Use the same app ID, immutable subject mapping, issuer, a dedicated 32+ character shared signing secret, a parent cookie domain, and an exact list of trusted HTTPS origins on every site. For example: cookie domain `example.org`, origins `https://one.example.org` and `https://two.example.org`.

Successful authentication issues a Secure, HttpOnly, SameSite=Lax parent-domain cookie containing a signed, one-minute identity assertion. It contains mapped identity claims, not OAuth access or refresh tokens. Each participating site consumes the assertion once, resolves its own local account under its own account policy, and creates a normal WordPress session. Consuming an assertion does not issue another one, so trust cannot extend itself indefinitely. This is an SSO handoff: a user must visit another site during the assertion's one-minute lifetime. Later visits use the identity provider's normal SSO flow.

Every application on the parent domain must be trusted: a compromised sibling application or leaked shared signing secret can impersonate users. A cookie domain must be a domain you control, not a public suffix. Separate site-local roles are resolved independently. Logging out clears the shared assertion; already-created sessions on other WordPress sites require their own logout or IdP session management. This module does not claim universal cross-site session revocation. Subdirectory installations sharing the same origin already share the cookie namespace and should use WordPress multisite session handling instead.

## Guest accounts, moderation and avatars

Guest login is per-provider and requires the global Guest module. Guest providers create temporary subscriber identities with the configured lifetime (5 minutes to 24 hours), and cannot sign in existing permanent accounts. Expired guests cannot authenticate. Hourly cleanup destroys sessions and deletes expired guests only when they have not authored content; content-bearing expired guests remain disabled for administrator review. WordPress cron must run for scheduled cleanup.

Moderation marks identities first resolved through a moderated provider as pending and denies login until an administrator approves them. In Users → Edit User → SSO account moderation, an administrator can set Pending, Approved or Blocked. Updates have a dedicated nonce and require manage_options and edit_user. Blocking revokes all sessions; approval does not change roles. Privileged administrators are excluded from automatic pending status to preserve recovery access; provider administrator SSO protections still apply.

The avatar module reads the provider's configured avatar attribute (picture by default), accepts only a public HTTPS URL, and maps it to WordPress avatar display. The browser fetches the remote avatar; enabling it may disclose visitor network details to that image host. Attribute redirects evaluate configured claim/equals/url rules in order after login, then apply the global redirect allowlist.

## AI abilities and MCP

On WordPress 6.9 or later, enable **AI / MCP abilities** to register exactly eight namespaced abilities: `wpsso/list-providers`, `wpsso/get-provider`, `wpsso/configure-provider`, `wpsso/diagnose`, `wpsso/fix-common-error`, `wpsso/export-config`, `wpsso/login-audit`, `wpsso/support-query`. All execution paths require manage_options and recheck that the feature is enabled. Outputs exclude credentials and client identifiers; audit output contains personal data and remains administrator-only.

Install and configure the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) to expose these abilities to Claude Desktop, Cursor, ChatGPT or another compatible MCP client. This plugin provides abilities; the adapter owns MCP transport, authentication and connection instructions. Both current meta.public and the older meta.mcp.public flags are supplied. Use an authenticated administrator identity and the adapter's discovery tools to discover the abilities. Keep application passwords out of source code.

Safe automatic fixes are deliberately bounded to enabling PKCE, using Authorization Code, adding openid scope and clearing a callback override. Diagnosis does not pretend to test an IdP interactively; use Test SSO Config in the admin. support-query creates a local draft by default, retained for one day. It sends WordPress email only with explicit send=true and a configured support recipient; mail delivery depends on the site's WordPress mail setup.

References: [Abilities registration](https://developer.wordpress.org/reference/functions/wp_register_ability/), [official MCP adapter guide](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/).

## WP-CLI and recovery

```sh
wp wpsso providers --format=json
wp wpsso diagnose my-provider
wp wpsso export ./sso-backup-redacted.json
wp wpsso import ./sso-settings-private.json
wp wpsso set debug false
wp wpsso audit --limit=50 --format=csv
wp wpsso recover
wp wpsso recover --network
```

CLI runs with the authority of the local server operator. Backups are redacted configuration, not a database backup: restore client identifiers and secrets through the admin or a protected import file. Existing local secrets survive an import containing empty redacted values. File export refuses to overwrite a file and sets restrictive permissions where supported. Never pass credentials as shell command arguments; use a protected file and remove it securely when finished. `recover` disables authentication enforcement, site restriction, standard-login blocking and single-flow behavior. Network-enforced settings must be recovered with --network from the appropriate network context.

## Protected media deployment

WordPress PHP cannot intercept a direct request to a static file in wp-content/uploads. Use the plugin's protected-media delivery URL and deny direct access to protected originals and generated sizes at the web server or private object store. Configure and test the Apache/Nginx rules documented in the main deployment guide before relying on this protection. CDN caches also need private-cache behavior or signed delivery URLs. Merely protecting an attachment page does not protect its binary file.
