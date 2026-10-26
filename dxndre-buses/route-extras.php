<?php
if(!defined('ABSPATH'))exit;
function dxb_extra_facts($id){
 $cache=get_transient('dxb_extra_facts_v2');
 if($cache===false){$cache=[];$html=dxb_public_source('garages','http://www.londonbusroutes.net/garages.htm');
  if(!is_wp_error($html)){$x=dxb_doc($html,true);foreach($x->query('//tr') as $row){$h=$x->query('./th',$row);$c=$x->query('./td',$row);if($h->length!==2||$c->length<2||!$x->query('.//a[@name]',$h->item(0))->length)continue;$code=trim(dxb_clean($h->item(0)),'() ');if($code==='TK')continue;$name=dxb_clean($h->item(1));preg_match_all('/\b(?:[A-Z]{0,3}\d{1,3}[A-Z]?|SCS)\b/',dxb_clean($c->item(1)),$m);foreach($m[0] as $route)$cache[$route]['garages'][]=$name.' ('.$code.')';}}
  $html=dxb_public_source('details','http://www.londonbusroutes.net/details.htm');
  if(!is_wp_error($html)){$x=dxb_doc($html,true);foreach(preg_split('/\r?\n/',$x->document->textContent) as $line){
   // Numeric daytime headways only; school journey counts and tram rows are excluded.
   if(preg_match('/^\s{0,3}([A-Z]{0,3}\d{1,3}[A-Z]?)\s{2,}.+?\s+[A-Z]{1,3}\s+\d+\s+\d+\s+\d+\s+[\d-]+\s+([\d-]+)\s+([\d-]+)\s+([\d-]+)\s+\d{2}\/\d{2}\/\d{2}/',$line,$m)&&!isset($cache[$m[1]]['frequency'])&&preg_match('/^\d+(?:-\d+)?$/',$m[2])){$f=[];foreach([2=>'Mon–Sat',3=>'Sunday',4=>'Evening'] as $n=>$label)if(preg_match('/^\d+(?:-\d+)?$/',$m[$n]))$f[]=$label.' every '.$m[$n].' min';$cache[$m[1]]['frequency']=implode(' · ',$f);}
  }}set_transient('dxb_extra_facts_v2',$cache,HOUR_IN_SECONDS);
 }
 $facts=$cache[$id]??[];if(isset($facts['garages']))$facts['garages']=array_values(array_unique($facts['garages']));
 // Community reference snapshot, retrieved 14 October 2026. Linked on each badge.
 $cross=explode(' ','20 80 81 96 107 116 117 142 150 166 167 203 215 216 217 233 235 246 258 275 279 290 292 293 298 313 317 324 327 331 370 372 375 397 403 404 405 406 407 411 418 428 434 439 462 464 465 466 467 491 492 498 601 608 626 635 667 677 685 699 B12 K3 R5 R10 R68 S1 S2 W14 N279');
 if(in_array($id,$cross,true)){$facts['crossBoundary']=true;$facts['crossBoundarySource']='https://bus-routes-in-london.fandom.com/wiki/List_of_TfL_bus_routes_that_cross_the_Greater_London_boundary';}
 $records=['SL7'=>'Longest London Route','N199'=>'Longest Night Route','389'=>'Shortest London Route','631'=>'Shortest School Route','N97'=>'Shortest Night Route','9'=>'Among London’s Oldest Routes','11'=>'Among London’s Oldest Routes','12'=>'Among London’s Oldest Routes','24'=>'Oldest Unchanged Route','R5'=>'Least Frequent Regular Route','R10'=>'Least Frequent Regular Route','969'=>'London’s Mobility Route'];
 if(isset($records[$id])){$facts['record']=$records[$id];$facts['recordSource']='https://bus-routes-in-london.fandom.com/wiki/London_Bus_Route_Records';}return $facts;
}
function dxb_destination_matches($row,$stop){
 if(!empty($row['destinationId']))return $row['destinationId']===($stop['id']??'');
 $normalise=static fn($v)=>trim(preg_replace('/\s+/u',' ',preg_replace('/\b(?:bus station|station|stn)\b/iu','',mb_strtolower($v))));
 $names=array_map($normalise,preg_split('~\s*[/,]\s*~u',$stop['name']??''));
 $destinations=array_map($normalise,preg_split('~\s*[/,]\s*~u',$row['destination']??''));
 return (bool)array_intersect(array_filter($names),array_filter($destinations));
}
function dxb_short_working($row,$selected,$sequences){
 $candidates=[];
 foreach($sequences as $seq){$stops=$seq['stopPoint']??[];$ids=array_column($stops,'id');if(!in_array($selected,$ids,true)||count($ids)!==count(array_unique($ids)))continue;$candidates[]=$stops;}
 if(!$candidates)return false;
 // Keep legitimate branch termini. Only a confirmed interior destination ID is evidence of early termination; terminal approach/set-down stops are excluded.
 foreach($candidates as $stops)if(dxb_destination_matches($row,end($stops)))return false;
 foreach($candidates as $stops){$index=array_search($selected,array_column($stops,'id'),true);foreach($stops as $i=>$s)if($i>=$index&&$i<count($stops)-2&&!empty($row['destinationId'])&&$row['destinationId']===($s['id']??''))return true;}
 return false;
}
function dxb_arrival_positions($id,$stop,$rows){
 $all=dxb_tfl('/Line/'.rawurlencode($id).'/Arrivals',30);if(is_wp_error($all))return $rows;$sequences=[];
 foreach($rows as &$row){$dir=$row['direction'];if(!in_array($dir,['inbound','outbound'],true))continue;
  if(!isset($sequences[$dir])){$data=dxb_tfl('/Line/'.rawurlencode($id).'/Route/Sequence/'.$dir.'?excludeCrowding=true',HOUR_IN_SECONDS);$sequences[$dir]=is_wp_error($data)?[]:($data['stopPointSequences']??[]);}
  $row['shortWorking']=dxb_short_working($row,$stop,$sequences[$dir]);
  $vehicle=array_values(array_filter($all,static fn($a)=>($a['vehicleId']??'')===$row['vehicle']&&($a['tripId']??'')===$row['trip']&&($a['direction']??'')===$dir&&strtotime($a['expectedArrival']??'')>=time()-15));usort($vehicle,static fn($a,$b)=>strcmp($a['expectedArrival'],$b['expectedArrival']));$next=$vehicle[0]??null;
  $row['nextStop']=$next['stationName']??'';$away=[];$remaining=[];
  foreach($sequences[$dir] as $seq){$stops=$seq['stopPoint']??[];$ids=array_column($stops,'id');if(count($ids)!==count(array_unique($ids)))continue; // Loops cannot be disambiguated from predictions alone.
   $selected=array_search($stop,$ids,true);$nextIndex=array_search($next['naptanId']??'',$ids,true);if($selected===false)continue;
   if($nextIndex!==false&&$nextIndex<=$selected)$away[]=$selected-$nextIndex;
   // Match an explicit destination ID or a unique exact stop name (optionally prefixed by an area).
   $matches=[];foreach($stops as $i=>$s)if(dxb_destination_matches($row,$s))$matches[]=$i;$end=count($matches)===1?$matches[0]:false;
   if($end!==false&&$end>=$selected)$remaining[]=$end-$selected;
  }
  if(count(array_unique($away))===1)$row['stopsAway']=$away[0];if(count(array_unique($remaining))===1)$row['destinationStops']=$remaining[0];
 }unset($row);return $rows;
}
add_action('rest_api_init',static function(){register_rest_route('dx-buses/v1','/running/(?P<route>[a-zA-Z0-9]{1,6})',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>static function($r){$id=strtolower($r['route']);$valid=dxb_route_exists($id);if(is_wp_error($valid))return $valid;$data=dxb_tfl('/Line/'.rawurlencode($id).'/Arrivals',30);if(is_wp_error($data))return $data;$vehicles=[];foreach($data as $a)if(!empty($a['vehicleId'])&&strtolower($a['lineId']??'')===$id&&strtotime($a['expectedArrival']??'')>time()&&strtotime($a['timestamp']??'')>time()-180)$vehicles[$a['vehicleId']]=true;return ['count'=>count($vehicles),'vehicles'=>array_map('strval',array_keys($vehicles)),'checked'=>gmdate('c')];}]);});
