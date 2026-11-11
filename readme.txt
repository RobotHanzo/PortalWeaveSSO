=== PortalWeave SSO ===
Tags: oauth, openid-connect, sso, scim, login
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

OAuth and OpenID Connect sign-in with account mapping, access controls, SCIM provisioning and local audit reports.

== Description ==
PortalWeave SSO connects a site to administrator-configured OAuth 2.0 and OpenID Connect identity providers. Authorization Code with S256 PKCE is the default. Public clients can use PKCE without a client secret.

* Configure multiple providers, attribute mappings and group/email-domain role policies with visual editors or advanced JSON.
* Register users at first login or allow only existing accounts. Account linking requires explicit configuration and a verified, unique email.
* Keep administrator SSO disabled by default; enable it per provider with a verified account claim.
* Display login buttons using the login widget, [portalweave_sso_login] shortcode, WordPress login screen or WooCommerce login form.
* Preview custom WordPress sign-in button styles and provider/custom icons independently of website buttons.
* Restrict posts, pages, categories and media using login, role or group rules.
* Visualize retained authentication activity over 7, 30 or 90 days, page through retained audit logs and export CSV reports. Debug logging, client IP collection and optional modules are administrator controlled.
* Enable SCIM Users/Groups, JWT sign-in, temporary guest accounts, moderation, mapped pictures, attribute redirects or a short shared-subdomain handoff.
* Use site/network settings, WP-CLI and eight optional administrator-only WordPress abilities.

All bundled functionality is available without a developer account, license key, paid upgrade or trial expiry. Optional module switches are configuration controls. Guest-account expiry is a site access policy, not a limit on plugin functionality. Identity providers may impose their own account, subscription or usage requirements.

SSO requires HTTPS and PHP OpenSSL. Client credentials and tokens are encrypted using this installation's WordPress salts; keep those salts stable. Native abilities require WordPress 6.9+. External MCP clients also require the separately installed WordPress MCP Adapter. This plugin does not call an AI model service.

This is an independent plugin. Provider names describe integrations and do not imply endorsement or affiliation.

== External services ==

The plugin has no developer-hosted authentication, tracking, licensing or update service. It sends no telemetry to its author and downloads no remote executable code. All plugin JavaScript and CSS are distributed locally.

Each service below is an optional identity provider used to sign users in and obtain the configured profile/group claims for local account and access decisions. No provider is contacted until an administrator configures it and its authentication flow runs. Preset setup and bundled logos work locally.

For every configured identity service below, sign-in sends the browser to its authorization endpoint with the client identifier, scopes, callback URL, random state/nonce and PKCE challenge. WordPress sends the returned authorization code, PKCE verifier and configured client authentication to that service's token endpoint; it sends an access token when requesting profile or group data. Public signing-key requests send no user token. Explicit refresh sends the refresh token and client authentication. Configured logout sends the browser to that service with client ID, callback, state and an ID-token hint when available. An explicitly enabled legacy Password grant sends the entered username/password to that provider over HTTPS without retaining the password; the legacy Implicit grant returns tokens through the browser callback. These transfers happen only for the configured login, validation, refresh or logout operation. Tokens are never included in audit or support exports.

Preset service policy references:

* [Microsoft Entra ID / Azure AD, Azure AD B2C and Office 365](https://learn.microsoft.com/entra/identity/): Microsoft hosts the configured tenant's sign-in/token/key endpoints; Microsoft Graph can supply profile/group data. The identity transfers above apply to the selected tenant and B2C policy. [Privacy](https://www.microsoft.com/en-us/privacy/privacystatement), [product terms](https://www.microsoft.com/licensing/terms/).
* [Amazon Cognito](https://aws.amazon.com/cognito/): AWS hosts the configured regional user pool and authorization domain. The identity transfers above go to that pool's endpoints, including any configured custom domain. [Privacy](https://aws.amazon.com/privacy/), [customer agreement](https://aws.amazon.com/agreement/), [service terms](https://aws.amazon.com/service-terms/).
* [Google identity services](https://developers.google.com/identity): [privacy](https://policies.google.com/privacy), [API terms](https://developers.google.com/terms), [user data policy](https://developers.google.com/terms/api-services-user-data-policy).
* [Okta](https://www.okta.com/): [privacy](https://www.okta.com/legal/privacy-policy/), [agreements](https://www.okta.com/legal/).
* [Auth0](https://auth0.com/): [privacy](https://www.okta.com/legal/privacy-policy/), [legal resources](https://auth0.com/legal).
* [Salesforce](https://www.salesforce.com/): [privacy](https://www.salesforce.com/company/legal/privacy/), [customer agreements](https://www.salesforce.com/company/legal/customer-agreements/).
* [OneLogin](https://www.onelogin.com/): [privacy](https://www.oneidentity.com/legal/privacy.aspx), [legal resources](https://www.oneidentity.com/legal/).
* [Discord](https://discord.com/developers/docs/topics/oauth2): [privacy](https://discord.com/privacy), [developer terms](https://support-dev.discord.com/hc/en-us/articles/8562894815383-Discord-Developer-Terms-of-Service).
* [ClassLink](https://www.classlink.com/): [privacy](https://www.classlink.com/company/privacy), [contact](https://www.classlink.com/contact). Obtain the applicable school/organization license and service agreement from ClassLink.
* Keycloak, authentik and custom providers: the administrator chooses the deployment, endpoint and applicable operator terms/privacy policy. Selecting a self-hosted preset does not contact the software project's developer.

Optional mapped pictures and custom provider or WordPress sign-in icons are browser requests to the selected image hosts when displayed, including login pages and administrator previews. Hosts receive the visitor's IP address and ordinary browser request information; sign-in icons use a no-referrer policy. Uploaded icons use the site's media delivery service. Support email sends the administrator's message through the site's mail service to the chosen recipient only on explicit submission (or an explicit support-ability send request); redacted configuration is attached only when requested. Booking, forum and demo links open only when selected. The site operator must disclose these actual image, mail, support and link destinations and their policies.

Optional SCIM accepts incoming authenticated provisioning requests from the administrator's chosen client and returns account/group data; it does not select or contact a vendor. Optional shared-subdomain SSO sends selected identity claims in a signed, unencrypted one-minute cookie to explicitly trusted sites, without OAuth tokens. Optional abilities return authorized configuration, diagnostics and audit data through the separately installed MCP Adapter to the chosen client; this plugin does not call an AI service. Disclose the actual provisioning, trusted-site and MCP/client operators' terms and privacy policies before enabling them.

The external-services document is included in the ZIP. Its maintained source is [available here](https://github.com/RobotHanzo/PortalWeaveSSO/blob/main/docs/external-services.md). Site operators must publish the policies applicable to their configured providers. One Identity policy pages may restrict automated retrieval; confirm the applicable OneLogin agreement with your account contact. ClassLink's privacy notice refers to school/organization license and service-level agreements supplied by ClassLink, rather than a universal public service contract.

== Privacy and data retention ==

The plugin stores local identity links, mapped profile fields, group memberships and encrypted tokens. Authentication auditing can be disabled. When enabled, it retains username, email, provider, status, time and navigation path for the configured retention period (90 days by default). IP recording is a separate opt-in and defaults off. Debug records expire after seven days; local support drafts expire after one day. Scheduled cleanup requires working WordPress cron.

Login/logout state cookies last up to ten minutes. Optional JWT sign-in uses a five-minute challenge cookie; optional shared-subdomain sign-in uses a signed one-minute cookie containing selected identity attributes, not OAuth tokens. WordPress manages its normal login cookies.

Suggested privacy-policy text is available in the WordPress privacy tools. Personal-data exports include audit and linked profile information but exclude credentials. Erasure anonymizes audit data, removes local provider links/tokens/groups and known plugin-mapped custom fields, and revokes sessions. It preserves the WordPress account, roles, content, moderation/provisioning blocks and private files. Other multisite sites and external providers manage their own retained data. Later sign-in/provisioning may supply profile data again.

Deactivation preserves data. Uninstall preserves settings unless the administrator enables data removal. Even with removal enabled, WordPress accounts, content, public mapped profile values, private-media files and their recovery metadata are retained. Review docs/privacy.md and include the private vault in server backups. The plugin does not claim to provide or guarantee legal compliance.

== Installation ==
Upgrades from PortalWeave SSO 1.0.1 retain existing configuration. Replacing an installation that uses the earlier technical namespace requires the controlled procedure in docs/provider-setup.md; there are no earlier-name aliases or automatic conversion of that stored data. Restrict access externally during that replacement, including settings, identity links, access rules, provider URLs and private-media metadata.

1. Upload portalweave-sso-1.3.1.zip or copy the portalweave-sso directory into wp-content/plugins.
2. Activate PortalWeave SSO and open PortalWeave SSO in the WordPress admin sidebar.
3. Add a provider, register the displayed callback URL and enter its details. Use Test connection on the final review step before saving the provider.
4. Saved providers also offer Test SSO Config to inspect returned attributes. Configure mappings and test an ordinary user before enforcing login.
5. Add [portalweave_sso_login], the PortalWeave SSO login widget or use the WordPress/WooCommerce login form integration.
6. Optionally customize the WordPress sign-in buttons and icons under Appearance > WordPress sign-in. Existing styling is inherited until customization is enabled.

Optional features start disabled. Use the distributed user guides for provider, SCIM, media and MCP setup. Development source and build tools are [publicly maintained](https://github.com/RobotHanzo/PortalWeaveSSO).

== Frequently Asked Questions ==
= Can media be private? =
Yes. Selected originals and registered sizes move to a writable directory outside the document root, and use authenticated delivery. Purge existing CDN copies and include the vault in backups. Review custom image/storage integrations before rollout.

= Does this require a developer-hosted service or paid plugin upgrade? =
No. Authentication uses the provider selected by the administrator. All bundled modules are included; provider service requirements are separate.

= Which grants and logout methods are supported? =
Authorization Code with PKCE and refresh tokens are supported. Legacy Password and signed OIDC Implicit flows require explicit per-provider opt-in. Implicit requires a signed ID token with an access-token hash; unsolicited URL bearer-token login is rejected. OIDC RP-initiated and signed back-channel logout are supported; iframe front-channel logout is not implemented.

= Does shared sign-in revoke every participating site's session? =
No. It is a one-minute handoff between explicitly trusted HTTPS subdomains and creates independent WordPress sessions. Normal IdP sign-in remains available after that handoff expires.

= How do I recover local login? =
Run wp portalweave-sso recover (or --network), or temporarily define PORTALWEAVE_SSO_ALLOW_LOCAL_LOGIN in wp-config.php.

= Are credentials in configuration exports? =
No. Re-enter credentials on a new installation. Same-site restores retain existing credentials.

== Changelog ==
= 1.3.1 =
* Remove custom CSS input and rendering; use structured appearance controls and WordPress's own CSS editor.
* Register only the prefixed [portalweave_sso_login] shortcode; replace earlier unprefixed placements.
* Keep private-media directory identifiers independent of authentication salts and preserve existing file recovery paths.
* Scope media errors to media screens and expand service disclosures with a maintained documentation link.
* Enforce content restrictions for mixed-case WordPress REST routes.
* Deny native Application Password authentication for deactivated, moderated and expired accounts while preserving multisite isolation.
* Prevent request parameters from suppressing local-login audit events and avoid duplicate SSO login events.

= 1.3.0 =
* Redesign sign-out with a named identity-provider action, site-only sign-out and Go back.
* Show provider sign-out only when the linked provider has a valid HTTPS end-session endpoint.
* Keep cancellation and confirmation links read-only, and preserve browser-bound logout state for provider returns.

= 1.2.1 =
* Resolve visitor IPs behind explicitly trusted Nginx and Cloudflare proxies for opted-in audit logging.
* Validate IPv4/IPv6 forwarding chains, ignore spoofed headers from untrusted peers, and support Cloudflare Pseudo IPv4's original IPv6 address.
* Document server-only proxy trust and header configuration without changing collection or retention preferences.

= 1.2.0 =
* Add PortalWeave SSO directly to the WordPress admin sidebar.
* Configure mappings in visual editors, with advanced JSON editing available for those fields.
* Show an animated floating reminder for unsaved settings, with Save changes and Revert changes actions and reduced-motion support.
* Group integrations into focused panels with related configuration and provider settings links.
* Paginate retained audit events with event counts, stable page navigation and a refresh action.
* Upload or select custom provider button logos from the WordPress media library alongside manual image URLs.
* Refresh affected feature screenshots and administration documentation.

= 1.1.0 =
* Test a new provider connection before saving, using the current setup details in a separate tab without changing the administrator session.
* Customize WordPress sign-in button text, colors, sizing and icons with a live preview, presets and media-library selection.
* Organize provider configuration into focused tabs while preserving edits across sections.
* Add Activity overview charts for 7, 30 or 90 days, with a separate audit-log tab.
* Add clear clipboard and form-action feedback, keyboard navigation and responsive layouts.
* Expand the visual feature guide and update provider, privacy and administration documentation.

= 1.0.1 =
* Replace the technical namespace with PortalWeave SSO identifiers. Earlier installations require the documented controlled replacement procedure.
* Add provider-specific guided setup, credential instructions and bundled provider logos.
* Sort provider choices alphabetically and remove logo borders.
* Address Plugin Check advisories and enforce complete strict checks in CI.
* Add Bash helper entrypoints, verified ZIP packaging and resilient CI image downloads.

= 1.0.0 =
Initial multi-provider OAuth/OIDC, access policy, provisioning and operations release.
Includes private-media recovery, privacy-policy guidance, data export/erasure and administrator-controlled retention.

== Upgrade Notice ==
= 1.3.1 =
Security update for REST content restrictions, Application Password account policies and login auditing. Existing settings and credentials are retained.

= 1.3.0 =
Choose whether to sign out of this site alone or also the named identity provider. Providers without a configured end-session endpoint show site-only sign-out and Go back. Existing confirmation preferences are retained.

= 1.2.1 =
Adds trusted-proxy IP resolution. Configure trusted proxy addresses and the forwarding header in wp-config.php when the web server has not already restored REMOTE_ADDR. IP collection remains off by default.

= 1.2.0 =
Adds visual mapping editors, an unsaved-settings reminder, organized integration panels, audit pagination and media-library logos. Existing configuration and credentials are retained. Find PortalWeave SSO directly in the admin sidebar.
