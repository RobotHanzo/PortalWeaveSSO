# PortalWeave SSO optional integrations and operations

Every optional module starts disabled. Enabling a module does not disable the core account rules: provider identities, verified email, administrator protections, domain restrictions and redirect allowlists still apply.

The plugin's proposed directory slug/text domain is `portalweave-sso`. Protocol routes and abilities use `portalweave-sso`, commands use `wp portalweave-sso`, PHP classes use `PortalWeaveSSO`, hooks and option/meta names use `portalweave_sso_*`, and server configuration constants use `PORTALWEAVE_SSO_*`. Earlier technical names are unsupported; existing deployments must follow [the replacement procedure](provider-setup.md#replacing-an-earlier-installation). See [external-service disclosures](external-services.md) and [privacy/retention behavior](privacy.md) when deciding which integrations to enable.

## SCIM 2.0 inbound provisioning

Enable **SCIM** and set a dedicated random bearer secret of at least 32 characters. This secret is encrypted in the WordPress settings and excluded from exports. Configure the provisioning client with the base URL `https://your-site.example/wp-json/portalweave-sso/v1/scim/v2` and `Authorization: Bearer YOUR_SECRET`. HTTPS is required. Configure trusted reverse-proxy HTTPS detection in WordPress when TLS terminates at a proxy; never trust arbitrary incoming forwarded headers.

The API implements Users and Groups collection/item GET, POST, PUT, PATCH and DELETE, plus ServiceProviderConfig, Schemas and ResourceTypes discovery. A User POST always creates a subscriber with a random unusable-in-practice password. SCIM cannot set passwords, WordPress roles or capabilities, take over existing local users, or mutate privileged users. Creating an already-used username, email or externalId returns a conflict. Changing a WordPress username returns a mutability error. Groups are separate provider groups; group provisioning does not grant WordPress roles.

On multisite, provisioning ownership, activity, external IDs and group memberships are scoped to the current blog. A site cannot read or modify another site's provisioned accounts. Because WordPress profile fields and session tokens are global, SCIM refuses global user changes once an account belongs to more than one blog, or holds privileged capabilities anywhere in the network. Manage shared directory identities through a deliberately network-wide provisioning service instead of a single site's bearer credential.

Supported filters are `userName eq "value"`, `externalId eq "value"`, `id eq "value"`, `displayName eq "value"` and `emails.value eq "value"`. Other grammar returns a SCIM invalidFilter response. Pagination uses startIndex (one based) and count (0–200). Bulk, sorting, password changes and ETags are not advertised. Collection listing remains suitable for small and medium directories; equality filtering currently scans provisioned resources in batches, so benchmark large directories before rollout.

PATCH accepts add, replace and remove for userName, externalId, displayName, active, name, emails and members; `name.givenName`/`name.familyName` and `members[value eq "123"]` removal are supported. Unsupported paths fail explicitly. PUT replaces the supported mutable fields; absent optional attributes are cleared or use their standard active default. DELETE users deactivates the local account and revokes every WordPress session instead of deleting authored content. GET continues returning the retained resource with active=false. Group deletion removes memberships. Reprovision the user with active=true to restore access.

### Example

```http
POST /wp-json/portalweave-sso/v1/scim/v2/Users
Authorization: Bearer YOUR_SECRET
Content-Type: application/scim+json

{"schemas":["urn:ietf:params:scim:schemas:core:2.0:User"],"externalId":"directory-123","userName":"ada","active":true,"emails":[{"value":"ada@example.org","primary":true}],"name":{"givenName":"Ada","familyName":"Lovelace"}}
```

Reference: [SCIM protocol, RFC 7644](https://www.rfc-editor.org/rfc/rfc7644.html).

## JWT single sign-on

Enable the JWT SSO module and the provider's JWT SSO switch. This uses an OpenID provider capable of returning a signed ID token with `response_type=id_token` and `response_mode=form_post`. Pin the provider's issuer, client ID, signing algorithms and signing key source exactly as with the normal OIDC flow.

Register an additional callback at the provider: `https://your-site.example/?portalweave_sso_action=jwt_callback`. Initiate login through `https://your-site.example/?portalweave_sso_action=jwt_start&app=PROVIDER_ID`. A safe `redirect_to` can be appended. The plugin creates a five-minute browser-bound challenge, nonce and state. The provider must POST state and id_token to the callback. Cross-site POST needs a Secure, HttpOnly, SameSite=None challenge cookie. A unique string jti and a token expiry at most five minutes from callback time are additionally required; some providers need a custom claim or token policy for this. The default Authorization Code flow is available when a provider cannot meet this contract.

ID tokens in query parameters and unsolicited bearer-token login are not accepted. State and token jti are consumed atomically. Validation uses the configured keys and issuer, never token-supplied key URLs. This module's five-minute assertion and replay policy may be stricter than an identity provider's defaults.

## Shared sign-in across trusted subdomains

Enable Shared SSO globally and on the corresponding provider at every participating site. Use the same app ID, immutable subject mapping, issuer, a dedicated 32+ character shared signing secret, a parent cookie domain, and an exact list of trusted HTTPS origins on every site. For example: cookie domain `example.org`, origins `https://one.example.org` and `https://two.example.org`.

Successful authentication issues a Secure, HttpOnly, SameSite=Lax parent-domain cookie containing a signed, one-minute identity assertion. It contains mapped identity claims, not OAuth access or refresh tokens. Signing does not encrypt the claims: participating domain applications can read them. Each participating site consumes the assertion once, resolves its own local account under its own account policy, and creates a normal WordPress session. Consuming an assertion does not issue another one, so trust cannot extend itself indefinitely. This is an SSO handoff: a user must visit another site during the assertion's one-minute lifetime. Later visits use the identity provider's normal SSO flow.

Every application on the parent domain must be trusted: a compromised sibling application or leaked shared signing secret can impersonate users. A cookie domain must be a domain you control, not a public suffix. Separate site-local roles are resolved independently. Logging out clears the shared assertion; already-created sessions on other WordPress sites require their own logout or IdP session management. This module does not claim universal cross-site session revocation. Subdirectory installations sharing the same origin already share the cookie namespace and should use WordPress multisite session handling instead.

## Guest accounts, moderation and avatars

Guest login is per-provider and requires the global Guest module. Guest providers create temporary subscriber identities with the configured lifetime (5 minutes to 24 hours), and cannot sign in existing permanent accounts. Expired guests cannot authenticate. Hourly cleanup destroys sessions and deletes expired guests only when they have not authored content; content-bearing expired guests remain disabled for administrator review. WordPress cron must run for scheduled cleanup.

Moderation marks identities first resolved through a moderated provider as pending and denies login until an administrator approves them. In Users → Edit User → SSO account moderation, an administrator can set Pending, Approved or Blocked for nonprivileged accounts. Updates have a dedicated nonce and require manage_options and edit_user. Moderation status is scoped by blog on multisite. Blocking revokes all sessions; approval does not change roles. Accounts privileged anywhere in the network are excluded from moderation to preserve recovery access; provider administrator SSO protections still apply.

The avatar module reads the provider's configured avatar attribute (picture by default), accepts only a public HTTPS URL, and maps it to WordPress avatar display. The browser fetches the remote avatar; enabling it may disclose visitor network details to that image host. Attribute redirects evaluate configured claim/equals/url rules in order after login, then apply the global redirect allowlist.

Configure/disclose the actual picture host before enabling the feature. The module replaces the avatar URL when a valid mapped value exists; WordPress core's normal avatar/Gravatar fallback remains a separate site setting. Provider icons are also optional remote images; the plugin bundles its executable JavaScript and styles locally.

## AI abilities and MCP

On WordPress 6.9 or later, enable **AI / MCP abilities** to register exactly eight namespaced abilities: `portalweave-sso/list-providers`, `portalweave-sso/get-provider`, `portalweave-sso/configure-provider`, `portalweave-sso/diagnose`, `portalweave-sso/fix-common-error`, `portalweave-sso/export-config`, `portalweave-sso/login-audit`, `portalweave-sso/support-query`. All execution paths require manage_options and recheck that the feature is enabled. Outputs exclude credentials and client identifiers; audit output contains personal data and remains administrator-only.

Install and configure the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) to expose these abilities to Claude Desktop, Cursor, ChatGPT or another compatible MCP client. This plugin provides abilities; the adapter owns MCP transport, authentication and connection instructions. Both current meta.public and the older meta.mcp.public flags are supplied. Use an authenticated administrator identity and the adapter's discovery tools to discover the abilities. Keep application passwords out of source code.

PortalWeave SSO does not call an AI model or send data to a named AI vendor itself. Information returned to your authenticated client may be handled by that client or its configured AI service. Review and disclose those actual destinations, especially for personal audit output.

Safe automatic fixes are deliberately bounded to enabling PKCE, using Authorization Code, adding openid scope and clearing a callback override. Diagnosis does not pretend to test an IdP interactively; use Test SSO Config in the admin. support-query creates a local draft by default, retained for one day. It sends WordPress email only with explicit send=true and a configured support recipient; mail delivery depends on the site's WordPress mail setup.

References: [Abilities registration](https://developer.wordpress.org/reference/functions/wp_register_ability/), [official MCP adapter guide](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/).

## WP-CLI and recovery

```sh
wp portalweave-sso providers --format=json
wp portalweave-sso diagnose my-provider
wp portalweave-sso export ./sso-backup-redacted.json
wp portalweave-sso import ./sso-settings-private.json
wp portalweave-sso set debug false
wp portalweave-sso audit --limit=50 --format=csv
wp portalweave-sso recover
wp portalweave-sso recover --network
```

CLI runs with the authority of the local server operator. Backups contain redacted configuration, not the database. A same-site import preserves client identifiers and secrets by app ID. On a clean installation, importing a redacted backup disables incomplete providers and enforcement until their credentials are entered. File export refuses to overwrite a file and sets restrictive permissions where supported. Never pass credentials as shell command arguments; use a protected file and remove it securely when finished. `recover` disables authentication enforcement, site restriction, standard-login blocking and single-flow behavior. Network-enforced settings must be recovered with --network from the appropriate network context.

Alternatively, temporarily define `PORTALWEAVE_SSO_ALLOW_LOCAL_LOGIN` as `true` in `wp-config.php` to restore local browser login from the server. Remove the override after correcting the policy. There is no public query-string recovery bypass. Browser login restrictions do not replace WordPress REST application-password authentication; review those credentials and endpoint permissions separately.

## Protected media deployment

WordPress PHP cannot intercept direct static-file requests, so protecting an attachment relocates its originals and generated sizes outside the document root. Configure a writable `PORTALWEAVE_SSO_PRIVATE_MEDIA_DIR` (the default is a sibling of ABSPATH) and use the plugin-generated media delivery URLs, which enforce authorization and private cache headers. Protection fails closed if relocation cannot complete. Purge any previously cached web-server or CDN copies and remove externally hosted public duplicates before relying on access control. Verify your deployment's document root and private directory in a browser; protecting an attachment page alone does not protect copies that still exist elsewhere.

Deactivation and uninstall do not publish or delete vault files. The data-removal setting is off by default; even opt-in uninstall cleanup retains vault recovery metadata and WordPress accounts/content/public profile values. Use the [administrator guide](provider-setup.md) and [privacy guide](privacy.md) to plan erasure, removal and recovery.
