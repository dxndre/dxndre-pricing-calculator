<?php
if(!defined('ABSPATH'))exit;
function dxb_consultation_feed(){
    $cached=get_transient('dxb_consultations_v1');if($cached!==false)return $cached;
    $stale=get_transient('dxb_consultations_stale_v1');
    if(get_transient('dxb_consultations_retry'))return $stale?array_merge($stale,['stale'=>true]):new WP_Error('consultations_unavailable','Consultations temporarily unavailable.',['status'=>503]);
    set_transient('dxb_consultations_retry',1,120);
    $hub='https://haveyoursay.tfl.gov.uk/hub-page/londons-buses-taxis-and-phvs';
    $html=dxb_public_source('consultation_hub',$hub,HOUR_IN_SECONDS);
    if(is_wp_error($html))return $html;
    $x=dxb_doc($html);$node=$x->query('//script[@id="__NEXT_DATA__"]')->item(0);
    $state=$node?json_decode($node->textContent,true):[];$state=$state['props']['pageProps']['initialState']??[];
    $token=$state['currentUser']['anonymousUser']['token']??'';$hubId=$state['hub']['hub']['id']??'';
    if(!$token||!ctype_digit((string)$hubId))return new WP_Error('consultations_format','Consultation source unavailable.',['status'=>503]);
    $url=add_query_arg(['filters'=>['parent_id'=>$hubId,'state'=>'published','visibility_mode'=>'Public'],'per_page'=>100,'filterable'=>'true','sort'=>['published_at'=>'desc']],'https://haveyoursay.tfl.gov.uk/api/v2/projects');
    $response=wp_remote_get($url,['timeout'=>12,'headers'=>['x-api-token'=>'Bearer '.$token],'limit_response_size'=>2000000]);
    $data=is_wp_error($response)?null:json_decode(wp_remote_retrieve_body($response),true);
    if(!is_array($data['data']??null))return $stale?array_merge($stale,['stale'=>true]):new WP_Error('consultations_unavailable','Consultations temporarily unavailable.',['status'=>503]);
    $items=[];
    foreach($data['data'] as $row){$a=$row['attributes']??[];if(($a['visibility-mode']??'')!=='Public')continue;
        $title=wp_strip_all_tags($a['name']??'');$description=html_entity_decode(wp_strip_all_tags($a['description']??''),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $text=$title.'. '.$description;$routes=[];
        preg_match_all('/\broutes?\s+((?:(?:[A-Z]{1,3})?\d{1,3}[A-Z]?(?:\s*[,\/&]\s*|\s+(?:and|or)\s+)?)+)/i',$text,$matches);
        foreach($matches[1] as $list){preg_match_all('/\b(?:[A-Z]{1,3})?\d{1,3}[A-Z]?\b/i',$list,$ids);$routes=array_merge($routes,array_map('strtoupper',$ids[0]));}
        if(!$routes)continue;
        $slug=$a['permalink']??'';if(!preg_match('/^[a-zA-Z0-9_-]+$/',$slug))continue;
        $status=preg_match('/consultation (?:has (?:now )?(?:closed|concluded)|is (?:now )?closed)/i',$description)?'Consultation closed':'Consultation · check current status';
        $items[]=['title'=>$title,'routes'=>array_values(array_unique($routes)),'status'=>$status,'url'=>'https://haveyoursay.tfl.gov.uk/'.$slug,'published'=>$a['published-at']??''];
    }
    $result=['items'=>$items,'source'=>$hub,'checked'=>gmdate('c'),'stale'=>false];
    set_transient('dxb_consultations_v1',$result,HOUR_IN_SECONDS);set_transient('dxb_consultations_stale_v1',$result,DAY_IN_SECONDS);delete_transient('dxb_consultations_retry');return $result;
}
add_action('rest_api_init',static function(){register_rest_route('dx-buses/v1','/consultations/(?P<route>[a-zA-Z0-9]{1,6})',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>static function($r){$data=dxb_consultation_feed();if(is_wp_error($data))return $data;$id=strtoupper($r['route']);$data['items']=array_values(array_filter($data['items'],static fn($item)=>in_array($id,$item['routes'],true)));return $data;}]);});
