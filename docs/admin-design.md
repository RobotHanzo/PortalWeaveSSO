# Admin settings design

PortalWeave's administrator UI is organized around tasks, using server-rendered screens under the existing Settings entry. The route is `page=wpsso&tab=SCREEN`; network administration uses the same renderer with network capabilities and settings scope.

| Screen | Primary task |
| --- | --- |
| Overview | Understand current configuration and the setup sequence |
| Identity providers | List providers, add one, or open a single provider editor |
| Login & logout | Default provider, sign-in behavior and redirect policy |
| Access policies | Site/domain/content/media restrictions and network enforcement |
| Appearance | Login buttons, widget text and signed-in messages |
| Integrations | Optional modules, SCIM/shared credentials and AI abilities |
| Activity | Audit transactions, collection choices, retention and debugging |
| Tools & support | Configuration backup/restore, troubleshooting and support |

Navigation uses real URLs and works without JavaScript. The current screen is marked with `aria-current="page"`. Overview and the provider list render no settings form. Each editor posts its explicit screen scope, preserves the truncation sentinel and returns to that screen after saving or validation failure. Adding a provider uses a separate `new_provider=1` flag; an existing provider whose ID is `new` remains editable normally.

The provider editor renders one provider and groups endpoints, token validation, attribute/role mapping, account verification, optional integrations and button presentation. Advanced sections use native disclosure controls. Provider templates continue to fill empty fields; they do not overwrite credentials. An enabled status describes saved configuration and does not claim that a provider connection was tested successfully.

## Saving and security

`Admin::save()` retains capability, nonce, shape, JSON and truncation checks. `AdminSettings::prepare()` then allows only fields owned by the selected screen. Global screens omit the provider map. A provider save submits the selected provider and empty placeholders for preserved IDs, allowing `Config::save()` to retain each provider's stored configuration and credentials. Stored plaintext or ciphertext is never forwarded as newly submitted secret input. Nonsecret nested settings preserve omitted keys, while explicit false checkbox values still turn features off.

The network-enforced site view remains read-only. Settings preparation, persistence and the existing Config policy remain separate; the layout does not bypass authentication, privilege mapping, media protection or uninstall choices.

## Visual and interaction choices

The layout uses WordPress's inherited system font, white surfaces, native gray borders and blue actions. Persistent task navigation provides hierarchy; the main content shows one task rather than a long page of unrelated controls. On narrow screens navigation wraps above the content, provider rows stack, and the audit table scrolls within its container. Controls have visible keyboard focus, associated labels/help and generous touch targets. No remote fonts, images, frontend framework or production dependency was added.

The design work used the official [frontend-design skill](https://github.com/anthropics/skills/tree/main/skills/frontend-design) and [Playwright skill](https://github.com/openai/skills/tree/main/skills/.curated/playwright), with [WordPress menu guidance](https://developer.wordpress.org/plugins/administration-menus/) and [nonce guidance](https://developer.wordpress.org/apis/security/nonces/) informing integration with the host admin interface. Skills are development tools and are excluded from the plugin distribution.

## Verification

PHP regression checks cover screen isolation, active navigation, secret-free rendering, scoped writes, retained providers/credentials, checkbox false values, malformed/cross-screen inputs and real multisite credential preservation. Existing protocol and account tests remain applicable.

Browser verification uses the disposable integration site and fake providers, snapshots/restores raw settings, and exercises desktop/mobile layout, keyboard focus, history, no-JavaScript navigation, provider presets and real form submissions. Artifacts are local under `output/playwright/`; they and authenticated test browser state must never be committed or shipped. Current CI reports are the authoritative runtime evidence; historical counts in older verification notes do not describe every later revision.
