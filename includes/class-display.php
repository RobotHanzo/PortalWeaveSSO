<?php
namespace WPSSO;

defined('ABSPATH') || exit;

final class Display
{
    public static function register(): void
    {
        add_shortcode('wpsso_oauth_login', [self::class, 'shortcode']);
        add_action('widgets_init', static fn() => register_widget(Login_Widget::class));
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
        add_action('login_enqueue_scripts', [self::class, 'assets']);
        add_action('login_form', [self::class, 'login_buttons']);
        add_action('woocommerce_login_form_end', [self::class, 'login_buttons']);
        add_filter('plugin_action_links_' . plugin_basename(WPSSO_FILE), static function ($links) {
            array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=wpsso')) . '">' . esc_html__('Settings', 'wpsso') . '</a>');
            return $links;
        });
        add_action('wp_login', static function ($name, $user) {
            // SSO records success separately once navigation has been resolved.
            if (empty($_GET['wpsso_action'])) {
                Audit::record('local_login', '', $user);
            }
        }, 20, 2);
        add_action('wp_login_failed', static fn($name) => Audit::record('local_failure', '', null, 'wpsso_local_credentials'));
        add_action('wp_logout', static fn($id) => Audit::record('local_logout', '', $id));
        add_action('show_user_profile', [self::class, 'profile']);
        add_action('edit_user_profile', [self::class, 'profile']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'exporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'eraser']);
    }

    public static function assets(): void
    {
        $button = Config::get('button');
        if (($GLOBALS['pagenow'] ?? '') === 'wp-login.php' && empty($button['admin_style'])) {
            return;
        }
        wp_enqueue_style('wpsso-buttons', WPSSO_URL . 'assets/login.css', [], WPSSO_VERSION);
        wp_enqueue_script('wpsso-login', WPSSO_URL . 'assets/login.js', [], WPSSO_VERSION, true);
        $css = sprintf('.wpsso-login{--wpsso-size:%dpx;--wpsso-radius:%dpx;--wpsso-spacing:%dpx}', $button['size'], $button['radius'], $button['spacing']);
        wp_add_inline_style('wpsso-buttons', $css . '\n' . wp_strip_all_tags($button['custom_css']));
    }

    public static function shortcode($atts = []): string
    {
        return self::buttons(shortcode_atts(['app' => '', 'redirect' => '', 'popup' => '', 'title' => ''], $atts, 'wpsso_oauth_login'));
    }

    public static function login_buttons(): void
    {
        echo self::buttons(); // Escaped by buttons().
    }

    public static function buttons(array $args = []): string
    {
        $settings = Config::all();
        $style = $settings['button'];
        $html = '<div class="wpsso-login">';
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $link = !empty($style['logout_link']) ? '<a class="wpsso-logout" href="' . esc_url(Auth::logout_url()) . '">' . esc_html__('Sign out', 'wpsso') . '</a>' : '';
            $message = wp_kses_post($style['logout_message']);
            return $html . '<p class="wpsso-session">' . str_replace(['##user##', '##Sign Out##'], [esc_html($user->display_name), $link], $message) . '</p></div>';
        }
        $title = $args['title'] ?? $style['above_text'];
        if ($title) {
            $html .= '<p class="wpsso-label">' . esc_html($title) . '</p>';
        }
        $requested = isset($_REQUEST['redirect_to']) && is_string($_REQUEST['redirect_to']) ? wp_unslash($_REQUEST['redirect_to']) : '';
        $redirect = ($args['redirect'] ?? '') ?: ($settings['login_redirect'] ?: ($requested ?: Redirect::current()));
        $popup = ($args['popup'] ?? '') !== '' ? filter_var($args['popup'], FILTER_VALIDATE_BOOLEAN) : $settings['popup'];
        $theme = in_array($style['theme'], ['default', 'white', 'hover', 'custom', 'smart'], true) ? $style['theme'] : 'default';
        $shape = in_array($style['shape'], ['round', 'round-edge', 'round_edge', 'square', 'long'], true) ? str_replace('_', '-', $style['shape']) : 'round-edge';
        foreach ($settings['apps'] as $id => $app) {
            if (!$app['enabled'] || (!empty($args['app']) && $args['app'] !== $id)) {
                continue;
            }
            $text = $app['button_text'] ?: str_replace('{app}', $app['name'] ?: $id, $style['text']);
            $class = 'wpsso-button wpsso-theme-' . $theme . ' wpsso-shape-' . $shape;
            $class .= !empty($style['shadow']) ? ' wpsso-shadow' : '';
            $class .= !empty($style['transform']) ? ' wpsso-transform' : '';
            $html .= '<a class="' . esc_attr($class) . '" href="' . esc_url(Auth::login_url($id, $redirect, false, $popup)) . '"' . ($popup ? ' data-wpsso-popup="1"' : '') . '>';
            if ($app['icon_url']) {
                $html .= '<img class="wpsso-icon" src="' . esc_url($app['icon_url']) . '" alt="" loading="lazy" referrerpolicy="no-referrer">';
            } else {
                $html .= '<span class="wpsso-mark" aria-hidden="true">' . esc_html(strtoupper(mb_substr($app['name'] ?: $id, 0, 1))) . '</span>';
            }
            $html .= '<span>' . esc_html($text) . '</span></a>';
        }
        return $html . '</div>';
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
        echo '<h2>' . esc_html__('SSO profile attributes', 'wpsso') . '</h2><table class="form-table">';
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
        $exporters['wpsso'] = ['exporter_friendly_name' => 'WPSSO login audit', 'callback' => static function ($email, $page = 1) {
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wpsso_audit WHERE email = %s ORDER BY id LIMIT 100 OFFSET %d", $email, ($page - 1) * 100), ARRAY_A);
            $data = [];
            foreach ($rows as $row) {
                $fields = [];
                foreach (['status', 'app', 'created_at', 'client_ip', 'navigation'] as $key) {
                    $fields[] = ['name' => $key, 'value' => $row[$key]];
                }
                $data[] = ['group_id' => 'wpsso-audit', 'group_label' => 'SSO login audit', 'item_id' => 'wpsso-' . $row['id'], 'data' => $fields];
            }
            return ['data' => $data, 'done' => count($rows) < 100];
        }];
        return $exporters;
    }

    public static function eraser(array $erasers): array
    {
        $erasers['wpsso'] = ['eraser_friendly_name' => 'WPSSO login audit', 'callback' => static function ($email) {
            global $wpdb;
            $count = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}wpsso_audit SET email = '', username = '', user_id = 0, client_ip = '', navigation = '' WHERE email = %s", $email));
            return ['items_removed' => $count > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
        }];
        return $erasers;
    }
}

final class Login_Widget extends \WP_Widget
{
    public function __construct()
    {
        parent::__construct('wpsso_oauth_login', 'WPSSO login', ['description' => __('OAuth and OpenID Connect login buttons.', 'wpsso')]);
    }

    public function widget($args, $instance): void
    {
        echo $args['before_widget'];
        if (!empty($instance['title'])) {
            echo $args['before_title'] . esc_html($instance['title']) . $args['after_title'];
        }
        echo Display::buttons(['app' => $instance['app'] ?? '']);
        echo $args['after_widget'];
    }

    public function form($instance): void
    {
        echo '<p><label for="' . esc_attr($this->get_field_id('title')) . '">Title</label><input class="widefat" id="' . esc_attr($this->get_field_id('title')) . '" name="' . esc_attr($this->get_field_name('title')) . '" value="' . esc_attr($instance['title'] ?? '') . '"></p>';
        echo '<p><label for="' . esc_attr($this->get_field_id('app')) . '">Provider</label><select class="widefat" id="' . esc_attr($this->get_field_id('app')) . '" name="' . esc_attr($this->get_field_name('app')) . '"><option value="">All enabled providers</option>';
        foreach (Config::apps() as $id => $app) {
            echo '<option value="' . esc_attr($id) . '" ' . selected($instance['app'] ?? '', $id, false) . '>' . esc_html($app['name'] ?: $id) . '</option>';
        }
        echo '</select></p>';
    }

    public function update($new, $old): array
    {
        return ['title' => sanitize_text_field($new['title'] ?? ''), 'app' => sanitize_key($new['app'] ?? '')];
    }
}
