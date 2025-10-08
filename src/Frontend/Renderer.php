<?php

namespace CCM\Frontend;

class Renderer
{
    private $settings;

    public function __construct()
    {
        $this->settings = \CCM\Plugin::get_settings();
    }

    public function init(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        if (($this->settings['mode'] ?? 'direct') === 'direct') {
            if (! empty($this->settings['ga4_id'])) {
                add_action('wp_head', [$this, 'render_gcm_defaults'], 0);
            }
            add_action('wp_head', [$this, 'render_blocked_scripts'], 1);
        }

        add_action('wp_head', [$this, 'render_custom_category_scripts'], 2);

        if (($this->settings['mode'] ?? '') === 'gtm' && ! empty($this->settings['gtm_id'])) {
            add_action('wp_head', [$this, 'render_gcm_defaults'], 0);
            add_action('wp_head', [$this, 'render_gtm_script'], 1);
            add_action('wp_body_open', [$this, 'render_gtm_noscript']);
        }
    }

    public function enqueue_scripts(): void
    {
        if (! empty($this->settings['hide_from_bots']) && $this->is_bot()) {
            return;
        }

        $ver = $this->assets_version();

        wp_enqueue_style(
            'ccm-cookieconsent-bundle',
            CCM_PLUGIN_URL.'assets/dist/ccm-cookieconsent-bundle.css',
            [],
            $ver
        );

        wp_enqueue_script(
            'ccm-cookieconsent-bundle',
            CCM_PLUGIN_URL.'assets/dist/ccm-cookieconsent-bundle.js',
            [],
            $ver,
            false
        );

        wp_add_inline_script('ccm-cookieconsent-bundle', $this->build_config_json(), 'before');
        $this->add_custom_colors_css();
    }

    private function assets_version(): string
    {
        $base = defined('CCM_VERSION') ? (string) CCM_VERSION : '1.0.0';

        if (defined('CCM_PLUGIN_DIR')) {
            $css = CCM_PLUGIN_DIR.'assets/dist/ccm-cookieconsent-bundle.css';
            $js = CCM_PLUGIN_DIR.'assets/dist/ccm-cookieconsent-bundle.js';
            $mtime = max(@filemtime($css) ?: 0, @filemtime($js) ?: 0);
            return $mtime ? (string) $mtime : $base;
        }

        return $base;
    }

    private function build_config_json(): string
    {
        $lang = substr(get_locale(), 0, 2) ?: 'cs';

        $config = [
            'restUrl' => rest_url(),
            'nonce' => wp_create_nonce('wp_rest'),
            'revision' => '1.0',
            'mode' => ! empty($this->settings['gtm_id']) ? 'gtm' : 'direct',
            'categoryScripts' => $this->settings['category_scripts'] ?? [],
            'cookiesToErase' => $this->settings['cookies_to_erase'] ?? '_ga,_gid,_gat,_gcl_,__utm,_fbp,fr,_uet,_ttp,_pin_',
            'disablePageInteraction' => (bool) ($this->settings['force_consent'] ?? false),
            'hideFromBots' => (bool) ($this->settings['hide_from_bots'] ?? true),
            'categories' => [
                'necessary' => ['enabled' => true, 'readOnly' => true],
                'analytics' => [],
                'marketing' => [],
                'functionality' => [],
            ],
            'language' => [
                'default' => $lang,
                'translations' => [
                    'cs' => [
                        'consentModal' => [
                            'title' => $this->settings['texts']['title'] ?? '',
                            'description' => $this->settings['texts']['description'] ?? '',
                            'acceptAllBtn' => $this->settings['texts']['accept_all'] ?? __('Povolit vše', 'hellocookie'),
                            'acceptNecessaryBtn' => $this->settings['texts']['accept_necessary'] ?? __('Pouze nezbytné', 'hellocookie'),
                            'showPreferencesBtn' => $this->settings['texts']['show_preferences'] ?? __('Nastavení', 'hellocookie'),
                        ],
                        'preferencesModal' => [
                            'title' => $this->settings['texts']['preferences_title'] ?? __('Nastavení cookies', 'hellocookie'),
                            'acceptAllBtn' => $this->settings['texts']['accept_all'] ?? __('Povolit vše', 'hellocookie'),
                            'acceptNecessaryBtn' => $this->settings['texts']['accept_necessary'] ?? __('Pouze nezbytné', 'hellocookie'),
                            'savePreferencesBtn' => $this->settings['texts']['save_preferences'] ?? __('Uložit nastavení', 'hellocookie'),
                            'sections' => [
                                [
                                    'title' => $this->settings['texts']['necessary_title'] ?? __('Nezbytné cookies', 'hellocookie'),
                                    'description' => $this->settings['texts']['necessary_description'] ?? __('Tyto soubory cookie jsou nezbytné pro základní funkčnost webu.', 'hellocookie'),
                                    'linkedCategory' => 'necessary',
                                ],
                                [
                                    'title' => $this->settings['texts']['analytics_title'] ?? __('Analytické cookies', 'hellocookie'),
                                    'description' => $this->settings['texts']['analytics_description'] ?? __('Pomáhají nám pochopit, jak návštěvníci používají náš web.', 'hellocookie'),
                                    'linkedCategory' => 'analytics',
                                ],
                                [
                                    'title' => $this->settings['texts']['marketing_title'] ?? __('Marketingové cookies', 'hellocookie'),
                                    'description' => $this->settings['texts']['marketing_description'] ?? __('Používají se pro zobrazování relevantních reklam.', 'hellocookie'),
                                    'linkedCategory' => 'marketing',
                                ],
                                [
                                    'title' => $this->settings['texts']['functionality_title'] ?? __('Funkční cookies', 'hellocookie'),
                                    'description' => $this->settings['texts']['functionality_description'] ?? __('Umožňují pokročilé funkce webu.', 'hellocookie'),
                                    'linkedCategory' => 'functionality',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'guiOptions' => [
                'consentModal' => [
                    'layout' => $this->settings['ui_layout'] ?? 'box',
                    'position' => $this->settings['ui_position'] ?? 'bottom right',
                    'transition' => $this->settings['ui_transition'] ?? 'slide',
                    'flipButtons' => (bool) ($this->settings['ui_flip_buttons'] ?? false),
                    'equalWeightButtons' => (bool) ($this->settings['ui_equal_weight_buttons'] ?? true),
                ],
            ],
            'cookie' => [
                'expiresAfterDays' => (int) ($this->settings['cookie_expiration'] ?? 182),
            ],
        ];

    $js_config = 'window.CCM_CONFIG = '.wp_json_encode($config).';';

        if (! empty($this->settings['custom_css'])) {
            $custom_css = (string) $this->settings['custom_css'];
            // Vkládáme pouze obsah, bez <style> wrapperu
            $js_config .= "\n".'if(typeof document !== "undefined"){var s=document.createElement("style");s.textContent='.wp_json_encode($custom_css).';document.head.appendChild(s);}';
        }

        return $js_config;
    }

    public function render_blocked_scripts(): void
    {
        if (! empty($this->settings['ga4_id'])) {
            Services::ga4($this->settings['ga4_id']);
        }

        if (! empty($this->settings['meta_pixel_id'])) {
            Services::metaPixel($this->settings['meta_pixel_id']);
        }

        if (! empty($this->settings['clarity_id'])) {
            Services::clarity($this->settings['clarity_id']);
        }
    }

    public function render_custom_category_scripts(): void
    {
        $cat_scripts = $this->settings['category_scripts'] ?? [];
        if (! is_array($cat_scripts) || empty($cat_scripts)) {
            return;
        }

        foreach (['analytics', 'marketing', 'functionality'] as $cat) {
            $code = isset($cat_scripts[$cat]) ? (string) $cat_scripts[$cat] : '';
            $code = trim($code);
            if ($code === '') {
                continue;
            }
            $safe = $this->escape_script_text($code);
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- vlastní JS je očištěn od <script> a escapován pro </script
            echo '<script type="text/plain" data-category="'.esc_attr($cat).'">'.$safe."</script>\n";
        }
    }

    private function escape_script_text(string $js): string
    {
        $js = preg_replace('~<\s*/?\s*script\b[^>]*>~i', '', $js);

        return str_replace('</script', '<\\/script', (string) $js);
    }

    public function render_gtm_script(): void
    {
        $gtm_id = (string) ($this->settings['gtm_id'] ?? '');
        ?>
        <!-- Google Tag Manager -->
        <script>
            (function (w, d, s, l, i) {
                w[l] = w[l] || [];
                w[l].push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
                var f = d.getElementsByTagName(s)[0],
                    j = d.createElement(s), dl = l !== 'dataLayer' ? '&l=' + l : '';
                j.async = true; j.src = 'https://www.googletagmanager.com/gtm.js?id=' + i + dl;
                f.parentNode.insertBefore(j, f);
            })(window, document, 'script', 'dataLayer', '<?php echo esc_js($gtm_id); ?>');
        </script>
        <!-- End Google Tag Manager -->
        <?php
    }

    public function render_gtm_noscript(): void
    {
        $gtm_id = (string) ($this->settings['gtm_id'] ?? '');
        ?>
        <!-- Google Tag Manager (noscript) -->
        <noscript>
            <iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr($gtm_id); ?>" height="0" width="0"
                style="display:none;visibility:hidden" title="Google Tag Manager">
            </iframe>
        </noscript>
        <!-- End Google Tag Manager (noscript) -->
        <?php
    }

    private function add_custom_colors_css(): void
    {
        $colors = is_array($this->settings['colors'] ?? null) ? $this->settings['colors'] : [];
        $defaults = ['bg' => '#ffffff', 'text' => '#333333', 'accent' => '#007cba'];

        if (! $colors) {
            return;
        }

        $bg = sanitize_hex_color($colors['bg'] ?? '') ?: $defaults['bg'];
        $text = sanitize_hex_color($colors['text'] ?? '') ?: $defaults['text'];
        $accent = sanitize_hex_color($colors['accent'] ?? '') ?: $defaults['accent'];

        // Pokud jsou stejné jako default, CSS nevkládej
        if ($bg === $defaults['bg'] && $text === $defaults['text'] && $accent === $defaults['accent']) {
            return;
        }

        $custom_css = "
        /* EU Cookie Consent Manager - Custom Colors */
        .cc-banner, .cc-window {
            background-color: {$bg} !important;
            color: {$text} !important;
        }
        .cc-btn, .cc-allow, .cc-deny {
            background-color: {$accent} !important;
            border-color: {$accent} !important;
        }
        .cc-btn:hover, .cc-allow:hover, .cc-deny:hover {
            background-color: {$accent}dd !important;
        }";

        wp_add_inline_style('ccm-cookieconsent-bundle', $custom_css);
    }

    /**
     * Early inline script: Google Consent Mode defaults (v2) + Clarity stub s default denied.
     * Běží s prioritou 0, tj. před GTM/GA/Clarity.
     */
    public function render_gcm_defaults(): void
    {
        $is_gtm = (($this->settings['mode'] ?? '') === 'gtm' && ! empty($this->settings['gtm_id']));
        $is_direct_ga4 = (($this->settings['mode'] ?? 'direct') === 'direct' && ! empty($this->settings['ga4_id']));
        if (! $is_gtm && ! $is_direct_ga4) {
            return;
        }
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag() { window.dataLayer.push(arguments); }
            window.gtag = window.gtag || gtag;

            // Consent Mode v2 – default denied
            gtag('consent', 'default', {
                ad_storage: 'denied',
                analytics_storage: 'denied',
                ad_user_data: 'denied',
                ad_personalization: 'denied',
                functionality_storage: 'granted',
                security_storage: 'granted',
                wait_for_update: 500
            });

            // Clarity stub + default denied (v2)
            (function () {
                if (typeof window.clarity !== 'function') {
                    window.clarity = function () { (window.clarity.q = window.clarity.q || []).push(arguments); };
                }
                try { window.clarity('consentv2', { analytics_storage: 'denied', ad_storage: 'denied' }); } catch (e) { }
            })();
        </script>
        <?php
    }

    private function is_bot(): bool
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($ua === '') {
            return false;
        }
        return (bool) preg_match('~bot|crawl|spider|slurp|facebookexternalhit|mediapartners|bingpreview|pinterest|crawler~i', $ua);
    }
}