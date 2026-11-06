<?php
namespace PortalWeaveSSO;

defined('ABSPATH') || exit;

/** Server-rendered provider recipes, enhanced into one task at a time locally. */
final class ProviderWizard
{
    public static function render(string $selected, bool $network, bool $readonly, string $base_url): void
    {
        $catalog = ProviderSetup::catalog();
        echo '<section class="portalweave-sso-onboarding" data-portalweave-setup>';
        if (!isset($catalog[$selected])) {
            echo '<h3 class="portalweave-sso-setup-heading">' . esc_html__('Which service will people sign in with?', 'portalweave-sso') . '</h3><p class="portalweave-sso-setup-intro">' . esc_html__('Choose your provider for a setup guide tailored to its console and credentials.', 'portalweave-sso') . '</p><ul class="portalweave-sso-provider-options">';
            foreach ($catalog as $key => $recipe) {
                echo '<li><a class="portalweave-sso-provider-choice" href="' . esc_url(add_query_arg('setup_provider', $key, $base_url)) . '">';
                self::logo($key);
                echo '<span><strong class="portalweave-sso-provider-label">' . esc_html($recipe['label']) . '</strong><span class="portalweave-sso-provider-summary">' . esc_html($recipe['summary']) . '</span></span></a></li>';
            }
            echo '</ul></section>';
            return;
        }
        $recipe = $catalog[$selected];
        $steps = $recipe['steps'];
        $steps['review'] = ['title' => __('Review your connection', 'portalweave-sso'), 'description' => __('Check the details, then save this provider. Your existing sign-in policy stays in effect.', 'portalweave-sso')];
        echo '<div class="portalweave-sso-setup-service">';
        self::logo($selected);
        echo '<div><strong>' . esc_html($recipe['label']) . '</strong><p>' . esc_html($recipe['summary']) . '</p></div></div><ol class="portalweave-sso-setup-progress" aria-label="' . esc_attr__('Provider setup progress', 'portalweave-sso') . '"><li><a href="' . esc_url($base_url) . '">' . esc_html__('Choose provider', 'portalweave-sso') . '</a></li>';
        foreach ($steps as $key => $step) {
            echo '<li><button type="button" data-step-target="' . esc_attr($key) . '" disabled>' . esc_html($step['title']) . '</button></li>';
        }
        echo '</ol><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="portalweave-sso-settings-form portalweave-sso-setup-form" data-setup-form>';
        wp_nonce_field('portalweave_sso_save');
        foreach (['action' => 'portalweave_sso_save', 'portalweave_sso_screen' => 'providers', 'portalweave_sso_provider' => '', 'portalweave_sso_creating' => '1'] as $key => $value) {
            self::hidden($key, $value);
        }
        if ($network) {
            self::hidden('network', '1');
        }
        self::hidden('settings[provider_setup][provider]', $selected);
        self::hidden('settings[provider_setup][id]', $selected . '-' . bin2hex(random_bytes(4)));
        echo '<fieldset' . ($readonly ? ' disabled' : '') . '><p class="portalweave-sso-setup-status screen-reader-text" data-setup-status role="status" aria-live="polite"></p>';
        foreach ($steps as $key => $step) {
            $heading = 'portalweave-sso-setup-step-' . $key;
            echo '<fieldset class="portalweave-sso-setup-step" data-setup-step="' . esc_attr($key) . '" aria-labelledby="' . esc_attr($heading) . '"><legend class="screen-reader-text">' . esc_html($step['title']) . '</legend><div class="portalweave-sso-setup-step-layout"><div class="portalweave-sso-setup-fields"><h3 class="portalweave-sso-setup-heading" id="' . esc_attr($heading) . '" tabindex="-1">' . esc_html($step['title']) . '</h3><p class="portalweave-sso-setup-intro">' . esc_html($step['description'] ?? '') . '</p>';
            if ($key === 'registration') {
                self::callback(in_array($selected, ['authentik', 'entra', 'office365', 'b2c', 'keycloak', 'okta', 'auth0', 'onelogin'], true));
            }
            if ($key === 'review') {
                self::review($recipe);
            } else {
                foreach ($recipe['fields'] as $field => $definition) {
                    if ($definition['step'] === $key) {
                        self::field($field, $definition);
                    }
                }
            }
            if ($key === 'registration') {
                echo '<label class="portalweave-sso-setup-confirm"><input type="checkbox" name="settings[provider_setup][callback_registered]" value="1" required> <span>' . esc_html__('I registered this exact redirect URI with the provider.', 'portalweave-sso') . '</span></label>';
            }
            echo '</div><aside class="portalweave-sso-setup-guide" aria-label="' . esc_attr__('Setup instructions', 'portalweave-sso') . '">';
            if ($key === 'review') {
                echo '<h4>' . esc_html__('What happens next', 'portalweave-sso') . '</h4><p>' . esc_html__('After saving, open Test SSO Config to inspect the returned attributes. Then test sign-in with an ordinary account before making this your default provider.', 'portalweave-sso') . '</p><p>' . esc_html__('A successful attribute test does not verify account linking, email verification or administrator access. Review those policies in the advanced editor.', 'portalweave-sso') . '</p>';
                if ($network) {
                    echo '<p>' . esc_html__('Network providers can be tested here only when network configuration is active. Use a staging site to validate a new network policy before enforcing it.', 'portalweave-sso') . '</p>';
                }
            } else {
                echo '<h4>' . esc_html__('In your provider console', 'portalweave-sso') . '</h4>';
                if (!empty($step['instructions'])) {
                    echo '<ol>';
                    foreach ($step['instructions'] as $instruction) {
                        echo '<li>' . esc_html($instruction) . '</li>';
                    }
                    echo '</ol>';
                }
            }
            echo '<a href="' . esc_url($recipe['docs']) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Open the official setup guide', 'portalweave-sso') . '</a></aside></div>';
            if ($key === 'registration' || $key === 'review') {
                foreach (($recipe['notices'] ?? []) as $notice) {
                    echo '<p class="portalweave-sso-setup-note">' . esc_html($notice) . '</p>';
                }
            }
            echo '</fieldset>';
        }
        if (!$readonly) {
            echo '<div class="portalweave-sso-setup-navigation" data-setup-navigation hidden><button class="button button-secondary" type="button" data-setup-back>' . esc_html__('Back', 'portalweave-sso') . '</button><button class="button button-primary" type="button" data-setup-next>' . esc_html__('Continue', 'portalweave-sso') . '</button><button class="button button-primary" type="submit" data-setup-submit>' . esc_html__('Save provider', 'portalweave-sso') . '</button></div><div class="portalweave-sso-setup-navigation" data-setup-fallback-submit><button class="button button-primary" type="submit">' . esc_html__('Save provider', 'portalweave-sso') . '</button></div>';
        }
        echo '</fieldset>';
        self::hidden('portalweave_sso_complete', '1');
        echo '</form></section>';
    }

    private static function hidden(string $name, string $value): void
    {
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
    }

    private static function logo(string $provider): void
    {
        $files = ['custom' => 'custom.svg', 'authentik' => 'authentik.svg', 'discord' => 'discord.svg', 'keycloak' => 'keycloak.svg', 'auth0' => 'auth0.svg', 'okta' => 'okta.svg', 'entra' => 'entra.svg', 'office365' => 'office365.svg', 'cognito' => 'cognito.svg', 'google' => 'google.svg', 'salesforce' => 'salesforce.svg', 'onelogin' => 'onelogin.png', 'b2c' => 'b2c.svg', 'classlink' => 'classlink.png'];
        echo '<span class="portalweave-sso-provider-logo" aria-hidden="true">';
        if (isset($files[$provider])) {
            echo '<img src="' . esc_url(plugins_url('assets/providers/' . $files[$provider], PORTALWEAVE_SSO_FILE)) . '" width="40" height="40" alt="" decoding="async">';
        }
        echo '</span>';
    }

    private static function callback(bool $include_logout): void
    {
        $uris = [['id' => 'portalweave-sso-setup-callback', 'label' => __('Redirect URI', 'portalweave-sso'), 'url' => Config::callback(), 'help' => __('Paste this into the provider’s allowed redirect or callback URLs, exactly as shown.', 'portalweave-sso')]];
        if ($include_logout) {
            $uris[] = ['id' => 'portalweave-sso-setup-logout', 'label' => __('Sign-out redirect URI', 'portalweave-sso'), 'url' => add_query_arg('portalweave_sso_action', 'logout_callback', home_url('/')), 'help' => __('If the provider asks for an allowed logout or sign-out return URL, register this separate URI.', 'portalweave-sso')];
        }
        foreach ($uris as $uri) {
            echo '<div class="portalweave-sso-setup-callback"><label for="' . esc_attr($uri['id']) . '">' . esc_html($uri['label']) . '</label><input type="text" id="' . esc_attr($uri['id']) . '" value="' . esc_attr($uri['url']) . '" readonly spellcheck="false"><button type="button" class="button button-secondary" data-setup-copy aria-controls="' . esc_attr($uri['id']) . '" data-copy-success="' . esc_attr__('Redirect URI copied.', 'portalweave-sso') . '" data-copy-error="' . esc_attr__('Select and copy the redirect URI above.', 'portalweave-sso') . '" hidden>' . esc_html__('Copy URI', 'portalweave-sso') . '</button><p>' . esc_html($uri['help']) . '</p></div>';
        }
    }

    private static function field(string $key, array $definition): void
    {
        $id = 'portalweave-sso-setup-' . $key;
        $type = $definition['type'] ?? 'text';
        $conditional = !empty($definition['when']);
        $required = !empty($definition['required']);
        echo '<div class="portalweave-sso-setup-field" data-setup-field="' . esc_attr($key) . '" data-setup-required="' . ($required ? '1' : '0') . '"' . ($conditional ? ' data-setup-when="' . esc_attr(wp_json_encode($definition['when'])) . '"' : '') . '><label for="' . esc_attr($id) . '">' . esc_html($definition['label']) . '</label>';
        $attributes = ['id' => $id, 'name' => 'settings[provider_setup][' . $key . ']', 'aria-describedby' => $id . '-help'];
        if ($type !== 'select') {
            $attributes += ['type' => $type, 'value' => $definition['default'] ?? '', 'autocomplete' => $type === 'password' ? 'new-password' : 'off', 'spellcheck' => 'false', 'autocapitalize' => 'none'];
            if (isset($definition['placeholder'])) {
                $attributes['placeholder'] = $definition['placeholder'];
            }
            if ($type === 'url') {
                $attributes['pattern'] = 'https://.+';
            }
        }
        echo $type === 'select' ? '<select' : '<input';
        foreach ($attributes as $name => $value) {
            echo ' ' . esc_attr($name) . '="' . esc_attr($value) . '"';
        }
        echo $required && !$conditional ? ' required' : '';
        echo '>';
        if ($type === 'select') {
            foreach ($definition['options'] as $value => $label) {
                echo '<option value="' . esc_attr($value) . '"' . selected((string) ($definition['default'] ?? ''), (string) $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
        }
        if ($type === 'password') {
            echo '<button type="button" class="button button-secondary" data-setup-toggle-secret aria-controls="' . esc_attr($id) . '" data-show-label="' . esc_attr__('Show secret', 'portalweave-sso') . '" data-hide-label="' . esc_attr__('Hide secret', 'portalweave-sso') . '" hidden>' . esc_html__('Show secret', 'portalweave-sso') . '</button>';
        }
        echo '<p id="' . esc_attr($id . '-help') . '" class="description">' . esc_html($definition['help'] ?? '') . '</p></div>';
    }

    private static function review(array $recipe): void
    {
        self::field('name', ['label' => __('Display name', 'portalweave-sso'), 'help' => __('Shown on your sign-in button. You can change it later.', 'portalweave-sso'), 'default' => $recipe['label']]);
        echo '<dl class="portalweave-sso-setup-review"><div><dt>' . esc_html__('Provider', 'portalweave-sso') . '</dt><dd data-setup-review="provider">' . esc_html($recipe['label']) . '</dd></div>';
        foreach ($recipe['fields'] as $key => $definition) {
            if ($key === 'client_id' || $key === 'client_secret') {
                continue;
            }
            echo '<div data-setup-review-field="' . esc_attr($key) . '"><dt>' . esc_html($definition['label']) . '</dt><dd data-setup-review="' . esc_attr($key) . '"></dd></div>';
        }
        foreach (['client_id' => __('Client ID', 'portalweave-sso'), 'client_secret' => __('Client secret', 'portalweave-sso')] as $key => $label) {
            echo '<div><dt>' . esc_html($label) . '</dt><dd data-setup-review="' . esc_attr($key) . '" data-review-entered="' . esc_attr__('Entered', 'portalweave-sso') . '" data-review-empty="' . esc_attr__('Not entered', 'portalweave-sso') . '" data-review-not-required="' . esc_attr__('Not required', 'portalweave-sso') . '">' . esc_html__('Check the credentials above.', 'portalweave-sso') . '</dd></div>';
        }
        echo '</dl><p>' . esc_html__('Credentials are stored encrypted on this WordPress installation. They are excluded from configuration exports.', 'portalweave-sso') . '</p>';
    }
}
