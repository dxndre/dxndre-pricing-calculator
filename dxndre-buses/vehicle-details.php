<?php
if(!defined('ABSPATH'))exit;
// Known model families fill gaps in fleet source body-style metadata.
function dxb_vehicle_decker_type($vehicle) {
 if (preg_match('/Volvo\s+BZL\s+MCV\b|Enviro(?:100|200)(?:EV)?\b|StreetLite\b|MetroCity\b|GB\s*Kite\b|Irizar\s+ie\s*tram\s*12\b|BYD\s+B12\b/i', $vehicle['model'] ?? '')) {
  $vehicle['type'] = 'Single decker';
 }
 return $vehicle;
}
/** A vehicle allocation is shown only when the fleet source actually confirms it. */
function dxb_vehicle_garage_label($garage) {
 if (is_string($garage)) return sanitize_text_field($garage);
 if (!is_array($garage)) return '';
 $name=sanitize_text_field($garage['name']??$garage['label']??'');
 $code=sanitize_text_field($garage['code']??'');
 return $name ? $name.($code&&stripos($name,'('.$code.')')===false?' ('.$code.')':'') : '';
}
function dxb_vehicle_info($r){
 $reg=strtoupper(preg_replace('/\s+/','',$r['vehicle']));$key='dxb_vehicle_v3_'.md5($reg);$cached=get_transient($key);if($cached!==false)return dxb_vehicle_decker_type($cached);
 $response=wp_remote_get('https://bustimes.org/api/vehicles/?reg='.rawurlencode($reg),['timeout'=>8,'limit_response_size'=>200000]);
 if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)return new WP_Error('vehicle_unavailable','Fleet details are temporarily unavailable.',['status'=>503]);
 $data=json_decode(wp_remote_retrieve_body($response),true);$matches=array_values(array_filter($data['results']??[],static fn($v)=>strtoupper(str_replace(' ','',$v['reg']??''))===$reg));
 $result=['registration'=>$reg,'found'=>false];
 if(count($matches)===1){$v=$matches[0];$t=$v['vehicle_type']??[];$result+=['fleet'=>$v['fleet_code']?:($v['fleet_number']??''),'model'=>$t['name']??'','type'=>$t['style']??'','fuel'=>$t['fuel']??'','garage'=>dxb_vehicle_garage_label($v['garage']??null),'source'=>'https://bustimes.org/vehicles/'.rawurlencode($v['slug']),'withdrawn'=>(bool)($v['withdrawn']??false)];$result['found']=true;$features=wp_json_encode($v['special_features']??'').' '.($v['notes']??'');$result['usb']=preg_match('/\bUSB\b/i',$features)?(preg_match('/(?:no|without)\s+USB/i',$features)?false:true):null;}
 $result=dxb_vehicle_decker_type($result);set_transient($key,$result,$result['found']?DAY_IN_SECONDS:HOUR_IN_SECONDS);return $result;
}
add_action('rest_api_init',static function(){
 register_rest_route('dx-buses/v1','/vehicle/(?P<vehicle>[a-zA-Z0-9]{3,12})',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>'dxb_vehicle_info']);
 register_rest_route('dx-buses/v1','/vehicle/(?P<vehicle>[a-zA-Z0-9]{3,12})/arrivals',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>static function($r){$data=dxb_tfl('/Vehicle/'.rawurlencode($r['vehicle']).'/Arrivals',30);if(is_wp_error($data))return $data;$out=[];foreach($data as $a)$out[]=['route'=>$a['lineName']??'','stop'=>$a['stationName']??'','location'=>$a['currentLocation']??'','destination'=>$a['destinationName']??'','expected'=>$a['expectedArrival']??''];usort($out,static fn($a,$b)=>strcmp($a['expected'],$b['expected']));return ['arrivals'=>array_slice($out,0,8),'checked'=>gmdate('c')];}]);
 register_rest_route('dx-buses/v1','/directions/(?P<route>[a-zA-Z0-9]{1,6})',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>static function($r){$valid=dxb_route_exists(strtolower($r['route']));if(is_wp_error($valid))return $valid;$out=[];$arrivals=dxb_tfl('/Line/'.rawurlencode(strtolower($r['route'])).'/Arrivals',30);foreach(['inbound','outbound'] as $dir){$d=dxb_tfl('/Line/'.rawurlencode(strtolower($r['route'])).'/Route/Sequence/'.$dir.'?excludeCrowding=true',HOUR_IN_SECONDS);if(is_wp_error($d))continue;$seqs=$d['stopPointSequences']??[];usort($seqs,static fn($a,$b)=>count($b['stopPoint']??[])<=>count($a['stopPoint']??[]));$stops=$seqs[0]['stopPoint']??[];$last=end($stops);if(!empty($last['name'])) {
 $name=$last['name'];$names=[];
 if(!is_wp_error($arrivals))foreach($arrivals as $a)if(($a['direction']??'')===$dir && !empty($a['destinationName']) && ($a['destinationNaptanId']??'')===($last['id']??'')){$label=trim(explode(',', $a['destinationName'])[0]);$names[$label]=($names[$label]??0)+1;}
 if($names){arsort($names);$name=array_key_first($names);}
 // TfL's stop sequence calls the Ilford terminus Hainault Street.
 if(strtolower($r['route'])==='25' && $last['name']==='Hainault Street')$name='Ilford';
 $out[$dir]='Towards '.$name;
 }}return $out;}]);
});
