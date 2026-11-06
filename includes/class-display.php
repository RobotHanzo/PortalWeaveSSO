<?php
namespace PortalWeaveSSO;

defined('ABSPATH') || exit;

final class Display
{
    public static function register(): void
    {
        add_shortcode('sso_oauth_login', [self::class, 'shortcode']);
        add_action('widgets_init', static fn() => register_widget(Login_Widget::class));
        add_action('wp_enqueue_scripts', [self::class, 'frontend_assets']);
        add_action('wp_footer', [self::class, 'late_styles']);
        add_action('login_enqueue_scripts', [self::class, 'assets']);
        add_action('login_form', [self::class, 'login_buttons']);
        add_action('woocommerce_login_form_end', [self::class, 'login_buttons']);
        add_filter('plugin_action_links_' . plugin_basename(PORTALWEAVE_SSO_FILE), static function ($links) {
            array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=portalweave-sso')) . '">' . esc_html__('Settings', 'portalweave-sso') . '</a>');
            return $links;
        });
        add_action('wp_login', static function ($name, $user) {
            // SSO records success separately once navigation has been resolved.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing context for an already authenticated core login event.
            if (empty($_GET['portalweave_sso_action'])) {
                Audit::record('local_login', '', $user);
            }
        }, 20, 2);
        add_action('wp_login_failed', static fn($name) => Audit::record('local_failure', '', null, 'portalweave_sso_local_credentials'));
        add_action('wp_logout', static fn($id) => Audit::record('local_logout', '', $id));
        add_action('show_user_profile', [self::class, 'profile']);
        add_action('edit_user_profile', [self::class, 'profile']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'eraser']);
    }

    public static function frontend_assets(): void
    {
        $has_buttons = is_active_widget(false, false, 'portalweave_sso_oauth_login', true)
            || (function_exists('is_account_page') && is_account_page());
        foreach (($GLOBALS['wp_query']->posts ?? []) as $post) {
            if ($post instanceof \WP_Post && has_shortcode($post->post_content, 'sso_oauth_login')) {
                $has_buttons = true;
                break;
            }
        }
        if ($has_buttons) {
            self::assets();
        }
    }

    public static function assets(): void
    {
        $settings = Config::all();
        $button = $settings['button'];
        $custom = self::wp_login_options($settings);
        wp_enqueue_script('portalweave-sso-login', PORTALWEAVE_SSO_URL . 'assets/login.js', [], PORTALWEAVE_SSO_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
        if (($GLOBALS['pagenow'] ?? '') === 'wp-login.php' && empty($button['admin_style']) && $custom === null) {
            return;
        }
        $path = PORTALWEAVE_SSO_DIR . 'assets/login.css';
        $revision = PORTALWEAVE_SSO_VERSION . '-' . (string) filemtime($path);
        wp_enqueue_style('portalweave-sso-buttons', PORTALWEAVE_SSO_URL . 'assets/login.css', [], $revision);
        $css = sprintf('.portalweave-sso-login{--portalweave-sso-size:%dpx;--portalweave-sso-radius:%dpx;--portalweave-sso-spacing:%dpx}', $button['size'], $button['radius'], $button['spacing']);
        if (!wp_style_is('portalweave-sso-buttons', 'done') && !wp_styles()->get_data('portalweave-sso-buttons', 'after')) {
            wp_add_inline_style('portalweave-sso-buttons', $css . "\n" . ($custom !== null ? self::wp_login_css($custom) : wp_strip_all_tags($button['custom_css'])));
        }
    }

    /** Explicit WordPress-page context keeps shortcode and WooCommerce styles shared. */
    private static function wp_login_options(array $settings): ?array
    {
        if (($GLOBALS['pagenow'] ?? '') !== 'wp-login.php') {
            return null;
        }
        $style = Config::validate_wp_login_button($settings['wp_login_button'] ?? []);
        return !is_wp_error($style) && $style['enabled'] ? $style : null;
    }

    /** Shared with the administrator preview; values are validated at this boundary too. */
    public static function wp_login_css(array $options): string
    {
        $style = Config::validate_wp_login_button($options);
        if (is_wp_error($style)) {
            return '';
        }
        $values = [
            'background' => $style['background'], 'color' => $style['text_color'], 'border' => $style['border_color'],
            'hover-background' => $style['hover_background'], 'hover-color' => $style['hover_text_color'],
            'radius' => $style['radius'] . 'px', 'size' => $style['font_size'] . 'px',
            'padding-y' => $style['padding_y'] . 'px', 'padding-x' => $style['padding_x'] . 'px',
            'icon-size' => $style['icon_size'] . 'px', 'width' => $style['full_width'] ? '100%' : 'fit-content',
        ];
        $css = '.portalweave-sso-login.portalweave-sso-login-screen{';
        foreach ($values as $key => $value) {
            $css .= '--portalweave-sso-wp-' . $key . ':' . $value . ';';
        }
        return $css . '}';
    }

    /** Provider images stay external images; bundled paths come from a fixed map. */
    public static function provider_icon(array $app): string
    {
        if (isset($app['icon_url']) && is_string($app['icon_url']) && Config::https_url($app['icon_url'])) {
            return $app['icon_url'];
        }
        $provider = isset($app['provider']) && is_string($app['provider']) ? $app['provider'] : '';
        return ProviderSetup::logo_url($provider) ?: ProviderSetup::logo_url('custom');
    }

    /** Shortcodes in templates can render after wp_head has printed styles. */
    public static function late_styles(): void
    {
        if (wp_style_is('portalweave-sso-buttons', 'enqueued') && !wp_style_is('portalweave-sso-buttons', 'done')) {
            wp_print_styles(['portalweave-sso-buttons']);
        }
    }

    /** The login renderer permits only its presentation and navigation markup. */
    public static function allowed_html(): array
    {
        return [
            'div' => ['class' => true], 'p' => ['class' => true],
            'span' => ['class' => true, 'aria-hidden' => true],
            'a' => ['class' => true, 'href' => true, 'rel' => true, 'target' => true, 'data-portalweave-sso-popup' => true],
            'img' => ['class' => true, 'src' => true, 'alt' => true, 'loading' => true, 'referrerpolicy' => true],
            'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'br' => [],
        ];
    }

    public static function shortcode($atts = []): string
    {
        return self::buttons(shortcode_atts(['app' => '', 'redirect' => '', 'popup' => '', 'title' => ''], $atts, 'sso_oauth_login'));
    }

    public static function login_buttons(): void
    {
        echo wp_kses(self::buttons(), self::allowed_html());
    }

    public static function buttons(array $args = []): string
    {
        $settings = Config::all();
        $style = $settings['button'];
        $custom = self::wp_login_options($settings);
        if ($custom !== null) {
            $style = array_replace($style, ['theme' => 'custom', 'shape' => 'round-edge', 'shadow' => $custom['shadow'], 'transform' => false]);
        }
        self::assets();
        $html = '<div class="portalweave-sso-login' . ($custom !== null ? ' portalweave-sso-login-screen' : '') . '">';
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $link = !empty($style['logout_link']) ? '<a class="portalweave-sso-logout" href="' . esc_url(Auth::logout_url()) . '">' . esc_html__('Sign out', 'portalweave-sso') . '</a>' : '';
            $message = str_replace(['##user##', '##Sign Out##'], [esc_html($user->display_name), $link], $style['logout_message']);
            return wp_kses($html . '<p class="portalweave-sso-session">' . $message . '</p></div>', self::allowed_html());
        }
        $title = ($args['title'] ?? '') ?: $style['above_text'];
        if ($title) {
            $html .= '<p class="portalweave-sso-label">' . esc_html($title) . '</p>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation preference only; Redirect::validate() enforces the configured allowlist before use.
        $requested = isset($_REQUEST['redirect_to']) && is_string($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '';
        $redirect = ($args['redirect'] ?? '') ?: ($settings['login_redirect'] ?: ($requested ?: Redirect::current()));
        $popup = ($args['popup'] ?? '') !== '' ? filter_var($args['popup'], FILTER_VALIDATE_BOOLEAN) : $settings['popup'];
        $theme = in_array($style['theme'], ['default', 'white', 'hover', 'custom', 'smart'], true) ? $style['theme'] : 'default';
        $shape = in_array($style['shape'], ['round', 'round-edge', 'round_edge', 'square', 'long'], true) ? str_replace('_', '-', $style['shape']) : 'round-edge';
        foreach ($settings['apps'] as $id => $app) {
            if (!$app['enabled'] || (!empty($args['app']) && $args['app'] !== $id)) {
                continue;
            }
            $text = $app['button_text'] ?: str_replace('{app}', $app['name'] ?: $id, $style['text']);
            if ($custom !== null) {
                if ($custom['text'] !== '') {
                    $text = str_replace('{app}', $app['name'] ?: $id, $custom['text']);
                } elseif ($text === '') {
                    /* translators: %s: provider name. */
                    $text = sprintf(__('Sign in with %s', 'portalweave-sso'), $app['name'] ?: $id);
                }
            }
            $class = 'portalweave-sso-button portalweave-sso-theme-' . $theme . ' portalweave-sso-shape-' . $shape;
            $class .= !empty($style['shadow']) ? ' portalweave-sso-shadow' : '';
            $class .= !empty($style['transform']) ? ' portalweave-sso-transform' : '';
            $class .= $custom !== null && $custom['icon_position'] === 'right' ? ' portalweave-sso-icon-right' : '';
            $html .= '<a class="' . esc_attr($class) . '" href="' . esc_url(Auth::login_url($id, $redirect, false, $popup)) . '"' . ($popup ? ' data-portalweave-sso-popup="1"' : '') . '>';
            $icon = $app['icon_url'];
            if ($custom !== null) {
                $icon = $custom['icon_source'] === 'custom' ? $custom['icon_url'] : ($custom['icon_source'] === 'provider' ? self::provider_icon($app) : '');
            }
            if ($icon !== '') {
                $html .= '<img class="portalweave-sso-icon" src="' . esc_url($icon) . '" alt="" loading="lazy" referrerpolicy="no-referrer">';
            } elseif ($custom === null) {
                $html .= '<span class="portalweave-sso-mark" aria-hidden="true">' . esc_html(strtoupper(mb_substr($app['name'] ?: $id, 0, 1))) . '</span>';
            }
            $html .= '<span>' . esc_html($text) . '</span></a>';
        }
        return wp_kses($html . '</div>', self::allowed_html());
    }

    public static function profile(\WP_User $user): void
    {
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }
        $app_id = get_user_meta($user->ID, Auth::app_meta_key(), true);
        $app = $app_id ? Config::app($app_id) : null;
        if (!$app) {
            return;
        }
        echo '<h2>' . esc_html__('SSO profile attributes', 'portalweave-sso') . '</h2><table class="form-table">';
        foreach ($app['custom_mapping'] as $key => $path) {
            if (!Config::safe_meta_key($key)) {
                continue;
            }
            $value = get_user_meta($user->ID, $key, true);
            echo '<tr><th>' . esc_html($key) . '</th><td><code>' . esc_html(is_scalar($value) ? (string) $value : wp_json_encode($value)) . '</code></td></tr>';
        }
        echo '</table>';
    }

    public static function exporter(array $exporters): array
    {
        $exporters['portalweave-sso'] = ['exporter_friendly_name' => __('PortalWeave SSO login audit', 'portalweave-sso'), 'callback' => static function ($email, $page = 1) {
            $rows = Audit::for_email($email, $page);
            $data = [];
            foreach ($rows as $row) {
                $fields = [];
                foreach (['status', 'app', 'created_at', 'client_ip', 'navigation'] as $key) {
                    $fields[] = ['name' => $key, 'value' => $row[$key]];
                }
                $data[] = ['group_id' => 'portalweave-sso-audit', 'group_label' => __('SSO login audit', 'portalweave-sso'), 'item_id' => 'portalweave-sso-' . $row['id'], 'data' => $fields];
            }
            return ['data' => $data, 'done' => count($rows) < 100];
        }];
        return $exporters;
    }

    public static function eraser(array $erasers): array
    {
        $erasers['portalweave-sso'] = ['eraser_friendly_name' => __('PortalWeave SSO login audit', 'portalweave-sso'), 'callback' => static function ($email) {
            $count = Audit::anonymize($email);
            return ['items_removed' => $count > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
        }];
        return $erasers;
    }
}

final class Login_Widget extends \WP_Widget
{
    public function __construct()
    {
        parent::__construct('portalweave_sso_oauth_login', __('PortalWeave SSO login', 'portalweave-sso'), ['description' => __('OAuth and OpenID Connect login buttons.', 'portalweave-sso')]);
    }

    public function widget($args, $instance): void
    {
        echo wp_kses_post($args['before_widget'] ?? '');
        if (!empty($instance['title'])) {
            echo wp_kses_post($args['before_title'] ?? '') . esc_html($instance['title']) . wp_kses_post($args['after_title'] ?? '');
        }
        echo wp_kses(Display::buttons(['app' => $instance['app'] ?? '']), Display::allowed_html());
        echo wp_kses_post($args['after_widget'] ?? '');
    }

    public function form($instance): void
    {
        echo '<p><label for="' . esc_attr($this->get_field_id('title')) . '">' . esc_html__('Title', 'portalweave-sso') . '</label><input class="widefat" id="' . esc_attr($this->get_field_id('title')) . '" name="' . esc_attr($this->get_field_name('title')) . '" value="' . esc_attr($instance['title'] ?? '') . '"></p>';
        echo '<p><label for="' . esc_attr($this->get_field_id('app')) . '">' . esc_html__('Provider', 'portalweave-sso') . '</label><select class="widefat" id="' . esc_attr($this->get_field_id('app')) . '" name="' . esc_attr($this->get_field_name('app')) . '"><option value="">' . esc_html__('All enabled providers', 'portalweave-sso') . '</option>';
        foreach (Config::apps() as $id => $app) {
            echo '<option value="' . esc_attr($id) . '" ' . selected($instance['app'] ?? '', $id, false) . '>' . esc_html($app['name'] ?: $id) . '</option>';
        }
        echo '</select></p>';
    }

    public function update($new, $old): array
    {
        return ['title' => isset($new['title']) && is_string($new['title']) ? sanitize_text_field($new['title']) : '', 'app' => isset($new['app']) && is_string($new['app']) ? sanitize_key($new['app']) : ''];
    }
}
