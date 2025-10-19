<?php

namespace CCM;

class Plugin
{
    const DB_VERSION = '1.1';
    const TABLE_NAME = 'ccm_consent_log';
    const ALLOWED_CATEGORIES = ['necessary', 'analytics', 'marketing', 'functionality'];

    public function init(): void
    {
        add_action('init', [$this, 'check_db_version']);
        add_action('rest_api_init', [self::class, 'register_rest_routes']);

        if (is_admin()) {
            $this->init_admin();
        } else {
            $this->init_frontend();
        }

        add_action('init', [$this, 'register_shortcodes']);
        add_action('ccm_cleanup_consent_logs', [self::class, 'cleanup_old_consent_logs']);

        // Ensure cron is scheduled even if activation hook didn't run
        if (! wp_next_scheduled('ccm_cleanup_consent_logs')) {
            wp_schedule_event(time(), 'daily', 'ccm_cleanup_consent_logs');
        }

        // Add custom $wpdb alias for convenience
        add_action('init', function () {
            global $wpdb;
            if (empty($wpdb->ccm_consent_log)) {
                $wpdb->ccm_consent_log = $wpdb->prefix.self::TABLE_NAME;
            }
        });
    }

    public function check_db_version(): void
    {
        $current_version = get_option('ccm_db_version', '0');
        if (version_compare($current_version, self::DB_VERSION, '<')) {
            $this->migrate_database($current_version);
        }
    }

    private function migrate_database(string $from_version): void
    {
        self::create_consent_log_table();
        update_option('ccm_db_version', self::DB_VERSION);
    }

    private function init_admin(): void
    {
        if (class_exists('CCM\\Admin\\SettingsPage')) {
            (new Admin\SettingsPage())->init();
        }
    }

    private function init_frontend(): void
    {
        if (class_exists('CCM\\Frontend\\Renderer')) {
            (new Frontend\Renderer())->init();
        }
        if (class_exists('CCM\\Frontend\\Services')) {
            Frontend\Services::boot();
        }
    }

    public function register_shortcodes(): void
    {
        add_shortcode('cookie_revisit', [$this, 'cookie_revisit_shortcode']);
    }

    public function cookie_revisit_shortcode($atts = []): string
    {
        $atts = shortcode_atts([
            'text' => __('Změnit nastavení cookies', 'hellocookie'),
            'class' => 'ccm-revisit-button'
        ], $atts, 'cookie_revisit');

        return sprintf(
            '<button class="%s" data-cc="show-preferencesModal" type="button" aria-haspopup="dialog" aria-expanded="false" aria-label="%s">%s</button>',
            esc_attr($atts['class']),
            esc_attr($atts['text']),
            esc_html($atts['text'])
        );
    }

    public static function get_settings(): array
    {
        $defaults = [
            'ga4_id' => '',
            'meta_pixel_id' => '',
            'clarity_id' => '',
            'gtm_id' => '',
            'texts' => [
                'title' => __('Používáme cookies', 'hellocookie'),
                'description' => __('Tento web používá soubory cookie pro zlepšení uživatelského zážitku a analýzu návštěvnosti.', 'hellocookie'),
                'accept_all' => __('Přijmout vše', 'hellocookie'),
                'accept_necessary' => __('Pouze nezbytné', 'hellocookie'),
                'show_preferences' => __('Nastavení', 'hellocookie')
            ],
            'ui_layout' => 'box',
            'ui_position' => 'bottom right',
            'ui_transition' => 'slide',
            'ui_flip_buttons' => false,
            'ui_equal_weight_buttons' => true,
            'custom_css' => '',
            'category_scripts' => [
                'analytics' => '',
                'marketing' => '',
                'functionality' => ''
            ],
            'force_consent' => false,
            'hide_from_bots' => true,
            'cookie_expiration' => 182,
            'cookies_to_erase' => '_ga,_gid,_gat,_gcl_,__utm,_fbp,fr,_uet,_ttp',
        ];

        $settings = wp_parse_args(get_option('ccm_settings', []), $defaults);
        $settings['mode'] = ! empty($settings['gtm_id']) ? 'gtm' : 'direct';
        $settings['retention_months'] = (int) ($settings['retention_months'] ?? 12);
        $settings['remove_logs_on_uninstall'] = (bool) ($settings['remove_logs_on_uninstall'] ?? false);

        return $settings;
    }

    public static function activate_plugin(): void
    {
        self::ensure_secret_salt();

        if (! wp_next_scheduled('ccm_cleanup_consent_logs')) {
            wp_schedule_event(time(), 'daily', 'ccm_cleanup_consent_logs');
        }

        update_option('ccm_db_version', self::DB_VERSION);
    }

    public static function deactivate_plugin(): void
    {
        wp_clear_scheduled_hook('ccm_cleanup_consent_logs');
    }

    private static function ensure_secret_salt(): void
    {
        if (! get_option('ccm_secret_salt')) {
            add_option('ccm_secret_salt', bin2hex(random_bytes(32)), '', false);
        }
    }

    public static function register_rest_routes(): void
    {
        $route_args = [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'handle_consent_rest'],
            'args' => [
                'consent_id' => ['type' => 'string', 'required' => true],
                'categories' => ['type' => 'array', 'required' => true, 'validate_callback' => [self::class, 'validate_categories']],
                'version_hash' => ['type' => 'string', 'required' => false],
                'source' => ['type' => 'string', 'required' => true, 'enum' => ['accept', 'change']],
            ],
        ];

        register_rest_route('ccm/v1', '/consent', $route_args);
        register_rest_route('ccm/v1', '/consents', $route_args);
    }

    private static function create_consent_log_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix.self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            consent_hash varchar(64) NOT NULL COMMENT 'HMAC-SHA256 hashed consent ID for pseudonymization',
            categories text NOT NULL COMMENT 'JSON array of accepted categories',
            version_hash varchar(64) DEFAULT '' COMMENT 'Plugin/config version for audit trail',
            source enum('accept','change') NOT NULL DEFAULT 'accept' COMMENT 'How consent was given',
            ip_hash varchar(64) DEFAULT NULL COMMENT 'Hashed IP for basic fraud prevention',
            user_agent_hash varchar(64) DEFAULT NULL COMMENT 'Hashed UA for basic fraud prevention',
            PRIMARY KEY (id),
            KEY consent_hash (consent_hash),
            KEY created_at (created_at)
        ) {$charset} COMMENT='GDPR-compliant consent log with pseudonymized data';";

        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function validate_categories($categories): bool
    {
        if (! is_array($categories))
            return false;
        foreach ($categories as $category) {
            if (! in_array($category, self::ALLOWED_CATEGORIES, true))
                return false;
        }
        return true;
    }

    public static function sanitize_categories($categories): array
    {
        if (! is_array($categories))
            return [];
        $sanitized = array_map('sanitize_text_field', $categories);
        return array_intersect($sanitized, self::ALLOWED_CATEGORIES);
    }

    /** REST: log consent data securely */
    public static function handle_consent_rest($request)
    {
        global $wpdb;
        $table = $wpdb->prefix.self::TABLE_NAME;

        $consent_id = $request->get_param('consent_id');
        $categories = $request->get_param('categories');
        $version = $request->get_param('version_hash') ?: '';
        $source = $request->get_param('source') ?: 'accept';
        $salt = get_option('ccm_secret_salt');

        if (! $salt) {
            return new \WP_Error('config_error', 'Plugin not properly activated', ['status' => 500]);
        }

        $consent_hash = hash_hmac('sha256', $consent_id, $salt);
        
        // Optional pseudonymized fraud prevention (GDPR-compliant)
        $ip_hash = null;
        $ua_hash = null;
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip_hash = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'], $salt);
        }
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            $ua_hash = hash_hmac('sha256', $_SERVER['HTTP_USER_AGENT'], $salt);
        }

        $result = $wpdb->insert(
            $table,
            [
                'created_at' => current_time('mysql', true),
                'consent_hash' => $consent_hash,
                'categories' => wp_json_encode($categories),
                'version_hash' => $version,
                'source' => $source,
                'ip_hash' => $ip_hash,
                'user_agent_hash' => $ua_hash,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            return new \WP_Error('db_error', 'Database error occurred', ['status' => 500]);
        }

        wp_cache_delete('ccm_consent_log_count', 'ccm');

        return rest_ensure_response(['success' => true, 'message' => 'Consent logged successfully']);
    }

    /** Remove old consent logs based on retention policy */
    public static function cleanup_old_consent_logs(): void
    {
        global $wpdb;
        if (empty($wpdb->ccm_consent_log)) {
            $wpdb->ccm_consent_log = $wpdb->prefix.self::TABLE_NAME;
        }

        $settings = self::get_settings();
        $months = (int) ($settings['retention_months'] ?? 12);
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$months} months"));

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$wpdb->ccm_consent_log}` WHERE created_at < %s",
                $cutoff
            )
        );

        if ($deleted > 0) {
            wp_cache_delete('ccm_consent_log_count', 'ccm');
        }
    }
}