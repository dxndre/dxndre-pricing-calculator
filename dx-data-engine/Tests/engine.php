<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Independent filesystem fixtures: php Tests/engine.php (does not bootstrap WordPress). */
define('ABSPATH', sys_get_temp_dir() . '/dx-test-webroot/');
function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0755, true); }
function wp_json_encode($data, $flags=0) { return json_encode($data, $flags); }
function do_action(...$args) {}
wp_mkdir_p(ABSPATH);
require dirname(__DIR__) . '/Core/Engine.php';
use DX\Data\Engine;
$base=sys_get_temp_dir().'/dx-engine-fixture-'.bin2hex(random_bytes(5));
$e=new Engine($base.'/public',$base.'/private','https://example.test/dx-data');
$data=['items'=>[['id'=>1,'title'=>'Public gym']]];
$schema=['type'=>'object','additionalProperties'=>false,'properties'=>['items'=>['type'=>'array','maxItems'=>20,'items'=>['type'=>'object','additionalProperties'=>false,'properties'=>['id'=>['type'=>'integer'],'title'=>['type'=>'string','maxLength'=>100]]]]]];
$provider=['id'=>'fixture','classification'=>'public_static','schemaVersion'=>1,'schema'=>$schema,'build'=>function()use(&$data){return $data;},'count'=>fn($v)=>count($v['items'])];
$tests=[];
function test($name,$fn){global $tests;try{$fn();$tests[$name]='PASS';}catch(Throwable $err){$tests[$name]='FAIL: '.$err->getMessage();}}
function ok($v){if(!$v)throw new RuntimeException('assertion');}
function rejects($fn,$prefix=''){try{$fn();}catch(Throwable $err){if(!$prefix||str_starts_with($err->getMessage(),$prefix))return;throw $err;}throw new RuntimeException('expected rejection');}
test('invalid identifier',fn()=>rejects(fn()=>$e->register(array_replace($provider,['id'=>'../secret']))));
test('private classification rejected',fn()=>rejects(fn()=>$e->register(array_replace($provider,['classification'=>'private']))));
test('unknown dependency rejected',fn()=>rejects(fn()=>$e->register(array_replace($provider,['dependencies'=>['missing']]))));
test('unbounded object rejected',fn()=>rejects(fn()=>$e->register(array_replace($provider,['schema'=>['type'=>'object']]))));
test('unknown required schema key rejected',fn()=>rejects(fn()=>$e->register(array_replace($provider,['schema'=>array_replace($schema,['required'=>['secret']])]))));
$e->register($provider);
test('duplicate registration rejected',fn()=>rejects(fn()=>$e->register($provider)));
test('initial generation and validation',function()use($e){$m=$e->generate('fixture');ok($m['current']['records']===1);$e->validate('fixture');});
$first=$e->metadata('fixture')['current'];
test('unchanged content does not republish',function()use($e,$first){$path=$e->public.'/fixture/manifest.json';$hash=hash_file('sha256',$path);$mtime=filemtime($path);$m=$e->generate('fixture');ok($m['unchanged']===true&&$m['current']===$first&&hash_file('sha256',$path)===$hash&&filemtime($path)===$mtime);});
test('restricted field rejected; LKG retained',function()use($e,&$data,$first){$data['items'][0]['password']='do-not-publish';rejects(fn()=>$e->generate('fixture'),'schema_extra');unset($data['items'][0]['password']);ok($e->validate('fixture')===$first);ok(!str_contains(file_get_contents($e->public.'/fixture/'.$first['file']),'do-not-publish'));});
test('empty data rejected',function()use($e,&$data){$old=$data;$data=['items'=>[]];rejects(fn()=>$e->generate('fixture'),'empty_');$data=$old;$e->validate('fixture');});
test('missing required field rejected',function()use($e,&$data){unset($data['items'][0]['title']);rejects(fn()=>$e->generate('fixture'),'schema_missing');$data['items'][0]['title']='Public gym';});
test('corruption detected and repair preserves immutable identity',function()use($e,$first){file_put_contents($e->public.'/fixture/'.$first['file'],'broken');rejects(fn()=>$e->validate('fixture'),'invalid_integrity');$e->generate('fixture');ok($e->validate('fixture')===$first);});
test('missing public manifest detected and repaired',function()use($e){unlink($e->public.'/fixture/manifest.json');rejects(fn()=>$e->validate('fixture'),'invalid_public_manifest');$e->generate('fixture');$e->validate('fixture');});
test('schema mismatch detected',function()use($e){$p=$e->public.'/fixture/manifest.json';$m=json_decode(file_get_contents($p),true);$m['schemaVersion']=99;file_put_contents($p,json_encode($m));rejects(fn()=>$e->validate('fixture'),'invalid_public_manifest');$e->generate('fixture');});
test('concurrent generation lock rejects second worker',function()use($e){$l=fopen($e->private.'/fixture.lock','c');flock($l,LOCK_EX);rejects(fn()=>$e->generate('fixture'),'generation_busy');flock($l,LOCK_UN);fclose($l);});
test('failed publication preserves committed data',function()use($e,&$data){$p=$e->public.'/fixture/manifest.json';$original=file_get_contents($p);unlink($p);mkdir($p);$data['items'][0]['title']='New version';rejects(fn()=>$e->generate('fixture'),'atomic_');rmdir($p);file_put_contents($p,$original);$e->validate('fixture');$data['items'][0]['title']='Public gym';ok(count(glob($e->public.'/fixture/dataset.*.json'))===1);});
test('bounded historical retention',function()use($e,&$data){foreach(['Two','Three','Four'] as $v){$data['items'][0]['title']=$v;$e->generate('fixture');}ok(count(glob($e->public.'/fixture/dataset.*.json'))===2);});
test('isolation and generation suspension persist',function()use($e){$e->control('fixture','consumption',false);ok(json_decode(file_get_contents($e->public.'/fixture/manifest.json'),true)['available']===false);$e->control('fixture','generation',false);rejects(fn()=>$e->generate('fixture'),'generation_suspended');ok($e->controls('fixture')===['generation'=>false,'consumption'=>false]);$e->control('fixture','generation',true);$e->control('fixture','consumption',true);$e->validate('fixture');});
test('withdrawal removes current and historical public copies',function()use($e){$e->invalidate('fixture',true);ok(!glob($e->public.'/fixture/dataset.*'));ok(empty($e->metadata('fixture')['current']));ok(json_decode(file_get_contents($e->public.'/fixture/manifest.json'),true)['available']===false);});
test('provider failure isolation',function()use($e,$provider){$e->register(array_replace($provider,['id'=>'broken','build'=>function(){throw new RuntimeException('secret SQL credentials');}]));rejects(fn()=>$e->generate('broken'),'generation_failed');ok($e->metadata('broken')['error']==='generation_failed');$e->generate('fixture');$e->validate('fixture');});
test('exception prefixes cannot leak secrets into logs',function()use($e,$provider){$e->register(array_replace($provider,['id'=>'private-error','build'=>function(){throw new RuntimeException('generation_secretSQLpassword123');}]));rejects(fn()=>$e->generate('private-error'),'generation_failed');ok(!str_contains(file_get_contents($e->private.'/events.json'),'secretSQL'));});
test('payload budget enforced',function()use($e,$provider){$e->register(array_replace($provider,['id'=>'tiny','maxBytes'=>1]));rejects(fn()=>$e->generate('tiny'),'payload_budget');ok(!glob($e->public.'/tiny/dataset.*'));});
test('events bounded at 100',function()use($e){for($i=0;$i<105;$i++)$e->invalidate('fixture');ok(count($e->events())===100);});
test('source mutation during build aborts publication',function()use($e,$provider){$e->register(array_replace($provider,['id'=>'changing','build'=>function()use($e){$e->invalidate('changing');return ['items'=>[['id'=>1,'title'=>'Changed']]];}]));rejects(fn()=>$e->generate('changing'),'generation_source_changed');ok(!is_file($e->public.'/changing/manifest.json'));});
test('generation deadline enforced',function()use($e,$provider){$e->register(array_replace($provider,['id'=>'slow','maxSeconds'=>0.001,'build'=>function(){usleep(3000);return ['items'=>[['id'=>1,'title'=>'Slow']]];}]));rejects(fn()=>$e->generate('slow'),'generation_deadline');});
test('unsafe personalised shell rejected',function()use($e,$provider){$e->register(array_replace($provider,['id'=>'unsafe','shell'=>fn()=>str_repeat('x',600).'_wpnonce']));rejects(fn()=>$e->generate('unsafe'),'unsafe_shell');ok(!is_file($e->public.'/unsafe/index.html'));});
if (function_exists('pcntl_fork') && function_exists('posix_kill')) test('killed generation preserves publication and releases lock',function()use($e,$provider){
 $interrupt=false;
 $e->register(array_replace($provider,['id'=>'interrupted','build'=>function()use(&$interrupt){if($interrupt)posix_kill(getmypid(),SIGKILL);return ['items'=>[['id'=>1,'title'=>'Committed']]];}]));
 $e->generate('interrupted');$prior=$e->validate('interrupted');$interrupt=true;
 $pid=pcntl_fork();if($pid===-1)throw new RuntimeException('fork_failed');if($pid===0){$e->generate('interrupted');exit(99);}
 pcntl_waitpid($pid,$status);ok(pcntl_wifsignaled($status)&&pcntl_wtermsig($status)===SIGKILL);ok($e->validate('interrupted')===$prior);
 $interrupt=false;$e->generate('interrupted');ok($e->metadata('interrupted')['status']==='healthy');
});
// Simulate an interrupted private stage: it is not discoverable under the public directory.
test('interrupted staging never publicly exposed',function()use($e){file_put_contents($e->private.'/publish-interrupted','incomplete secret');ok(!glob($e->public.'/*/publish-*'));$e->generate('fixture');$e->validate('fixture');});
$result=['passed'=>count(array_filter($tests,fn($x)=>$x==='PASS')),'total'=>count($tests),'tests'=>$tests];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
function remove_fixture($dir){foreach(scandir($dir) as $f)if($f!=='.'&&$f!=='..'){is_dir($dir.'/'.$f)?remove_fixture($dir.'/'.$f):unlink($dir.'/'.$f);}rmdir($dir);}
remove_fixture($base);
exit($result['passed']===$result['total']?0:1);
