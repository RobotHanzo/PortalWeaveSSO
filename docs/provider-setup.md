# PortalWeave SSO provider setup and administrator guide

Install `portalweave-sso-1.0.1.zip` through **Plugins → Add New → Upload Plugin**, activate **PortalWeave SSO**, and open **Settings → PortalWeave SSO**. On multisite, **Network Admin → PortalWeave SSO** can enforce a network-wide policy. Each WordPress network has its own network option.

The proposed directory slug and text domain are `portalweave-sso`. Use the `[sso_oauth_login]` shortcode, `PORTALWEAVE_SSO_*` server configuration constants, `PortalWeaveSSO` PHP namespace and `wp portalweave-sso` commands.

## Replacing an earlier installation

This release replaces the entire technical namespace. It provides no earlier-name aliases, automatic database conversion or fallback to previous storage. Treat replacement as a controlled deployment rather than an in-place compatible update:

1. Restrict the site at the web server or another external access boundary before replacing the plugin. Earlier whole-site, content, moderation and provisioning blocks will not be read under the new names. Existing WordPress accounts and sessions persist; changing the plugin namespace does not revoke those sessions.
2. Back up the complete database, WordPress salts, server configuration and private-media vault together. Retain the previous plugin version for rollback. A redacted configuration export omits credentials and cannot recover identity links, tokens, policy metadata or vault provenance.
3. Reconfigure site and network settings, provider credentials, account-linking policies, content/category/media restrictions, moderation and provisioning ownership under the new namespace. Do not enable automatic registration or provisioning until existing identities and ownership have been reconciled; otherwise duplicate or differently owned accounts can result. An operator-managed database conversion needs its own staging validation and rollback plan.
4. Update provider OAuth/JWT callbacks and back-channel logout URLs, SCIM base URLs, shared-domain peers, MCP ability names, server constants, custom PHP hooks, CLI scripts, shortcodes, widget placements and custom CSS. Copy the new URLs from the plugin. Re-enter secrets excluded by configuration exports.
5. Reconcile private-media manifests and attachment recovery metadata with the intended private vault path before enabling delivery. The default vault directory also changes. Retain every original private file; never copy vault files into public uploads to repair links. Verify authenticated delivery, unauthorized denial and recovery on a staging copy.
6. Reconcile earlier scheduled tasks, audit records and retained personal data through an explicit maintenance procedure. The new cleanup and uninstall handlers operate on the new namespace and do not remove earlier records or schedules. Verify retention consent and cron operation on every affected site/network.
7. Test regular and administrator authentication, recovery login, authorization and logout, then remove the external restriction only after all intended policies and private-media checks pass. Roll back the database, files and configuration together if validation fails.

## Register an identity provider

The Overview screen links to each setup task. Identity providers, Login & logout, Access policies, Appearance, Integrations, Activity and Tools & support are separate screens. Each save applies only to its current screen; editing one provider preserves the others.

1. Open **Identity providers > Add provider** and choose your service. Its guide shows one task at a time, with the relevant provider-console instructions beside the fields. Existing providers open in the advanced editor through **Configure**.
2. Supply the server, tenant or application details requested by that recipe. Discord and Google skip server configuration entirely. The server derives known endpoints; ClassLink and custom services ask for authoritative connection details instead of guessing them.
3. Follow **Register** in the provider console and copy the exact **Redirect URI** shown in the guide. Providers that use a sign-out return URI also show that separate value. Confirm registration after adding the URI. This confirmation records your setup step; it does not contact or verify the external registration.
4. Follow the provider-specific credential instructions. Copy the actual client ID and secret value, not a tenant ID, secret identifier, API key, bot token or account password. The documented OneLogin public-client recipe uses PKCE and needs only its client ID. **Back** retains unsaved fields in the current form; the wizard does not store secrets in browser storage or a saved draft.
5. Review the connection and optionally change its display name, then **Save provider**. A unique internal app ID is generated for the form and shown in the advanced editor after saving. Other providers, the default provider and site-wide access policies are preserved. Endpoint derivation and all required fields are validated again on the server; JavaScript controls presentation only. Without JavaScript, the selected recipe remains a complete ordered form with the same final save.
6. Run **Test SSO Config** while signed in as an administrator. The protected result shows raw provider attributes without changing your WordPress session. It does not test account eligibility, verification claims or role assignment. A network provider can be tested from the network UI only while that network configuration is active; use staging to validate a network policy before enforcing it.
7. Configure mappings and account policies from the actual returned attributes. Guided OIDC profiles use Authorization Code, S256 PKCE and pinned RS256/JWKS validation; verified-email checks remain enabled, email linking remains opt-in and administrator SSO remains disabled. A provider that omits a trustworthy verification claim needs deliberate claim configuration; the wizard never fabricates one or disables verification to make a test pass.
8. Test a non-admin user and any explicitly permitted administrator before forcing site authentication or disabling WordPress password login. Retain filesystem or WP-CLI recovery access. Advanced edits can configure a documented alternate issuer, client-authentication method, signing algorithm or callback override without weakening signature/state checks.

### Details requested by each guide

| Service | Connection details before credentials |
| --- | --- |
| Microsoft Entra ID / Microsoft 365 | Directory tenant UUID; the application client ID and secret Value come from the app registration. |
| Azure AD B2C | Existing-tenant policy authority and exact policy metadata issuer, including its trailing slash. The app client UUID is also requested as its own resource scope so the token response includes the required Bearer access token. |
| Amazon Cognito | User-pool issuer and managed-login/custom-domain origin; these are different addresses. Both documented issuer families are supported. |
| Keycloak | Instance base URL and realm. Preserve a real deployment base path such as `/auth`. |
| Okta | Organization origin, plus an explicit custom authorization-server ID only when using that server. |
| Salesforce | Production, sandbox or custom environment. A custom authority requires its exact metadata issuer. Instructions use External Client Apps. |
| Google | Client ID and secret only; fixed Google endpoints are derived. |
| Auth0 | Tenant or custom-domain origin and the Regular Web Application credentials. |
| OneLogin | Tenant origin and public-client ID; configure **None (PKCE)** in the provider. |
| Discord | Client ID and secret only; the OAuth2 profile uses `identify email`, immutable `id`, verified `verified`, and the documented authorization-response issuer. |
| ClassLink | Current partner-provided endpoints, client-authentication contract and stable/verified claim paths. The older public endpoint template is not assumed current. |
| Custom OAuth / OIDC | Explicit protocol, endpoints, scopes and documented client authentication. OIDC requires its exact issuer and trusted JWKS; OAuth2 can supply an authorization-response issuer when it emits `iss`. |

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

Review [external services and provider policy links](external-services.md) before enabling each provider. Setup derives local configuration and links official instructions; it does not fetch discovery documents or contact an identity service. Actual authentication exchanges occur when login/test/refresh/logout flows run; optional remote icons and mapped pictures also make browser requests. Choose necessary scopes and mappings, and disclose the actual recipients to your users.

For provider-initiated OIDC session revocation, enable **signed OIDC back-channel logout** on the app and register the displayed back-channel URL with your provider. The provider must send a signed logout token; the endpoint validates the provider signature and logout-token claims and rejects replays. The ordinary end-session URL is used for browser-initiated logout.

Provider references: [Microsoft discovery metadata](https://learn.microsoft.com/en-us/entra/identity-platform/v2-protocols-oidc), [Cognito endpoints](https://docs.aws.amazon.com/cognito/latest/developerguide/federation-endpoints.html), [OneLogin user-info](https://developers.onelogin.com/docs/openid-connect/api/user-info/), [ClassLink user-info](https://apisandbox.classlink.com/reference/v2myinfo), and [authentik OAuth2 provider](https://docs.goauthentik.io/add-secure-apps/providers/oauth2/).

## Mapping

Standard mapping is a JSON object with `username`, `email`, `first_name`, `last_name`, `display_name`, and `groups`. Values are claim paths such as `realm_access.roles`. Custom mapping is a safe WordPress user-meta key mapped to a claim path. Privilege-related and plugin-internal meta keys are rejected.

Role mapping is an object of group names to role arrays, for example `{"staff":["editor"],"students":["subscriber"]}`. Domain roles use the same format with exact domains. “Block if unmapped” requires an explicit group or domain match; the default role does not count. Keep-existing-role policy applies only to existing accounts. Mapping a privileged role does not bypass administrator SSO restrictions.

Domain allow/block policy uses exact lower-case domains. Domain mapping is an optional object such as `{"old.example":"new.example"}`. A provider’s original email verification remains necessary when linking accounts. Never rely on an unsigned request attribute for account ownership.

## Login display

Insert `[sso_oauth_login]` or add the **PortalWeave SSO login** widget. WooCommerce's login form gets enabled-provider buttons. Customize global theme, shape, size, curve, spacing, shadow, and hover treatment. Each app can set text and an HTTPS icon URL. `##user##` and `##Sign Out##` are available in the logged-in message. Button assets load for the relevant login/widget/shortcode/account contexts; normal pages without those displays do not need them.

## Content restrictions and media

Post/page editors and category forms expose login, allowed-role, and allowed-group controls. A matching configured role or group grants access. Attachment editing includes a protected checkbox; the global protected-ID list provides the same file protection.

Protecting an attachment relocates its original, thumbnails, and other recorded derivatives outside the web document root. WordPress generates authenticated delivery URLs and applies the attachment and parent-content access policies before serving files. Unprotecting moves the files back to their original upload paths. Configure a writable `PORTALWEAVE_SSO_PRIVATE_MEDIA_DIR` in `wp-config.php` when the default private sibling directory of `ABSPATH` is not writable. The plugin rejects a vault inside the web document root. Purge previously cached copies from the web server and CDN when protecting media. An attachment-page role restriction alone does not relocate static files; select file protection as well.

Before removal, back up the database and vault together. If a file should become public again, remove its ID from the global protected-ID list and save, then clear its per-attachment protected checkbox if set. Verify the restored original and derivatives through an uncached browser request. A conflicting public file causes restoration to fail rather than overwrite it; resolve the conflict before removing the plugin. If the file must remain private, keep the vault and attachment recovery metadata and plan to restore the plugin or provide a separate authenticated delivery service. Do not move vault files into public uploads merely to make old links work.

## Backups, reports, and support

Configuration download is credential-free JSON. Restore it through the import form or `wp portalweave-sso import backup.json`. Importing into the same installation preserves omitted or redacted client IDs and stored secrets according to the app ID. On a clean installation, incomplete restored apps and login enforcement are disabled; enter credentials, test SSO, and enable your intended access policy. An independent database backup, including the WordPress salts used for encryption and the private media directory, is required for complete recovery.

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
