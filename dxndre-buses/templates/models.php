<?php
if (!defined('ABSPATH')) exit;
$models=get_posts(['post_type'=>'bus','post_status'=>'publish','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
if (!$models || !function_exists('get_field')) return;
$seen=[];$slides=[];
foreach($models as $model){
 $name=get_field('bus_model',$model->ID) ?: $model->post_title;
 $key=strtolower(trim($name));if(isset($seen[$key]))continue;$seen[$key]=true;
 $image=get_the_post_thumbnail_url($model->ID,'full');
 if(!$image){$gallery=get_field('bus_gallery',$model->ID);$first=$gallery[0]??null;if($first)$image=is_array($first)?($first['url']??''):wp_get_attachment_image_url(is_object($first)?$first->ID:$first,'full');}
 if(!$image){$entries=get_posts(['post_type'=>'bus-diary-entry','post_status'=>'publish','numberposts'=>1,'meta_key'=>'bus_used','meta_value'=>$model->ID]);if($entries)$image=get_the_post_thumbnail_url($entries[0]->ID,'full');}
 $slides[]=compact('model','name','image');
}
?>
<section id="bus-models" class="dxb-section" aria-labelledby="bus-models-heading">
 <header class="dxb-section-heading"><div><p class="dxb-eyebrow">The vehicles behind the journeys</p><h2 id="bus-models-heading">Meet the models.</h2></div></header>
 <div class="dx-model-carousel" role="region" aria-roledescription="carousel" aria-label="Bus models">
 <?php foreach($slides as $i=>$slide):extract($slide); ?>
 <article class="dx-model-slide" data-model-slide role="group" aria-roledescription="slide" aria-label="<?php echo esc_attr(($i+1).' of '.count($slides)); ?>" <?php if($i)echo 'hidden'; ?>>
 <?php if($image):?><img class="dx-model-image" src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($name); ?>" loading="lazy"><?php endif;?>
 <div class="dx-model-copy"><p class="dxb-eyebrow"><?php echo esc_html(get_field('manufacturer',$model->ID)); ?></p><h3><?php echo esc_html($name); ?></h3></div>
 <div class="dx-model-specs"><p class="dxb-eyebrow">Model details</p><dl>
 <?php foreach(['manufacturer'=>'Manufacturer','chassis'=>'Chassis','body_type'=>'Body type','engine_type'=>'Powertrain','power_output'=>'Power output'] as $field=>$label):$value=get_field($field,$model->ID);if(!$value)continue;?>
 <div><dt><?php echo esc_html($label);?></dt><dd><?php echo esc_html($value);?></dd></div>
 <?php endforeach;?></dl><a href="<?php echo esc_url(get_permalink($model));?>">Explore this model <span aria-hidden="true">↗</span></a></div>
 </article><?php endforeach;?>
 <?php if(count($slides)>1):?><div class="dx-model-controls"><button type="button" data-model-prev aria-label="Previous bus model">←</button><span data-model-count aria-live="polite">1 / <?php echo count($slides);?></span><button type="button" data-model-next aria-label="Next bus model">→</button></div><?php endif;?>
 </div>
</section>
