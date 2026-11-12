<?php
/** Exercise the bundled MO files through WordPress locale switching and real renderers. */
foreach ([
    'es_ES' => ['Ajustes', 'Informar de un error', 'Iniciar sesión con Sample provider', 'Has iniciado sesión como'],
    'fr_FR' => ['Réglages', 'Signaler un bug', 'Se connecter avec Sample provider', 'Connecté en tant que'],
    'de_DE' => ['Einstellungen', 'Fehler melden', 'Mit Sample provider anmelden', 'Angemeldet als'],
    'pt_BR' => ['Configurações', 'Relatar um erro', 'Entrar com Sample provider', 'Conectado como'],
    'zh_CN' => ['设置', '报告问题', '使用 Sample provider 登录', '登录。'],
    'zh_TW' => ['設定', '回報問題', '使用 Sample provider 登入', '登入。'],
    'ja' => ['設定', '不具合を報告', 'Sample provider でログイン', 'としてログイン中。'],
] as $test_locale => $expected) {
    portalweave_sso_test('l10n: ' . $test_locale . ' loads bundled translations and preserves custom wording', static function () use ($test_locale, $expected) {
        $previous_switcher = $GLOBALS['wp_locale_switcher'];
        $available = static fn($languages) => array_unique(array_merge($languages, [$test_locale]));
        add_filter('get_available_languages', $available);
        $switcher = new WP_Locale_Switcher();
        $switcher->init();
        $GLOBALS['wp_locale_switcher'] = $switcher;
        try {
            portalweave_sso_assert(switch_to_locale($test_locale), 'Could not switch to ' . $test_locale);
            portalweave_sso_assert(__('Settings', 'portalweave-sso') === $expected[0], 'Bundled catalog did not load through WordPress');
            $links = apply_filters('plugin_action_links_' . plugin_basename(PORTALWEAVE_SSO_FILE), ['existing' => '<a href="#existing">Existing</a>']);
            portalweave_sso_assert(isset($links['existing']), 'Bug tracker removed an existing action');
            portalweave_sso_assert(str_contains(implode('', $links), $expected[1]) && str_contains(implode('', $links), 'https://github.com/RobotHanzo/PortalWeaveSSO/issues/new/choose'), 'Missing translated bug tracker action');
            $network_links = apply_filters('network_admin_plugin_action_links_' . plugin_basename(PORTALWEAVE_SSO_FILE), []);
            portalweave_sso_assert(str_contains(implode('', $network_links), $expected[1]), 'Network plugin list has no translated bug tracker action');
            $settings = \PortalWeaveSSO\Config::defaults();
            $settings['apps'] = ['localized' => portalweave_sso_app(['id' => 'localized', 'name' => 'Sample provider'])];
            update_option('portalweave_sso_settings', $settings, false);
            $html = portalweave_sso_button_render('wp-login.php')['html'];
            portalweave_sso_assert(str_contains($html, $expected[2]), 'Default login text remained English');
            portalweave_sso_assert(get_option('portalweave_sso_settings')['button']['text'] === 'Sign in with {app}', 'Translation changed stored settings');
            $settings['button']['text'] = 'Custom portal: {app}';
            update_option('portalweave_sso_settings', $settings, false);
            portalweave_sso_assert(str_contains(portalweave_sso_button_render('wp-login.php')['html'], 'Custom portal: Sample provider'), 'Custom login wording was replaced');
            $settings['apps']['localized']['button_text'] = 'Provider-specific wording';
            update_option('portalweave_sso_settings', $settings, false);
            portalweave_sso_assert(str_contains(portalweave_sso_button_render('wp-login.php')['html'], 'Provider-specific wording'), 'Provider wording was replaced');
            $admin = get_user_by('login', 'portalweave_sso_test_admin');
            wp_set_current_user($admin->ID);
            $session = \PortalWeaveSSO\Display::buttons();
            $translated_message = __('Signed in as ##user##. ##Sign Out##', 'portalweave-sso');
            portalweave_sso_assert($translated_message !== 'Signed in as ##user##. ##Sign Out##', 'Session message has no translation');
            portalweave_sso_assert(str_contains($session, $expected[3]), 'Session message remained English');
            $settings['button']['logout_message'] = 'Custom session: ##user##. ##Sign Out##';
            update_option('portalweave_sso_settings', $settings, false);
            portalweave_sso_assert(str_contains(\PortalWeaveSSO\Display::buttons(), 'Custom session:'), 'Custom session wording was replaced');
            $plural = _n('%1$s: %2$s event', '%1$s: %2$s events', 2, 'portalweave-sso');
            portalweave_sso_assert($plural !== '%1$s: %2$s events' && str_contains($plural, '%2$s'), 'Plural translation failed');
            portalweave_sso_assert(__('Configure an immutable provider subject attribute.', 'portalweave-sso') === 'Configure an immutable provider subject attribute.', 'Untranslated messages lost English fallback');
        } finally {
            if (is_locale_switched()) restore_previous_locale();
            remove_filter('locale', [$switcher, 'filter_locale']);
            remove_filter('determine_locale', [$switcher, 'filter_locale']);
            remove_filter('get_available_languages', $available);
            $GLOBALS['wp_locale_switcher'] = $previous_switcher;
        }
        portalweave_sso_assert(__('Settings', 'portalweave-sso') === 'Settings', 'Locale restoration retained the previous catalog');
    });
}
