=== PortalWeave SSO ===
Tags: oauth, openid-connect, sso, scim, login
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

OAuth and OpenID Connect sign-in with account mapping, access controls, SCIM provisioning and local audit reports.

== Description ==
PortalWeave SSO connects a site to administrator-configured OAuth 2.0 and OpenID Connect identity providers. Authorization Code with S256 PKCE is the default. Public clients can use PKCE without a client secret.

* Configure multiple providers, attribute mappings and group/email-domain role policies.
* Register users at first login or allow only existing accounts. Account linking requires explicit configuration and a verified, unique email.
* Keep administrator SSO disabled by default; enable it per provider with a verified account claim.
* Display login buttons using the login widget, [sso_oauth_login] shortcode, WordPress login screen or WooCommerce login form.
* Restrict posts, pages, categories and media using login, role or group rules.
* Review local authentication transactions and export CSV reports. Debug logging, client IP collection and optional modules are administrator controlled.
* Enable SCIM Users/Groups, JWT sign-in, temporary guest accounts, moderation, mapped pictures, attribute redirects or a short shared-subdomain handoff.
* Use site/network settings, WP-CLI and eight optional administrator-only WordPress abilities.

All bundled functionality is available without a developer account, license key, paid upgrade or trial expiry. Optional module switches are configuration controls. Guest-account expiry is a site access policy, not a limit on plugin functionality. Identity providers may impose their own account, subscription or usage requirements.

SSO requires HTTPS and PHP OpenSSL. Client credentials and tokens are encrypted using this installation's WordPress salts; keep those salts stable. Native abilities require WordPress 6.9+. External MCP clients also require the separately installed WordPress MCP Adapter. This plugin does not call an AI model service.

This is an independent plugin. Provider names describe integrations and do not imply endorsement or affiliation.

== External services ==

The plugin has no developer-hosted authentication, tracking, licensing or update service. It sends no telemetry to its author and downloads no remote executable code. All plugin JavaScript and CSS are distributed locally.

After an administrator configures a provider, sign-in redirects the browser to that provider with the client identifier, requested scopes, callback URL, random state/nonce and PKCE challenge. The server exchanges the code and requests signing keys, profile or groups at the configured HTTPS endpoints. Returned account/profile/group data is used for local authentication and authorization. Optional refresh and logout requests go to the same configured provider. Tokens are never included in audit or support exports.

Preset service policy references:

* [Microsoft identity services](https://learn.microsoft.com/entra/identity/): [privacy](https://www.microsoft.com/en-us/privacy/privacystatement), [product terms](https://www.microsoft.com/licensing/terms/).
* [Amazon Cognito](https://aws.amazon.com/cognito/): [privacy](https://aws.amazon.com/privacy/), [customer agreement](https://aws.amazon.com/agreement/), [service terms](https://aws.amazon.com/service-terms/).
* [Google identity services](https://developers.google.com/identity): [privacy](https://policies.google.com/privacy), [API terms](https://developers.google.com/terms), [user data policy](https://developers.google.com/terms/api-services-user-data-policy).
* [Okta](https://www.okta.com/): [privacy](https://www.okta.com/legal/privacy-policy/), [agreements](https://www.okta.com/legal/).
* [Auth0](https://auth0.com/): [privacy](https://www.okta.com/legal/privacy-policy/), [legal resources](https://auth0.com/legal).
* [Salesforce](https://www.salesforce.com/): [privacy](https://www.salesforce.com/company/legal/privacy/), [customer agreements](https://www.salesforce.com/company/legal/customer-agreements/).
* [OneLogin](https://www.onelogin.com/): [privacy](https://www.oneidentity.com/legal/privacy.aspx), [legal resources](https://www.oneidentity.com/legal/).
* [Discord](https://discord.com/developers/docs/topics/oauth2): [privacy](https://discord.com/privacy), [developer terms](https://support-dev.discord.com/hc/en-us/articles/8562894815383-Discord-Developer-Terms-of-Service).
* [ClassLink](https://www.classlink.com/): [privacy](https://www.classlink.com/company/privacy), [contact](https://www.classlink.com/contact). Obtain the applicable school/organization license and service agreement from ClassLink.
* Keycloak, authentik and custom providers: the administrator chooses the deployment, endpoint and applicable operator terms/privacy policy. Selecting a self-hosted preset does not contact the software project's developer.

Optional mapped pictures and custom provider icons are browser requests to administrator-selected image hosts. Those hosts receive browser network information. Support email is sent only after an administrator explicitly submits a message or requests sending through the support ability; its recipient is administrator configured. MCP transport and any data handling by a connected AI client belong to the separately configured adapter/client.

The external-services document is included in the ZIP. Its maintained source is [available here](https://github.com/RobotHanzo/PortalWeaveSSO/blob/compliance/wordpress-org-submission/docs/external-services.md). Site operators must publish the policies applicable to their configured providers.

== Privacy and data retention ==

The plugin stores local identity links, mapped profile fields, group memberships and encrypted tokens. Authentication auditing can be disabled. When enabled, it retains username, email, provider, status, time and navigation path for the configured retention period (90 days by default). IP recording is a separate opt-in and defaults off. Debug records expire after seven days; local support drafts expire after one day. Scheduled cleanup requires working WordPress cron.

Login/logout state cookies last up to ten minutes. Optional JWT sign-in uses a five-minute challenge cookie; optional shared-subdomain sign-in uses a signed one-minute cookie containing selected identity attributes, not OAuth tokens. WordPress manages its normal login cookies.

Suggested privacy-policy text is available in the WordPress privacy tools. Personal-data exports include audit and linked profile information but exclude credentials. Erasure anonymizes audit data, removes local provider links/tokens/groups and known plugin-mapped custom fields, and revokes sessions. It preserves the WordPress account, roles, content, moderation/provisioning blocks and private files. Other multisite sites and external providers manage their own retained data. Later sign-in/provisioning may supply profile data again.

Deactivation preserves data. Uninstall preserves settings unless the administrator enables data removal. Even with removal enabled, WordPress accounts, content, public mapped profile values, private-media files and their recovery metadata are retained. Review docs/privacy.md and include the private vault in server backups. The plugin does not claim to provide or guarantee legal compliance.

== Installation ==
This release replaces the technical namespace without aliases or automatic conversion of earlier stored data. Existing installations must restrict access externally and follow the replacement procedure in docs/provider-setup.md before reopening, including settings, identity links, access rules, provider URLs and private-media metadata.

1. Upload portalweave-sso-1.0.1.zip or copy the portalweave-sso directory into wp-content/plugins.
2. Activate PortalWeave SSO and open Settings > PortalWeave SSO.
3. Add a provider, register the displayed callback URL, enter its credentials/endpoints/signing settings and save.
4. Run Test SSO Config as an administrator to inspect returned attributes. Configure mappings and test an ordinary user before enforcing login.
5. Add [sso_oauth_login], the PortalWeave SSO login widget or use the WordPress/WooCommerce login form integration.

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
= 1.0.1 =
* Replace the technical namespace with PortalWeave SSO identifiers and use [sso_oauth_login] for login buttons. Earlier installations require the documented controlled replacement procedure.
* Add provider-specific guided setup, credential instructions and bundled provider logos.
* Sort provider choices alphabetically and remove logo borders.
* Address Plugin Check advisories and enforce complete strict checks in CI.
* Add Bash helper entrypoints, verified ZIP packaging and resilient CI image downloads.

= 1.0.0 =
Initial multi-provider OAuth/OIDC, access policy, provisioning and operations release.
Includes private-media recovery, privacy-policy guidance, data export/erasure and administrator-controlled retention.
