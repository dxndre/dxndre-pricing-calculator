<?php
if (!defined('WP_CLI') || !WP_CLI) exit('WP-CLI only.');
$measure=[];
$q=get_num_queries();$t=hrtime(true);$data=dxgym_data();$state=dxgym_state([]);$html=dxgym_view($data,$state);$measure['compatibility_component']=['ms'=>round((hrtime(true)-$t)/1e6,2),'queries'=>get_num_queries()-$q,'peak_bytes'=>memory_get_peak_usage(true),'gyms'=>count($data['gyms']),'reviews'=>count($data['reviews']),'html_bytes'=>strlen($html)];
$q=get_num_queries();$t=hrtime(true);$m=dx_data_engine()->generate('gyms');$measure['controlled_generation']=['ms'=>round((hrtime(true)-$t)/1e6,2),'queries'=>get_num_queries()-$q,'peak_bytes'=>memory_get_peak_usage(true),'unchanged'=>$m['unchanged']];
$file=dx_data_engine()->public.'/gyms/'.$m['current']['file'];$measure['publication']=['json_bytes'=>filesize($file),'gzip_bytes'=>filesize($file.'.gz'),'shell_bytes'=>filesize(dirname($file).'/index.html'),'manifest_bytes'=>filesize(dirname($file).'/manifest.json')];
echo wp_json_encode($measure,JSON_PRETTY_PRINT);
