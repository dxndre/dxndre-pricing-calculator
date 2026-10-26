<?php
if (!defined('ABSPATH')) exit;
function dxb_doc($html,$legacy=false) {
    $doc=new DOMDocument();$old=libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="'.($legacy?'Windows-1252':'UTF-8').'">'.$html,LIBXML_NONET);
    libxml_clear_errors();libxml_use_internal_errors($old);return new DOMXPath($doc);
}
function dxb_clean($node) {return trim(preg_replace('/\s+/u',' ',str_replace("\xc2\xa0",' ',$node->textContent)));}
function dxb_public_source($name,$url,$ttl=86400) {
    $key='dxb_source_'.$name; $cached=get_transient($key);if($cached!==false)return $cached;
    $stale=get_transient($key.'_stale');
    if(get_transient($key.'_retry'))return $stale ?: new WP_Error('source_unavailable','Source temporarily unavailable.',['status'=>503]);
    set_transient($key.'_retry',1,60);
    $r=wp_remote_get($url,['timeout'=>12,'limit_response_size'=>2500000]);
    if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200)return $stale ?: new WP_Error('source_unavailable','Source temporarily unavailable.',['status'=>503]);
    $body=wp_remote_retrieve_body($r);if(strlen($body)<100)return new WP_Error('source_empty','Source temporarily unavailable.',['status'=>503]);
    set_transient($key,$body,$ttl);set_transient($key.'_stale',$body,7*DAY_IN_SECONDS);delete_transient($key.'_retry');return $body;
}
function dxb_parse_catalog($html,$wiki,$details) {
    $x=dxb_doc($html,true);$out=[];
    preg_match('/Information correct to\s+([0-9]+ [A-Za-z]+ [0-9]{4})/',$x->document->textContent,$date);
    foreach($x->query('//tr') as $row) {
        $c=$x->query('./td',$row);if($c->length!==3)continue;
        $a=$x->query('.//a[@name]',$c->item(0))->item(0);if(!$a)continue;
        $id=strtoupper(dxb_clean($a));if(!preg_match('/^(?:[A-Z]{0,3}\d{1,3}[A-Z]?|SCS)$/',$id))continue;
        $anchor=$a->getAttribute('name');if(str_starts_with($anchor,'T'))continue; // Tramlink has its own numbered services.
        $kind=str_starts_with($anchor,'S')?'School variant':(str_starts_with($anchor,'N')?'Night service':'Main service');
        $colour=strtolower(ltrim($c->item(0)->getAttribute('bgcolor'),'#'));
        $vehicle=in_array($colour,['afdfff','00ff00'])?'Single deckers':($colour==='ff0000'?'Double deckers':'');
        $copy=$c->item(1)->cloneNode(true);foreach(iterator_to_array($copy->getElementsByTagName('font')) as $font)if(in_array(strtolower($font->getAttribute('color')),['blue','0000ff','green','007f00']))$font->parentNode?->removeChild($font);
        foreach(iterator_to_array($copy->getElementsByTagName('br')) as $br){while($br->nextSibling)$br->parentNode->removeChild($br->nextSibling);}
        $path=dxb_clean($copy);$points=preg_split('/\s+-\s+/u',$path);$endpoints=count($points)>1?reset($points).' ↔ '.end($points):$path;
        $circular=false;$oneWay=false;$limitations=[];
        foreach($x->query('.//font',$c->item(1)) as $note){
            $colourNote=strtolower($note->getAttribute('color'));if(!in_array($colourNote,['blue','0000ff']))continue;
            $noteCopy=$note->cloneNode(true);foreach(iterator_to_array($noteCopy->getElementsByTagName('br')) as $br)$br->parentNode->replaceChild($br->ownerDocument->createTextNode(' '),$br);$noteText=dxb_clean($noteCopy);
            if(preg_match('/circular/i',$noteText))$circular=true;if(preg_match('/in this direction only/i',$noteText))$oneWay=true;
            if(preg_match('/no [^.]*service|peak hours|operates .*only|(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|school days).*only/i',$noteText))$limitations[]=$noteText;
        }
        $out[$id]['workings'][]=['label'=>$kind,'destinations'=>$endpoints,'operator'=>dxb_clean($c->item(2)),'vehicle'=>$vehicle,'circular'=>$circular,'oneWay'=>$oneWay&&!$circular];
        if($kind==='Main service'||($kind==='Night service'&&str_starts_with($id,'N'))){$out[$id]['circular']=($out[$id]['circular']??false)||$circular;$out[$id]['serviceNotes']=array_merge($out[$id]['serviceNotes']??[],$limitations);}

        foreach($x->query('.//font',$c->item(1)) as $note){$noteText=dxb_clean($note);if(preg_match('/school|short working|some journeys/i',$noteText))$out[$id]['workingNotes'][]=$noteText;}
        $out[$id]['operators'][]=dxb_clean($c->item(2));
        if($vehicle)$out[$id]['vehicles'][]=$vehicle;
        $out[$id]['schoolVariant']=($out[$id]['schoolVariant']??false)||$kind==='School variant';
        $out[$id]['allDay']=($out[$id]['allDay']??false)||($kind==='Night service'&&!str_starts_with($id,'N'));
        $out[$id]['source']='http://www.londonbusroutes.net/routes.htm#'.$anchor;
        $out[$id]['updated']=$date[1]??'Source date unavailable';
    }
    // Read the explicitly listed vehicle models as supplementary allocation evidence.
    if($details){$dx=dxb_doc($details,true);$text=$dx->document->textContent;$route=null;
        foreach(preg_split('/\r?\n/',$text) as $line){
            if(preg_match('/^\s{0,3}([A-Z]{0,3}\d{1,3}[A-Z]?|SCS)\s{2,}(.{1,29})/',$line,$m)){$route=$m[1];$model=$m[2];}
            elseif($route&&preg_match('/^ {5}([A-Za-z0-9][^*]{2,28})/',$line,$m)){$model=$m[1];}else{if(trim($line)===''||str_starts_with(trim($line),'---'))$route=null;continue;}
            if(!isset($out[$route]))continue;
            if(preg_match('/Enviro400|Gemini|New Bus for London|Electroliner|\bE40|\bB5LH|\bB9TL|\bB7TL|DB300|B11E02/i',$model))$out[$route]['vehicles'][]='Double deckers';
            if(preg_match('/Enviro200|Streetlite|\bE20|Citaro|Metrocity|\bSolo\b|GB Kite/i',$model))$out[$route]['vehicles'][]='Single deckers';
        }
    }
    if($wiki){$wx=dxb_doc($wiki);
        foreach(['Former_routes'=>'former','Future_routes'=>'future','Temporary_routes'=>'temporary','Silvertown_Tunnel_cycle_shuttle'=>'shuttle'] as $section=>$type){
            $table=$wx->query('//*[@id="'.$section.'"]/following::table[1]')->item(0);if(!$table)continue;
            foreach($wx->query('.//tr',$table) as $row){$cells=$wx->query('./td',$row);if($cells->length<3)continue;
                $id=preg_replace('/\[.*?\]/','',dxb_clean($cells->item(0)));if(!preg_match('/^(?:[A-Z]{0,3}\d{1,3}[A-Z]?|SCS)$/',$id))continue;
                $notes=preg_replace('/\[.*?\]/','',dxb_clean($cells->item($cells->length-1)));
                $out[$id]['listingType']=$type;
                if($type==='former'&&$cells->length>=5){
                    $out[$id]['withdrawnDate']=trim(preg_replace('/\[.*?\]/','',dxb_clean($cells->item(3))));
                    foreach($wx->query('.//a[@href]',$cells->item(0)) as $historyLink){$href=$historyLink->getAttribute('href');if(preg_match('~^(?:https://en.wikipedia.org)?(?:/wiki/|\./)(London_Buses_route_[A-Za-z0-9_]+)$~',$href,$hm))$out[$id]['historySource']='https://en.wikipedia.org/wiki/'.$hm[1];}
                }
                $out[$id]['listingNotes']=$notes;
                $out[$id]['listingEndpoints']=dxb_clean($cells->item(1)).' ↔ '.dxb_clean($cells->item(2));
                $out[$id]['listingSource']='https://en.wikipedia.org/wiki/List_of_bus_routes_in_London#'.$section;
                if(in_array($type,['temporary','shuttle'])&&$cells->length>4)$out[$id]['operators'][]=dxb_clean($cells->item(3));
            }
        }
    }
    foreach($out as &$entry){$entry['serviceNotes']=array_values(array_unique($entry['serviceNotes']??[]));$entry['workingNotes']=array_values(array_unique($entry['workingNotes']??[]));$entry['operators']=array_values(array_unique($entry['operators']??[]));$v=array_unique($entry['vehicles']??[]);$entry['vehicle']=count($v)>1?'Single and double deckers':(reset($v)?:'Vehicle type not confirmed');unset($entry['vehicles']);}
    return $out;
}
function dxb_catalog() {
    $cached=get_transient('dxb_catalog_v5');if($cached!==false)return $cached;
    $routes=dxb_public_source('routes','http://www.londonbusroutes.net/routes.htm');if(is_wp_error($routes))return $routes;
    $wiki=dxb_public_source('wiki','https://en.wikipedia.org/wiki/List_of_bus_routes_in_London');
    $details=dxb_public_source('details','http://www.londonbusroutes.net/details.htm');
    $data=dxb_parse_catalog($routes,is_wp_error($wiki)?'':$wiki,is_wp_error($details)?'':$details);
    if(count($data)<100)return new WP_Error('catalog_format','Route directory temporarily unavailable.',['status'=>503]);
    set_transient('dxb_catalog_v5',$data,HOUR_IN_SECONDS);return $data;
}
/** Read only explicit operating dates; never infer an opening year from a route number. */
function dxb_route_history($id,$profile){
    if($id==='82')return ['tenure'=>'1986–2017','start'=>'21 June 1986','end'=>'1 April 2017','source'=>'https://www.londonbuses.co.uk/_routes/withdrawn/082-3.html'];
    $history=['end'=>$profile['withdrawnDate']??'','source'=>$profile['listingSource']??''];
    if(empty($profile['historySource']))return $history;
    $html=dxb_public_source('history_'.strtolower($id),$profile['historySource']);if(is_wp_error($html))return $history;
    $x=dxb_doc($html);$start='';$end='';
    foreach($x->query('//table[contains(concat(" ",normalize-space(@class)," ")," infobox ")]//tr') as $row){$th=$x->query('./th',$row)->item(0);$td=$x->query('./td',$row)->item(0);if(!$th||!$td)continue;$label=strtolower(dxb_clean($th));$value=trim(preg_replace('/\[.*?\]|\([^)]*\)/','',dxb_clean($td)));if(in_array($label,['began service','commenced service','first service','introduced']))$start=$value;if(in_array($label,['ended service','last service','withdrawn']))$end=$value;}
    if(preg_match('/\b((?:18|19|20)\d{2})\b/',$start,$sy)&&preg_match('/\b((?:19|20)\d{2})\b/',$end,$ey)&&$sy[1]<=$ey[1]){
        if(preg_match('/\b((?:19|20)\d{2})\b/',$history['end'],$listed)&&$listed[1]!==$ey[1])return $history;
        return ['tenure'=>$sy[1].'–'.$ey[1],'start'=>$start,'end'=>$end,'source'=>$profile['historySource']];
    }
    return $history;
}
function dxb_route_profile($id,$live=true) {
    $catalog=dxb_catalog();if(is_wp_error($catalog))return $catalog;
    $id=strtoupper($id);$p=$catalog[$id]??[];
    $type=$p['listingType']??'regular';
    if($live&&in_array($type,['former','future']))$type='regular';
    if($type==='regular'){
        if(preg_match('/^SL\d+$/',$id))$type='superloop';elseif(preg_match('/^EL\d+$/',$id))$type='eastlondon';elseif(preg_match('/^BL\d+$/',$id))$type='bakerloop';elseif(preg_match('/^N\d+$/',$id))$type='night';elseif(preg_match('/^9\d{2}$/',$id))$type='mobility';elseif(preg_match('/^6\d{2}$/',$id))$type='school';elseif($p['allDay']??false)$type='24hour';
    }
    if(in_array($id,['7X','18X','23X','36X','148X','Y316']))$type='carnival';
    if($id==='SCS')$type='shuttle';
    $p['badgeType']=$type;if($type==='former')$p['history']=dxb_route_history($id,$p);$p['allDay']=($p['allDay']??false)||$id==='EL1';$p['name']=$id;return array_merge($p,dxb_extra_facts($id));
}
add_action('rest_api_init',static function(){register_rest_route('dx-buses/v1','/profile/(?P<route>[a-zA-Z0-9]{1,6})',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>static function($r){$live=dxb_route_exists(strtolower($r['route']));if(is_wp_error($live)&&$live->get_error_code()!=='route_not_found')return $live;return dxb_route_profile($r['route'],!is_wp_error($live));}]);});
