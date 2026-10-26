<?php
/** Cached, attributed route updates from London Bus Routes. */
if (!defined('ABSPATH')) exit;
function dxb_parse_news($html) {
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="Windows-1252">' . $html, LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($doc);
    $clean = static function($node) { return trim(preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $node->textContent))); };
    preg_match('/Information correct to\s+([0-9]+ [A-Za-z]+ [0-9]{4})/', $doc->textContent, $date);
    $items = [];
    foreach ($xpath->query('//table') as $table) {
        $head = $xpath->query('.//tr[1]', $table)->item(0);
        if (!$head || stripos($head->textContent, 'Details') === false) continue;
        $category = stripos($head->textContent, 'Expected') !== false ? 'Planned change' : (stripos($head->textContent, 'Start date') !== false ? 'Diversion' : 'Recent change');
        foreach ($xpath->query('.//tr', $table) as $row) {
            $cells = $xpath->query('./td', $row);
            if ($cells->length < 3) continue;
            preg_match_all('/\b(?:[A-Z]{1,2})?\d{1,3}\b/', strtoupper($clean($cells->item(0))), $routes);
            $anchor = $xpath->query('.//a[@name]', $cells->item(1))->item(0);
            if (!$routes[0] || !$anchor) continue;
            $detail = $clean($cells->item(1));
            // Short excerpts; readers can open the complete original entry.
            $words = preg_split('/\s+/u', $detail);
            $summary = implode(' ', array_slice($words, 0, 32)) . (count($words) > 32 ? '…' : '');
            $when = $clean($cells->item(2)) ?: 'Date to be confirmed';
            if ($cells->length > 3) $when .= ' – ' . ($clean($cells->item(3)) ?: 'until further notice');
            $items[] = ['routes'=>$routes[0], 'category'=>$category,'date'=>$when,'summary'=>$summary,'url'=>'http://www.londonbusroutes.net/changes.htm#' . rawurlencode($anchor->getAttribute('name'))];
        }
    }
    if (!$items || empty($date[1])) return new WP_Error('news_format', 'The route news source could not be read.', ['status'=>503]);
    return ['items'=>$items,'updated'=>$date[1],'source'=>'http://www.londonbusroutes.net/changes.htm','stale'=>false];
}
function dxb_news($request) {
    $data = get_transient('dxb_news_v1');
    if (!$data) {
        $stale = get_transient('dxb_news_stale_v1');
        if (get_transient('dxb_news_retry_v1')) {
            if (!$stale) return new WP_Error('news_unavailable','Route news is temporarily unavailable.',['status'=>503]);
            $data=$stale; $data['stale']=true;
        } else {
            set_transient('dxb_news_retry_v1',1,MINUTE_IN_SECONDS);
            $response=wp_remote_get('http://www.londonbusroutes.net/changes.htm',['timeout'=>12,'limit_response_size'=>1500000]);
            $data=is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200 ? new WP_Error('news_unavailable','Route news is temporarily unavailable.',['status'=>503]) : dxb_parse_news(wp_remote_retrieve_body($response));
            if (is_wp_error($data)) {
                if (!$stale) return $data;
                $data=$stale; $data['stale']=true;
            } else {
                set_transient('dxb_news_v1',$data,HOUR_IN_SECONDS);
                set_transient('dxb_news_stale_v1',$data,7*DAY_IN_SECONDS);
                delete_transient('dxb_news_retry_v1');
            }
        }
    }
    $route=strtoupper($request['route']);
    $data['items']=array_values(array_filter($data['items'],static function($item) use($route){return in_array($route,$item['routes'],true);}));
    usort($data['items'], static function($a,$b) {
        $rank=['Planned change'=>0,'Diversion'=>1,'Recent change'=>2];
        $order=$rank[$a['category']] <=> $rank[$b['category']];
        if ($order) return $order;
        if ($a['category']==='Recent change') return (strtotime($b['date']) ?: 0) <=> (strtotime($a['date']) ?: 0);
        return 0;
    });
    // Five brief excerpts, with links to the complete source.
    $data['items']=array_slice($data['items'],0,5);
    return $data;
}
add_action('rest_api_init',static function(){register_rest_route('dx-buses/v1','/news/(?P<route>[a-zA-Z0-9]{1,6})',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>'dxb_news']);});
