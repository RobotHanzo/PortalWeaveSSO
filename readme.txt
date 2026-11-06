=== WPSSO OAuth & OpenID Connect ===
Contributors: stsa
Tags: oauth, openid-connect, sso, scim, login
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-provider OAuth/OIDC sign-in, account policies, access controls, provisioning and audit tools.

== Description ==
Connect OAuth 2.0 and OpenID Connect providers with Authorization Code and PKCE. Configure attribute and role mappings, verified account linking and administrator policies. Includes login widgets, shortcodes, WooCommerce integration, private media, audit reports, network configuration, optional SCIM, JWT SSO, moderation and eight opt-in WordPress abilities.

SSO requires HTTPS and PHP OpenSSL. Client credentials and tokens are encrypted using WordPress salts. Optional features are disabled by default. Live identity-provider registration is required. WordPress 6.9+ and the WordPress MCP Adapter are required for MCP transport.

== Installation ==
Upload the plugin ZIP, activate it and open Settings > WordPress SSO. Add a provider, register its callback, enter credentials and endpoints, then run Test SSO Config before enforcing login.

Use [wpsso_oauth_login] to render buttons. See README.md and docs/ for deployment, media vault, recovery and integration instructions.

== Frequently Asked Questions ==
= Can media be private? =
Yes. Selected originals and registered sizes move to a writable directory outside the document root, and use authenticated delivery. Purge existing CDN copies and include the vault in backups. Review custom image/storage integrations before rollout.

= How do I recover local login? =
Run wp wpsso recover (or --network), or temporarily define WPSSO_ALLOW_LOCAL_LOGIN in wp-config.php.

= Are credentials in configuration exports? =
No. Re-enter credentials on a new installation. Same-site restores retain existing credentials.

== Changelog ==
= 1.0.0 =
Initial multi-provider OAuth/OIDC, access policy, provisioning and operations release.
