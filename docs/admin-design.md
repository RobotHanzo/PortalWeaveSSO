# Admin settings design

PortalWeave's administrator UI is organized around tasks, using server-rendered screens under its own **PortalWeave SSO** entry in the WordPress admin sidebar. Site administration requires `manage_options`; Network Admin retains its separate entry and `manage_network_options` requirement. The canonical route is `admin.php?page=portalweave-sso&tab=SCREEN`; network administration uses the same renderer with network capabilities and settings scope. Previously bookmarked `options-general.php?page=portalweave-sso` URLs redirect to the canonical screen after a site-administrator capability check, retaining only scalar local navigation fields. New navigation, save redirects and configuration-test return links use `admin.php`.

| Screen | Primary task |
| --- | --- |
| Overview | Understand current configuration and the setup sequence |
| Identity providers | List providers, add one, or open a single provider editor |
| Login & logout | Default provider, sign-in behavior and redirect policy |
| Access policies | Site/domain/content/media restrictions and network enforcement |
| Appearance | Login buttons, widget text and signed-in messages |
| Integrations | Optional modules, SCIM/shared credentials and AI abilities |
| Activity | Retained-event overview, audit logs, collection choices, retention and debugging |
| Tools & support | Configuration backup/restore, troubleshooting and support |

Navigation uses real URLs and works without JavaScript. The current screen is marked with `aria-current="page"`. Overview and the provider list render no settings form. Each editor posts its explicit screen scope, preserves the truncation sentinel and returns to that screen after saving or validation failure. Adding a provider uses a separate `new_provider=1` flag; an existing provider whose ID is `new` remains editable normally.

Adding a provider opens a separate guided setup. A real-link service chooser opens only that service's recipe. JavaScript enhances the ordered form into one step at a time: optional instance/tenant details, provider-console registration, credentials and review. Discord and Google skip instance details; authentik adds its application slug; OneLogin's documented public PKCE recipe omits a secret; ClassLink and custom services use authoritative manual details. Each recipe supplies its own console instructions and credential locations, with links to official documentation. Internal app IDs are generated and the display name can be changed during review.

The callback strip distinguishes sign-in and post-logout return URLs where required. Required fields are checked before advancing; progress buttons revisit completed steps, headings receive focus, passwords are concealed again on navigation and review shows only credential-presence labels. No credentials enter browser storage or URLs. Without JavaScript, all of the selected recipe's labelled steps remain visible with the same final test and save actions.

Review places a secondary **Test connection** button beside the primary **Save provider** button. Testing posts the current form to the canonical home origin in a separate tab, retaining the unsaved setup form. It runs the full creation-only recipe validation and requires the form nonce, completion sentinel and site/network administrator capability. The test creates no provider or account and never changes defaults, access policy or the WordPress session. Launch feedback remains separate from the save pending state, so a test does not lock the original form.

An unsaved test seals a binary-safe provider snapshot with the existing authenticated secret encryption inside the ordinary ten-minute, single-use OAuth flow. Browser cookie, purpose, blog, initiating administrator session and network/capability checks gate its use at callback. Only that consumed test flow can resolve the snapshot; normal sign-in continues to resolve saved configuration. The shared callback retains its PKCE, token, issuer, signature and subject checks and stops at the protected attribute result before account resolution or token/session storage. Consumption deletes the snapshot; expired flows are unusable and removed by the existing hourly cleanup. This also allows testing a new network recipe before enforcement; site-local testing remains blocked by an enforced network policy.

Each callback has its own visible, polite live status for copying, success and failure. The copy button also reflects the pending or completed action and prevents duplicate requests while copying. If clipboard access and the legacy copy fallback fail, the read-only URI stays selected so the administrator can use the device's copy command. Feedback belongs to the selected callback rather than an unrelated wizard step.

Existing providers use seven editor tabs: Connection, Endpoints, Security, Mapping, Accounts, Integrations and Login button. Connection keeps the provider identity, credentials and scopes together; endpoint URLs have their own tab. An enabled status describes saved configuration and does not claim a successful connection or eligible user. Network test links are withheld when the network configuration is inactive, avoiding a misleading test against a different site-local provider with the same ID.

The tabs enhance real section anchors into an ARIA tablist with associated panels and one tab stop. Left/Right arrows follow the text direction; Home/End select the first/last tab, while modified keys keep their browser behavior. Section fragments and browser history restore the selected panel. Without JavaScript, every section remains visible and the anchors jump to its heading. Navigation remains usable in the network-enforced read-only view. Native validation reveals the first invalid field's panel before the browser focuses it.

## Saving and security

`Admin::save()` retains capability, nonce, shape, JSON and truncation checks. `AdminSettings::prepare()` then allows only fields owned by the selected screen. Global screens omit the provider map. A provider save submits the selected provider and empty placeholders for preserved IDs, allowing `Config::save()` to retain each provider's stored configuration and credentials. Stored plaintext or ciphertext is never forwarded as newly submitted secret input. Nonsecret nested settings preserve omitted keys, while explicit false checkbox values still turn features off.

The provider editor has one form: Save provider saves all seven sections. Switching tabs hides panels without disabling their fields or clearing unsaved values, so inactive sections remain in the POST payload. Blank secret inputs preserve stored credentials unless the administrator explicitly clears them. The allowlisted GET `provider_section` and POST `portalweave_sso_provider_section` select presentation and preserve the return section after a save or error; unknown sections fall back to Connection and structured POST values are rejected. Section navigation never narrows the provider update or enters stored configuration.

The network-enforced site view remains read-only. Settings preparation, persistence and the existing Config policy remain separate; the layout does not bypass authentication, privilege mapping, media protection or uninstall choices.

Validated provider, settings, import and support submissions show an action-specific pending button label and visible live message while preventing duplicate submissions. Invalid forms remain editable without entering the pending state, and settings, credentials and submitter values remain in the POST payload. A `pageshow` reset restores action labels and clears pending feedback when returning through browser history. Pending feedback never claims success: the server's redirected success or error notice remains authoritative.

Guided forms submit an explicitly allowed scalar `provider_setup` map, accepted only for new-provider creation and never alongside an existing provider map. `ProviderSetup::prepare()` validates the selected catalog, fields, conditions, HTTPS addresses, issuer conventions and opaque credentials, then derives the ordinary provider configuration on the server. `AdminSettings::prepare()` reuses its existing one-provider preservation path before `Config::save()`. JavaScript does not supply authoritative endpoints or policy defaults. Unsupported discovery assumptions are exposed as explicit provider inputs rather than fetched remotely or filled with placeholders.

## Activity views

Activity opens Overview by default, with a separate Audit logs view. Ordinary server-rendered links preserve browser history and work without JavaScript. GET `activity_view` accepts only `overview` or `audit`; `activity_days` accepts 7, 30 or 90, defaulting to 30. Audit preference forms carry the corresponding allowlisted POST context so scoped saves and validation errors return to the same view and overview period. Navigation values are not stored settings. Overview renders no preference form, raw audit rows or CSV control.

`Audit::summary()` aggregates all retained rows in the selected range, independently of the audit table's latest-100 limit. The window includes today and the preceding calendar days in UTC; today is partial, and zero-event days are included. One prepared SQL statement returns daily/category counts and the eight most active provider identifiers from one read view. Remaining provider events form a remainder bucket. Configured names label known identifiers, with an identifier fallback; a displayed empty identifier is labelled WordPress / system. The API does not fetch user IDs, usernames, emails, IPs, navigation URLs or error codes, and creates no new store or cache.

The categories are fixed audit statuses, not distinct people, sessions or authentication attempts:

| Category | Recorded statuses |
| --- | --- |
| Sign-in events | `success`, `local_login` |
| Failure events | `failure`, `local_failure` |
| Sign-out events | `logout`, `local_logout` |
| Provider test events | `test_success` |
| Other events | Every remaining status, including refresh, provisioning and administrative events |

One action may generate several events. Retention and disabled collection can leave gaps; switching collection off does not hide retained history. An empty period gets explicit guidance, while an aggregate query error displays an unavailable notice instead of zero statistics. In Network Admin, history still belongs to the current site. Provider labels and the collection-status notice use that site's active configuration, including enforced network policy, rather than an inactive network draft.

The daily SVG has an accessible name and description, supplementary native hover titles and an equivalent expandable date/count table. Category and provider bars expose their labels and numeric values as text. Chart and table scroll regions support keyboard access; the two-column table fits narrow layouts and the timeline scrolls within its container. Rendering requires no JavaScript, chart library or remote assets. Audit logs retains the latest 100 rows, CSV export of up to 5,000 rows and existing collection/retention controls; the overview period does not filter those rows or the export.

## Visual and interaction choices

The layout uses WordPress's inherited system font, white surfaces, cool gray borders and blue actions. Persistent task navigation provides hierarchy; the main content shows one task rather than a long page of unrelated controls. The setup workbench uses a genuine numbered sequence and a single main task beside its provider-console guidance, rather than repeated settings cards. On narrow screens the guide moves below the fields, progress wraps, navigation moves above content and the audit table scrolls within its container. Controls have visible keyboard focus, associated labels/help and generous touch targets. No remote fonts, frontend framework or production dependency was added.

The shared site and network settings header pairs the product name with the local [PortalWeave SSO mark](branding/README.md), including during provider setup. The decorative image has empty alternative text beside the visible name, intrinsic dimensions and a high-resolution source. It scales from 48px to 40px on narrow screens; provider-specific logos retain their separate role in the chooser and setup guide.

Provider artwork is local static media. Its per-file sources, hashes and retained terms are documented in `assets/providers/README.txt`; names and marks identify integrations without implying endorsement. Artwork does not automatically inherit the plugin code's GPL license. The chooser uses the actual provider marks rather than invented initials or unrelated service icons.

The design work used the official [frontend-design skill](https://github.com/anthropics/skills/tree/main/skills/frontend-design) and [Playwright skill](https://github.com/openai/skills/tree/main/skills/.curated/playwright), with [WordPress menu guidance](https://developer.wordpress.org/plugins/administration-menus/) and [nonce guidance](https://developer.wordpress.org/apis/security/nonces/) informing integration with the host admin interface. Skills are development tools and are excluded from the plugin distribution.

## WordPress sign-in appearance

Appearance has Shared buttons and WordPress sign-in panels inside one scoped form. The separate flat `wp_login_button` settings map inherits the existing shared behavior until its `enabled` switch is set. Partial saves merge through `AdminSettings::prepare()` and preserve hidden values, providers, encrypted credentials and access policy. The allowlisted appearance section survives saving; real anchors leave every field reachable without JavaScript.

The custom renderer activates only in the actual `wp-login.php` context. It shares authentication links with the existing renderer and changes presentation only. Structured controls validate color strings, integer dimensions, switches, icon choices and credential-free HTTPS image URLs before storage. The renderer validates again before emitting scoped CSS variables. Custom WordPress appearance omits shared arbitrary CSS and can style the page even when shared WordPress styling is disabled; turning customization off restores that earlier switch's behavior. Other shortcode, widget and WooCommerce contexts keep the shared appearance.

The preview uses the production button stylesheet, selected provider names and image URLs, without credentials or real authentication links. Native media selection requires upload permission; a manual HTTPS URL remains available. Preset, media, image failure and remove actions have visible live feedback. Provider images resolve through the same fixed bundled-file map used by setup, with configured provider icons taking precedence and a generic bundled fallback. Images remain decorative beside a text label; custom image URL query data is removed by both configuration-redaction APIs. Remote preview/sign-in image requests are disclosed in the provider, service and privacy guides.

## Verification

PHP regression checks cover screen isolation, active navigation, secret-free rendering, scoped writes, retained providers/credentials, checkbox false values, malformed/cross-screen inputs and real multisite credential preservation. Guided-setup regressions additionally cover every recipe's derived endpoints, issuer variants, conditional/public clients, exact secret bytes, creation-only scope and the actual administrative POST/redirect boundary. Activity regressions cover UTC boundaries, full-range totals, category/provider grouping, private-field exclusion, empty/error handling, view/range round trips and current-site multisite scope. Existing protocol and account tests remain applicable.

Browser verification uses the disposable integration site and fake providers, snapshots/restores raw settings, and exercises desktop/mobile layout, keyboard focus, history, no-JavaScript navigation, provider presets and real form submissions. Raw captures, traces and authenticated test browser state stay in ignored local artifact paths such as `output/playwright/`; they must never be committed or shipped. Reviewed product screenshots with clearly fictional sample data may be saved separately in `docs/screenshots/` for the README, following the [repository screenshot guidance](../AGENTS.md#documentation-and-screenshots). Those images are excluded from the production ZIP. Current CI reports are the authoritative runtime evidence; historical counts in older verification notes do not describe every later revision.
