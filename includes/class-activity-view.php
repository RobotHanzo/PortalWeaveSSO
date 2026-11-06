<?php
namespace PortalWeaveSSO;

defined('ABSPATH') || exit;

/** Render count-only activity summaries inside the capability-checked admin screen. */
final class ActivityView
{
    public static function render(array|\WP_Error $summary, array $provider_names, array $period_urls, int $days, bool $collecting): void {
        echo '<div data-activity-overview><div class="portalweave-sso-activity-toolbar"><div><h3>' . esc_html__('Activity overview', 'portalweave-sso') . '</h3><p>' . esc_html__('Retained audit events for the current site, including when viewed in Network Admin.', 'portalweave-sso') . '</p></div><nav class="portalweave-sso-activity-periods" aria-label="' . esc_attr__('Overview period', 'portalweave-sso') . '">';
        foreach ($period_urls as $period => $url) {
            /* translators: %d: number of days in the overview, either 7, 30 or 90. */
            $label = sprintf(__('%d days', 'portalweave-sso'), $period);
            echo '<a href="' . esc_url($url) . '"' . ($period === $days ? ' class="is-active" aria-current="true"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav></div>';
        if (is_wp_error($summary)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($summary->get_error_message()) . '</p></div></div>';
            return;
        }
        /* translators: 1: first UTC calendar date, 2: last UTC calendar date. */
        $window = sprintf(__('From %1$s to %2$s (UTC). Today is partial.', 'portalweave-sso'), self::date($summary['start']), self::date($summary['end']));
        echo '<p class="portalweave-sso-activity-window">' . esc_html($window) . '</p>';
        if (!$collecting) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Audit collection is currently off for this site. Retained events are still shown.', 'portalweave-sso') . '</p></div>';
        }
        echo '<dl class="portalweave-sso-summary-grid portalweave-sso-activity-totals">';
        foreach ([
            [__('Recorded events', 'portalweave-sso'), $summary['total']],
            [__('Sign-in events', 'portalweave-sso'), $summary['types']['sign_ins']],
            [__('Failure events', 'portalweave-sso'), $summary['types']['failures']],
            [__('Sign-out events', 'portalweave-sso'), $summary['types']['sign_outs']],
        ] as [$label, $count]) {
            echo '<div class="portalweave-sso-stat"><dt>' . esc_html($label) . '</dt><dd>' . esc_html(number_format_i18n($count)) . '</dd></div>';
        }
        echo '</dl><p class="portalweave-sso-activity-note">' . esc_html__('Counts describe recorded events, not unique users. One action can create multiple audit entries. Retention and disabled collection can leave gaps in the history.', 'portalweave-sso') . '</p>';
        if (!$summary['total']) {
            echo '<section class="portalweave-sso-section portalweave-sso-empty-state"><h3>' . esc_html__('No events in this period', 'portalweave-sso') . '</h3><p>' . esc_html__('Try another period or review collection settings under Audit logs. Earlier activity cannot be reconstructed if it was not recorded or has been removed.', 'portalweave-sso') . '</p></section></div>';
            return;
        }
        self::timeline($summary['daily']);
        echo '<div class="portalweave-sso-activity-comparisons">';
        $types = [];
        foreach ([
            'sign_ins' => __('Sign-in events', 'portalweave-sso'),
            'failures' => __('Failure events', 'portalweave-sso'),
            'sign_outs' => __('Sign-out events', 'portalweave-sso'),
            'tests' => __('Provider test events', 'portalweave-sso'),
            'other' => __('Other events', 'portalweave-sso'),
        ] as $key => $label) {
            $types[] = ['label' => $label, 'count' => $summary['types'][$key]];
        }
        self::bars(__('Events by type', 'portalweave-sso'), $types, __('Sign-in events include SSO and WordPress password sign-ins. Other events include refreshes, provisioning and administrative operations.', 'portalweave-sso'));
        $providers = [];
        foreach ($summary['providers'] as $provider) {
            $app = $provider['app'];
            $label = $app === '' ? __('WordPress / system', 'portalweave-sso') : (($provider_names[$app] ?? '') ?: $app);
            $providers[] = ['label' => $label, 'count' => $provider['count']];
        }
        if ($summary['other_providers']) {
            $providers[] = ['label' => __('Other providers', 'portalweave-sso'), 'count' => $summary['other_providers']];
        }
        self::bars(__('Activity by provider', 'portalweave-sso'), $providers, __('Shows the eight most active providers. Remaining providers are grouped together; events without a provider appear as WordPress / system.', 'portalweave-sso'));
        echo '</div></div>';
    }

    private static function date(string $date, bool $short = false): string {
        return wp_date($short ? 'M j' : get_option('date_format'), strtotime($date . ' UTC'), new \DateTimeZone('UTC'));
    }

    private static function timeline(array $daily): void {
        echo '<figure class="portalweave-sso-section portalweave-sso-activity-chart"><figcaption><h3 id="portalweave-sso-timeline-title">' . esc_html__('Daily audit events', 'portalweave-sso') . '</h3><p id="portalweave-sso-timeline-description">' . esc_html__('The bars show retained events by UTC date. On narrow screens, scroll horizontally for more dates. Hover for a count, or open the daily counts table below.', 'portalweave-sso') . '</p></figcaption><div class="portalweave-sso-timeline-scroll" tabindex="0" role="region" aria-label="' . esc_attr__('Daily activity chart', 'portalweave-sso') . '"><svg viewBox="0 0 960 260" width="960" height="260" role="img" aria-labelledby="portalweave-sso-timeline-title" aria-describedby="portalweave-sso-timeline-description" xmlns="http://www.w3.org/2000/svg">';
        $maximum = max(2, max(array_column($daily, 'count')));
        $scale = (int) ceil($maximum / 2) * 2;
        foreach ([0, 1, 2] as $tick) {
            $y = 210 - $tick * 90;
            echo '<line class="portalweave-sso-chart-grid" x1="54" x2="940" y1="' . esc_attr((string) $y) . '" y2="' . esc_attr((string) $y) . '"></line><text class="portalweave-sso-chart-label" x="44" y="' . esc_attr((string) ($y + 5)) . '" text-anchor="end">' . esc_html(number_format_i18n($scale * $tick / 2)) . '</text>';
        }
        $slot = 886 / count($daily);
        $stride = max(1, (int) ceil(count($daily) / 7));
        foreach ($daily as $index => $point) {
            $height = 180 * $point['count'] / $scale;
            $x = 54 + $index * $slot + 2;
            $y = 210 - $height;
            /* translators: 1: UTC date, 2: localized event count. */
            $title = sprintf(_n('%1$s: %2$s event', '%1$s: %2$s events', $point['count'], 'portalweave-sso'), self::date($point['date']), number_format_i18n($point['count']));
            echo '<rect class="portalweave-sso-chart-bar" x="' . esc_attr(number_format($x, 2, '.', '')) . '" y="' . esc_attr(number_format($y, 2, '.', '')) . '" width="' . esc_attr(number_format(max(1, $slot - 4), 2, '.', '')) . '" height="' . esc_attr(number_format($height, 2, '.', '')) . '"><title>' . esc_html($title) . '</title></rect>';
            if ($index % $stride === 0 || $index === count($daily) - 1) {
                echo '<text class="portalweave-sso-chart-label" x="' . esc_attr(number_format($x + ($slot - 4) / 2, 2, '.', '')) . '" y="242" text-anchor="middle">' . esc_html(self::date($point['date'], true)) . '</text>';
            }
        }
        echo '</svg></div><details class="portalweave-sso-daily-counts"><summary>' . esc_html__('View daily counts', 'portalweave-sso') . '</summary><div class="portalweave-sso-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr__('Daily audit event counts in UTC', 'portalweave-sso') . '"><table class="widefat striped"><caption class="screen-reader-text">' . esc_html__('Daily audit event counts in UTC', 'portalweave-sso') . '</caption><thead><tr><th scope="col">' . esc_html__('Date (UTC)', 'portalweave-sso') . '</th><th scope="col">' . esc_html__('Recorded events', 'portalweave-sso') . '</th></tr></thead><tbody>';
        foreach ($daily as $point) {
            echo '<tr><th scope="row"><time datetime="' . esc_attr($point['date']) . '">' . esc_html(self::date($point['date'])) . '</time></th><td>' . esc_html(number_format_i18n($point['count'])) . '</td></tr>';
        }
        echo '</tbody></table></div></details></figure>';
    }

    private static function bars(string $title, array $rows, string $description): void {
        $maximum = max(1, max(array_column($rows, 'count')));
        echo '<figure class="portalweave-sso-section portalweave-sso-activity-chart"><figcaption><h3>' . esc_html($title) . '</h3><p>' . esc_html($description) . '</p></figcaption><ul class="portalweave-sso-activity-bars">';
        foreach ($rows as $row) {
            $width = round(100 * $row['count'] / $maximum, 2);
            echo '<li><div class="portalweave-sso-activity-bar-label"><span>' . esc_html($row['label']) . '</span><strong>' . esc_html(number_format_i18n($row['count'])) . '</strong></div><div class="portalweave-sso-activity-bar-track" aria-hidden="true"><span style="width: ' . esc_attr((string) $width) . '%"></span></div></li>';
        }
        echo '</ul></figure>';
    }
}
