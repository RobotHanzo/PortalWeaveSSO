# Product screenshot maintenance

`appearance.png` and `wordpress-button-style.png` were refreshed on 2026-11-11 using PortalWeave SSO 1.3.1 in a disposable WordPress 7.1.4 fixture. They show the prefixed shortcode, structured controls and a fictional Example provider preview after removal of custom CSS input. The WordPress appearance choice was saved in that fixture. Both captures were reviewed for framing, credentials, nonces, server paths and personal data; the capture wrapper was removed afterwards.

These PNGs were captured in disposable WordPress 7.1.4 fixtures. The full gallery was retaken on 2026-11-07 using PortalWeave SSO 1.2.0, including a genuine multisite fixture for network enforcement and anonymous contexts for public sign-in. Providers, accounts, domains and audit records are fictional examples; they do not establish successful registration or authentication with an external provider.

Capture the actual interface with Playwright. Keep the WordPress styling and use a consistent desktop viewport; crop to the plugin workspace or the relevant complete section. Public sign-in captures must use an anonymous browser context. Use ordinary UI navigation to show the provider guide steps and expanded settings. Never fill or reveal a real secret for a screenshot.

Leave 24 CSS pixels of the surrounding native background on every side of each capture. Preserve the content's original dimensions and typography; panels, headings and controls must not touch the image edges. Apply this padding only in the capture framing, without changing production styles or redrawing the interface. Check the content bounds and restore any temporary capture wrapper after each screenshot.

The gallery covers overview, provider choice and setup, public login and shortcode placement, appearance, account mapping and verification, access policy, optional modules, audit/privacy, backup/restore and genuine multisite enforcement. Current site screens use `wp-admin/admin.php?page=portalweave-sso&tab=...`; network controls use the corresponding Network Admin screen. See [admin screen design](../admin-design.md) for the route and interaction contracts.

Existing-provider configuration uses its seven tabs; capture the appropriate active tab for mapping and account-policy views. Keep these editor screenshots distinct from the step-by-step creation guide.

Activity screenshots separate the count-only Overview from Audit logs. Chart history is fictional seeded data; capture the selected UTC period and labels so the sample does not imply live authentication or complete historical coverage.

The Audit logs image is an excerpt of the first six complete rows of the first 50-event page. Its upper pagination bar retains the event range, page count and navigation controls. Capture at row boundaries and identify the excerpt in the caption; do not resize the table text to fit every row into one image.

The current gallery also shows the top-level WordPress sidebar entry, visual mapping rules and their Advanced JSON switch, grouped integration settings, native media selection with a sample uploaded provider logo, and the floating Save changes / Revert all changes reminder. Unsaved examples were reverted after capture. A second agent reviewed all selected images for framing, clipping, sensitive data and misleading connection status before they were copied here.

The sign-out screenshots were captured on 2026-11-07 using PortalWeave SSO 1.3.0 in the same disposable single-site fixture. `signout-provider.png` shows all three choices with the fictional Sample work account provider configured for end-session logout. `signout-site-only.png` shows the local sign-out and Go back choices when the fictional Sample community account provider has no end-session endpoint. These are sample capabilities, not verified external provider connections. The confirmation page was also reviewed at a 320-pixel viewport, with a long provider name, keyboard focus and JavaScript disabled; its selected product images received a second privacy and framing review.

Review images individually for clipping, misleading state and sensitive data before replacing them. Update the matching README captions and alternative text when the interface changes. Raw browser output, authenticated profiles, cookies, traces and fixture setup files stay in ignored local artifact directories.

These are repository documentation assets. Both the production allowlist and `.distignore` exclude this directory from the installable plugin ZIP. Follow [AGENTS.md](../../AGENTS.md#documentation-and-screenshots) and the [disposable verification guidance](../verification.md#reproduce-the-current-checks) when refreshing the gallery.
