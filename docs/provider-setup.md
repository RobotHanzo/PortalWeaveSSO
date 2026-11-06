# Provider setup and administrator guide

Install the ZIP through **Plugins → Add New → Upload Plugin**, activate it, and open **Settings → WordPress SSO**. On multisite, **Network Admin → Network SSO** can enforce a network-wide policy. Each WordPress network has its own network option.

## Register an identity provider

1. Add an app with a unique stable app ID. Choose a provider template or enter custom endpoints.
2. Replace every `YOUR_*` placeholder. Get the exact issuer and endpoints from the provider’s discovery document or current tenant documentation. Templates are starting points, not discovery substitutes.
3. Register the displayed callback URL as an exact redirect URI. If using a callback override, it must point to this installation and use an accepted origin. Requests retain the original validated destination through the state transaction.
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

For provider-initiated OIDC session revocation, enable **signed OIDC back-channel logout** on the app and register the displayed back-channel URL with your provider. The provider must send a signed logout token; the endpoint validates the provider signature and logout-token claims and rejects replays. The ordinary end-session URL is used for browser-initiated logout.

Provider references: [Microsoft discovery metadata](https://learn.microsoft.com/en-us/entra/identity-platform/v2-protocols-oidc), [Cognito endpoints](https://docs.aws.amazon.com/cognito/latest/developerguide/federation-endpoints.html), [OneLogin user-info](https://developers.onelogin.com/docs/openid-connect/api/user-info/), [ClassLink user-info](https://apisandbox.classlink.com/reference/v2myinfo), and [authentik OAuth2 provider](https://docs.goauthentik.io/add-secure-apps/providers/oauth2/).

## Mapping

Standard mapping is a JSON object with `username`, `email`, `first_name`, `last_name`, `display_name`, and `groups`. Values are claim paths such as `realm_access.roles`. Custom mapping is a safe WordPress user-meta key mapped to a claim path. Privilege-related and plugin-internal meta keys are rejected.

Role mapping is an object of group names to role arrays, for example `{"staff":["editor"],"students":["subscriber"]}`. Domain roles use the same format with exact domains. “Block if unmapped” requires an explicit group or domain match; the default role does not count. Keep-existing-role policy applies only to existing accounts. Mapping a privileged role does not bypass administrator SSO restrictions.

Domain allow/block policy uses exact lower-case domains. Domain mapping is an optional object such as `{"old.example":"new.example"}`. A provider’s original email verification remains necessary when linking accounts. Never rely on an unsigned request attribute for account ownership.

## Login display

Insert `[wpsso_oauth_login]` or add the WordPress SSO widget. WooCommerce’s login form gets enabled-provider buttons. Customize global theme, shape, size, curve, spacing, shadow, and hover treatment. Each app can set text and an HTTPS icon URL. `##user##` and `##Sign Out##` are available in the logged-in message.

## Content restrictions and media

Post/page editors and category forms expose login, allowed-role, and allowed-group controls. A matching configured role or group grants access. Attachment editing includes a protected checkbox; the global protected-ID list provides the same file protection.

Protecting an attachment relocates its original, thumbnails, and other recorded derivatives outside the web document root. WordPress generates authenticated delivery URLs and applies the attachment and parent-content access policies before serving files. Unprotecting moves the files back to their original upload paths. Configure a writable `WPSSO_PRIVATE_MEDIA_DIR` in `wp-config.php` when the default private sibling directory of `ABSPATH` is not writable. The plugin rejects a vault inside the web document root. Purge previously cached copies from the web server and CDN when protecting media. An attachment-page role restriction alone does not relocate static files; select file protection as well.

## Backups, reports, and support

Configuration download is credential-free JSON. Restore it through the import form or `wp wpsso import backup.json`. Importing into the same installation preserves omitted or redacted client IDs and stored secrets according to the app ID. On a clean installation, incomplete restored apps and login enforcement are disabled; enter credentials, test SSO, and enable your intended access policy. An independent database backup, including the WordPress salts used for encryption and the private media directory, is required for complete recovery.

The transaction table and CSV include username, status, app, UTC time, email, client IP, navigation URL, and stable error code. CSV fields are protected from spreadsheet formula interpretation. Configure retention and access to these personal-data reports. Debug events expire weekly.

Configure your own support email, booking link, forum link, and demo/trial link. The support form sends mail only when an authorized administrator submits it. **Send Plugin Configuration** excludes client IDs, secrets, passwords, and tokens; endpoint URLs and policy remain included.

## AI and MCP

The eight abilities are off by default. They require `manage_options` and a WordPress version that provides the Abilities API (6.9+). MCP clients additionally need the official WordPress MCP Adapter and its authenticated transport. Authenticate Claude Desktop, Cursor, ChatGPT, or another client using the adapter’s documented mechanism. No anonymous MCP server is supplied by this plugin. Disabling abilities blocks subsequent ability execution.

## Legacy grants

Implicit and Password flows require a separate explicit opt-in for each app. Prefer Authorization Code with PKCE. Password flow exposes the provider password to the client and does not support many modern MFA flows. Implicit flow returns tokens through the browser and requires signed identity-token validation and transaction binding. Enable these only for an existing provider integration that requires them.
