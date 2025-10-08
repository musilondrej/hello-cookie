<?php

namespace CCM\Frontend;

class Services
{
    const HANDLE_PREFIX = 'ccm-consent-';

    /** @var array<string,string> handle => inline JS (without <script>) */
    private static $inline_map = [];

    /** @var array<string,string> handle => category ('analytics'|'marketing'|'functionality'|'necessary') */
    private static $category_map = [];

    /** @var array<string,string> handle => noscript HTML (if needed) */
    private static $noscript_map = [];

    /** @var array<string,bool> handles allowed to run before consent (e.g., cookieless GA4, Clarity init) */
    private static $allow_map = [];

    /** @var array<string,array<string,string>> optional extra attributes for the <script> tag per handle */
    private static $attr_map = [];

    public static function boot(): void
    {
        add_filter('script_loader_tag', [__CLASS__, 'filter_script_loader_tag'], 10, 3);
        add_action('wp_footer', [__CLASS__, 'print_noscripts'], 20);
    }

    private static function ver(string $salt = ''): string
    {
        $base = defined('CCM_VERSION') ? (string) CCM_VERSION : '1.0.0';
        return $salt === '' ? $base : ($base.'-'.substr(md5($salt), 0, 8));
    }

    private static function sanitize_inline_js(string $code): string
    {
        $code = preg_replace('~<\s*/?\s*script\b[^>]*>~i', '', $code) ?? $code;
        return str_replace('</script', '<\\/script', $code);
    }

    public static function filter_script_loader_tag(string $tag, string $handle, string $src): string
    {
        if (strpos($handle, self::HANDLE_PREFIX) !== 0) {
            return $tag;
        }

        $category = self::$category_map[$handle] ?? 'analytics';

        // Allowed pre-consent scripts
        if (! empty(self::$allow_map[$handle])) {
            if ($src === '' || $src === false) {
                $code = self::$inline_map[$handle] ?? '';
                if ($code === '')
                    return $tag;
                $safe = self::sanitize_inline_js($code);
                return '<script type="text/javascript">'.$safe.'</script>'."\n";
            }
            return $tag;
        }

        // helper: build extra attributes
        $extra_attrs = '';
        if (! empty(self::$attr_map[$handle]) && is_array(self::$attr_map[$handle])) {
            foreach (self::$attr_map[$handle] as $k => $v) {
                if ($k === '' || $v === '') continue;
                $extra_attrs .= ' '.esc_attr($k).'="'.esc_attr($v).'"';
            }
        }

        // Blocked inline scripts
        if ($src === '' || $src === false) {
            $code = self::$inline_map[$handle] ?? '';
            if ($code === '')
                return $tag;
            $safe = self::sanitize_inline_js($code);
            return sprintf(
                '<script type="text/plain" data-category="%s"%s>%s</script>'."\n",
                esc_attr($category),
                $extra_attrs,
                $safe
            );
        }

        // Blocked external scripts
        return sprintf(
            '<script type="text/plain" data-category="%s" data-src="%s"%s></script>'."\n",
            esc_attr($category),
            esc_url($src),
            $extra_attrs
        );
    }

    public static function print_noscripts(): void
    {
        if (empty(self::$noscript_map))
            return;

        foreach (self::$noscript_map as $html) {
            echo $html."\n"; // trusted HTML
        }
    }

    /**
     * Google Analytics 4 cookieless pre-consent setup.
     */
    public static function ga4(string $measurement_id): void
    {
        $id = trim($measurement_id);
        if ($id === '')
            return;

        $loader = self::HANDLE_PREFIX.'ga4-loader';
        self::$category_map[$loader] = 'analytics';
        self::$allow_map[$loader] = true;

        wp_register_script(
            $loader,
            'https://www.googletagmanager.com/gtag/js?id='.rawurlencode($id),
            [],
            self::ver($id),
            false
        );
        wp_enqueue_script($loader);

        $inline = sprintf(
            'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}window.gtag=window.gtag||gtag;'
            .'gtag("config","%1$s",{anonymize_ip:true,allow_google_signals:false,allow_ad_personalization_signals:false,client_storage:"none",cookie_flags:"max-age=7200;secure;samesite=none"});',
            esc_js($id)
        );

        wp_add_inline_script($loader, $inline, 'after');
    }

    /**
     * Meta Pixel (deferred until marketing consent is granted).
     */
    public static function metaPixel(string $pixel_id): void
    {
        $id = trim($pixel_id);
        if ($id === '')
            return;

        $init = self::HANDLE_PREFIX.'meta-init';
        self::$category_map[$init] = 'marketing';

        $inline =
            '!(function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)})(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");'
            .sprintf('fbq("init","%s");fbq("track","PageView");', esc_js($id));

        self::$inline_map[$init] = $inline;

        wp_register_script($init, false, [], self::ver($id), false);
        wp_enqueue_script($init);
    }

    /**
     * Microsoft Clarity (blocked until analytics consent).
     */
    public static function clarity(string $project_id): void
    {
        $id = trim($project_id);
        if ($id === '')
            return;

        $inline = '(function(c,l,a,r,i,t,y){if(c[a] && c[a].initialized)return;c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};c[a].initialized=true;t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window, document, "clarity", "script", "'.esc_js($id).'");';
        $safe = self::sanitize_inline_js($inline);
        echo '<script type="text/plain" data-category="analytics" id="clarity-init">'.$safe.'</script>'."\n"; // trusted JS content sanitized
    }
}