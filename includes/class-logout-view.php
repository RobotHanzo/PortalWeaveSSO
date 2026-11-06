<?php
namespace PortalWeaveSSO;

defined('ABSPATH') || exit;

/** Present the sign-out choices after Auth has checked the request and provider. */
final class LogoutView
{
    public static function render(array $context): string {
        $title = __('Sign out', 'portalweave-sso');
        wp_enqueue_style('portalweave-sso-logout', plugins_url('assets/logout.css', PORTALWEAVE_SSO_FILE), [], PORTALWEAVE_SSO_VERSION);
        ob_start();
        wp_print_styles('portalweave-sso-logout');
        $stylesheet = (string) ob_get_clean();
        $logo = plugins_url('assets/branding/logo.png', PORTALWEAVE_SSO_FILE);
        $html = '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . esc_html($title . ' — ' . $context['site_name']) . '</title>' . $stylesheet . '</head><body class="portalweave-sso-logout"><main class="portalweave-sso-logout-panel" aria-labelledby="portalweave-sso-logout-title">';
        $html .= '<header class="portalweave-sso-logout-site"><img src="' . esc_url($logo) . '" width="44" height="44" alt=""><div><p class="portalweave-sso-logout-site-name">' . esc_html($context['site_name']) . '</p><p class="portalweave-sso-logout-brand">' . esc_html__('PortalWeave SSO', 'portalweave-sso') . '</p></div></header>';
        $html .= '<h1 id="portalweave-sso-logout-title">' . esc_html($title) . '</h1><p class="portalweave-sso-logout-description">' . esc_html($context['can_provider'] ? __('Choose where to end your session.', 'portalweave-sso') : __('End your session on this site, or go back to stay signed in.', 'portalweave-sso')) . '</p>';
        $html .= '<form class="portalweave-sso-logout-actions" method="post" action="' . esc_url($context['action_url']) . '"><input type="hidden" name="confirm" value="1"><input type="hidden" name="_wpnonce" value="' . esc_attr($context['nonce']) . '"><input type="hidden" name="redirect_to" value="' . esc_attr($context['redirect']) . '">';
        if ($context['can_provider']) {
            /* translators: %s: the configured name of the identity provider. */
            $combined_label = sprintf(__('Sign out of this site and %s', 'portalweave-sso'), $context['provider_name']);
            $html .= '<button class="portalweave-sso-logout-button portalweave-sso-logout-primary" type="submit" name="logout_scope" value="all">' . esc_html($combined_label) . '</button>';
        }
        $html .= '<button class="portalweave-sso-logout-button ' . ($context['can_provider'] ? 'portalweave-sso-logout-secondary' : 'portalweave-sso-logout-primary') . '" type="submit" name="logout_scope" value="site">' . esc_html__('Sign out of this site', 'portalweave-sso') . '</button></form>';
        if ($context['can_provider']) {
            /* translators: %s: the configured name of the identity provider. */
            $note = sprintf(__('Signing out of this site only keeps you signed in to %s.', 'portalweave-sso'), $context['provider_name']);
            $html .= '<p class="portalweave-sso-logout-note">' . esc_html($note) . '</p>';
        } elseif ($context['provider_name'] !== '') {
            /* translators: %s: the configured name of the identity provider. */
            $note = sprintf(__('Sign-out from %s is not available here. Your session there will stay active.', 'portalweave-sso'), $context['provider_name']);
            $html .= '<p class="portalweave-sso-logout-note">' . esc_html($note) . '</p>';
        }
        $html .= '<div class="portalweave-sso-logout-back"><a id="portalweave-sso-logout-back" href="' . esc_url($context['back_url']) . '">' . esc_html__('Go back', 'portalweave-sso') . '</a></div></main></body></html>';
        return $html;
    }
}
