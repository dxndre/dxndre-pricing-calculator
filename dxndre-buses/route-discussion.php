<?php
if(!defined('ABSPATH'))exit;
add_action('init',static function(){register_post_type('dxb_discussion',['label'=>'Bus Route Discussions','public'=>false,'show_ui'=>true,'supports'=>['title','comments'],'capability_type'=>'post','map_meta_cap'=>true]);});
function dxb_discussion_post($route){$posts=get_posts(['post_type'=>'dxb_discussion','name'=>'route-'.strtolower($route),'post_status'=>'publish','numberposts'=>1]);return $posts[0]->ID??0;}
add_action('rest_api_init',static function(){register_rest_route('dx-buses/v1','/discussion/(?P<route>[a-zA-Z0-9]{1,6})',[
 ['methods'=>'GET','permission_callback'=>'__return_true','callback'=>static function($r){$post=dxb_discussion_post($r['route']);$comments=$post?get_comments(['post_id'=>$post,'status'=>'approve','number'=>50,'order'=>'DESC']):[];$items=[];foreach($comments as $c)$items[]=['name'=>$c->comment_author,'body'=>wp_strip_all_tags($c->comment_content),'date'=>get_comment_date('j F Y',$c)];return ['items'=>$items];}],
 ['methods'=>'POST','permission_callback'=>static function($r){$origin=$r->get_header('origin');if(!$origin||wp_parse_url($origin,PHP_URL_HOST)!==wp_parse_url(home_url(),PHP_URL_HOST))return new WP_Error('origin','Please post from this website.',['status'=>403]);return true;},'callback'=>static function($r){
  $route=strtoupper($r['route']);$valid=dxb_route_exists(strtolower($route));if(is_wp_error($valid)){if($valid->get_error_code()!=='route_not_found')return $valid;$p=dxb_route_profile($route,false);if(is_wp_error($p)||empty($p['listingType']))return new WP_Error('invalid_route','Choose a recognised route.',['status'=>400]);}
  $name=sanitize_text_field($r->get_param('name'));$body=sanitize_textarea_field($r->get_param('body'));
  if($r->get_param('website')||mb_strlen($name)<2||mb_strlen($name)>60||mb_strlen($body)<5||mb_strlen($body)>2000)return new WP_Error('invalid_comment','Use a name of 2–60 characters and a comment of 5–2,000 characters.',['status'=>400]);
  $key='dxb_comment_'.hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'unknown',wp_salt());if(get_transient($key))return new WP_Error('rate_limit','Please wait a minute before posting again.',['status'=>429]);
  $post=dxb_discussion_post($route);if(!$post){$post=wp_insert_post(['post_type'=>'dxb_discussion','post_status'=>'publish','post_title'=>'Route '.$route,'post_name'=>'route-'.strtolower($route),'comment_status'=>'open'],true);if(is_wp_error($post))return $post;}
  // All guest comments are held for the site owner's review; no notifications are sent here.
  $id=wp_insert_comment(['comment_post_ID'=>$post,'comment_author'=>$name,'comment_content'=>$body,'comment_approved'=>0,'comment_type'=>'comment']);if(!$id)return new WP_Error('save_failed','Your comment could not be saved. Please try again.',['status'=>500]);set_transient($key,1,60);return ['message'=>'Thank you. Your comment is awaiting moderation.'];
 }]
]);});
