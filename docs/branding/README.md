# PortalWeave SSO artwork

The original interwoven portal concept brings connected identity providers into one recognizable mark. Broad blue and teal forms keep the symbol readable at small sizes. The wordmark is **PortalWeave SSO**; the banner tagline is **OAuth & OpenID Connect for WordPress**. No external provider marks are used.

The palette uses blue `#135E96`, teal `#10AD9B`, navy ink `#102B46` and a light `#F0F6FC` background. Preserve the mark's proportions and check the wordmark and tagline at the final display size.

## Masters and previews

The PNG masters were created with built-in image generation from an original PortalWeave SSO brief on **2026-11-07, Asia/Singapore**.

- [logo.png](logo.png): transparent logo master. Suggested alternative text: “PortalWeave SSO interwoven portal mark.”
- [banner.png](banner.png): banner master with the wordmark and tagline. Suggested alternative text: “PortalWeave SSO — OAuth & OpenID Connect for WordPress.”

## Runtime logo

The shared settings header uses locally served PNG copies of the mark:

| File | Dimensions |
| --- | --- |
| [assets/branding/logo.png](../../assets/branding/logo.png) | 128 × 128 pixels |
| [assets/branding/logo-2x.png](../../assets/branding/logo-2x.png) | 256 × 256 pixels |

These optimized UI images are included in the installable plugin through the existing `assets/` production allowlist.

## Listing exports

The technical PNG exports live separately in [`.wordpress-org/`](../../.wordpress-org/):

| File | Dimensions |
| --- | --- |
| [icon-128x128.png](../../.wordpress-org/icon-128x128.png) | 128 × 128 pixels |
| [icon-256x256.png](../../.wordpress-org/icon-256x256.png) | 256 × 256 pixels |
| [banner-772x250.png](../../.wordpress-org/banner-772x250.png) | 772 × 250 pixels |
| [banner-1544x500.png](../../.wordpress-org/banner-1544x500.png) | 1544 × 500 pixels |

Review exports against the official [WordPress.org plugin asset rules](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/) before any directory publication.

The masters in `docs/branding/` and the listing exports in `.wordpress-org/` remain outside the installable plugin's production allowlist. `.distignore` also excludes these two directories for secondary distribution tools; the runtime logo copies remain included. Listing exports are not a deployed WordPress.org listing. Directory approval and publication remain separate; review the deploy action's listing-asset handling before enabling deployment. Keep existing published release tags and artifacts unchanged.
