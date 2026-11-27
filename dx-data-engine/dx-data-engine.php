<?php
/**
 * Plugin Name: DX. Data Engine
 * Description: Strict public dataset providers, atomic static publication and bounded administration.
 * Version: 1.0.0
 * Requires PHP: 8.1
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/Core/Engine.php';
require_once __DIR__ . '/Providers/Gyms.php';

function dx_data_engine(): \DX\Data\Engine {
	static $engine;
	if (!$engine) {
		$uploads = wp_upload_dir(null, false);
		$engine = new \DX\Data\Engine($uploads['basedir'] . '/dx-data', defined('DX_DATA_PRIVATE_DIR') ? DX_DATA_PRIVATE_DIR : dirname(rtrim(ABSPATH, '/')) . '/dx-data-private', $uploads['baseurl'] . '/dx-data');
	}
	return $engine;
}
add_action('init', function () {
	\DX\Data\Gyms::register(dx_data_engine());
	do_action('dx_data_register', dx_data_engine());
}, 99);

function dx_data_content_event(int $id, bool $withdraw = false, ?string $type = null): void {
	if (wp_is_post_revision($id) || wp_is_post_autosave($id)) return;
	$type = $type ?: get_post_type($id);
	foreach (dx_data_engine()->providers() as $key => $provider) {
		if (in_array($type, $provider['postTypes'], true) || $type === 'attachment') {
			try {
				dx_data_engine()->invalidate($key, $withdraw);
				if (!wp_next_scheduled('dx_data_regenerate', [$key])) wp_schedule_single_event(time() + 15, 'dx_data_regenerate', [$key]);
			} catch (\Throwable $error) { update_option('dx_data_storage_error', 'Dataset invalidation failed. Inspect filesystem permissions before reopening.', false); }
		}
	}
}
add_action('save_post', function ($id, $post) { dx_data_content_event((int)$id, !empty($post->post_password)); }, 100, 2);
add_action('transition_post_status', function ($new, $old, $post) { if (($old === 'publish' && $new !== 'publish') || ($post->post_type === 'attachment' && in_array($new, ['trash','private'], true))) dx_data_content_event((int)$post->ID, true); }, 100, 3);
add_action('before_delete_post', function ($id, $post) { if ($post->post_status === 'publish') dx_data_content_event((int)$id, true, $post->post_type); }, 10, 2);
foreach (['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook) add_action($hook, function ($meta_id, $id, $key) {
	$type = get_post_type($id);
	if ($type) {
		foreach (dx_data_engine()->providers() as $provider) if (in_array($type, $provider['postTypes'], true) && in_array(ltrim($key, '_'), $provider['metaKeys'], true)) { dx_data_content_event((int)$id); break; }
	}
}, 100, 3);
add_action('acf/save_post', function ($id) { if (is_numeric($id)) dx_data_content_event((int)$id); }, 100);
add_action('delete_attachment', function ($id) { dx_data_content_event((int)$id, true, 'attachment'); });
add_action('edit_attachment', function ($id) { dx_data_content_event((int)$id); });
add_action('set_object_terms', function ($id) { dx_data_content_event((int)$id); });
add_action('edited_term', function ($term_id, $tt_id, $taxonomy) { foreach (dx_data_engine()->providers() as $id => $provider) if (in_array($taxonomy, $provider['taxonomies'], true)) dx_data_engine()->invalidate($id); }, 10, 3);
add_action('delete_term', function ($term_id, $tt_id, $taxonomy) { foreach (dx_data_engine()->providers() as $id => $provider) if (in_array($taxonomy, $provider['taxonomies'], true)) dx_data_engine()->invalidate($id, true); }, 10, 3);
add_action('shutdown', function () { if (did_action('init') && (is_admin() || (defined('WP_CLI') && WP_CLI))) dx_data_engine()->drain(); }, 999);
// Invalidation events also cover dependent providers and authenticated REST edits.
add_action('dx_data_event', function ($event) { if (($event['event'] ?? '') === 'dataset.invalidation.requested' && !wp_next_scheduled('dx_data_regenerate', [$event['dataset']])) wp_schedule_single_event(time() + 15, 'dx_data_regenerate', [$event['dataset']]); });
add_action('dx_data_regenerate', function ($id) { try { if (is_file(dx_data_engine()->private . '/' . $id . '.dirty')) dx_data_engine()->generate($id); } catch (\Throwable $error) { /* One bounded retry; never self-reschedule. */ } });

// Canonical fallback is disk-only after WP bootstrap. Web-server rewriting removes bootstrap too.
add_action('template_redirect', function () {
	if (untrailingslashit(wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)) !== untrailingslashit(wp_parse_url(home_url('/fitness/gyms/'), PHP_URL_PATH))) return;
	$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
	if (!in_array($method, ['GET','HEAD'], true)) { status_header(405); header('Allow: GET, HEAD'); exit; }
	$path = dx_data_engine()->public . '/gyms/index.html';
	if (!is_file($path)) { status_header(503); header('Retry-After: 300'); header('Cache-Control: no-store'); echo '<!doctype html><html><title>Gym League temporarily unavailable</title><h1>Taking a short pause.</h1><p>Please return shortly. <a href="/fitness/">Explore Fitness</a></p></html>'; exit; }
	$etag = '"' . hash_file('sha256', $path) . '"';
	status_header(200); header('Content-Type: text/html; charset=UTF-8'); header('Cache-Control: public, max-age=60, must-revalidate'); header('ETag: ' . $etag); header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT'); header('X-DX-Data-Shell: static-file');
	if (!empty($_SERVER['QUERY_STRING'])) header('X-Robots-Tag: noindex, follow');
	// Weak validators are equivalent for GET/HEAD; Nginx may weaken ETags when compressing.
	$validators = array_map('trim', explode(',', wp_unslash($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')));
	if (in_array('*', $validators, true) || in_array($etag, array_map(static fn($v)=>preg_replace('/^W\//', '', $v), $validators), true)) { status_header(304); exit; }
	if (empty($_SERVER['HTTP_IF_NONE_MATCH']) && !empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= filemtime($path)) { status_header(304); exit; }
	if ($method !== 'HEAD') readfile($path);
	exit;
}, -100);

add_action('admin_menu', function () { add_management_page('DX. Data Engine','DX. Data Engine','manage_options','dx-data','dx_data_admin'); });
function dx_data_admin(bool $workspace = false): void {
	if (!current_user_can('manage_options')) wp_die('Not authorised', '', ['response'=>403]);
	$engine = dx_data_engine();
	echo '<div class="wrap dx-data-console">' . ($workspace ? '' : '<h2>DX. Data Engine</h2>') . '<p>Public static datasets · measured generation activity · no automatic monitoring requests</p>';
	if (get_option('dx_data_storage_error')) echo '<div class="notice notice-error"><p>' . esc_html(get_option('dx_data_storage_error')) . '</p></div>';
	foreach ($engine->providers() as $id => $provider) {
		$m = $engine->metadata($id); $current = $m['current'] ?? []; $controls = $engine->controls($id);
		echo '<section class="dxcp-card dx-data-provider"><h2>' . esc_html($provider['name']) . '</h2><table class="widefat striped dx-data-table"><tbody>';
		$rows = ['Module'=>$provider['module'],'Status'=>$m['status'] ?? 'Unavailable','Pending changes'=>is_file($engine->private . '/' . $id . '.dirty') ? 'Yes' : 'No','Schema version'=>$provider['schemaVersion'],'Content version'=>$current['version'] ?? '—','Records'=>$current['records'] ?? '—','Dataset bytes'=>$current['bytes'] ?? '—','Public asset'=>isset($current['file']) ? $engine->url.'/'.$id.'/'.$current['file'] : '—','Checksum'=>$current['checksum'] ?? '—','Dataset age (seconds)'=>isset($current['generatedAt']) ? max(0,time()-strtotime($current['generatedAt'])) : '—','Successful generations'=>$m['successes'] ?? 0,'Last successful generation'=>$m['lastSuccess'] ?? '—','Last attempt'=>$m['lastAttempt'] ?? '—','Generation duration (ms)'=>$m['durationMs'] ?? '—','PHP process peak bytes'=>$m['peakMemoryBytes'] ?? '—','Failures'=>$m['failures'] ?? 0,'Last error code'=>$m['error'] ?? '—','Last-known-good'=>$m['previous']['version'] ?? 'None','Freshness policy'=>$provider['freshness'],'Generation enabled'=>$controls['generation']?'Yes':'No','Consumption enabled'=>$controls['consumption']?'Yes':'No'];
		foreach ($rows as $key => $value) echo '<tr><th>' . esc_html($key) . '</th><td style="overflow-wrap:anywhere">' . esc_html((string)$value) . '</td></tr>';
		echo '</tbody></table><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:18px">'; wp_nonce_field('dx_data_' . $id); if ($workspace) echo '<input type="hidden" name="workspace" value="1">';
		echo '<input type="hidden" name="action" value="dx_data_action"><input type="hidden" name="dataset" value="' . esc_attr($id) . '"><label>Operation <select name="operation">';
		foreach (['generate'=>'Regenerate','validate'=>'Validate current dataset','invalidate'=>'Mark for controlled regeneration','suspend'=>'Suspend generation','resume'=>'Resume generation','isolate'=>'Isolate public consumption','reopen'=>'Restore public consumption','prune'=>'Prune obsolete versions'] as $value => $label) echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
		echo '</select></label> <label><input type="checkbox" name="confirm" value="1"> Confirm isolation or pruning</label> <button class="button button-primary dxcp-btn">Run operation</button></form></section>';
	}
	echo '<h2>Recent generation events</h2><p>Last 100 events. No visitor parameters or content are logged.</p><table class="widefat striped dx-data-table"><thead><tr><th>Time</th><th>Dataset</th><th>Event</th></tr></thead><tbody>';
	foreach (array_reverse($engine->events()) as $event) echo '<tr><td>' . esc_html($event['at']) . '</td><td>' . esc_html($event['dataset']) . '</td><td>' . esc_html($event['event']) . '</td></tr>';
	echo '</tbody></table></div>';
}
add_action('admin_post_dx_data_action', function () {
	if (!current_user_can('manage_options')) wp_die('Not authorised', '', ['response'=>403]);
	$id = is_string($_POST['dataset'] ?? null) ? sanitize_key($_POST['dataset']) : ''; check_admin_referer('dx_data_' . $id);
	$op = is_string($_POST['operation'] ?? null) ? $_POST['operation'] : '';
	if (in_array($op, ['isolate','prune'], true) && ($_POST['confirm'] ?? '') !== '1') wp_die('Confirm this operation first.');
	try {
		$e = dx_data_engine();
		switch ($op) {
			case 'generate': $e->generate($id); break;
			case 'validate': $e->validate($id); break;
			case 'invalidate': $e->invalidate($id); break;
			case 'suspend': $e->control($id, 'generation', false); break;
			case 'resume': $e->control($id, 'generation', true); break;
			case 'isolate': $e->control($id, 'consumption', false); break;
			case 'reopen': $e->control($id, 'consumption', true); break;
			case 'prune': $e->prune($id); break;
			default: throw new \RuntimeException('Unknown operation');
		}
	} catch (\Throwable $error) { wp_die('Operation failed. ' . esc_html($error->getMessage())); }
	wp_safe_redirect(!empty($_POST['workspace']) && function_exists('dxcp_business_url') ? dxcp_business_url('data-engine') : admin_url('tools.php?page=dx-data')); exit;
});

if (defined('WP_CLI') && WP_CLI) {
	\WP_CLI::add_command('dx-data', function ($args, $assoc) {
		$operation = $args[0] ?? 'list'; $id = $args[1] ?? 'gyms'; $e = dx_data_engine();
		try {
			if ($operation === 'list') $result = array_map(static fn($p)=>['id'=>$p['id'],'schema'=>$p['schemaVersion'],'classification'=>$p['classification']], array_values($e->providers()));
			elseif (in_array($operation, ['status','inspect'], true)) $result = $e->metadata($id) + ['pending'=>is_file($e->private . '/' . $id . '.dirty')];
			elseif ($operation === 'generate') $result = $e->generate($id);
			elseif ($operation === 'validate') $result = $e->validate($id);
			elseif ($operation === 'invalidate') { $e->invalidate($id); $result = ['status'=>'pending']; }
			else throw new \RuntimeException('Unknown command');
			\WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		} catch (\Throwable $error) { \WP_CLI::error($error->getMessage()); }
	});
}

// The same administrator-only console is available inside the existing Business HQ shell.
function dx_data_console_assets(): void {
	if (current_user_can('manage_options')) wp_enqueue_style('dx-data-console', plugins_url('Frontend/admin.css', __FILE__), [], '1.0.0');
}
add_action('admin_enqueue_scripts', function ($hook) { if ($hook === 'tools_page_dx-data') dx_data_console_assets(); });
add_action('wp_enqueue_scripts', function () { if (isset($_GET['business_view']) && $_GET['business_view'] === 'data-engine') dx_data_console_assets(); });
