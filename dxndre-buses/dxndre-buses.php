<?php
/**
 * Plugin Name: DXNDRE Buses
 * Description: A photographic bus journal, route explorer and TfL arrivals, styled for DXNDRE.
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) exit;

function dxb_meta($id, $key) { return (string) get_post_meta($id, $key, true); }
function dxb_journeys() {
    $items = [];
    foreach (get_posts(['post_type'=>'bus-diary-entry','numberposts'=>50,'post_status'=>'publish','orderby'=>'date','order'=>'DESC']) as $p) {
        $items[] = ['id'=>$p->ID,'title'=>$p->post_title,'route'=>dxb_meta($p->ID,'primary_route') ?: $p->post_title,'url'=>get_permalink($p),'image'=>get_the_post_thumbnail_url($p,'large') ?: '', 'image_id'=>get_post_thumbnail_id($p),'start'=>dxb_meta($p->ID,'journey_start_name'),'end'=>dxb_meta($p->ID,'journey_end_name'),'time'=>dxb_meta($p->ID,'time_of_day'),'date'=>get_the_date('F Y',$p),'excerpt'=>wp_strip_all_tags(get_the_excerpt($p))];
    }
    return $items;
}
function dxb_photos() {
    $items=[]; $seen=[];
    foreach (get_posts(['post_type'=>'dx_bus_photo','numberposts'=>-1,'post_status'=>'publish']) as $p) {
        if(is_page('buses') && !dxb_meta($p->ID,'dxb_route')) continue;
        $aid=get_post_thumbnail_id($p); if (!$aid) continue; $seen[$aid]=true;
        $items[]=['id'=>$aid,'title'=>$p->post_title,'route'=>strtoupper(dxb_meta($p->ID,'dxb_route')),'image'=>wp_get_attachment_image_url($aid,'large'),'full'=>wp_get_attachment_image_url($aid,'full'),'caption'=>wp_strip_all_tags($p->post_excerpt ?: $p->post_content),'operator'=>dxb_meta($p->ID,'dxb_operator'),'vehicle'=>dxb_meta($p->ID,'dxb_vehicle'),'date'=>dxb_meta($p->ID,'dxb_date'),'location'=>dxb_meta($p->ID,'dxb_location'),'scenery'=>dxb_meta($p->ID,'dxb_scenery'),'bad'=>dxb_meta($p->ID,'dxb_bad')==='1','captured'=>!empty(wp_get_attachment_metadata($aid)['image_meta']['created_timestamp'])?gmdate('Y-m-d',(int)wp_get_attachment_metadata($aid)['image_meta']['created_timestamp']):dxb_meta($p->ID,'dxb_date'),'technical'=>dxb_photo_technical($aid),'alt'=>get_post_meta($aid,'_wp_attachment_image_alt',true) ?: $p->post_title];
    }
    foreach (dxb_journeys() as $j) {
        if (!$j['image_id'] || isset($seen[$j['image_id']])) continue;
        $seen[$j['image_id']]=true;
        $metadata=wp_get_attachment_metadata($j['image_id']);
        $captured=!empty($metadata['image_meta']['created_timestamp']) ? gmdate('Y-m-d',(int)$metadata['image_meta']['created_timestamp']) : '';
        $items[]=['id'=>$j['image_id'],'title'=>'From the '.$j['route'].' diary','route'=>$j['route'],'image'=>$j['image'],'full'=>wp_get_attachment_image_url($j['image_id'],'full'),'caption'=>$j['start'].' → '.$j['end'],'operator'=>'','vehicle'=>'','date'=>'','location'=>'','captured'=>$captured,'technical'=>dxb_photo_technical($j['image_id']),'alt'=>get_post_meta($j['image_id'],'_wp_attachment_image_alt',true) ?: 'Photograph from the '.$j['route'].' bus diary'];
    }
    return $items;
}
function dxb_register_content() {
    register_post_type('dx_bus_photo',['labels'=>['name'=>'Photography','singular_name'=>'Photograph','add_new_item'=>'Add photograph'],'public'=>false,'show_ui'=>true,'show_in_rest'=>true,'menu_icon'=>'dashicons-camera','supports'=>['title','editor','excerpt','thumbnail'],'capability_type'=>'post','map_meta_cap'=>true]);
    register_post_type('dx_bus_article',['labels'=>['name'=>'Bus articles','singular_name'=>'Bus article','add_new_item'=>'Add bus article'],'public'=>true,'show_in_rest'=>true,'menu_icon'=>'dashicons-media-document','supports'=>['title','editor','excerpt','thumbnail','revisions'],'rewrite'=>['slug'=>'buses/articles'],'has_archive'=>false]);
}
add_action('init','dxb_register_content');
add_action('add_meta_boxes',function(){
    add_meta_box('dxb-photo-details','Photograph details',function($p){
        wp_nonce_field('dxb_photo_save','dxb_photo_nonce');
        echo '<p>Set the photograph as the featured image. Published photographs appear automatically on Photography. Add a route to also include them in the bus archive.</p>';
        echo '<p><label><input type="checkbox" name="dxb_bad" value="1" '.checked(dxb_meta($p->ID,'dxb_bad'),'1',false).'> 💩 Bad photo — replacement planned</label></p>';
        echo '<p><label>Scenery category<br><select name="dxb_scenery"><option value="">Not categorised</option>'; foreach(['city'=>'City','suburbs'=>'Suburbs','rural'=>'Rural','coast'=>'Coast'] as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected(dxb_meta($p->ID,'dxb_scenery'),$value,false).'>'.esc_html($label).'</option>'; echo '</select></label></p>';
        foreach(['route'=>'Route (e.g. N8)','operator'=>'Operator when photographed','vehicle'=>'Registration / fleet number','date'=>'Date photographed','location'=>'Location'] as $key=>$label) {
            echo '<p><label>'.esc_html($label).'<br><input style="width:100%" name="dxb_'.esc_attr($key).'" type="'.($key==='date'?'date':'text').'" value="'.esc_attr(dxb_meta($p->ID,'dxb_'.$key)).'"></label></p>';
        }
    },'dx_bus_photo','normal');
});
add_action('save_post_dx_bus_photo',function($id){
    if (!isset($_POST['dxb_photo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dxb_photo_nonce'])),'dxb_photo_save') || !current_user_can('edit_post',$id) || wp_is_post_revision($id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return;
    $scenery=sanitize_key(wp_unslash($_POST['dxb_scenery']??'')); update_post_meta($id,'dxb_scenery',in_array($scenery,['city','suburbs','rural','coast'],true)?$scenery:'');
    update_post_meta($id,'dxb_bad',isset($_POST['dxb_bad'])?'1':'');
    foreach(['route','operator','vehicle','date','location'] as $key) if(isset($_POST['dxb_'.$key])) update_post_meta($id,'dxb_'.$key,sanitize_text_field(wp_unslash($_POST['dxb_'.$key])));
});

add_action('admin_menu',function(){
    add_options_page('Bus explorer','Bus explorer','manage_options','dxb-settings',function(){
        if(!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>Bus explorer</h1><p>The explorer uses TfL’s public API with shared caching. Add a valid TfL application key here to use your subscription. This setting is separate from the older diary integration.</p><form method="post" action="options.php">';
        settings_fields('dxb_settings'); do_settings_sections('dxb-settings'); submit_button(); echo '</form></div>';
    });
});
add_action('admin_init',function(){
    register_setting('dxb_settings','dxb_tfl_app_key',['type'=>'string','sanitize_callback'=>function($value){$value=sanitize_text_field($value);return $value ?: get_option('dxb_tfl_app_key','');},'show_in_rest'=>false]);
    add_settings_section('dxb_api','TfL connection','__return_false','dxb-settings');
    add_settings_field('dxb_tfl_app_key','Application key',function(){echo '<input type="password" name="dxb_tfl_app_key" value="" autocomplete="new-password" class="regular-text"><p class="description">'.(get_option('dxb_tfl_app_key')?'A key is saved. Leave blank to keep it.':'Optional. Public access is currently used.').'</p>';},'dxb-settings','dxb_api');
});

function dxb_tfl($path,$ttl=300) {
    $key='dxb_'.md5($path); $cached=get_transient($key);
    if ($cached!==false) return $cached;
    if (get_transient($key.'_failed')) return new WP_Error('tfl_unavailable','TfL information is temporarily unavailable. Please try again shortly.',['status'=>503]);
    if (get_transient($key.'_lock')) return new WP_Error('tfl_busy','Route information is loading. Please try again in a moment.',['status'=>503]);
    set_transient($key.'_lock',1,20);
    $url='https://api.tfl.gov.uk'.$path;
    $app_key=defined('DXB_TFL_APP_KEY') ? DXB_TFL_APP_KEY : get_option('dxb_tfl_app_key','');
    if ($app_key) $url=add_query_arg('app_key',$app_key,$url);
    $r=wp_remote_get($url,['timeout'=>12,'headers'=>['Accept'=>'application/json']]);
    delete_transient($key.'_lock');
    $code=is_wp_error($r)?0:wp_remote_retrieve_response_code($r);
    $data=is_wp_error($r)?null:json_decode(wp_remote_retrieve_body($r),true);
    if ($code!==200 || !is_array($data)) {
        $retry = !is_wp_error($r) ? (int) wp_remote_retrieve_header($r,'retry-after') : 0;
        set_transient($key.'_failed',1,max(30,min($retry,3600)));
        return new WP_Error('tfl_unavailable','TfL information is temporarily unavailable. Please try again shortly.',['status'=>503]);
    }
    set_transient($key,$data,$ttl); return $data;
}
function dxb_route_index() {
    $data=dxb_tfl('/Line/Mode/bus',DAY_IN_SECONDS);
    if(is_wp_error($data)) return $data;
    return array_values(array_map(function($r){return ['id'=>$r['id'],'name'=>$r['name']];},$data));
}
function dxb_route_exists($id) {
    $index=dxb_route_index(); if(is_wp_error($index)) return $index;
    foreach($index as $r) if($r['id']===$id) return true;
    return new WP_Error('route_not_found','That route was not found in the current TfL network. Try another route number.',['status'=>404]);
}

// Infer round-the-clock operation from TfL's cached public timetable.
function dxb_service_type($id, $stops) {
    if (preg_match('/^el[0-9]+$/i', $id)) return 'eastlondon';
    if (preg_match('/^sl[0-9]+$/i', $id)) return 'superloop';
    if (strtolower($id) === 'bl1') return 'bakerloop';
    if (preg_match('/^n[0-9]+$/i', $id)) return 'night';
    if (preg_match('/^6[0-9]{2}$/', $id) && $id !== '607') return 'school';
    if (empty($stops)) return 'regular';
    $data = dxb_tfl('/Line/'.rawurlencode($id).'/Timetable/'.rawurlencode($stops[0]['id']), DAY_IN_SECONDS);
    if (is_wp_error($data)) return 'regular';
    foreach (($data['timetable']['routes'] ?? []) as $route) {
        foreach (($route['schedules'] ?? []) as $schedule) {
            $hours = [];
            foreach (($schedule['knownJourneys'] ?? []) as $journey) $hours[(int)$journey['hour'] % 24] = true;
            foreach (($schedule['periods'] ?? []) as $period) {
                if (!isset($period['fromTime']['hour'], $period['toTime']['hour'])) continue;
                $start = (int)$period['fromTime']['hour']; $end = (int)$period['toTime']['hour'];
                if ($end < $start) $end += 24;
                for ($hour = $start; $hour <= $end; $hour++) $hours[$hour % 24] = true;
            }
            if (count($hours) === 24) return '24hour';
        }
    }
    return 'regular';
}

add_action('rest_api_init',function(){
    register_rest_route('dx-buses/v1','/routes',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>'dxb_route_index']);
    register_rest_route('dx-buses/v1','/route/(?P<route>[a-zA-Z0-9]{1,6})',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>function($r){
        $id=strtolower($r['route']); $valid=dxb_route_exists($id);
        if(is_wp_error($valid)) {
            if($valid->get_error_code()==='route_not_found') {
                $profile=dxb_route_profile($id,false);
                if(!is_wp_error($profile)&&isset($profile['listingType'])) return ['id'=>$id,'name'=>strtoupper($id),'direction'=>'inbound','branches'=>[$profile['listingEndpoints']],'stops'=>[],'serviceType'=>$profile['badgeType'],'source'=>'Route directory','archive'=>true,'profile'=>$profile];
            }
            foreach(dxb_journeys() as $journey) if(strtolower($journey['route'])===$id) return ['id'=>$id,'name'=>strtoupper($id),'direction'=>'inbound','description'=>'From the personal archive','branches'=>[$journey['start'].' → '.$journey['end']],'stops'=>[],'source'=>'Personal archive','archive'=>true];
            return $valid;
        }
        $dir=$r->get_param('direction') ?: 'inbound';
        if(!in_array($dir,['inbound','outbound'],true)) return new WP_Error('bad_direction','Choose a valid direction.',['status'=>400]);
        $data=dxb_tfl('/Line/'.rawurlencode($id).'/Route/Sequence/'.$dir.'?excludeCrowding=true',HOUR_IN_SECONDS);
        if(is_wp_error($data)||empty($data['stopPointSequences'])){$other=$dir==='inbound'?'outbound':'inbound';$fallback=dxb_tfl('/Line/'.rawurlencode($id).'/Route/Sequence/'.$other.'?excludeCrowding=true',HOUR_IN_SECONDS);if(!is_wp_error($fallback)&&!empty($fallback['stopPointSequences'])){$data=$fallback;$dir=$other;}}
        if(is_wp_error($data))return $data;
        $stops=[];
        foreach(($data['stopPointSequences']??[]) as $seq) foreach(($seq['stopPoint']??[]) as $s) $stops[$s['id']]=['id'=>$s['id'],'name'=>$s['name'],'lat'=>$s['lat']??null,'lon'=>$s['lon']??null];
        $branches=[];
        foreach(($data['orderedLineRoutes']??[]) as $branch) $branches[]=html_entity_decode($branch['name']??'',ENT_QUOTES|ENT_HTML5,'UTF-8');
        return ['id'=>$id,'name'=>strtoupper($id),'direction'=>$dir,'description'=>$data['lineName']??strtoupper($id),'branches'=>$branches,'stops'=>array_values($stops),'serviceType'=>dxb_service_type($id,array_values($stops)),'source'=>'TfL','retrieved'=>gmdate('c')];
    }]);
    register_rest_route('dx-buses/v1','/arrivals/(?P<route>[a-zA-Z0-9]{1,6})/(?P<stop>[a-zA-Z0-9]{5,30})',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>function($r){
        $id=strtolower($r['route']); $valid=dxb_route_exists($id); if(is_wp_error($valid)) return $valid;
        $data=dxb_tfl('/Line/'.rawurlencode($id).'/Arrivals/'.rawurlencode($r['stop']),20);
        if(is_wp_error($data)) return $data;
        $rows=[];
        foreach($data as $a) if(strtolower($a['lineId']??'')===$id) $rows[]=['destination'=>$a['destinationName']??'','expected'=>$a['expectedArrival']??'','vehicle'=>$a['vehicleId']??'','location'=>$a['currentLocation']??'','trip'=>$a['tripId']??'','direction'=>$a['direction']??'','destinationId'=>$a['destinationNaptanId']??''];
        usort($rows,function($a,$b){return strcmp($a['expected'],$b['expected']);});
        $rows=dxb_arrival_positions($id,$r['stop'],array_slice($rows,0,6));
        return ['arrivals'=>$rows,'retrieved'=>gmdate('c')];
    }]);
});

add_filter('template_include',function($template){return is_page('photography') ? __DIR__.'/templates/photography.php' : (is_page('buses') ? __DIR__.'/templates/buses.php' : $template);},99);
add_filter('body_class',function($classes){if(is_page(['buses','photography'])) $classes[]='dxb-page'; return $classes;});
add_action('wp_enqueue_scripts',function(){
    if(!is_page(['buses','photography'])) return;
    wp_localize_script('dxndre-theme','DXB',['api'=>esc_url_raw(rest_url('dx-buses/v1/')),'journeys'=>dxb_journeys(),'photos'=>dxb_photos()]);
},100);
register_activation_hook(__FILE__,function(){dxb_register_content();flush_rewrite_rules();});
register_deactivation_hook(__FILE__,'flush_rewrite_rules');

require_once __DIR__ . '/route-news.php';

require_once __DIR__ . '/route-catalog.php';
require_once __DIR__ . '/route-consultations.php';

require_once __DIR__ . '/vehicle-details.php';

require_once __DIR__.'/route-extras.php';
require_once __DIR__.'/route-discussion.php';

require_once __DIR__ . '/route-timetable.php';

// A single route-wide feed supplies approximate positions without one request per stop.
add_action('rest_api_init', function () {
    register_rest_route('dx-buses/v1', '/line-positions/(?P<route>[a-zA-Z0-9]{1,6})', [
        'methods'=>'GET', 'permission_callback'=>'__return_true',
        'callback'=>function ($request) {
            $route = strtolower($request['route']);
            $valid = dxb_route_exists($route); if (is_wp_error($valid)) return $valid;
            $data = dxb_tfl('/Line/'.rawurlencode($route).'/Arrivals', 30);
            if (is_wp_error($data)) return $data;
            usort($data, static fn($a,$b) => strcmp($a['expectedArrival'] ?? '', $b['expectedArrival'] ?? ''));
            $vehicles = [];
            foreach ($data as $row) {
                $reg = $row['vehicleId'] ?? '';
                if (!$reg || isset($vehicles[$reg]) || strtotime($row['expectedArrival'] ?? '') < time() - 15) continue;
                $vehicles[$reg] = ['registration'=>$reg, 'stopId'=>$row['naptanId'] ?? '', 'stop'=>$row['stationName'] ?? '', 'direction'=>$row['direction'] ?? '', 'destination'=>$row['destinationName'] ?? '', 'expected'=>$row['expectedArrival'] ?? ''];
            }
            return ['vehicles'=>array_values($vehicles), 'retrieved'=>gmdate('c')];
        }
    ]);
});

function dxb_photo_technical($aid) {
 $meta=wp_get_attachment_metadata($aid);$im=$meta['image_meta']??[];
 $out=[];
 if(!empty($im['camera']))$out['Camera']=$im['camera'];
 if(!empty($im['focal_length']))$out['Focal length']=round((float)$im['focal_length'],2).' mm';
 if(!empty($im['aperture']))$out['Aperture']='ƒ/'.$im['aperture'];
 if(!empty($im['iso']))$out['ISO']=(string)$im['iso'];
 if(!empty($im['shutter_speed'])){$v=(float)$im['shutter_speed'];$out['Shutter']=$v<1?'1/'.round(1/$v).' s':$v.' s';}
 if(!empty($im['created_timestamp']))$out['Taken']=gmdate('j F Y, H:i',(int)$im['created_timestamp']);
 if(!empty($meta['width']))$out['Dimensions']=$meta['width'].' × '.$meta['height'];
 $gps=get_post_meta($aid,'dxb_gps',true);if($gps)$out['gps']=$gps;
 return $out;
}
add_action('wp_enqueue_scripts',function(){if(is_page('photography'))wp_enqueue_script('dxb-photography',plugins_url('assets/photography.js',__FILE__),['dxndre-theme'],filemtime(__DIR__.'/assets/photography.js'),true);},110);

add_filter('wp_generate_attachment_metadata',function($metadata,$aid){
 if(!function_exists('exif_read_data'))return $metadata;
 $exif=@exif_read_data(get_attached_file($aid),'ANY_TAG',true);$g=$exif['GPS']??[];
 if(!empty($g['GPSLatitude'])&&!empty($g['GPSLongitude'])){
  $r=function($n){$v=explode('/',(string)$n);return count($v)>1&&floatval($v[1])?floatval($v[0])/floatval($v[1]):floatval($v[0]);};
  $d=function($v)use($r){return $r($v[0])+$r($v[1])/60+$r($v[2])/3600;};
  update_post_meta($aid,'dxb_gps',['lat'=>$d($g['GPSLatitude'])*($g['GPSLatitudeRef']==='S'?-1:1),'lon'=>$d($g['GPSLongitude'])*($g['GPSLongitudeRef']==='W'?-1:1)]);
 }return $metadata;
},10,2);
