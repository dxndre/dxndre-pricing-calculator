<?php
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with((string) wp_parse_url(home_url(), PHP_URL_HOST), '.local')) exit('Local WP-CLI only.');
$e=dx_data_engine();$post_id=0;$results=[];
try {
 $post_id=wp_insert_post(['post_type'=>'gym-review','post_status'=>'publish','post_title'=>'DX Data Engine local lifecycle fixture','post_content'=>'Local verification fixture.']);
 if(is_wp_error($post_id)||!$post_id)throw new RuntimeException('fixture_insert_failed');
 $results['publish_marks_dirty']=is_file($e->private.'/gyms.dirty');
 $e->generate('gyms');$m=$e->validate('gyms');$bundle=json_decode(file_get_contents($e->public.'/gyms/'.$m['file']),true);
 $results['published_fixture_in_dataset']=in_array($post_id,array_column($bundle['data']['gyms'],'id'),true);
 wp_update_post(['ID'=>$post_id,'post_status'=>'draft']);
 $tombstone=json_decode(file_get_contents($e->public.'/gyms/manifest.json'),true);
 $results['unpublish_fails_closed_and_purges_history']=($tombstone['available']===false&&!glob($e->public.'/gyms/dataset.*'));
 $e->generate('gyms');$m=$e->validate('gyms');$bundle=json_decode(file_get_contents($e->public.'/gyms/'.$m['file']),true);
 $results['unpublished_fixture_removed']=!in_array($post_id,array_column($bundle['data']['gyms'],'id'),true);
} finally {if($post_id)wp_delete_post($post_id,true);$e->generate('gyms');}
echo wp_json_encode($results,JSON_PRETTY_PRINT);
