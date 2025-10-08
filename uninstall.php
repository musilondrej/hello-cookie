<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('ccm_settings');

// Optionally drop logs table if user opted-in
$settings = get_option('ccm_settings', []);
if (!empty($settings['remove_logs_on_uninstall'])) {
    global $wpdb;
    $table = $wpdb->prefix.'ccm_consent_log';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->prefix is safe
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}