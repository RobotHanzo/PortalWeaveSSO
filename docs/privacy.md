# Privacy and local data in PortalWeave SSO

This document describes the bundled plugin's behavior so a site administrator can prepare an accurate notice and handle local data. Adapt it to the actual providers, image hosts, support recipient, optional modules, hosting and retention policy used by the site. It does not establish a legal basis or guarantee compliance with privacy laws. The plugin also supplies suggested text in WordPress's **Settings → Privacy** policy guide.

## Data collected and stored

| Local data | Purpose and storage |
| --- | --- |
| Provider configuration | The WordPress site option, or an explicitly enforced network option, stores the administrator's endpoints, client identifier, scopes, mappings, access policy and display preferences. Client secrets, HMAC signing secrets, SCIM credentials and shared-SSO secrets are encrypted using a key derived from this installation's WordPress salts. Configuration persists until changed, deliberately removed or covered by opt-in uninstall cleanup. |
| Linked account and profile data | Provider subjects and OIDC session IDs are stored as scoped SHA-256 digests for identity/session matching. The plugin also stores the selected provider, groups, mapped custom user fields, optional avatar URL and metadata that identifies which fields/links it created. Mapping can update normal WordPress username, email, name and display-name fields and role assignments. Those normal account fields also belong to WordPress's own account lifecycle. |
| OAuth token material | Access, refresh and ID tokens returned by the provider are encrypted in user metadata using the installation's WordPress salts. Token expiry metadata is retained for refresh decisions. There is no general timed purge of all linked-account metadata or encrypted tokens: they are replaced/removed by the applicable login, logout, erasure or cleanup flow. A token's provider expiry and the lifetime of its stored database record are different. |
| Login/transaction audit | Enabled by default. The site-local audit table stores local user ID, username, email, status, provider/app, UTC timestamp, stable error code and navigation URL. Query strings and fragments are removed from the navigation URL. **Client IP recording is a separate opt-in and is off by default.** Proxy-forwarded IP headers are not trusted by the plugin. Local WordPress login/failure/logout events can also be recorded. |
| Diagnostic events | Off by default. When auditing and debug recording are enabled, a bounded local option stores event time, status, app and error code, up to the newest 500 records. The plugin does not put bearer tokens, client secrets, passwords or raw provider attributes in this diagnostic log. |
| Provisioning/moderation/guest data | Optional modules store configured SCIM identifiers and group data, provisioning activity/block state, moderation decisions and guest expiry timestamps. The guest feature can create local accounts. Expired guest accounts that authored content are retained rather than deleting their work. |
| Authentication transactions and replay controls | Short-lived local options/transients contain the app, approved navigation/callback, nonce, PKCE verifier, initiating test administrator where relevant, browser-binding digest and expiration. Additional replay/locking records protect token refresh and signed logout/JWT/shared handoffs. Public JWKS keys are cached locally, normally for one hour. |
| Support drafts and mail | The support ability stores a locally prepared subject, message and redacted configuration in a transient for 24 hours, including when mail sending fails. Explicit support submission sends through WordPress's configured mail service to the administrator's configured recipient. Any copies in mailboxes, delivery logs or backups have separate retention. |
| Protected media | Media protection moves selected original files and derivatives to a private vault outside the web document root. Attachment metadata records private and original/public paths needed for authenticated delivery and recovery. Protecting a file does not erase copies already held by a CDN, browser, web-server cache or backup. |

The plugin sends no usage analytics or telemetry to its developer. Authentication/profile/key requests go to configured identity endpoints. Optional images are fetched by the browser, optional support uses the configured mail service, and enabled MCP abilities return data to the authenticated client through the separately installed adapter. The complete destination and trigger inventory is in [external services](external-services.md).

**Test SSO Config** displays the raw returned attribute set to the initiating authenticated administrator. Review it privately. A screenshot, copied result, CSV download, WordPress personal-data export or support message can create another copy of personal data outside the plugin's retention controls.

### Local activity statistics

The administrator-only Activity Overview aggregates retained rows from the existing site-local audit table over 7, 30 or 90 UTC calendar days, including a partial current day. It returns daily counts, fixed event categories and up to eight provider identifiers with a remainder count. It does not read or display the audit rows' user IDs, usernames, emails, IP addresses, navigation URLs or error codes. Provider names come from the current site's active configuration. Network Admin does not combine other sites' history.

These are recorded-event counts, not unique people or authentication attempts. Disabled collection and retention cleanup can leave gaps. Turning collection off still permits viewing retained history, and erasing identifying audit fields does not remove the remaining event counts. The overview adds no separate analytics store, aggregate cache, cookies, remote chart library or telemetry request; its charts and equivalent daily table are rendered locally. Empty history and a failed statistics query are reported separately.

The separate Audit logs view still displays the latest 100 personal audit rows and offers CSV export of up to 5,000 rows. Selecting an overview period does not filter that view or its export, or change collection/retention preferences. Existing administrator permissions, scoped saving, collection choices and cleanup remain in effect.

## Cookies and retention

| Cookie or record | Lifetime and handling |
| --- | --- |
| `__Host-portalweave_sso_<digest>` | Login/logout browser-binding cookie: up to 10 minutes, Secure, HttpOnly, SameSite=Lax, path `/`, no Domain attribute. Its name contains a digest; its value is a random browser secret. A valid consumed flow removes the local record and clears the cookie. Unfinished/expired local flow records are cleaned by the scheduled authentication cleanup. |
| `__Secure-portalweave-sso-jwt` | Optional JWT SSO challenge: 5 minutes, Secure, HttpOnly, SameSite=None, path `/`. The corresponding transient expires after 5 minutes. Successful consumption removes the transient and clears the cookie. |
| `__Secure-portalweave-sso-shared` | Optional shared-subdomain handoff: 1 minute, Secure, HttpOnly, SameSite=Lax, path `/`, scoped to the configured parent domain. It contains a signed, readable assertion with selected mapped identity claims and permitted relying sites, never OAuth tokens. Trust every application able to set/read cookies on that parent domain. |
| WordPress authentication cookies | WordPress creates its normal authentication cookies after successful sign-in. Their names, expiry and persistence follow WordPress core and site configuration. The plugin does not replace them with a separate long-lived SSO session cookie. See [WordPress cookies documentation](https://developer.wordpress.org/advanced-administration/wordpress/cookies/). |
| Audit rows | Default retention: 90 days. Administrators can choose 1–3,650 days or stop new collection. Turning auditing off leaves existing rows to expire. The scheduled daily cleanup removes rows older than the configured period. |
| Diagnostic events | Retained for up to 7 days, with a maximum of the newest 500 records; the daily cleanup prunes older entries. |
| Ability support drafts | Transient expiry: 24 hours. Expiration makes a draft unavailable; physical removal of expired transient rows depends on WordPress's transient cleanup/cache behavior. |

Time-limited authentication records are rejected after their expiration even if cleanup has not yet removed their bytes. WordPress cron and, where applicable, the object-cache backend control scheduled deletion. Offline sites, disabled cron, database backups and separate server logs can retain copies beyond the nominal period. The site operator must manage those systems and its backup-retention policy.

Your identity provider can set additional cookies during login/logout and has its own storage/retention rules. Optional images and external clients can also have their own behavior. Those cookies are not created or governed by this plugin.

## Exporting and erasing personal data

Use WordPress **Tools → Export Personal Data** and **Tools → Erase Personal Data** with the normal WordPress authorization/confirmation process. The plugin registers separate audit and linked-profile handlers. Matching uses the requested email address, including duplicate-email accounts, with pagination for profile data.

The audit exporter returns matching provider, status, UTC time, client IP if collected, and navigation data. Its eraser clears the local user ID, username, email, IP address and navigation fields while retaining non-identifying event information. Other retained records and external logs need separate review.

The linked-profile exporter includes current site-scoped provider identity/session digests, groups, mapped custom values, selected provider, avatar/provisioning metadata and relevant security-policy state. It excludes OAuth credentials and tokens. The linked-profile eraser removes provider links, stored tokens, groups, selected-provider metadata, optional mapped avatar, SCIM profile/group metadata and tracked safe custom fields; it revokes the affected account's local sessions when plugin-linked data is removed.

Erasure retains moderation blocks, provisioning activity/block policy and guest expiry controls so that a request cannot reactivate a blocked or expired account. It reports those retained controls. It does not delete the WordPress account, core profile fields, role assignments, authored content, protected attachments/vault files or the external provider's account. Use WordPress's account/content tools and the actual provider's processes for those items. A later authorized SSO login or provisioning request can supply profile information again.

Multisite account data is partly shared by WordPress, while the plugin's links, groups, tokens and audit records are scoped to a site. An export/erasure runs in the current site context; repeat it for other sites with retained records. Separately review shared public custom fields, network configuration, backups and any external recipients.

## Deactivation and uninstall

Deactivation stops the plugin's recurring jobs and preserves its data. **Delete plugin data when uninstalling** is off by default, so deleting the plugin normally preserves settings, reports and linked metadata for reinstallation or recovery.

If an administrator explicitly enables uninstall cleanup, uninstall removes applicable plugin settings, audit/debug records, temporary records, internal identity/token/group/provisioning metadata and post/category access rules. Cleanup evaluates each site's local/network setting on multisite. It retains WordPress accounts, role assignments, content, normal/public profile fields and custom profile-field values; it removes the plugin's internal mapping index rather than deleting those public custom values. Personal-data erasure is the deliberate mechanism for removing the tracked safe custom fields of an individual account.

Private vault files and their recovery metadata are retained even with cleanup selected. Uninstall does not publish or destroy private files. Restore protected files intentionally while the plugin is active, or preserve the vault plus attachment metadata and follow the recovery procedure in the [provider/setup documentation](provider-setup.md). Remove post/category restrictions only when the site owner intends that access change. Existing public/CDN caches and database/file backups require separate handling.

## Preparing the site's notice

State who operates the site, which providers and image/client/support recipients it uses, why each attribute and audit field is needed, whether IP collection is enabled, the chosen retention periods and how users can contact the administrator. Explain any account linking, provisioning, moderation or shared-domain deployment that affects them. Review requested scopes and stored custom mappings before adding a new recipient or purpose. The plugin's suggested policy text is a starting point and must match the deployed configuration.
