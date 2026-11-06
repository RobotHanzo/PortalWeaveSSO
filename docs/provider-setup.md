# PortalWeave SSO provider setup and administrator guide

Install `portalweave-sso-1.0.0.zip` through **Plugins → Add New → Upload Plugin**, activate **PortalWeave SSO**, and open **Settings → PortalWeave SSO**. On multisite, **Network Admin → PortalWeave SSO** can enforce a network-wide policy. Each WordPress network has its own network option.

The proposed directory slug and text domain are `portalweave-sso`. The `[wpsso_oauth_login]` shortcode, `WPSSO_*` server configuration constants, `WPSSO` namespace and `wp wpsso` commands retain their established names for compatibility.

## Register an identity provider

1. Add an app with a unique stable app ID. Choose a provider template or enter custom endpoints.
2. Replace every `YOUR_*` placeholder. Get the exact issuer and endpoints from the provider’s discovery document or current tenant documentation. Templates are starting points, not discovery substitutes.
3. Register the displayed callback URL as an exact redirect URI. A callback override must use this installation's canonical origin and route back to WordPress. The separately validated original destination is retained through the state transaction, so returning to the page that initiated login does not require changing the provider callback.
4. Use Authorization Code, PKCE S256, `openid profile email`, and RSA signature validation for OIDC. Public clients can use no client authentication and omit the secret. Confidential clients should use the provider-supported Basic header or request-body method.
5. Pin only the expected JWT algorithms. RSA uses keys from the configured HTTPS JWKS URL. HMAC uses an explicit signing secret; never mix an RSA public key into an HMAC secret.
6. Run **Test SSO Config** while signed in as an administrator. The protected result shows raw provider attributes, without changing your WordPress user session. Configure mappings from that result.
7. Keep auto-registration, existing-account linking, and administrator SSO set to your intended policy. Administrator SSO is disabled by default. When enabled, the provider’s configured verified claim must match the configured JSON scalar. An expectation of `true`, `"true"`, `1`, or `"1"` accepts those four representations; other scalar expectations use an exact string comparison.
8. Test a non-admin user and an approved administrator before forcing site authentication or disabling WordPress password login. Retain filesystem or WP-CLI recovery access.

## Provider notes

| Provider | Registration / endpoint considerations |
| --- | --- |
| Microsoft Entra ID / Office 365 | Use the tenant UUID for endpoints and issuer. A tenant-specific app avoids variable issuer checks required by a multi-tenant `common` endpoint. Configure group claims or a supported group-info API when groups exceed token limits. |
| Azure AD B2C | Include the correct user-flow or custom-policy name in endpoints. The issuer can differ from the endpoint path: copy the discovery document’s exact issuer. |
| AWS Cognito | Issuer/JWKS use the region and user-pool ID. Authorization, token, and user-info endpoints use the hosted UI/custom domain. |
| Keycloak | Use the realm’s discovery document. Groups/roles often appear under nested claims such as `realm_access.roles`. |
| Okta | Select the correct authorization server; `oauth2/default` and the organization server have different issuers and endpoints. |
| Salesforce | Sandbox, custom-domain, and production registrations can use different hosts. Copy the issuer rather than inferring it from the login host. |
| Google | Register a Web application callback, request email/profile scopes, and use `email_verified`. |
| Auth0 | Retain the trailing slash in the issuer. Group and role claims may use a namespaced claim; use the exact key/path returned by your tenant. |
| OneLogin | Use your tenant’s OIDC v2 discovery metadata. |
| Discord | OAuth-only. Use `id` as the immutable subject, `identify email` scopes, and `verified` as the verified-email claim. |
| ClassLink | Verify the current launchpad registration endpoints and immutable user identifier with your tenant. Providers without a trustworthy verification claim cannot automatically link existing accounts by email. |
| authentik | The issuer and JWKS are application-specific; authorization/token/user-info can use shared instance endpoints. |
| Custom OAuth / OIDC | Enter public HTTPS endpoints and a stable subject. OAuth-only needs a user-info endpoint. OIDC needs an exact issuer and JWT validation keys. |

Multiple apps can be enabled at once. Each button selects its own app. The default app is used for forced and single-flow login.

Review [external services and provider policy links](external-services.md) before enabling each provider. Selecting a template only fills local fields. Actual authentication exchanges occur when login/test/refresh/logout flows run; optional remote icons and mapped pictures also make browser requests. Choose necessary scopes and mappings, and disclose the actual recipients to your users.

For provider-initiated OIDC session revocation, enable **signed OIDC back-channel logout** on the app and register the displayed back-channel URL with your provider. The provider must send a signed logout token; the endpoint validates the provider signature and logout-token claims and rejects replays. The ordinary end-session URL is used for browser-initiated logout.

Provider references: [Microsoft discovery metadata](https://learn.microsoft.com/en-us/entra/identity-platform/v2-protocols-oidc), [Cognito endpoints](https://docs.aws.amazon.com/cognito/latest/developerguide/federation-endpoints.html), [OneLogin user-info](https://developers.onelogin.com/docs/openid-connect/api/user-info/), [ClassLink user-info](https://apisandbox.classlink.com/reference/v2myinfo), and [authentik OAuth2 provider](https://docs.goauthentik.io/add-secure-apps/providers/oauth2/).

## Mapping

Standard mapping is a JSON object with `username`, `email`, `first_name`, `last_name`, `display_name`, and `groups`. Values are claim paths such as `realm_access.roles`. Custom mapping is a safe WordPress user-meta key mapped to a claim path. Privilege-related and plugin-internal meta keys are rejected.

Role mapping is an object of group names to role arrays, for example `{"staff":["editor"],"students":["subscriber"]}`. Domain roles use the same format with exact domains. “Block if unmapped” requires an explicit group or domain match; the default role does not count. Keep-existing-role policy applies only to existing accounts. Mapping a privileged role does not bypass administrator SSO restrictions.

Domain allow/block policy uses exact lower-case domains. Domain mapping is an optional object such as `{"old.example":"new.example"}`. A provider’s original email verification remains necessary when linking accounts. Never rely on an unsigned request attribute for account ownership.

## Login display

Insert `[wpsso_oauth_login]` or add the **PortalWeave SSO login** widget. WooCommerce's login form gets enabled-provider buttons. Customize global theme, shape, size, curve, spacing, shadow, and hover treatment. Each app can set text and an HTTPS icon URL. `##user##` and `##Sign Out##` are available in the logged-in message. Button assets load for the relevant login/widget/shortcode/account contexts; normal pages without those displays do not need them.

## Content restrictions and media

Post/page editors and category forms expose login, allowed-role, and allowed-group controls. A matching configured role or group grants access. Attachment editing includes a protected checkbox; the global protected-ID list provides the same file protection.

Protecting an attachment relocates its original, thumbnails, and other recorded derivatives outside the web document root. WordPress generates authenticated delivery URLs and applies the attachment and parent-content access policies before serving files. Unprotecting moves the files back to their original upload paths. Configure a writable `WPSSO_PRIVATE_MEDIA_DIR` in `wp-config.php` when the default private sibling directory of `ABSPATH` is not writable. The plugin rejects a vault inside the web document root. Purge previously cached copies from the web server and CDN when protecting media. An attachment-page role restriction alone does not relocate static files; select file protection as well.

Before removal, back up the database and vault together. If a file should become public again, remove its ID from the global protected-ID list and save, then clear its per-attachment protected checkbox if set. Verify the restored original and derivatives through an uncached browser request. A conflicting public file causes restoration to fail rather than overwrite it; resolve the conflict before removing the plugin. If the file must remain private, keep the vault and attachment recovery metadata and plan to restore the plugin or provide a separate authenticated delivery service. Do not move vault files into public uploads merely to make old links work.

## Backups, reports, and support

Configuration download is credential-free JSON. Restore it through the import form or `wp wpsso import backup.json`. Importing into the same installation preserves omitted or redacted client IDs and stored secrets according to the app ID. On a clean installation, incomplete restored apps and login enforcement are disabled; enter credentials, test SSO, and enable your intended access policy. An independent database backup, including the WordPress salts used for encryption and the private media directory, is required for complete recovery.

The transaction table and CSV include username, status, app, UTC time, email, navigation URL and stable error code. Audit collection starts enabled, with a default retention of 90 days. **Include client IP addresses in audit events** is a separate opt-in and is off by default. Turn off **Record SSO transactions and login audit events** to stop new collection; existing rows remain until cleanup. CSV fields are protected from spreadsheet formula interpretation. Diagnostic debug events are optional, bounded to 500 entries, and pruned after seven days. Scheduled removal requires working WordPress cron.

Configure your own support email, booking link, forum link, and demo/trial link. The support form sends mail only when an authorized administrator submits it. **Send Plugin Configuration** excludes client IDs, secrets, passwords, and tokens; endpoint URLs and policy remain included.

## Privacy controls and uninstall

Use **Settings → Privacy** to review the plugin's suggested policy text. Adapt it to the configured providers, optional images, support recipient, modules and audit-retention choices. WordPress **Tools → Export Personal Data** and **Tools → Erase Personal Data** handle matching audit and linked-profile information. Erasure removes tracked safe custom fields and local SSO links/tokens, while retaining account security blocks, WordPress core account/content data and protected files. Review the [privacy guide](privacy.md) for exact scope and multisite handling.

Deactivation preserves data. **Delete plugin data when uninstalling** is off by default. Enabling it removes applicable plugin settings, audit/temporary records, internal linked metadata and post/category rules when the plugin is deleted. WordPress accounts, roles, content, public/custom profile values, private vault files and recovery metadata are retained. Restore protected media intentionally while the plugin is active, or keep the vault and attachment metadata for recovery. A redacted configuration download alone cannot restore the database or private files.

## AI and MCP

The eight abilities are off by default. They require `manage_options` and a WordPress version that provides the Abilities API (6.9+). MCP clients additionally need the official WordPress MCP Adapter and its authenticated transport. Authenticate Claude Desktop, Cursor, ChatGPT, or another client using the adapter’s documented mechanism. No anonymous MCP server is supplied by this plugin. Disabling abilities blocks subsequent ability execution.

The plugin does not contact an AI service itself. A configured authenticated client can receive configuration/diagnostic results and personal audit data through the adapter, so review that client's privacy terms separately. Support submission still requires an explicit send request and a configured recipient.

## Legacy grants

Implicit and Password flows require a separate explicit opt-in for each app. Prefer Authorization Code with PKCE. Password flow exposes the provider password to the client and does not support many modern MFA flows. Implicit flow returns tokens through the browser and requires signed identity-token validation and transaction binding. Enable these only for an existing provider integration that requires them.
