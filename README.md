# PortalWeave SSO

[![CI](https://github.com/RobotHanzo/PortalWeaveSSO/actions/workflows/verify.yml/badge.svg)](https://github.com/RobotHanzo/PortalWeaveSSO/actions/workflows/verify.yml)

Download the [v1.0.1 plugin ZIP](https://github.com/RobotHanzo/PortalWeaveSSO/releases/download/v1.0.1/portalweave-sso-1.0.1.zip), with [release notes](https://github.com/RobotHanzo/PortalWeaveSSO/releases/tag/v1.0.1) and a [SHA-256 checksum](https://github.com/RobotHanzo/PortalWeaveSSO/releases/download/v1.0.1/portalweave-sso-1.0.1.zip.sha256).

Add OAuth 2.0 and OpenID Connect sign-in to WordPress with multiple identity providers, guided setup, account mapping and access policies for content and private media. Manage login appearance, optional integrations and activity from the WordPress administrator screens.

![PortalWeave SSO overview with setup tasks and configuration summary](docs/screenshots/overview.png)

Requires **WordPress 6.5+**, **PHP 8.1+ with OpenSSL**, and **HTTPS**.

## Quick start

1. [Download the plugin ZIP](https://github.com/RobotHanzo/PortalWeaveSSO/releases/download/v1.0.1/portalweave-sso-1.0.1.zip), upload it through **Plugins → Add New → Upload Plugin**, and activate **PortalWeave SSO**.
2. Open **Settings → PortalWeave SSO → Identity providers → Add provider**. Follow the service's guide, register its callback URL and save your credentials. Run **Test SSO Config**, configure account mappings and test a regular user before enforcing login.
3. Add `[sso_oauth_login]` to a page, use the **PortalWeave SSO login** widget, or sign in through the WordPress and WooCommerce login buttons.

For earlier installations, follow the [replacement guide](docs/provider-setup.md#replacing-an-earlier-installation) before upgrading.

## Screenshots

These screens were captured in disposable WordPress fixtures with fictional providers, users and sample audit records.

### Set up providers

Choose a service to open its setup guide, or enter your own OAuth/OIDC connection details.

![Provider chooser with supported identity services and custom OAuth or OIDC](docs/screenshots/provider-chooser.png)

<details>
<summary>Registration, tenant details and saved providers</summary>

Follow the provider-console instructions and register the displayed callback URL.

![Guided provider registration with callback URL and console instructions](docs/screenshots/onboarding.png)

Self-hosted providers ask for the relevant instance details.

![authentik setup asking for its instance address](docs/screenshots/authentik-host.png)

Each guide explains where to find the provider's credentials.

![Discord credential step with client ID and secret fields and console instructions](docs/screenshots/discord-credentials.png)

On the final step, **Test connection** opens a separate tab to check sign-in before saving. Your setup entries stay ready for **Save provider**. This example shows fictional credentials entered for review.

![Final provider setup review with secondary Test connection and primary Save provider buttons](docs/screenshots/onboarding-review.png)

Return to the provider list to configure an existing connection.

![Identity provider list with sample saved connections](docs/screenshots/providers.png)

Configure an existing provider through focused tabs. Switching tabs keeps your edits; **Save provider** saves every section.

![Provider configuration with Connection, Endpoints, Security, Mapping, Accounts, Integrations and Login button tabs](docs/screenshots/provider-settings.png)

</details>

### Add login buttons

Use a shortcode or widget to place provider buttons on a member page.

![Sample member portal displaying provider sign-in buttons](docs/screenshots/member-portal.png)

<details>
<summary>WordPress login and appearance settings</summary>

Provider buttons also appear on the WordPress login form, with their own optional styling.

<img src="docs/screenshots/wordpress-login.png" alt="WordPress login form with provider sign-in buttons" width="400">

Adjust button themes, shapes, labels and spacing in Appearance.

![Login button themes, shapes, labels and spacing settings](docs/screenshots/appearance.png)

Use **Appearance → WordPress sign-in** to customize colors, hover states, text, size and corners with a live preview. Choose provider logos, a custom image from your media library, or no icon.

![WordPress sign-in button appearance controls with colors, sizing, icon choices and a live preview](docs/screenshots/wordpress-button-style.png)

Choose a default provider, popup behavior and post-login destinations.

![Sign-in and logout behavior settings](docs/screenshots/login-settings.png)

</details>

### Control accounts and access

Configure site-wide sign-in requirements, content rules and protected attachments.

![Access policy settings for site restrictions and private media](docs/screenshots/access-policies.png)

<details>
<summary>Attribute mapping and account policies</summary>

Map profile fields and provider groups to WordPress roles.

![Provider attribute and group mapping settings](docs/screenshots/attribute-mapping.png)

Choose registration, email-linking and account-verification policies for each provider.

![Per-provider account registration, linking and verification policies](docs/screenshots/account-policy.png)

</details>

### Choose optional integrations

Enable provisioning, shared sign-in, guest accounts, moderation and other modules as needed.

![Optional integration and module settings](docs/screenshots/integrations.png)

<details>
<summary>Activity, backups and network administration</summary>

See daily trends, event types and provider activity over 7, 30 or 90 days.

![Activity overview showing daily event charts, event types and provider activity](docs/screenshots/activity-overview.png)

Open **Audit logs** to inspect individual records, export CSV and choose collection and retention settings.

![Activity report containing fictional sample login events](docs/screenshots/activity.png)

Choose what to record and how long to retain it.

![Audit collection, optional IP recording and retention controls](docs/screenshots/audit-privacy.png)

Download and restore credential-free configuration backups.

![Configuration backup, restore and troubleshooting controls](docs/screenshots/backup-support.png)

On multisite, manage the current network's configuration and enforcement.

![WordPress multisite network SSO policy settings](docs/screenshots/network-policy.png)

</details>

## Documentation

| Find out more | Guide |
| --- | --- |
| Provider setup, mapping and access policies | [Administrator guide](docs/provider-setup.md) |
| Provisioning, optional modules and automation | [Integrations guide](docs/integrations.md) |
| Data handling, retention and removal | [Privacy](docs/privacy.md), [external services](docs/external-services.md) |
| Development and contributions | [Repository guide](AGENTS.md), [architecture and hooks](docs/architecture.md) |
| Testing, packaging and publication | [Verification](docs/verification.md), [CI](docs/ci.md), [releasing](docs/releasing.md) |

License: [GPL-2.0-or-later](LICENSE).
