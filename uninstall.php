<?php
// Полное удаление WebMaster Telegram: настройки, журнал, служебные метки записей, крон.
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
foreach (['wmtg_settings', 'wmtg_log', 'wmtg_last_sent', 'wmtg_day', 'wmtg_since', 'wmtg_pause_until'] as $o) delete_option($o);
delete_transient('wmtg_lock');
delete_site_transient('wmtg_release');
foreach (['_wmtg_sent', '_wmtg_msg', '_wmtg_fail'] as $k) delete_post_meta_by_key($k);
wp_clear_scheduled_hook('wmtg_tick');
