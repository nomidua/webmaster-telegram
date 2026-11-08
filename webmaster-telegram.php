<?php
/**
 * Plugin Name: WebMaster Telegram
 * Plugin URI: https://github.com/nomidua/webmaster-telegram
 * Description: Автопостинг записей в Telegram-канал: фото, заголовок, анотация, хештеги, кнопка «Читать». Отбор популярных по просмотрам, расписание по дням недели, тест в личку, ручная отправка.
 * Version: 1.1
 * Author: nomidua
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * Text Domain: webmaster-telegram
 */

if (!defined('ABSPATH')) exit;

define('WMTG_VERSION', '1.1');
define('WMTG_FILE', __FILE__);
define('WMTG_REPO', 'nomidua/webmaster-telegram');
if (!defined('WMTG_API')) define('WMTG_API', 'https://api.telegram.org');

/* ------------------------------------------------------------------ */
/* Настройки                                                           */
/* ------------------------------------------------------------------ */

function wmtg_defaults() {
	$days = [];
	for ($d = 1; $d <= 7; $d++) $days[$d] = ['on' => 1, 'from' => 8, 'to' => 22, 'max' => 10];
	return [
		'enabled'      => 0,
		'token'        => '',
		'channel'      => '',
		'test_chat'    => '',
		'test_name'    => '',
		'default_lang' => strpos((string) get_locale(), 'uk') === 0 ? 'uk' : 'ru',
		'excerpt_len'  => 200,
		'hashtags'     => 2,
		'btn_ru'       => 'Читать',
		'btn_uk'       => 'Читати',
		'silent'       => 1,
		'utm'          => 1,
		'utm_str'      => 'utm_source=telegram&utm_medium=social&utm_campaign=channel',
		'mode'         => 'popular',
		'wait'         => 60,
		'views_key'    => 'post_views_count',
		'excl_cats'    => [],
		'interval'     => 40,
		'days'         => $days,
	];
}

function wmtg_opt($key = null) {
	static $o = null;
	if ($o === null || $key === '__reset') {
		$saved = get_option('wmtg_settings', []);
		$o = array_merge(wmtg_defaults(), is_array($saved) ? $saved : []);
		if ($key === '__reset') return $o;
	}
	return $key === null ? $o : ($o[$key] ?? null);
}

function wmtg_t($ru, $uk) {
	return strpos((string) get_user_locale(), 'uk') === 0 ? $uk : $ru;
}

/* ------------------------------------------------------------------ */
/* Журнал                                                              */
/* ------------------------------------------------------------------ */

function wmtg_log($type, $text) {
	$l = get_option('wmtg_log', []);
	if (!is_array($l)) $l = [];
	array_unshift($l, [time(), $type, $text]);
	update_option('wmtg_log', array_slice($l, 0, 200), false);
}

/* ------------------------------------------------------------------ */
/* Сообщение                                                           */
/* ------------------------------------------------------------------ */

function wmtg_title($post) {
	return html_entity_decode(wp_strip_all_tags(get_the_title($post)), ENT_QUOTES, 'UTF-8');
}

function wmtg_lang($id) {
	$l = get_post_meta($id, 'post_lang', true);
	return in_array($l, ['ru', 'uk'], true) ? $l : wmtg_opt('default_lang');
}

function wmtg_cut($text, $len) {
	$text = trim(preg_replace('/\s+/u', ' ', $text));
	if (mb_strlen($text) <= $len) return $text;
	$cut = mb_substr($text, 0, $len);
	$sp  = mb_strrpos($cut, ' ');
	if ($sp !== false && $sp > $len * 0.6) $cut = mb_substr($cut, 0, $sp);
	return rtrim($cut, " ,.;:—-–") . '…';
}

function wmtg_excerpt($post) {
	$ex = trim(wp_strip_all_tags((string) $post->post_excerpt, true));
	if ($ex !== '') return html_entity_decode($ex, ENT_QUOTES, 'UTF-8');
	$text = wp_strip_all_tags(strip_shortcodes((string) $post->post_content), true);
	return wmtg_cut(html_entity_decode($text, ENT_QUOTES, 'UTF-8'), max(50, (int) wmtg_opt('excerpt_len')));
}

function wmtg_hashtags($id) {
	$n = max(0, (int) wmtg_opt('hashtags'));
	if (!$n) return [];
	$tags = get_the_terms($id, 'post_tag');
	if (!is_array($tags)) return [];
	$out = [];
	foreach ($tags as $t) {
		$words = preg_split('/[^\p{L}\p{N}]+/u', html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
		if (!$words) continue;
		$h = '';
		foreach ($words as $w) $h .= mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
		if (preg_match('/^\p{N}+$/u', $h)) continue;
		$out[] = '#' . $h;
		if (count($out) >= $n) break;
	}
	return $out;
}

function wmtg_url($id) {
	$url = get_permalink($id);
	$utm = trim((string) wmtg_opt('utm_str'));
	if (wmtg_opt('utm') && $utm !== '') $url .= (strpos($url, '?') === false ? '?' : '&') . ltrim($utm, '?&');
	return $url;
}

/* Данные сообщения: caption (HTML), кнопка, фото */
function wmtg_message($post) {
	$e     = function ($s) { return htmlspecialchars($s, ENT_NOQUOTES, 'UTF-8'); };
	$title = wmtg_title($post);
	$ex    = wmtg_excerpt($post);
	$tags  = wmtg_hashtags($post->ID);
	$tail  = $tags ? "\n\n" . implode(' ', $tags) : '';
	$head  = '<b>' . $e($title) . '</b>';
	$room  = 1000 - mb_strlen($title) - mb_strlen($tail) - 10;
	if (mb_strlen($ex) > $room) $ex = wmtg_cut($ex, max(0, $room));
	$caption = $head . ($ex !== '' ? "\n\n" . $e($ex) : '') . $e($tail);
	$lang    = wmtg_lang($post->ID);
	$btn     = $lang === 'uk' ? wmtg_opt('btn_uk') : wmtg_opt('btn_ru');
	return [
		'caption' => $caption,
		'title'   => $title,
		'excerpt' => $ex,
		'tags'    => $tags,
		'button'  => $btn !== '' ? $btn : 'Читать',
		'url'     => wmtg_url($post->ID),
		'thumb'   => (int) get_post_thumbnail_id($post->ID),
	];
}

/* ------------------------------------------------------------------ */
/* Telegram API                                                        */
/* ------------------------------------------------------------------ */

function wmtg_api($method, $fields = [], $file = null, $token = null) {
	$token = $token ?? trim((string) wmtg_opt('token'));
	if ($token === '') return ['ok' => false, 'description' => 'no token'];
	$url  = WMTG_API . '/bot' . $token . '/' . $method;
	$args = ['timeout' => 25];
	if ($file) {
		$b    = 'wmtg' . wp_generate_password(16, false);
		$body = '';
		foreach ($fields as $k => $v) $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n" . (is_array($v) ? wp_json_encode($v) : $v) . "\r\n";
		$body .= "--$b\r\nContent-Disposition: form-data; name=\"{$file['field']}\"; filename=\"{$file['name']}\"\r\nContent-Type: {$file['type']}\r\n\r\n" . $file['data'] . "\r\n--$b--\r\n";
		$args['headers'] = ['Content-Type' => 'multipart/form-data; boundary=' . $b];
		$args['body']    = $body;
	} else {
		$args['headers'] = ['Content-Type' => 'application/json'];
		$args['body']    = wp_json_encode($fields);
	}
	$res = wp_remote_post($url, $args);
	if (is_wp_error($res)) return ['ok' => false, 'description' => $res->get_error_message(), 'net' => true];
	$j = json_decode(wp_remote_retrieve_body($res), true);
	if (!is_array($j)) return ['ok' => false, 'description' => 'HTTP ' . wp_remote_retrieve_response_code($res), 'net' => true];
	return $j;
}

/* Фото: копия до 1280px в JPEG (Telegram надёжно принимает JPEG) */
function wmtg_photo($thumb_id) {
	if (!$thumb_id) return null;
	$path = get_attached_file($thumb_id);
	if (!$path || !is_file($path)) return null;
	$ed = wp_get_image_editor($path);
	if (is_wp_error($ed)) return null;
	$ed->resize(1280, 1280, false);
	$ed->set_quality(85);
	$tmp = trailingslashit(get_temp_dir()) . 'wmtg-' . wp_generate_password(10, false) . '.jpg';
	$sv  = $ed->save($tmp, 'image/jpeg');
	if (is_wp_error($sv) || empty($sv['path']) || !is_file($sv['path'])) return null;
	$data = file_get_contents($sv['path']);
	@unlink($sv['path']);
	return $data ? ['field' => 'photo', 'name' => 'photo.jpg', 'type' => 'image/jpeg', 'data' => $data] : null;
}

/* Отправка записи в чат (канал или тестовый). Возвращает [ok, message_id|error] */
function wmtg_send_post($post, $chat) {
	$m      = wmtg_message($post);
	$markup = ['inline_keyboard' => [[['text' => $m['button'], 'url' => $m['url']]]]];
	$silent = wmtg_opt('silent') ? true : false;
	$photo  = wmtg_photo($m['thumb']);
	if ($photo) {
		$r = wmtg_api('sendPhoto', ['chat_id' => $chat, 'caption' => $m['caption'], 'parse_mode' => 'HTML', 'disable_notification' => $silent ? 'true' : 'false', 'reply_markup' => $markup], $photo);
	} else {
		$r = wmtg_api('sendMessage', ['chat_id' => $chat, 'text' => $m['caption'], 'parse_mode' => 'HTML', 'disable_notification' => $silent, 'link_preview_options' => ['is_disabled' => true], 'reply_markup' => $markup]);
	}
	if (!empty($r['ok'])) return [true, (int) ($r['result']['message_id'] ?? 0)];
	// Общая ошибка (сеть, токен, права, канал, лимиты) — не вина записи: ставим паузу, запись не помечаем
	$code   = (int) ($r['error_code'] ?? 0);
	$desc   = (string) ($r['description'] ?? 'error');
	$global = !empty($r['net']) || $code !== 400 || preg_match('/chat not found|not enough rights|bot was kicked|need administrator/i', $desc);
	return [false, $desc, (bool) $global];
}

/* Отправка в канал с отметкой и журналом */
function wmtg_publish($post, $how) {
	$chat = trim((string) wmtg_opt('channel'));
	if ($chat === '') return [false, 'no channel'];
	$r = wmtg_send_post($post, $chat);
	if ($r[0]) {
		update_post_meta($post->ID, '_wmtg_sent', time());
		update_post_meta($post->ID, '_wmtg_msg', $r[1]);
		delete_post_meta($post->ID, '_wmtg_fail');
		update_option('wmtg_last_sent', time(), false);
		delete_option('wmtg_alert');
		wmtg_log('ok', $how . ': «' . wmtg_title($post) . '» #' . $post->ID);
	} else {
		if (empty($r[2])) update_post_meta($post->ID, '_wmtg_fail', (int) get_post_meta($post->ID, '_wmtg_fail', true) + 1);
		else update_option('wmtg_alert', [time(), $r[1]], false);
		wmtg_log('err', $how . ': «' . wmtg_title($post) . '» #' . $post->ID . ' — ' . $r[1]);
	}
	return $r;
}

/* ------------------------------------------------------------------ */
/* Отбор и расписание                                                  */
/* ------------------------------------------------------------------ */

function wmtg_views($id) {
	if (function_exists('pvc_get_post_views')) return (int) pvc_get_post_views($id);
	return (int) get_post_meta($id, (string) wmtg_opt('views_key'), true);
}

/* Кандидаты: опубликованы вчера/сегодня (время сайта), не отправлены, после установки плагина */
function wmtg_candidates() {
	$tz    = wp_timezone();
	$start = (new DateTimeImmutable('yesterday', $tz))->getTimestamp();
	$since = max($start, (int) get_option('wmtg_since', time()));
	$posts = get_posts([
		'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'date', 'order' => 'ASC',
		'no_found_rows' => true, 'suppress_filters' => true,
		'date_query' => [['after' => gmdate('Y-m-d H:i:s', $since), 'column' => 'post_date_gmt', 'inclusive' => true]],
		'category__not_in' => array_map('intval', (array) wmtg_opt('excl_cats')),
		'meta_query' => [['key' => '_wmtg_sent', 'compare' => 'NOT EXISTS']],
	]);
	$out = [];
	$now = time();
	foreach ($posts as $p) {
		if ((int) get_post_meta($p->ID, '_wmtg_fail', true) >= 3) continue;
		$pub = strtotime($p->post_date_gmt . ' UTC');
		$age = max(1, ($now - $pub) / 3600);
		$v   = wmtg_views($p->ID);
		$out[] = ['post' => $p, 'pub' => $pub, 'views' => $v, 'vph' => round($v / $age, 1), 'ready' => ($now - $pub) >= 60 * (int) wmtg_opt('wait')];
	}
	if (wmtg_opt('mode') === 'popular') {
		usort($out, function ($a, $b) { return $b['ready'] <=> $a['ready'] ?: $b['vph'] <=> $a['vph'] ?: $a['pub'] <=> $b['pub']; });
	} else {
		$out = array_values(array_filter($out, function ($c) use ($now) { return $now - $c['pub'] <= DAY_IN_SECONDS; }));
	}
	return $out;
}

function wmtg_today_count() {
	$d = get_option('wmtg_day', []);
	return (is_array($d) && ($d['date'] ?? '') === wp_date('Y-m-d')) ? (int) $d['n'] : 0;
}

/* Можно ли отправлять сейчас: [bool, причина] */
function wmtg_slot_status() {
	$o   = wmtg_opt();
	$now = time();
	$day = (int) wp_date('N', $now);
	$cfg = $o['days'][$day] ?? null;
	$h   = (int) wp_date('G', $now);
	if (!$cfg || empty($cfg['on'])) return [false, wmtg_t('сегодня выходной', 'сьогодні вихідний')];
	if ($h < (int) $cfg['from'] || $h >= (int) $cfg['to']) return [false, sprintf(wmtg_t('вне часов %d:00–%d:00', 'поза годинами %d:00–%d:00'), $cfg['from'], $cfg['to'])];
	if (wmtg_today_count() >= (int) $cfg['max']) return [false, wmtg_t('дневной лимит исчерпан', 'денний ліміт вичерпано')];
	$next = (int) get_option('wmtg_last_sent', 0) + 60 * (int) $o['interval'];
	if ($now < $next) return [false, sprintf(wmtg_t('пауза до %s', 'пауза до %s'), wp_date('H:i', $next))];
	return [true, ''];
}

function wmtg_tick() {
	if (!wmtg_opt('enabled') || trim((string) wmtg_opt('token')) === '' || trim((string) wmtg_opt('channel')) === '') return;
	if (get_transient('wmtg_lock')) return;
	if ((int) get_option('wmtg_pause_until', 0) > time()) return;
	[$can] = wmtg_slot_status();
	if (!$can) return;
	set_transient('wmtg_lock', 1, 120);
	$pick = null;
	foreach (wmtg_candidates() as $c) {
		if ($c['ready']) { $pick = $c['post']; break; }
	}
	if ($pick) {
		$r = wmtg_publish($pick, wmtg_t('Авто', 'Авто'));
		if ($r[0]) {
			update_option('wmtg_day', ['date' => wp_date('Y-m-d'), 'n' => wmtg_today_count() + 1], false);
			delete_option('wmtg_pause_until');
		} elseif (!empty($r[2])) {
			update_option('wmtg_pause_until', time() + 15 * MINUTE_IN_SECONDS, false);
		}
	}
	delete_transient('wmtg_lock');
}

add_filter('cron_schedules', function ($s) {
	$s['wmtg5'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'WebMaster Telegram 5 min'];
	return $s;
});
add_action('wmtg_tick', 'wmtg_tick');
add_action('init', function () {
	if (!wp_next_scheduled('wmtg_tick')) wp_schedule_event(time() + 60, 'wmtg5', 'wmtg_tick');
});

register_activation_hook(__FILE__, function () {
	if (!get_option('wmtg_since')) update_option('wmtg_since', time(), false);
});
register_deactivation_hook(__FILE__, function () {
	wp_clear_scheduled_hook('wmtg_tick');
});

/* ------------------------------------------------------------------ */
/* Список записей: колонка, ссылка в строке, массовое действие         */
/* ------------------------------------------------------------------ */

/* Ссылка на пост в канале */
function wmtg_msg_link($id) {
	$m  = (int) get_post_meta($id, '_wmtg_msg', true);
	$ch = trim((string) wmtg_opt('channel'));
	if (!$m || $ch === '') return '';
	if ($ch[0] === '@') return 'https://t.me/' . substr($ch, 1) . '/' . $m;
	if (strpos($ch, '-100') === 0) return 'https://t.me/c/' . substr($ch, 4) . '/' . $m;
	return '';
}

function wmtg_icon($size = 14) {
	return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" aria-hidden="true" style="vertical-align:middle"><path fill="currentColor" d="M21.9 4.3 18.7 19.4c-.2 1.1-.9 1.3-1.8.8l-4.9-3.6-2.4 2.3c-.3.3-.5.5-1 .5l.4-5 9.1-8.2c.4-.4-.1-.6-.6-.2L6.3 13 1.5 11.5c-1-.3-1-1 .2-1.5l18.8-7.3c.9-.3 1.6.2 1.4 1.6z"/></svg>';
}

add_filter('manage_post_posts_columns', function ($cols) {
	$new = [];
	foreach ($cols as $k => $v) {
		$new[$k] = $v;
		if ($k === 'title') $new['wmtg'] = '<span title="Telegram">' . wmtg_icon(14) . '</span>';
	}
	return $new;
});

add_action('manage_post_posts_custom_column', function ($col, $id) {
	if ($col !== 'wmtg') return;
	$sent = (int) get_post_meta($id, '_wmtg_sent', true);
	if ($sent) {
		$t    = esc_attr(wmtg_t('Отправлено ', 'Відправлено ') . wp_date('d.m.Y H:i', $sent));
		$link = wmtg_msg_link($id);
		echo $link ? '<a href="' . esc_url($link) . '" target="_blank" rel="noopener" style="color:#00a32a;text-decoration:none" title="' . $t . wmtg_t(' — открыть в канале', ' — відкрити в каналі') . '">✓</a>' : '<span style="color:#00a32a" title="' . $t . '">✓</span>';
	} elseif ((int) get_post_meta($id, '_wmtg_fail', true) >= 3) {
		echo '<span style="color:#d63638" title="' . esc_attr(wmtg_t('Ошибка отправки (см. журнал)', 'Помилка відправки (див. журнал)')) . '">!</span>';
	}
}, 10, 2);

add_action('admin_head-edit.php', function () {
	if ((get_current_screen()->post_type ?? '') !== 'post') return;
	echo '<style>.wp-list-table .column-wmtg{width:26px;text-align:center;padding-left:0;padding-right:0}.wp-list-table th.column-wmtg{color:#2271b1}</style>';
	echo '<script>document.addEventListener("click",function(e){var a=e.target.closest("a.wmtg-send");if(a&&!confirm(a.dataset.sent?' . wp_json_encode(wmtg_t('Уже отправлено ', 'Вже відправлено ')) . '+a.dataset.sent+' . wp_json_encode(wmtg_t('. Отправить повторно?', '. Відправити повторно?')) . ':' . wp_json_encode(wmtg_t('Отправить запись в Telegram-канал сейчас?', 'Відправити запис у Telegram-канал зараз?')) . '))e.preventDefault();});document.addEventListener("submit",function(e){var f=e.target;if(f.id!=="posts-filter")return;var s=f.querySelector("select[name=action]"),s2=f.querySelector("select[name=action2]");if((s&&s.value==="wmtg_send")||(s2&&s2.value==="wmtg_send")){if(!confirm(' . wp_json_encode(wmtg_t('Отправить выбранные записи в Telegram сейчас?', 'Відправити вибрані записи в Telegram зараз?')) . '))e.preventDefault();}});</script>';
});

add_filter('post_row_actions', function ($actions, $post) {
	if ($post->post_type !== 'post' || $post->post_status !== 'publish' || !current_user_can('publish_posts') || !wmtg_opt('token') || !wmtg_opt('channel')) return $actions;
	$sent = (int) get_post_meta($post->ID, '_wmtg_sent', true);
	$url  = wp_nonce_url(admin_url('admin-post.php?action=wmtg_send&post=' . $post->ID . ($sent ? '&again=1' : '')), 'wmtg_send_' . $post->ID);
	$actions['wmtg'] = '<a class="wmtg-send" href="' . esc_url($url) . '"' . ($sent ? ' data-sent="' . esc_attr(wp_date('d.m.Y H:i', $sent)) . '"' : '') . '>' . esc_html($sent ? wmtg_t('В Telegram ещё раз', 'В Telegram ще раз') : wmtg_t('В Telegram', 'В Telegram')) . '</a>';
	return $actions;
}, 10, 2);

add_action('admin_post_wmtg_send', function () {
	$id = (int) ($_GET['post'] ?? 0);
	if (!$id || !current_user_can('publish_posts') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wmtg_send_' . $id)) wp_die('403');
	$p = get_post($id);
	if (!$p || $p->post_status !== 'publish') $r = [false, 'not published'];
	elseif (get_post_meta($id, '_wmtg_sent', true) && empty($_GET['again'])) $r = [false, wmtg_t('уже отправлено', 'вже відправлено')];
	else $r = wmtg_publish($p, wmtg_t('Вручную', 'Вручну'));
	wp_safe_redirect(add_query_arg(['wmtg_done' => $r[0] ? 1 : 0, 'wmtg_err' => $r[0] ? false : rawurlencode($r[1])], wp_get_referer() ?: admin_url('edit.php')));
	exit;
});

add_filter('bulk_actions-edit-post', function ($a) {
	if (current_user_can('publish_posts') && wmtg_opt('token') && wmtg_opt('channel')) $a['wmtg_send'] = wmtg_t('Отправить в Telegram', 'Відправити в Telegram');
	return $a;
});

add_filter('handle_bulk_actions-edit-post', function ($to, $action, $ids) {
	if ($action !== 'wmtg_send' || !current_user_can('publish_posts')) return $to;
	$ok = 0; $bad = 0; $skip = 0;
	foreach (array_slice((array) $ids, 0, 10) as $id) {
		$p = get_post($id);
		if (!$p || $p->post_status !== 'publish') { $bad++; continue; }
		if (get_post_meta($id, '_wmtg_sent', true)) { $skip++; continue; }
		$r = wmtg_publish($p, wmtg_t('Вручную', 'Вручну'));
		$r[0] ? $ok++ : $bad++;
		if (count($ids) > 1) sleep(1);
	}
	return add_query_arg(['wmtg_bulk_ok' => $ok, 'wmtg_bulk_bad' => $bad, 'wmtg_bulk_skip' => $skip], $to);
}, 10, 3);

add_action('admin_notices', function () {
	if (isset($_GET['wmtg_done'])) {
		if ($_GET['wmtg_done']) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(wmtg_t('Запись отправлена в Telegram.', 'Запис відправлено в Telegram.')) . '</p></div>';
		else echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(wmtg_t('Не отправлено: ', 'Не відправлено: ') . sanitize_text_field(wp_unslash(rawurldecode($_GET['wmtg_err'] ?? '')))) . '</p></div>';
	}
	if (isset($_GET['wmtg_bulk_ok'])) {
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html(sprintf(wmtg_t('Telegram: отправлено %d, не отправлено %d, пропущено уже отправленных %d (подробности в журнале плагина).', 'Telegram: відправлено %d, не відправлено %d, пропущено вже відправлених %d (подробиці в журналі плагіна).'), (int) $_GET['wmtg_bulk_ok'], (int) $_GET['wmtg_bulk_bad'], (int) ($_GET['wmtg_bulk_skip'] ?? 0))) . '</p></div>';
	}
	// F: предупреждения, если автопостинг стоит
	if (!current_user_can('manage_options') || !wmtg_opt('enabled')) return;
	$go = ' <a href="' . esc_url(admin_url('options-general.php?page=webmaster-telegram')) . '">' . esc_html(wmtg_t('Настройки →', 'Налаштування →')) . '</a>';
	$al = get_option('wmtg_alert');
	if (is_array($al)) echo '<div class="notice notice-warning"><p><b>WebMaster Telegram:</b> ' . esc_html(sprintf(wmtg_t('автопостинг не может отправить пост (%s): %s. Проверьте бота и канал.', 'автопостинг не може відправити пост (%s): %s. Перевірте бота і канал.'), wp_date('d.m H:i', $al[0]), $al[1])) . $go . '</p></div>';
	$next = wp_next_scheduled('wmtg_tick');
	if ($next && $next < time() - 30 * MINUTE_IN_SECONDS) echo '<div class="notice notice-warning"><p><b>WebMaster Telegram:</b> ' . esc_html(sprintf(wmtg_t('крон не запускался с %s — автопостинг стоит. Проверьте WP-Cron или серверный крон.', 'крон не запускався з %s — автопостинг стоїть. Перевірте WP-Cron або серверний крон.'), wp_date('d.m H:i', $next))) . $go . '</p></div>';
});

add_filter('removable_query_args', function ($a) {
	return array_merge($a, ['wmtg_done', 'wmtg_err', 'wmtg_bulk_ok', 'wmtg_bulk_bad', 'wmtg_bulk_skip']);
});

/* ------------------------------------------------------------------ */
/* Страница настроек                                                   */
/* ------------------------------------------------------------------ */

add_action('admin_menu', function () {
	add_options_page('WebMaster Telegram', 'WebMaster Telegram', 'manage_options', 'webmaster-telegram', 'wmtg_settings_page');
});

add_filter('plugin_action_links_' . plugin_basename(WMTG_FILE), function ($l) {
	array_unshift($l, '<a href="' . esc_url(admin_url('options-general.php?page=webmaster-telegram')) . '">' . esc_html(wmtg_t('Настройки', 'Налаштування')) . '</a>');
	return $l;
});

function wmtg_settings_save($in) {
	$o   = wmtg_opt();
	$tok = trim(sanitize_text_field($in['token'] ?? ''));
	$days = [];
	for ($d = 1; $d <= 7; $d++) {
		$r = $in['days'][$d] ?? [];
		$from = max(0, min(23, (int) ($r['from'] ?? 8)));
		$to   = max($from + 1, min(24, (int) ($r['to'] ?? 22)));
		$days[$d] = ['on' => empty($r['on']) ? 0 : 1, 'from' => $from, 'to' => $to, 'max' => max(0, min(100, (int) ($r['max'] ?? 10)))];
	}
	$ch = trim(sanitize_text_field($in['channel'] ?? ''));
	if ($ch !== '' && $ch[0] !== '@' && !preg_match('/^-?\d+$/', $ch)) $ch = '@' . preg_replace('~^(https?://)?t\.me/~', '', $ch);
	$new = [
		'enabled'      => empty($in['enabled']) ? 0 : 1,
		'token'        => $tok !== '' && strpos($tok, '•') === false ? $tok : $o['token'],
		'channel'      => $ch,
		'test_chat'    => $o['test_chat'],
		'test_name'    => $o['test_name'],
		'default_lang' => ($in['default_lang'] ?? '') === 'uk' ? 'uk' : 'ru',
		'excerpt_len'  => max(50, min(800, (int) ($in['excerpt_len'] ?? 200))),
		'hashtags'     => max(0, min(5, (int) ($in['hashtags'] ?? 2))),
		'btn_ru'       => sanitize_text_field($in['btn_ru'] ?? 'Читать'),
		'btn_uk'       => sanitize_text_field($in['btn_uk'] ?? 'Читати'),
		'silent'       => empty($in['silent']) ? 0 : 1,
		'utm'          => empty($in['utm']) ? 0 : 1,
		'utm_str'      => preg_replace('/[^A-Za-z0-9_\-=&.%]/', '', (string) ($in['utm_str'] ?? '')),
		'mode'         => ($in['mode'] ?? '') === 'all' ? 'all' : 'popular',
		'wait'         => max(0, min(720, (int) ($in['wait'] ?? 60))),
		'views_key'    => sanitize_key($in['views_key'] ?? 'post_views_count') ?: 'post_views_count',
		'excl_cats'    => array_map('intval', (array) ($in['excl_cats'] ?? [])),
		'interval'     => max(5, min(1440, (int) ($in['interval'] ?? 40))),
		'days'         => $days,
	];
	update_option('wmtg_settings', $new, false);
	wmtg_opt('__reset');
}

function wmtg_settings_page() {
	if (!current_user_can('manage_options')) return;
	$msg = []; // [type, text]
	$in  = wp_unslash($_POST);
	if (!empty($in['wmtg_do']) && check_admin_referer('wmtg_settings')) {
		$do = $in['wmtg_do'];
		if ($do === 'save') {
			wmtg_settings_save($in);
			$msg = ['success', wmtg_t('Сохранено.', 'Збережено.')];
		} elseif ($do === 'check') {
			wmtg_settings_save($in);
			$me = wmtg_api('getMe');
			if (empty($me['ok'])) {
				$msg = ['error', wmtg_t('Бот не отвечает: ', 'Бот не відповідає: ') . ($me['description'] ?? '')];
			} else {
				$bot = $me['result'];
				$cm  = wmtg_api('getChatMember', ['chat_id' => wmtg_opt('channel'), 'user_id' => $bot['id']]);
				if (empty($cm['ok'])) $msg = ['error', '@' . $bot['username'] . ' — ' . wmtg_t('канал не найден или бот не добавлен: ', 'канал не знайдено або бота не додано: ') . ($cm['description'] ?? '')];
				elseif (($cm['result']['status'] ?? '') !== 'administrator') $msg = ['error', '@' . $bot['username'] . ' — ' . wmtg_t('бот не администратор канала.', 'бот не адміністратор каналу.')];
				elseif (empty($cm['result']['can_post_messages'])) $msg = ['error', '@' . $bot['username'] . ' — ' . wmtg_t('нет права «Публиковать сообщения».', 'немає права «Публікувати повідомлення».')];
				else { delete_option('wmtg_alert'); $msg = ['success', '@' . $bot['username'] . ' — ' . wmtg_t('подключение в порядке: бот администратор канала и может публиковать.', 'підключення в порядку: бот адміністратор каналу і може публікувати.')]; }
			}
		} elseif ($do === 'find_me') {
			$u = wmtg_api('getUpdates', ['limit' => 50, 'allowed_updates' => ['message']]);
			$found = null;
			foreach (array_reverse((array) ($u['result'] ?? [])) as $up) {
				$c = $up['message']['chat'] ?? null;
				if ($c && ($c['type'] ?? '') === 'private') { $found = $c; break; }
			}
			if ($found) {
				$o = wmtg_opt();
				$o['test_chat'] = (string) $found['id'];
				$o['test_name'] = trim(($found['first_name'] ?? '') . ' ' . ($found['last_name'] ?? '')) . (isset($found['username']) ? ' @' . $found['username'] : '');
				update_option('wmtg_settings', $o, false);
				wmtg_opt('__reset');
				$msg = ['success', wmtg_t('Найден чат: ', 'Знайдено чат: ') . $o['test_name']];
			} else {
				$msg = ['error', wmtg_t('Не найдено. Откройте бота в Telegram, нажмите Start (или напишите ему любое сообщение) и повторите.', 'Не знайдено. Відкрийте бота в Telegram, натисніть Start (або напишіть йому будь-що) і повторіть.') . (!empty($u['description']) ? ' (' . $u['description'] . ')' : '')];
			}
		} elseif ($do === 'test') {
			$p = get_post((int) ($in['test_post'] ?? 0));
			if (!$p || $p->post_status !== 'publish' || !wmtg_opt('test_chat')) {
				$msg = ['error', wmtg_t('Нужен опубликованный ID записи и найденный тестовый чат.', 'Потрібен опублікований ID запису і знайдений тестовий чат.')];
			} else {
				$r = wmtg_send_post($p, wmtg_opt('test_chat'));
				$msg = $r[0] ? ['success', wmtg_t('Тест отправлен вам в личку.', 'Тест надіслано вам в особисті.')] : ['error', wmtg_t('Ошибка: ', 'Помилка: ') . $r[1]];
				wmtg_log($r[0] ? 'info' : 'err', 'Тест: «' . wmtg_title($p) . '» #' . $p->ID . ($r[0] ? '' : ' — ' . $r[1]));
			}
		} elseif (preg_match('/^send:(\d+)$/', $do, $mm)) {
			$p = get_post((int) $mm[1]);
			if (!$p || $p->post_status !== 'publish' || get_post_meta($p->ID, '_wmtg_sent', true)) $msg = ['error', wmtg_t('Запись не опубликована или уже отправлена.', 'Запис не опублікований або вже відправлений.')];
			else { $r = wmtg_publish($p, wmtg_t('Вручную', 'Вручну')); $msg = $r[0] ? ['success', wmtg_t('Отправлено в канал: ', 'Відправлено в канал: ') . wmtg_title($p)] : ['error', wmtg_t('Ошибка: ', 'Помилка: ') . $r[1]]; }
		} elseif ($do === 'import') {
			$f = $_FILES['wmtg_import']['tmp_name'] ?? '';
			$j = ($f && is_uploaded_file($f)) ? json_decode((string) file_get_contents($f), true) : null;
			if (!is_array($j) || ($j['plugin'] ?? '') !== 'webmaster-telegram' || !is_array($j['settings'] ?? null)) {
				$msg = ['error', wmtg_t('Это не файл настроек WebMaster Telegram.', 'Це не файл налаштувань WebMaster Telegram.')];
			} else {
				$o = wmtg_opt();
				$in = array_merge($o, array_diff_key($j['settings'], array_flip(['token', 'channel', 'test_chat', 'test_name', 'enabled'])));
				$in['token'] = '';
				$in['excl_cats'] = []; $miss = [];
				foreach ((array) ($j['settings']['excl_cats'] ?? []) as $slug) { $c = get_category_by_slug($slug); $c ? $in['excl_cats'][] = $c->term_id : $miss[] = $slug; }
				wmtg_settings_save($in);
				$msg = ['success', wmtg_t('Настройки импортированы. Токен, канал и галочка «Автопостинг» не изменены.', 'Налаштування імпортовано. Токен, канал і галочку «Автопостинг» не змінено.') . ($miss ? ' ' . wmtg_t('Не найдены рубрики: ', 'Не знайдено рубрик: ') . implode(', ', $miss) : '')];
			}
		} elseif ($do === 'clear_log') {
			update_option('wmtg_log', [], false);
			$msg = ['success', wmtg_t('Журнал очищен.', 'Журнал очищено.')];
		}
	}

	$o    = wmtg_opt();
	$tok  = (string) $o['token'];
	$mask = $tok !== '' ? substr($tok, 0, 6) . str_repeat('•', 10) . substr($tok, -4) : '';
	$last = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'no_found_rows' => true]);
	$lp   = $last ? $last[0] : null;
	$test_id = (int) ($in['test_post'] ?? ($lp ? $lp->ID : 0));
	$tp   = get_post($test_id) ?: $lp;
	$pv   = null;
	if ($tp) {
		$tags = get_the_terms($tp->ID, 'post_tag');
		$pv   = [
			'title'   => wmtg_title($tp),
			'excerpt' => html_entity_decode(trim(wp_strip_all_tags((string) $tp->post_excerpt, true)), ENT_QUOTES, 'UTF-8'),
			'text'    => html_entity_decode(wp_strip_all_tags(strip_shortcodes((string) $tp->post_content), true), ENT_QUOTES, 'UTF-8'),
			'tags'    => is_array($tags) ? array_values(wp_list_pluck($tags, 'name')) : [],
			'lang'    => wmtg_lang($tp->ID),
			'img'     => get_the_post_thumbnail_url($tp, 'medium_large') ?: '',
			'url'     => get_permalink($tp),
		];
	}

	$days_n = [1 => wmtg_t('Пн', 'Пн'), 2 => wmtg_t('Вт', 'Вт'), 3 => wmtg_t('Ср', 'Ср'), 4 => wmtg_t('Чт', 'Чт'), 5 => wmtg_t('Пт', 'Пт'), 6 => wmtg_t('Сб', 'Сб'), 7 => wmtg_t('Вс', 'Нд')];
	$row = function ($label, $field, $desc = '') {
		echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $field . ($desc ? '<p class="description">' . $desc . '</p>' : '') . '</td></tr>';
	};
	$btn = function ($do, $text, $cls = 'button') {
		return '<button type="submit" name="wmtg_do" value="' . esc_attr($do) . '" class="' . esc_attr($cls) . '">' . esc_html($text) . '</button>';
	};
	?>
	<div class="wrap wmtg-wrap">
	<h1>WebMaster Telegram <span style="font-size:13px;color:#646970">v<?php echo esc_html(WMTG_VERSION); ?></span></h1>
	<?php if ($msg) echo '<div class="notice notice-' . esc_attr($msg[0]) . ' is-dismissible"><p>' . esc_html($msg[1]) . '</p></div>'; ?>
	<style>
	.wmtg-wrap .form-table th{width:220px}
	.wmtg-cols{display:flex;gap:28px;align-items:flex-start;flex-wrap:wrap}
	.wmtg-cols>.wmtg-main{flex:1 1 560px;min-width:0}
	.wmtg-cols>.wmtg-side{flex:0 0 360px;position:sticky;top:46px}
	.wmtg-tg{background:#8eb1cf url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='40' height='40'%3E%3Ccircle cx='20' cy='20' r='1.5' fill='%23ffffff' fill-opacity='.25'/%3E%3C/svg%3E");padding:16px;border-radius:8px}
	.wmtg-bubble{background:#fff;border-radius:12px 12px 12px 4px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.15);font:14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#000}
	.wmtg-bubble img{display:block;width:100%;max-height:260px;object-fit:cover}
	.wmtg-bubble .t{padding:8px 12px 6px;white-space:pre-wrap;word-wrap:break-word}
	.wmtg-bubble .t a{color:#2a7ab0;text-decoration:none}
	.wmtg-bubble .m{padding:0 12px 6px;text-align:right;font-size:12px;color:#8a9aa9}
	.wmtg-kbd{margin-top:4px;background:rgba(0,0,0,.18);color:#fff;text-align:center;border-radius:8px;padding:9px;font:600 14px -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
	.wmtg-days td,.wmtg-days th{padding:6px 10px;text-align:center}
	.wmtg-days input[type=number]{width:64px}
	.wmtg-log{max-height:320px;overflow:auto;background:#fff;border:1px solid #dcdcde;padding:6px 10px;font:12px/1.6 Consolas,monospace}
	.wmtg-log .err{color:#d63638}.wmtg-log .ok{color:#1d2327}.wmtg-log .info{color:#646970}
	.wmtg-cand td,.wmtg-cand th{padding:6px 8px}
	@media(max-width:1100px){.wmtg-cols>.wmtg-side{position:static;flex-basis:100%}}
	</style>
	<form method="post" enctype="multipart/form-data">
	<?php wp_nonce_field('wmtg_settings'); ?>
	<div class="wmtg-cols"><div class="wmtg-main">

	<h2><?php echo esc_html(wmtg_t('Подключение', 'Підключення')); ?></h2>
	<table class="form-table" role="presentation">
	<?php
	$row(wmtg_t('Автопостинг', 'Автопостинг'), '<label><input type="checkbox" name="enabled" value="1"' . checked($o['enabled'], 1, false) . '> ' . esc_html(wmtg_t('Включён (по расписанию ниже)', 'Увімкнено (за розкладом нижче)')) . '</label>', esc_html(wmtg_t('Ручная отправка и тест работают и без этой галочки.', 'Ручна відправка і тест працюють і без цієї галочки.')));
	$row(wmtg_t('Токен бота', 'Токен бота'), '<input type="text" class="regular-text code" name="token" value="' . esc_attr($mask) . '" autocomplete="off" placeholder="1234567890:AA…">', esc_html(wmtg_t('Из @BotFather. Хранится в базе сайта, на странице показан частично. Чтобы заменить — вставьте новый.', 'З @BotFather. Зберігається в базі сайту, на сторінці показаний частково. Щоб замінити — вставте новий.')));
	$row(wmtg_t('Канал', 'Канал'), '<input type="text" class="regular-text" name="channel" value="' . esc_attr($o['channel']) . '" placeholder="@inforakurscom"> ' . $btn('check', wmtg_t('Проверить подключение', 'Перевірити підключення')), esc_html(wmtg_t('@username публичного канала или числовой ID. Бот должен быть администратором с правом «Публиковать сообщения».', '@username публічного каналу або числовий ID. Бот має бути адміністратором з правом «Публікувати повідомлення».')));
	?>
	</table>

	<h2><?php echo esc_html(wmtg_t('Сообщение', 'Повідомлення')); ?></h2>
	<table class="form-table" role="presentation">
	<?php
	$row(wmtg_t('Текст', 'Текст'), '<input type="number" min="50" max="800" class="small-text" name="excerpt_len" value="' . (int) $o['excerpt_len'] . '"> ' . esc_html(wmtg_t('знаков, если нет анотации', 'знаків, якщо немає анотації')), esc_html(wmtg_t('Порядок: жирный заголовок → пустая строка → анотация (отрывок) или начало текста.', 'Порядок: жирний заголовок → порожній рядок → анотація (уривок) або початок тексту.')));
	$row(wmtg_t('Хештеги', 'Хештеги'), '<input type="number" min="0" max="5" class="small-text" name="hashtags" value="' . (int) $o['hashtags'] . '"> ' . esc_html(wmtg_t('из меток (0 — без хештегов)', 'з міток (0 — без хештегів)')), esc_html(wmtg_t('Метка из нескольких слов склеивается: «Белый дом» → #БелыйДом.', 'Мітка з кількох слів склеюється: «Білий дім» → #БілийДім.')));
	$row(wmtg_t('Кнопка', 'Кнопка'), 'ru <input type="text" class="regular-text" style="width:140px" name="btn_ru" value="' . esc_attr($o['btn_ru']) . '"> &nbsp; uk <input type="text" class="regular-text" style="width:140px" name="btn_uk" value="' . esc_attr($o['btn_uk']) . '"> &nbsp; ' . esc_html(wmtg_t('по умолчанию', 'за замовчуванням')) . ' <select name="default_lang"><option value="ru"' . selected($o['default_lang'], 'ru', false) . '>ru</option><option value="uk"' . selected($o['default_lang'], 'uk', false) . '>uk</option></select>', esc_html(wmtg_t('Язык — по полю post_lang записи, иначе «по умолчанию».', 'Мова — за полем post_lang запису, інакше «за замовчуванням».')));
	$row(wmtg_t('Без звука', 'Без звуку'), '<label><input type="checkbox" name="silent" value="1"' . checked($o['silent'], 1, false) . '> ' . esc_html(wmtg_t('Подписчики получают уведомление без звука', 'Підписники отримують сповіщення без звуку')) . '</label>');
	$row('UTM', '<label><input type="checkbox" name="utm" value="1"' . checked($o['utm'], 1, false) . '> ' . esc_html(wmtg_t('Добавлять к ссылке', 'Додавати до посилання')) . '</label><br><input type="text" class="large-text code" name="utm_str" value="' . esc_attr($o['utm_str']) . '">', esc_html(wmtg_t('Ссылка спрятана в кнопке, в тексте поста её не видно. В GA4 трафик будет виден как telegram / social.', 'Посилання сховане в кнопці, в тексті поста його не видно. У GA4 трафік буде видно як telegram / social.')));
	?>
	</table>

	<h2><?php echo esc_html(wmtg_t('Отбор', 'Відбір')); ?></h2>
	<table class="form-table" role="presentation">
	<?php
	$row(wmtg_t('Режим', 'Режим'), '<label><input type="radio" name="mode" value="popular"' . checked($o['mode'], 'popular', false) . '> ' . esc_html(wmtg_t('Только популярные', 'Тільки популярні')) . '</label><br><label><input type="radio" name="mode" value="all"' . checked($o['mode'], 'all', false) . '> ' . esc_html(wmtg_t('Все по очереди', 'Усі по черзі')) . '</label>', esc_html(wmtg_t('Популярные: из записей за вчера и сегодня в каждый слот уходит та, у которой больше просмотров в час. «Все»: по порядку публикации, записи старше 24 ч пропускаются.', 'Популярні: із записів за вчора й сьогодні в кожен слот іде та, у якої більше переглядів за годину. «Усі»: за порядком публікації, записи старші за 24 год пропускаються.')));
	$row(wmtg_t('Ждать после публикации', 'Чекати після публікації'), '<input type="number" min="0" max="720" class="small-text" name="wait" value="' . (int) $o['wait'] . '"> ' . esc_html(wmtg_t('мин', 'хв')), esc_html(wmtg_t('Чтобы у свежей записи успели накопиться просмотры.', 'Щоб у свіжого запису встигли накопичитися перегляди.')));
	$src = function_exists('pvc_get_post_views') ? 'Post Views Counter' : wmtg_t('поле записи', 'поле запису');
	$row(wmtg_t('Просмотры', 'Перегляди'), '<input type="text" class="regular-text code" style="width:200px" name="views_key" value="' . esc_attr($o['views_key']) . '"> <span class="description">' . esc_html(wmtg_t('сейчас: ', 'зараз: ') . $src) . '</span>', esc_html(wmtg_t('Если установлен Post Views Counter — берётся он. Иначе — это поле записи (у темы Newspaper: post_views_count).', 'Якщо встановлено Post Views Counter — береться він. Інакше — це поле запису (у теми Newspaper: post_views_count).')));
	$cl = '';
	foreach (get_categories(['hide_empty' => false]) as $c) $cl .= '<label style="display:inline-block;min-width:150px;margin:0 12px 6px 0"><input type="checkbox" name="excl_cats[]" value="' . (int) $c->term_id . '"' . (in_array((int) $c->term_id, array_map('intval', (array) $o['excl_cats']), true) ? ' checked' : '') . '> ' . esc_html($c->name) . '</label>';
	$row(wmtg_t('Не отправлять из рубрик', 'Не відправляти з рубрик'), $cl);
	?>
	</table>

	<h2><?php echo esc_html(wmtg_t('Расписание', 'Розклад')); ?></h2>
	<table class="widefat striped wmtg-days" style="width:auto">
	<thead><tr><th><?php echo esc_html(wmtg_t('День', 'День')); ?></th><th><?php echo esc_html(wmtg_t('Включён', 'Увімкнено')); ?></th><th><?php echo esc_html(wmtg_t('С, ч', 'З, год')); ?></th><th><?php echo esc_html(wmtg_t('До, ч', 'До, год')); ?></th><th><?php echo esc_html(wmtg_t('Максимум постов', 'Максимум постів')); ?></th></tr></thead><tbody>
	<?php foreach ($days_n as $d => $n) { $c = $o['days'][$d] ?? ['on' => 1, 'from' => 8, 'to' => 22, 'max' => 10]; ?>
	<tr><th><?php echo esc_html($n); ?></th>
	<td><input type="checkbox" name="days[<?php echo $d; ?>][on]" value="1"<?php checked($c['on'], 1); ?>></td>
	<td><input type="number" min="0" max="23" name="days[<?php echo $d; ?>][from]" value="<?php echo (int) $c['from']; ?>"></td>
	<td><input type="number" min="1" max="24" name="days[<?php echo $d; ?>][to]" value="<?php echo (int) $c['to']; ?>"></td>
	<td><input type="number" min="0" max="100" name="days[<?php echo $d; ?>][max]" value="<?php echo (int) $c['max']; ?>"></td></tr>
	<?php } ?>
	</tbody></table>
	<table class="form-table" role="presentation">
	<?php $row(wmtg_t('Пауза между постами', 'Пауза між постами'), '<input type="number" min="5" max="1440" class="small-text" name="interval" value="' . (int) $o['interval'] . '"> ' . esc_html(wmtg_t('мин', 'хв')), esc_html(wmtg_t('Время — по часовому поясу сайта. Точность ±15 мин (крон хостинга). Ручная отправка идёт вне лимита, но пауза после неё учитывается.', 'Час — за часовим поясом сайту. Точність ±15 хв (крон хостингу). Ручна відправка йде поза лімітом, але пауза після неї враховується.'))); ?>
	</table>

	<p class="submit"><?php echo $btn('save', wmtg_t('Сохранить', 'Зберегти'), 'button button-primary'); ?></p>

	</div><div class="wmtg-side">
	<h2><?php echo esc_html(wmtg_t('Превью', 'Превʼю')); ?></h2>
	<?php if ($pv) { ?>
	<div class="wmtg-tg"><div class="wmtg-bubble" id="wmtg-bubble"><?php if ($pv['img']) echo '<img src="' . esc_url($pv['img']) . '" alt="">'; ?><div class="t" id="wmtg-text"></div><div class="m"><?php echo esc_html(wp_date('H:i')); ?></div></div><div class="wmtg-kbd" id="wmtg-kbd"></div></div>
	<p class="description"><?php echo esc_html(wmtg_t('Запись: ', 'Запис: ')); ?><input type="number" class="small-text" name="test_post" value="<?php echo (int) $tp->ID; ?>" style="width:90px"> <?php echo $btn('preview', wmtg_t('Показать', 'Показати')); ?><br><?php echo esc_html(wmtg_t('Превью обновляется сразу при изменении полей слева.', 'Превʼю оновлюється одразу при зміні полів ліворуч.')); ?></p>
	<?php } else echo '<p>' . esc_html(wmtg_t('Нет опубликованных записей.', 'Немає опублікованих записів.')) . '</p>'; ?>
	<h2><?php echo esc_html(wmtg_t('Тест в личку', 'Тест в особисті')); ?></h2>
	<p><?php echo esc_html(wmtg_t('1) Откройте своего бота в Telegram и нажмите Start. 2) «Найти мой чат». 3) «Отправить тест мне» — придёт ровно такой пост, как в канал.', '1) Відкрийте свого бота в Telegram і натисніть Start. 2) «Знайти мій чат». 3) «Надіслати тест мені» — прийде точно такий пост, як у канал.')); ?></p>
	<p><?php echo $btn('find_me', wmtg_t('Найти мой чат', 'Знайти мій чат')); ?> <?php echo $btn('test', wmtg_t('Отправить тест мне', 'Надіслати тест мені'), 'button button-secondary'); ?></p>
	<p class="description"><?php echo $o['test_chat'] ? esc_html(wmtg_t('Тестовый чат: ', 'Тестовий чат: ') . $o['test_name'] . ' (' . $o['test_chat'] . ')') : esc_html(wmtg_t('Тестовый чат не найден.', 'Тестовий чат не знайдено.')); ?></p>
	<h2><?php echo esc_html(wmtg_t('Перенос настроек', 'Перенесення налаштувань')); ?></h2>
	<p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wmtg_export'), 'wmtg_export')); ?>"><?php echo esc_html(wmtg_t('Экспорт в файл', 'Експорт у файл')); ?></a></p>
	<p><input type="file" name="wmtg_import" accept=".json,application/json"> <?php echo $btn('import', wmtg_t('Импорт', 'Імпорт')); ?></p>
	<p class="description"><?php echo esc_html(wmtg_t('Без токена, канала и тестового чата. Рубрики переносятся по слагу.', 'Без токена, каналу й тестового чату. Рубрики переносяться за слагом.')); ?></p>
	</div></div>

	<h2><?php echo esc_html(wmtg_t('Сейчас', 'Зараз')); ?></h2>
	<?php
	[$can, $why] = wmtg_slot_status();
	$day = (int) wp_date('N');
	echo '<p>' . esc_html(sprintf(wmtg_t('Сегодня отправлено: %d из %d. ', 'Сьогодні відправлено: %d з %d. '), wmtg_today_count(), (int) ($o['days'][$day]['max'] ?? 0)));
	if (!$o['enabled']) echo '<b>' . esc_html(wmtg_t('Автопостинг выключен.', 'Автопостинг вимкнено.')) . '</b>';
	else echo esc_html($can ? wmtg_t('Следующий пост — при ближайшем запуске крона.', 'Наступний пост — під час найближчого запуску крона.') : wmtg_t('Ожидание: ', 'Очікування: ') . $why . '.');
	echo '</p>';
	$cands = array_slice(wmtg_candidates(), 0, 8);
	if ($cands) {
		echo '<table class="widefat striped wmtg-cand" style="max-width:900px"><thead><tr><th>#</th><th>' . esc_html(wmtg_t('Кандидат', 'Кандидат')) . '</th><th>' . esc_html(wmtg_t('Опубликовано', 'Опубліковано')) . '</th><th>' . esc_html(wmtg_t('Просм.', 'Перегл.')) . '</th><th>' . esc_html(wmtg_t('В час', 'За год')) . '</th><th></th><th></th></tr></thead><tbody>';
		foreach ($cands as $i => $c) {
			echo '<tr><td>' . ($i + 1) . '</td><td>' . esc_html(wmtg_title($c['post'])) . '</td><td>' . esc_html(wp_date('d.m H:i', $c['pub'])) . '</td><td>' . (int) $c['views'] . '</td><td>' . esc_html($c['vph']) . '</td><td>' . ($c['ready'] ? '' : '<span class="description">' . esc_html(wmtg_t('ждёт', 'чекає')) . '</span>') . '</td><td style="text-align:right"><button type="submit" name="wmtg_do" value="send:' . (int) $c['post']->ID . '" class="button button-small" onclick="return confirm(' . esc_attr(wp_json_encode(wmtg_t('Отправить эту запись в канал сейчас?', 'Відправити цей запис у канал зараз?'))) . ')">' . esc_html(wmtg_t('Отправить сейчас', 'Відправити зараз')) . '</button></td></tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p class="description">' . esc_html(wmtg_t('Кандидатов нет: подходящие записи за вчера и сегодня уже отправлены или их нет.', 'Кандидатів немає: відповідні записи за вчора й сьогодні вже відправлені або їх немає.')) . '</p>';
	}
	?>

	<h2><?php echo esc_html(wmtg_t('Журнал', 'Журнал')); ?></h2>
	<div class="wmtg-log"><?php
	$log = get_option('wmtg_log', []);
	if (!$log) echo '<span class="info">' . esc_html(wmtg_t('Пусто.', 'Порожньо.')) . '</span>';
	foreach ((array) $log as $l) echo '<div class="' . esc_attr($l[1]) . '">' . esc_html(wp_date('d.m H:i', $l[0]) . '  ' . $l[2]) . '</div>';
	?></div>
	<p><?php echo $btn('clear_log', wmtg_t('Очистить журнал', 'Очистити журнал')); ?></p>
	</form>
	</div>
	<?php if ($pv) { ?>
	<script>
	(function(){
	var P=<?php echo wp_json_encode($pv, JSON_UNESCAPED_UNICODE); ?>,f=document.querySelector('.wmtg-wrap form');
	function v(n){var e=f.querySelector('[name="'+n+'"]');return e?(e.type==='checkbox'?e.checked:e.value):'';}
	function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
	function cut(t,n){t=t.replace(/\s+/g,' ').trim();if(t.length<=n)return t;var c=t.slice(0,n),s=c.lastIndexOf(' ');if(s>n*.6)c=c.slice(0,s);return c.replace(/[\s,.;:—–-]+$/,'')+'…';}
	function tag(t){var w=t.split(/[^\p{L}\p{N}]+/u).filter(Boolean);if(!w.length)return '';var h=w.map(function(x){return x.charAt(0).toUpperCase()+x.slice(1);}).join('');return /^\p{N}+$/u.test(h)?'':'#'+h;}
	function draw(){
	var ex=P.excerpt||cut(P.text,Math.max(50,parseInt(v('excerpt_len'))||200)),n=Math.max(0,parseInt(v('hashtags'))||0),tags=[];
	P.tags.forEach(function(t){var h=tag(t);if(h&&tags.length<n)tags.push(h);});
	document.getElementById('wmtg-text').innerHTML='<b>'+esc(P.title)+'</b>'+(ex?'\n\n'+esc(ex):'')+(tags.length?'\n\n<a>'+esc(tags.join(' '))+'</a>':'');
	var lang=P.lang||v('default_lang');document.getElementById('wmtg-kbd').textContent='↗ '+(lang==='uk'?v('btn_uk'):v('btn_ru'));
	}
	f.addEventListener('input',draw);f.addEventListener('change',draw);draw();
	})();
	</script>
	<?php }
}

add_action('admin_post_wmtg_export', function () {
	if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wmtg_export')) wp_die('403');
	$o = array_diff_key(wmtg_opt(), array_flip(['token', 'channel', 'test_chat', 'test_name', 'enabled']));
	$o['excl_cats'] = array_values(array_filter(array_map(function ($id) { $c = get_category((int) $id); return $c && !is_wp_error($c) ? $c->slug : ''; }, (array) $o['excl_cats'])));
	nocache_headers();
	header('Content-Type: application/json; charset=utf-8');
	header('Content-Disposition: attachment; filename="webmaster-telegram-' . sanitize_file_name(wp_parse_url(home_url(), PHP_URL_HOST)) . '.json"');
	echo wp_json_encode(['plugin' => 'webmaster-telegram', 'version' => WMTG_VERSION, 'settings' => $o], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
});

/* ------------------------------------------------------------------ */
/* Обновления с GitHub (публичный репозиторий, Releases)              */
/* ------------------------------------------------------------------ */

function wmtg_release($force = false) {
	$r = get_site_transient('wmtg_release');
	if ($r !== false && !$force) return $r;
	$res = wp_remote_get('https://api.github.com/repos/' . WMTG_REPO . '/releases/latest', ['timeout' => 10, 'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'WebMaster-Telegram']]);
	$r = [];
	if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
		$j = json_decode(wp_remote_retrieve_body($res), true);
		if (!empty($j['tag_name'])) {
			$zip = $j['zipball_url'] ?? '';
			foreach ((array) ($j['assets'] ?? []) as $a) if (substr($a['name'] ?? '', -4) === '.zip') { $zip = $a['browser_download_url']; break; }
			$r = ['version' => ltrim($j['tag_name'], 'vV'), 'zip' => $zip, 'notes' => (string) ($j['body'] ?? ''), 'url' => (string) ($j['html_url'] ?? '')];
		}
	}
	set_site_transient('wmtg_release', $r, $r ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
	return $r;
}

add_filter('pre_set_site_transient_update_plugins', function ($t) {
	if (!is_object($t)) return $t;
	$r = wmtg_release();
	$base = plugin_basename(WMTG_FILE);
	if ($r && version_compare($r['version'], WMTG_VERSION, '>')) {
		$t->response[$base] = (object) ['slug' => 'webmaster-telegram', 'plugin' => $base, 'new_version' => $r['version'], 'package' => $r['zip'], 'url' => $r['url']];
	} elseif (isset($t->response[$base])) {
		unset($t->response[$base]);
	}
	return $t;
});

add_filter('plugins_api', function ($res, $action, $args) {
	if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'webmaster-telegram') return $res;
	$r = wmtg_release();
	return (object) ['name' => 'WebMaster Telegram', 'slug' => 'webmaster-telegram', 'version' => $r['version'] ?? WMTG_VERSION, 'author' => 'nomidua', 'homepage' => 'https://github.com/' . WMTG_REPO, 'requires' => '6.2', 'requires_php' => '7.4', 'sections' => ['changelog' => nl2br(esc_html($r['notes'] ?? ''))], 'download_link' => $r['zip'] ?? ''];
}, 10, 3);

add_filter('upgrader_source_selection', function ($src, $remote, $upgrader, $extra) {
	if (($extra['plugin'] ?? '') !== plugin_basename(WMTG_FILE)) return $src;
	$want = trailingslashit($remote) . 'webmaster-telegram/';
	if (untrailingslashit($src) === untrailingslashit($want)) return $src;
	global $wp_filesystem;
	return $wp_filesystem && $wp_filesystem->move($src, $want, true) ? $want : $src;
}, 10, 4);

add_filter('plugin_row_meta', function ($links, $file) {
	if ($file !== plugin_basename(WMTG_FILE)) return $links;
	$links[] = '<a href="' . esc_url(wp_nonce_url(admin_url('plugins.php?wmtg_check=1'), 'wmtg_check')) . '">' . esc_html(wmtg_t('Проверить обновления', 'Перевірити оновлення')) . '</a>';
	return $links;
}, 10, 2);

add_action('admin_init', function () {
	if (empty($_GET['wmtg_check']) || !current_user_can('update_plugins') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wmtg_check')) return;
	wmtg_release(true);
	delete_site_transient('update_plugins');
	wp_safe_redirect(admin_url('plugins.php'));
	exit;
});
