<?php
namespace DX\Data;
defined('ABSPATH') || exit;

final class Gyms {
	public static function register(Engine $engine): void {
		$string = static fn($max = 2000) => ['type'=>'string','maxLength'=>$max];
		$score = ['type'=>'number','nullable'=>true,'sentinel'=>'unavailable','minimum'=>0,'maximum'=>10];
		$scores = ['type'=>'object','additionalProperties'=>false,'properties'=>array_fill_keys(['gym','swim','spa','cafe','clean','parking'], $score)];
		$hours = ['type'=>'object','additionalProperties'=>false,'properties'=>[
			'source'=>$string(),'label'=>$string(),'google'=>$string(),'checked'=>$string(100),'sourceType'=>$string(100),'basis'=>$string(),
			'open24'=>['type'=>'boolean','nullable'=>true], 'weekly'=>['type'=>'array','nullable'=>true,'maxItems'=>7,'items'=>['type'=>'array','maxItems'=>2,'items'=>$string(20)]],
		]];
		$properties = array_fill_keys(['title','name','chain','chainKey','location','url','image','imageSmall','date','dateISO','published','publishedISO','excerpt','price','padel'], $string());
		$properties += ['id'=>['type'=>'integer','minimum'=>1],'root'=>['type'=>'integer','minimum'=>1],'visits'=>['type'=>'integer','minimum'=>1],'rank'=>['type'=>'integer','minimum'=>1,'nullable'=>true],'scores'=>$scores,'overall'=>['type'=>'number','nullable'=>true,'minimum'=>0,'maximum'=>100],'band'=>['type'=>'object','additionalProperties'=>false,'properties'=>['label'=>$string(50),'color'=>$string(20)]],'lat'=>['type'=>'number','nullable'=>true,'minimum'=>-90,'maximum'=>90],'lng'=>['type'=>'number','nullable'=>true,'minimum'=>-180,'maximum'=>180],'open24'=>['type'=>'boolean','nullable'=>true],'hours'=>$hours];
		$record = ['type'=>'object','additionalProperties'=>false,'properties'=>$properties];
		$fragment = ['type'=>'object','additionalProperties'=>false,'properties'=>['id'=>['type'=>'integer','minimum'=>1],'row'=>$string(30000),'card'=>$string(30000),'reviewCard'=>$string(30000),'compareCard'=>$string(50000),'quickCard'=>$string(10000)]];
		$engine->register([
			'id'=>'gyms','name'=>'The Gym League','module'=>'Gym Dashboard','classification'=>'public_static','schemaVersion'=>1,
			'postTypes'=>['gym-review','project','service','bus_article'],'taxonomies'=>get_object_taxonomies('gym-review'),'metaKeys'=>['gym_chain','gym_location','visited_date','google_maps_url','gym_price_category','gym_open_24_hours','gym_padel_court','score_gym','score_swim','score_spa','score_cafe','cleanliness_maintenance','parking','_thumbnail_id','notes','gym_gallery','gym_visit_parent'],
			'freshness'=>'CMS changes; manually regenerate after theme, field-definition or opening-hours deployments','manifestTTL'=>60,'maxBytes'=>4194304,'maxSeconds'=>20,
			'schema'=>['type'=>'object','additionalProperties'=>false,'properties'=>[
				'gyms'=>['type'=>'array','minItems'=>1,'maxItems'=>2000,'items'=>$record], 'reviews'=>['type'=>'array','minItems'=>1,'maxItems'=>2000,'items'=>$record], 'visits'=>['type'=>'integer','minimum'=>1],
				'views'=>['type'=>'object','additionalProperties'=>false,'properties'=>array_fill_keys(['overview','league','explorer','compare','finder','map','reviews','insights'],$string(1000000))],
				'fragments'=>['type'=>'array','maxItems'=>2000,'items'=>$fragment],
				'assetVersion'=>$string(100),
			]],
			'build'=>[self::class,'build'],'count'=>static fn($data)=>count($data['gyms']), 'shell'=>[self::class,'shell'],
		]);
	}
	private static function fragment(string $html, string $xpath): array {
		$doc = new \DOMDocument(); $prior = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); libxml_clear_errors(); libxml_use_internal_errors($prior);
		$nodes = (new \DOMXPath($doc))->query($xpath); $output = [];
		foreach ($nodes as $node) $output[] = $doc->saveHTML($node);
		return $output;
	}
	public static function build(): array {
		if (!function_exists('dxgym_build_records')) throw new \RuntimeException('generation_theme_unavailable');
		$source = dxgym_build_records(); $data = ['gyms'=>[],'reviews'=>[],'visits'=>(int)$source['visits']];
		$fields = ['id','root','title','name','chain','chainKey','location','scores','overall','band','url','image','imageSmall','date','dateISO','published','publishedISO','excerpt','lat','lng','visits','price','open24','padel','rank'];
		foreach (['gyms','reviews'] as $set) foreach ($source[$set] as $item) {
			$record = [];
			foreach ($fields as $key) $record[$key] = $item[$key] ?? null;
			foreach (['lat','lng'] as $key) $record[$key] = is_numeric($record[$key]) ? (float)$record[$key] : null;
			$hours = dxgym_hours($item);
			$record['hours'] = array_replace(['source'=>'','label'=>'Hours not yet verified','google'=>'','checked'=>'','sourceType'=>'','basis'=>'','open24'=>null,'weekly'=>null], array_intersect_key($hours, array_flip(['source','label','google','checked','sourceType','basis','open24','weekly'])));
			$data[$set][] = $record;
		}
		$GLOBALS['dxgym_export_data'] = $data; $GLOBALS['dxgym_export_all'] = true;
		try {
			$state = ['view'=>'overview','q'=>'','chain'=>'','location'=>'','facility'=>'','price'=>'','open24'=>'','sort'=>'overall','page'=>1,'compare'=>[],'display'=>'map'];
			$views = [];
			foreach (['overview','league','explorer','compare','finder','map','reviews','insights'] as $view) { $state['view'] = $view; $views[$view] = dxgym_view($data, $state); }
			$rows = self::fragment($views['league'], '//table[contains(@class,"dxgym-table")]/tbody/tr');
			$compareCards = [];
			foreach (array_chunk(array_column($data['gyms'],'id'), 3) as $ids) {
				$state['view'] = 'compare'; $state['compare'] = $ids;
				foreach (self::fragment(dxgym_view($data,$state), '//article[@data-gym-record]') as $html) if (preg_match('/data-gym-record="(\d+)"/', $html, $match)) $compareCards[(int)$match[1]] = $html;
			}
			$state['compare'] = []; $fragments = []; $latest = array_column($data['gyms'], null, 'root');
			foreach ($data['reviews'] as $record) {
				$id = $record['id']; $row = '';
				foreach ($rows as $candidate) if (str_contains($candidate, esc_url($record['url']))) { $row = $candidate; break; }
				$reviewCard = dxgym_card($record, $state, true);
				$latestId = $latest[$record['root']]['id'] ?? $id;
				$reviewCard = str_replace('data-compare-id="'.$id.'"', 'data-compare-id="'.$latestId.'"', $reviewCard);
				$fragments[] = ['id'=>$id,'row'=>$row,'card'=>dxgym_card($record,$state),'reviewCard'=>$reviewCard,'compareCard'=>$compareCards[$id] ?? '', 'quickCard'=>dxgym_quick_cards([$record])];
			}
			$asset = include get_theme_file_path('build/index.asset.php');
			return $data + ['views'=>$views,'fragments'=>$fragments,'assetVersion'=>(string)$asset['version']];
		} finally { unset($GLOBALS['dxgym_export_data'],$GLOBALS['dxgym_export_all']); }
	}
	public static function shell(array $data, array $manifest, string $manifestURL): string {
		$GLOBALS['dxgym_export_data'] = $data;
		try {
			// Dedicated template has no wp_head/wp_footer/wp_body_open: never capture a session.
			ob_start(); include get_theme_file_path('templates/gym-static-shell.php'); return ob_get_clean();
		} finally { unset($GLOBALS['dxgym_export_data']); }
	}
}
