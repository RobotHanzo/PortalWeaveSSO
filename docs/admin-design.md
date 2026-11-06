# Admin settings design

PortalWeave's administrator UI is organized around tasks, using server-rendered screens under the existing Settings entry. The route is `page=portalweave-sso&tab=SCREEN`; network administration uses the same renderer with network capabilities and settings scope.

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

Adding a provider opens a separate guided setup. A real-link service chooser opens only that service's recipe. JavaScript enhances the ordered form into one step at a time: optional instance/tenant details, provider-console registration, credentials and review. Discord and Google skip instance details; authentik adds its application slug; OneLogin's documented public PKCE recipe omits a secret; ClassLink and custom services use authoritative manual details. Each recipe supplies its own console instructions and credential locations, with links to official documentation. Internal app IDs are generated and the display name can be changed during review.

The callback strip distinguishes sign-in and post-logout return URLs where required. Required fields are checked before advancing; progress buttons revisit completed steps, headings receive focus, passwords are concealed again on navigation and review shows only credential-presence labels. No credentials enter browser storage, URLs or saved drafts. Without JavaScript, all of the selected recipe's labelled steps remain visible with one final save.

Existing providers retain their advanced editor for endpoints, token validation, attribute/role mapping, account verification, optional integrations and button presentation. Advanced sections use native disclosure controls. An enabled status describes saved configuration and does not claim a successful connection or eligible user. Network test links are withheld when the network configuration is inactive, avoiding a misleading test against a different site-local provider with the same ID.

## Saving and security

`Admin::save()` retains capability, nonce, shape, JSON and truncation checks. `AdminSettings::prepare()` then allows only fields owned by the selected screen. Global screens omit the provider map. A provider save submits the selected provider and empty placeholders for preserved IDs, allowing `Config::save()` to retain each provider's stored configuration and credentials. Stored plaintext or ciphertext is never forwarded as newly submitted secret input. Nonsecret nested settings preserve omitted keys, while explicit false checkbox values still turn features off.

The network-enforced site view remains read-only. Settings preparation, persistence and the existing Config policy remain separate; the layout does not bypass authentication, privilege mapping, media protection or uninstall choices.

Guided forms submit an explicitly allowed scalar `provider_setup` map, accepted only for new-provider creation and never alongside an existing provider map. `ProviderSetup::prepare()` validates the selected catalog, fields, conditions, HTTPS addresses, issuer conventions and opaque credentials, then derives the ordinary provider configuration on the server. `AdminSettings::prepare()` reuses its existing one-provider preservation path before `Config::save()`. JavaScript does not supply authoritative endpoints or policy defaults. Unsupported discovery assumptions are exposed as explicit provider inputs rather than fetched remotely or filled with placeholders.

## Visual and interaction choices

The layout uses WordPress's inherited system font, white surfaces, cool gray borders and blue actions. Persistent task navigation provides hierarchy; the main content shows one task rather than a long page of unrelated controls. The setup workbench uses a genuine numbered sequence and a single main task beside its provider-console guidance, rather than repeated settings cards. On narrow screens the guide moves below the fields, progress wraps, navigation moves above content and the audit table scrolls within its container. Controls have visible keyboard focus, associated labels/help and generous touch targets. No remote fonts, frontend framework or production dependency was added.

Provider artwork is local static media. Its per-file sources, hashes and retained terms are documented in `assets/providers/README.txt`; names and marks identify integrations without implying endorsement. Artwork does not automatically inherit the plugin code's GPL license. The chooser uses the actual provider marks rather than invented initials or unrelated service icons.

The design work used the official [frontend-design skill](https://github.com/anthropics/skills/tree/main/skills/frontend-design) and [Playwright skill](https://github.com/openai/skills/tree/main/skills/.curated/playwright), with [WordPress menu guidance](https://developer.wordpress.org/plugins/administration-menus/) and [nonce guidance](https://developer.wordpress.org/apis/security/nonces/) informing integration with the host admin interface. Skills are development tools and are excluded from the plugin distribution.

## Verification

PHP regression checks cover screen isolation, active navigation, secret-free rendering, scoped writes, retained providers/credentials, checkbox false values, malformed/cross-screen inputs and real multisite credential preservation. Guided-setup regressions additionally cover every recipe's derived endpoints, issuer variants, conditional/public clients, exact secret bytes, creation-only scope and the actual administrative POST/redirect boundary. Existing protocol and account tests remain applicable.

Browser verification uses the disposable integration site and fake providers, snapshots/restores raw settings, and exercises desktop/mobile layout, keyboard focus, history, no-JavaScript navigation, provider presets and real form submissions. Artifacts are local under `output/playwright/`; they and authenticated test browser state must never be committed or shipped. Current CI reports are the authoritative runtime evidence; historical counts in older verification notes do not describe every later revision.
