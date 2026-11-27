<?php
namespace DX\Data;

defined('ABSPATH') || exit;

/** Small strict schema validator; every public object must declare its keys. */
final class Validator {
	public static function definition(array $schema, int $depth = 0): void {
		if ($depth > 16 || !in_array($schema['type'] ?? '', ['object','array','string','number','integer','boolean','null'], true)) throw new \RuntimeException('invalid_schema');
		if ($schema['type'] === 'object') {
			if (!isset($schema['properties']) || !is_array($schema['properties']) || ($schema['additionalProperties'] ?? null) !== false) throw new \RuntimeException('object_requires_allowlist');
			if (isset($schema['required']) && (!is_array($schema['required']) || array_diff($schema['required'], array_keys($schema['properties'])))) throw new \RuntimeException('invalid_schema_required');
			foreach ($schema['properties'] as $child) { if (!is_array($child)) throw new \RuntimeException('invalid_schema'); self::definition($child, $depth + 1); }
		}
		if ($schema['type'] === 'array') self::definition($schema['items'] ?? [], $depth + 1);
	}
	public static function check($value, array $schema, string $path = '$', int $depth = 0): void {
		if ($depth > 16) throw new \RuntimeException('schema_depth');
		if ($value === null && !empty($schema['nullable'])) return;
		if (isset($schema['sentinel']) && $value === $schema['sentinel']) return;
		$type = $schema['type'];
		$valid = match ($type) {
			'object' => is_array($value) && (!array_is_list($value) || !$value),
			'array' => is_array($value) && array_is_list($value),
			'integer' => is_int($value),
			'number' => (is_int($value) || is_float($value)) && is_finite((float)$value),
			'null' => $value === null,
			'boolean' => is_bool($value),
			default => is_string($value),
		};
		if (!$valid) throw new \RuntimeException('schema_type:' . $path);
		if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) throw new \RuntimeException('schema_enum:' . $path);
		if (is_string($value) && (strlen($value) > ($schema['maxLength'] ?? 20000) || !mb_check_encoding($value, 'UTF-8'))) throw new \RuntimeException('schema_string:' . $path);
		if (is_numeric($value) && $type !== 'string' && (($value < ($schema['minimum'] ?? -PHP_INT_MAX)) || ($value > ($schema['maximum'] ?? PHP_INT_MAX)))) throw new \RuntimeException('schema_range:' . $path);
		if ($type === 'object') {
			foreach ($schema['required'] ?? array_keys($schema['properties']) as $key) if (!array_key_exists($key, $value)) throw new \RuntimeException('schema_missing:' . $path . '.' . $key);
			foreach ($value as $key => $item) {
				if (!isset($schema['properties'][$key])) throw new \RuntimeException('schema_extra:' . $path . '.' . $key);
				self::check($item, $schema['properties'][$key], $path . '.' . $key, $depth + 1);
			}
		}
		if ($type === 'array') {
			if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? 2000)) throw new \RuntimeException('schema_count:' . $path);
			foreach ($value as $i => $item) self::check($item, $schema['items'], $path . '[' . $i . ']', $depth + 1);
		}
	}
}

final class Engine {
	private array $providers = [];
	private array $queue = [];
	private bool $running = false;
	public string $public;
	public string $private;
	public string $url;
	public function __construct(string $public, string $private, string $url) {
		$this->public = rtrim($public, '/'); $this->private = rtrim($private, '/'); $this->url = rtrim($url, '/');
	}
	public function register(array $provider): void {
		$id = $provider['id'] ?? '';
		if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $id) || isset($this->providers[$id])) throw new \RuntimeException('invalid_or_duplicate_provider');
		if (($provider['classification'] ?? '') !== 'public_static' || !is_callable($provider['build'] ?? null) || !is_callable($provider['count'] ?? null) || !is_int($provider['schemaVersion'] ?? null) || $provider['schemaVersion'] < 1) throw new \RuntimeException('invalid_provider');
		Validator::definition($provider['schema'] ?? []);
		$provider += ['name'=>$id,'module'=>$id,'postTypes'=>[],'taxonomies'=>[],'metaKeys'=>[],'dependencies'=>[],'maxBytes'=>8388608,'maxSeconds'=>20,'minRecords'=>1,'retain'=>2,'freshness'=>'on-change','manifestTTL'=>60];
		foreach ($provider['dependencies'] as $dependency) if (!is_string($dependency) || !isset($this->providers[$dependency])) throw new \RuntimeException('dependency_must_be_registered_first');
		if (!is_int($provider['retain']) || $provider['retain'] < 1 || $provider['retain'] > 2 || !is_int($provider['maxBytes']) || $provider['maxBytes'] < 1 || $provider['maxBytes'] > 8388608 || !is_numeric($provider['maxSeconds']) || $provider['maxSeconds'] <= 0 || $provider['maxSeconds'] > 30 || !is_int($provider['minRecords']) || $provider['minRecords'] < 1) throw new \RuntimeException('invalid_provider_budget');
		$this->providers[$id] = $provider;
	}
	public function providers(): array { return $this->providers; }
	public function provider(string $id): array {
		if (!isset($this->providers[$id])) throw new \RuntimeException('unknown_provider');
		return $this->providers[$id];
	}
	private function prepare(): void {
		foreach ([$this->private,$this->public] as $dir) if (!is_dir($dir) && !wp_mkdir_p($dir)) throw new \RuntimeException('storage_unavailable');
		$root = realpath(ABSPATH); $private = realpath($this->private);
		if (!$private || str_starts_with($private . '/', $root . '/')) throw new \RuntimeException('private_storage_must_be_outside_webroot');
		@chmod($this->private, 0700);
		foreach (glob($this->private . '/publish-*') ?: [] as $stage) if (is_file($stage) && filemtime($stage) < time() - 3600) @unlink($stage);
		$this->write($this->public . '/.htaccess', "Options -Indexes\n<IfModule mod_headers.c>\nHeader set X-Content-Type-Options nosniff\n<FilesMatch \"\\.json(\\.gz)?$\">\nHeader set X-Robots-Tag \"noindex\"\n</FilesMatch>\n<FilesMatch \"^dataset\\.[a-f0-9]{64}\\.json$\">\nHeader set Cache-Control \"public, max-age=31536000, immutable\"\n</FilesMatch>\n<FilesMatch \"^(manifest.json|index.html)$\">\nHeader set Cache-Control \"public, max-age=60, must-revalidate\"\n</FilesMatch>\n</IfModule>\n<IfModule mod_deflate.c>\nAddOutputFilterByType DEFLATE application/json text/html\n</IfModule>\n");
	}
	private function write(string $destination, string $bytes): void {
		// Staging is outside the webroot; cross-device rename fails closed.
		$tmp = tempnam($this->private, 'publish-');
		if (!$tmp) throw new \RuntimeException('staging_failed');
		try {
			if (file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes)) throw new \RuntimeException('write_failed');
			if (hash_file('sha256', $tmp) !== hash('sha256', $bytes)) throw new \RuntimeException('write_integrity_failed');
			@chmod($tmp, str_starts_with($destination, $this->private . '/') ? 0600 : 0644);
			if (!@rename($tmp, $destination)) throw new \RuntimeException('atomic_rename_failed');
		} finally { if (is_file($tmp)) @unlink($tmp); }
	}
	private function json(string $path): array {
		if (!is_file($path) || filesize($path) > 8388608) return [];
		$value = json_decode((string)file_get_contents($path), true);
		return is_array($value) ? $value : [];
	}
	public function metadata(string $id): array { $this->provider($id); return $this->json($this->private . '/' . $id . '.json'); }
	public function controls(string $id): array { return $this->metadata($id)['controls'] ?? ['generation'=>true,'consumption'=>true]; }
	private function saveMeta(string $id, array $meta): void { $this->write($this->private . '/' . $id . '.json', wp_json_encode($meta, JSON_UNESCAPED_SLASHES)); }
	private function event(string $id, string $type, array $metrics = []): void {
		$event = ['dataset'=>$id,'event'=>$type,'at'=>gmdate('c'),'metrics'=>$metrics];
		$lock = fopen($this->private . '/events.lock', 'c');
		if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('storage_lock_failed');
		try {
			$events = $this->json($this->private . '/events.json');
			$events[] = $event; $events = array_slice($events, -100);
			$this->write($this->private . '/events.json', wp_json_encode($events));
		} finally { flock($lock, LOCK_UN); fclose($lock); }
		do_action('dx_data_event', $event);
	}
	public function events(): array { return $this->json($this->private . '/events.json'); }
	public function invalidate(string $id, bool $withdraw = false, array $seen = []): void {
		$this->prepare(); $this->provider($id);
		if (isset($seen[$id]) || count($seen) > 20) return;
		$seen[$id] = true;
		$this->write($this->private . '/' . $id . '.dirty', wp_json_encode(['token'=>bin2hex(random_bytes(8)),'withdraw'=>$withdraw]));
		$this->queue[$id] = true;
		if ($withdraw) {
			$withdrawLock = fopen($this->private . '/' . $id . '.lock', 'c');
			if (!$withdrawLock || !flock($withdrawLock, LOCK_EX)) throw new \RuntimeException('storage_lock_failed');
			try {
			// Fail closed immediately; LKG must never defeat an explicit withdrawal.
			$this->maintenance($id, 'Content update pending');
			foreach (glob($this->public . '/' . $id . '/dataset.*') ?: [] as $file) @unlink($file);
			$meta = $this->metadata($id); unset($meta['current'],$meta['previous']); $meta['withdrawn'] = true; $this->saveMeta($id, $meta);
			} finally { flock($withdrawLock, LOCK_UN); fclose($withdrawLock); }
		}
		$this->event($id, 'dataset.invalidation.requested');
		foreach ($this->providers as $dependent => $provider) if (in_array($id, $provider['dependencies'], true)) $this->invalidate($dependent, $withdraw, $seen);
	}
	public function drain(): void {
		if ($this->running) return;
		$this->running = true;
		try { foreach (array_slice(array_keys($this->queue), 0, 3) as $id) { try { $this->generate($id); } catch (\Throwable $error) { /* Each provider has its own failure record. */ } } }
		finally { $this->running = false; $this->queue = []; }
	}
	public function generate(string $id): array {
		$p = $this->provider($id); $this->prepare();
		foreach ($p['dependencies'] as $dependency) if (empty($this->metadata($dependency)['current'])) throw new \RuntimeException('dependency_unavailable');
		$lock = fopen($this->private . '/' . $id . '.lock', 'c');
		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); throw new \RuntimeException('generation_busy'); }
		$started = microtime(true); $meta = $this->metadata($id); $dirty = $this->json($this->private . '/' . $id . '.dirty');
		try {
			if (($meta['controls']['generation'] ?? true) !== true) throw new \RuntimeException('generation_suspended');
			$meta['lastAttempt'] = gmdate('c'); $meta['status'] = 'generating'; $this->saveMeta($id, $meta); $this->event($id, 'dataset.generation.started');
			$data = ($p['build'])(); Validator::check($data, $p['schema']);
			$count = ($p['count'])($data);
			if (!is_int($count) || $count < $p['minRecords']) throw new \RuntimeException('empty_or_incomplete_dataset');
			$payload = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
			if (!is_string($payload) || strlen($payload) > $p['maxBytes']) throw new \RuntimeException('payload_budget');
			if (microtime(true) - $started > $p['maxSeconds']) throw new \RuntimeException('generation_deadline');
			if ($dirty !== $this->json($this->private . '/' . $id . '.dirty')) throw new \RuntimeException('generation_source_changed');
			$version = hash('sha256', $p['schemaVersion'] . ':' . $payload);
			$unchanged = ($meta['current']['version'] ?? '') === $version;
			if ($unchanged) { try { if (empty($this->json($this->public . '/' . $id . '/manifest.json')['available'])) throw new \RuntimeException('invalid_manifest'); $this->validate($id); } catch (\Throwable $e) { $unchanged = false; } }
			$dir = $this->public . '/' . $id;
			if (!is_dir($dir) && !wp_mkdir_p($dir)) throw new \RuntimeException('publication_directory_failed');
			$required = strlen($payload) * 3 + 1048576;
			$free = disk_free_space($dir); if ($free !== false && $free < $required) throw new \RuntimeException('insufficient_storage');
			if (!$unchanged) {
				$generated = ($meta['current']['version'] ?? '') === $version ? $meta['current']['generatedAt'] : gmdate('c');
				$bytes = wp_json_encode(['dataset'=>$id,'schemaVersion'=>$p['schemaVersion'],'version'=>$version,'generatedAt'=>$generated,'data'=>$data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
				$file = 'dataset.' . $version . '.json'; $checksum = hash('sha256', $bytes);
				$this->write($dir . '/' . $file, $bytes);
				if (function_exists('gzencode')) $this->write($dir . '/' . $file . '.gz', gzencode($bytes, 6));
				$manifest = ['dataset'=>$id,'schemaVersion'=>$p['schemaVersion'],'version'=>$version,'generatedAt'=>$generated,'records'=>$count,'bytes'=>strlen($bytes),'checksum'=>$checksum,'file'=>$file,'available'=>true,'freshness'=>$p['freshness']];
				if (!empty($p['shell'])) {
					$shell = ($p['shell'])($data, $manifest, $this->url . '/' . $id . '/manifest.json');
					if (!is_string($shell) || strlen($shell) < 500 || strlen($shell) > 2097152 || preg_match('/(?:_wpnonce|wp-admin-bar|logged-in|user_login|wpApiSettings)/i', $shell)) throw new \RuntimeException('unsafe_shell');
					$this->write($dir . '/index.html', $shell);
					$meta['shellChecksum'] = hash('sha256', $shell);
				}
				$this->write($dir . '/manifest.json', wp_json_encode($manifest, JSON_UNESCAPED_SLASHES));
				$meta['previous'] = $meta['current'] ?? null; $meta['current'] = $manifest;
				$this->event($id, 'dataset.publication.completed', ['bytes'=>strlen($bytes),'records'=>$count]);
			}
			$meta['status'] = 'healthy'; $meta['lastSuccess'] = gmdate('c'); $meta['durationMs'] = round((microtime(true) - $started) * 1000, 2); $meta['peakMemoryBytes'] = memory_get_peak_usage(true); $meta['error'] = ''; $meta['withdrawn'] = false; $meta['successes'] = ($meta['successes'] ?? 0) + 1; $meta['unchanged'] = $unchanged;
			$this->saveMeta($id, $meta);
			if ($dirty === $this->json($this->private . '/' . $id . '.dirty')) @unlink($this->private . '/' . $id . '.dirty');
			$this->prune($id);
			if (($meta['controls']['consumption'] ?? true) !== true) $this->maintenance($id, 'Temporarily unavailable');
			$this->event($id, 'dataset.generation.completed', ['ms'=>$meta['durationMs'],'unchanged'=>$unchanged]);
			return $meta;
		} catch (\Throwable $error) {
			$committed = $this->json($this->public . '/' . $id . '/manifest.json');
			$keep = array_filter([$committed['file'] ?? '', $meta['current']['file'] ?? '', $meta['previous']['file'] ?? '']);
			foreach (glob($this->public . '/' . $id . '/dataset.*') ?: [] as $candidate) if (!in_array(preg_replace('/\.gz$/', '', basename($candidate)), $keep, true)) @unlink($candidate);
			// Codes only: never log SQL, credentials, content or visitor parameters.
			$code = explode(':', $error->getMessage(), 2)[0];
			$allowed = ['schema_depth','schema_type','schema_enum','schema_string','schema_range','schema_missing','schema_extra','schema_count','empty_or_incomplete_dataset','payload_budget','generation_deadline','generation_source_changed','generation_suspended','generation_record_budget','generation_theme_unavailable','dependency_unavailable','storage_unavailable','storage_lock_failed','staging_failed','write_failed','write_integrity_failed','atomic_rename_failed','publication_directory_failed','insufficient_storage','unsafe_shell'];
			$known = in_array($code, $allowed, true) ? $code : 'generation_failed';
			$meta['status'] = str_starts_with($known, 'schema_') ? 'validation_failed' : 'generation_failed'; $meta['error'] = $known; $meta['failures'] = ($meta['failures'] ?? 0) + 1; $meta['durationMs'] = round((microtime(true) - $started) * 1000, 2); $this->saveMeta($id, $meta);
			$this->event($id, 'dataset.generation.failed', ['code'=>$known]);
			throw new \RuntimeException($known);
		} finally { flock($lock, LOCK_UN); fclose($lock); }
	}
	public function validate(string $id): array {
		$p = $this->provider($id); $meta = $this->metadata($id); $manifest = $meta['current'] ?? [];
		$published = $this->json($this->public . '/' . $id . '/manifest.json');
		if (($published['available'] ?? false) !== true || $published !== $manifest) throw new \RuntimeException('invalid_public_manifest');
		if (!preg_match('/^dataset\.[a-f0-9]{64}\.json$/D', $manifest['file'] ?? '') || ($manifest['schemaVersion'] ?? null) !== $p['schemaVersion']) throw new \RuntimeException('invalid_manifest');
		$path = $this->public . '/' . $id . '/' . $manifest['file'];
		if (!is_file($path) || filesize($path) !== $manifest['bytes'] || hash_file('sha256', $path) !== $manifest['checksum']) throw new \RuntimeException('invalid_integrity');
		$envelope = $this->json($path);
		if (($envelope['dataset'] ?? '') !== $id || ($envelope['version'] ?? '') !== $manifest['version'] || ($envelope['schemaVersion'] ?? null) !== $p['schemaVersion']) throw new \RuntimeException('invalid_envelope');
		Validator::check($envelope['data'] ?? null, $p['schema']);
		if (!empty($p['shell'])) { $shell = $this->public . '/' . $id . '/index.html'; if (!is_file($shell) || filesize($shell) < 500 || (isset($meta['shellChecksum']) && hash_file('sha256', $shell) !== $meta['shellChecksum'])) throw new \RuntimeException('invalid_shell'); }
		return $manifest;
	}
	public function prune(string $id): void {
		$p = $this->provider($id); $meta = $this->metadata($id); $keep = array_filter([$meta['current']['file'] ?? '', $p['retain'] > 1 ? ($meta['previous']['file'] ?? '') : '']);
		foreach (glob($this->public . '/' . $id . '/dataset.*') ?: [] as $file) if (!in_array(preg_replace('/\.gz$/', '', basename($file)), $keep, true)) @unlink($file);
	}
	public function maintenance(string $id, string $message): void {
		$this->prepare(); $this->provider($id); $dir = $this->public . '/' . $id;
		if (!is_dir($dir) && !wp_mkdir_p($dir)) throw new \RuntimeException('storage_unavailable');
		$this->write($dir . '/manifest.json', wp_json_encode(['dataset'=>$id,'available'=>false,'reason'=>'maintenance']));
		$this->write($dir . '/index.html', '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Information temporarily unavailable</title><body style="background:#0c1215;color:#eee;font:18px/1.6 system-ui;max-width:60ch;margin:12vh auto;padding:24px"><h1>Taking a short pause.</h1><p>' . htmlspecialchars($message) . '</p><p>Please return shortly. <a style="color:#d7ad57" href="/">Return home</a></p></body></html>');
	}
	public function control(string $id, string $key, bool $value): void {
		if (!in_array($key, ['generation','consumption'], true)) throw new \RuntimeException('invalid_control');
		$this->prepare(); $this->provider($id);
		$lock = fopen($this->private . '/' . $id . '.lock', 'c');
		if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('storage_lock_failed');
		try { $meta = $this->metadata($id); $meta['controls'] = $meta['controls'] ?? ['generation'=>true,'consumption'=>true]; $meta['controls'][$key] = $value; $this->saveMeta($id, $meta);
			if ($key === 'consumption' && !$value) $this->maintenance($id, 'Temporarily unavailable');
		} finally { flock($lock, LOCK_UN); fclose($lock); }
		if ($key === 'consumption' && $value) { $this->invalidate($id); $this->generate($id); }
	}
}
