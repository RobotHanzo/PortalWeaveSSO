# Product screenshot maintenance

These PNGs show PortalWeave SSO 1.0.1 in a disposable WordPress 7.1.4 fixture. Providers, accounts, domains and audit records are fictional examples; they do not establish successful registration or authentication with an external provider.

Capture the actual interface with Playwright. Keep the WordPress styling and use a consistent desktop viewport; crop to the plugin workspace or the relevant complete section. Public sign-in captures must use an anonymous browser context. Use ordinary UI navigation to show the provider guide steps and expanded settings. Never fill or reveal a real secret for a screenshot.

The gallery covers overview, provider choice and setup, public login and shortcode placement, appearance, account mapping and verification, access policy, optional modules, audit/privacy, backup/restore and genuine multisite enforcement. Current site screens use `wp-admin/options-general.php?page=portalweave-sso&tab=...`; network controls use the corresponding Network Admin screen. See [admin screen design](../admin-design.md) for the route and interaction contracts.

Existing-provider configuration uses its seven tabs; capture the appropriate active tab for mapping and account-policy views. Keep these editor screenshots distinct from the step-by-step creation guide.

Review images individually for clipping, misleading state and sensitive data before replacing them. Update the matching README captions and alternative text when the interface changes. Raw browser output, authenticated profiles, cookies, traces and fixture setup files stay in ignored local artifact directories.

These are repository documentation assets. Both the production allowlist and `.distignore` exclude this directory from the installable plugin ZIP. Follow [AGENTS.md](../../AGENTS.md#documentation-and-screenshots) and the [disposable verification guidance](../verification.md#reproduce-the-current-checks) when refreshing the gallery.
