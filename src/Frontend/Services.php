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

    /** @var array<string,bool> handles allowed to run before consent */
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
        return $salt === '' ? $base : ($base . '-' . substr(md5($salt), 0, 8));
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

        if (!empty(self::$allow_map[$handle])) {
            if ($src === '' || $src === false) {
                $code = self::$inline_map[$handle] ?? '';
                if ($code === '')
                    return $tag;
                $safe = self::sanitize_inline_js($code);
                return '<script type="text/javascript">' . $safe . '</script>' . "\n";
            }
            return $tag;
        }

        $extra_attrs = '';
        if (!empty(self::$attr_map[$handle]) && is_array(self::$attr_map[$handle])) {
            foreach (self::$attr_map[$handle] as $k => $v) {
                if ($k === '' || $v === '')
                    continue;
                $extra_attrs .= ' ' . esc_attr($k) . '="' . esc_attr($v) . '"';
            }
        }

        if ($src === '' || $src === false) {
            $code = self::$inline_map[$handle] ?? '';
            if ($code === '')
                return $tag;
            $safe = self::sanitize_inline_js($code);
            return sprintf(
                '<script type="text/plain" data-category="%s"%s>%s</script>' . "\n",
                esc_attr($category),
                $extra_attrs,
                $safe
            );
        }

        return sprintf(
            '<script type="text/plain" data-category="%s" data-src="%s"%s></script>' . "\n",
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
            echo $html . "\n";
        }
    }

    public static function ga4(string $measurement_id): void
    {
        $id = trim($measurement_id);
        if ($id === '') {
            return;
        }

        ob_start();
        ?>
        <script type="text/plain" data-category="analytics" async 
            data-src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr($id); ?>"></script>
        
        <script type="text/plain" data-category="analytics">
            window.dataLayer = window.dataLayer || [];
            function gtag() { dataLayer.push(arguments); }
            window.gtag = window.gtag || gtag;
            
            gtag('consent', 'update', {analytics_storage: 'granted'});
            gtag('js', new Date());
            gtag('config', '<?php echo esc_js($id); ?>', {anonymize_ip: true});
        </script>
        <?php
        echo ob_get_clean();
    }

    public static function metaPixel(string $pixel_id): void
    {
        $id = trim($pixel_id);
        if ($id === '')
            return;

        $init = self::HANDLE_PREFIX . 'meta-init';
        self::$category_map[$init] = 'marketing';

        $inline =
            '!(function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)})(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");'
            . sprintf('fbq("init","%s");fbq("track","PageView");', esc_js($id));

        self::$inline_map[$init] = $inline;

        wp_register_script($init, false, [], self::ver($id), false);
        wp_enqueue_script($init);
    }

    public static function clarity(string $project_id): void
    {
        $id = trim($project_id);
        if ($id === '') {
            return;
        }

        ob_start();
        ?>
        <script type="text/plain" data-category="analytics" id="clarity-init">
            (function(c,l,a,r,i,t,y){
                if(c[a] && c[a].initialized) return;
                c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                c[a].initialized=true;
                t=l.createElement(r);
                t.async=1;
                t.src="https://www.clarity.ms/tag/"+i;
                y=l.getElementsByTagName(r)[0];
                y.parentNode.insertBefore(t,y);
            })(window, document, "clarity", "script", "<?php echo esc_js($id); ?>");
        </script>
        <?php
        echo ob_get_clean();
    }
}