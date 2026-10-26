<?php
if (!defined('ABSPATH')) exit;
$journeys=dxb_journeys(); $photos=dxb_photos(); $featured=$journeys[0]??null;
foreach($journeys as $j) if(strtoupper($j['route'])==='N118') $featured=$j;
$hero=home_url('/wp-content/uploads/2026/10/calculator-bus-electroliners-v2.png');
$articles=get_posts(['post_type'=>'dx_bus_article','numberposts'=>3,'post_status'=>'publish']);
get_header();
?>
<div class="dxb" id="buses-overview">
    <div class="dxb-atmosphere" aria-hidden="true"></div>
    <div class="dxb-hero-media" aria-hidden="true"><img src="<?php echo esc_url($hero); ?>" alt="" fetchpriority="high"></div>
    <div class="dxb-wrap">
        <section class="dxb-hero" aria-labelledby="dxb-title">
            <p class="dxb-eyebrow">A personal passion</p>
            <h1 id="dxb-title">BUSES<span>.</span></h1>
            <div class="dx-bus-cutout"><img src="<?php echo esc_url(get_template_directory_uri().'/assets/img/bus.png'); ?>" alt="London double-decker bus"></div><div class="dx-bus-hero-copy"><h2>Every route.<br>A story.</h2><p class="dxb-intro">Photographs, journeys and stories from across the network.</p><a class="dx-cta" href="#routes">Explore the network ↓</a></div>
        </section>
            <nav class="dxb-nav" aria-label="Buses sections">
                <a href="#buses-overview" aria-current="location">Overview</a><a href="#routes">Routes</a><a href="#bus-diary">Bus Diary</a><a href="#news">News &amp; Views</a><a href="#bus-models">Bus Models</a><a href="#photography">Photography</a>
            </nav>
        <section id="routes" class="dxb-explorer dxb-panel" aria-labelledby="dxb-route-heading">
            <div class="dxb-explorer-main"><div class="dxb-query-intro">
                <div class="dxb-rule"></div><p class="dxb-eyebrow">Explore the network</p>
                <h2 id="dxb-route-heading">Where would you like to go?</h2>
                <p class="dxb-muted">Find a route. Discover its story.</p>
                </div><div class="dxb-query-controls"><form id="dxb-search" class="dxb-search" role="search" aria-label="Find a bus route">
                    <label class="dxb-sr" for="dxb-route-input">London bus route</label>
                    <span aria-hidden="true" class="dxb-search-icon">⌕</span>
                    <input id="dxb-route-input" name="route" type="search" placeholder="Enter a route, e.g. N98" autocomplete="off" maxlength="6" list="dxb-route-options" spellcheck="false">
                    <datalist id="dxb-route-options"></datalist><button class="dxb-button" type="submit">Explore <span aria-hidden="true">↗</span></button>
                </form>
                <div class="dxb-chips dxb-recent" aria-label="Recent routes"><?php foreach(['2','6','8','N98'] as $r): ?><button type="button" data-route="<?php echo esc_attr($r); ?>" aria-pressed="false"><?php echo esc_html($r); ?></button><?php endforeach; ?></div>
                <p id="dxb-status" class="dxb-status" role="status" aria-live="polite">Choose a route to explore the London bus network.</p>
                </div><div class="dxb-loader" role="status"><span></span>Loading Route Information</div><div id="dxb-route-state" class="dxb-route-state" hidden></div><div id="dxb-result" hidden>
                    <div class="dxb-route-identity"><h3 id="dxb-route-number"></h3><div><span class="dxb-eyebrow" id="dxb-route-type"></span><p id="dxb-route-description"></p></div></div>
                    <div class="dxb-tabs" role="tablist" aria-label="Route information">
                        <?php foreach(['pinned'=>'Pinned Buses','overview'=>'Overview','arrivals'=>'Stops & Arrivals','line'=>'Line','photos'=>'Photos','stories'=>'Stories','news'=>'News','discussion'=>'Discussion'] as $id=>$label): ?><button type="button" id="tab-<?php echo $id; ?>" role="tab" aria-controls="panel-<?php echo $id; ?>" aria-selected="<?php echo $id==='overview'?'true':'false'; ?>" tabindex="<?php echo $id==='overview'?'0':'-1'; ?>" data-tab="<?php echo $id; ?>"><?php echo $id==='pinned'?'Pinned<span class="dx-pin-desktop"> Buses</span>':esc_html($label); ?></button><?php endforeach; ?>
                    </div>
                    <div id="dxb-route-facts" aria-live="polite"></div><div id="dxb-consultation-notice" aria-live="polite"></div>
                    <div id="panel-pinned" role="tabpanel" aria-labelledby="tab-pinned" class="dxb-tab-panel" hidden><div id="dxb-marked"></div></div>
<div id="panel-overview" role="tabpanel" aria-labelledby="tab-overview" class="dxb-tab-panel">
                        <div id="dxb-endpoints"></div><p class="dxb-small dxb-muted">Explore stops and arrival predictions, or discover photographs and journeys from the archive.</p>
                        <details class="dxb-details"><summary>Route details <span aria-hidden="true">+</span></summary><p id="dxb-route-detail"></p><p class="dxb-small">Route information from TfL. Photographs and diary entries are a personal archive and may show earlier operations.</p></details>
                        <button type="button" id="dxb-share" class="dxb-text-button">Copy route link <span aria-hidden="true">↗</span></button>
                    </div>
                    <div id="panel-arrivals" role="tabpanel" aria-labelledby="tab-arrivals" class="dxb-tab-panel" hidden>
                        <div class="dxb-selects"><label>Direction<select id="dxb-direction"><option value="inbound">Inbound</option><option value="outbound">Outbound</option></select></label><label>Bus stop<select id="dxb-stop"><option value="">Choose a stop</option></select></label></div>
                        <label class="dxb-advanced-toggle"><input type="checkbox" id="dxb-advanced"> Enthusiast / Veg Mode</label><div id="dxb-tracking" aria-live="polite"></div>
                        <div id="dxb-arrivals" aria-live="polite"><p class="dxb-small">Choose a stop for live arrival predictions.</p></div><p id="dxb-updated" class="dxb-small dxb-muted"></p>
                    </div>
                    <div id="panel-line" role="tabpanel" aria-labelledby="tab-line" class="dxb-tab-panel" hidden><label for="dxb-line-direction">Direction</label><select id="dxb-line-direction"><option value="inbound">Inbound</option><option value="outbound">Outbound</option></select><div id="dxb-line-stops" aria-live="polite"></div></div>
                    <div id="panel-photos" role="tabpanel" aria-labelledby="tab-photos" class="dxb-tab-panel" hidden></div>
                    <div id="panel-news" role="tabpanel" aria-labelledby="tab-news" class="dxb-tab-panel" aria-live="polite" hidden></div>
                    <div id="panel-discussion" role="tabpanel" aria-labelledby="tab-discussion" class="dxb-tab-panel" hidden><h3>Route Discussion</h3><p class="dxb-small">Share a sighting, question or memory. Guest comments appear after moderation. Your name and comment will be public; please avoid personal contact details.</p><div id="dxb-comments"></div><form id="dxb-comment-form"><label><span class="dxb-visually-hidden">Your Name</span><input placeholder="Your Name" name="name" required minlength="2" maxlength="60" autocomplete="nickname"></label><label><span class="dxb-visually-hidden">Your Comment</span><textarea placeholder="Your Comment" name="body" required minlength="5" maxlength="2000" rows="5"></textarea></label><label class="dxb-honeypot" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label><button class="dxb-button" type="submit">Submit For Review ↗</button><p id="dxb-comment-status" role="status"></p></form></div>
                    <div id="panel-stories" role="tabpanel" aria-labelledby="tab-stories" class="dxb-tab-panel" hidden></div>
                </div>
                <noscript><p>Enable JavaScript to use the live route explorer. The photo archive and journey links below are still available.</p></noscript>
                <p class="dxb-source">Route &amp; arrival data: <a href="https://tfl.gov.uk/modes/buses/">Transport for London ↗</a></p>
            </div>
            <aside class="dxb-explorer-aside">
                <div><p class="dxb-eyebrow">Your next journey</p><h2>See the city from<br>a different seat.</h2></div>
                <figure><img id="dxb-route-photo" src="<?php echo esc_url($featured['image']??$hero); ?>" alt="<?php echo esc_attr($featured ? 'From the '.$featured['route'].' diary' : 'London buses at night'); ?>" decoding="async"><figcaption id="dxb-route-caption"><?php echo esc_html($featured ? 'From the '.$featured['route'].' diary · '.$featured['date'] : 'London after dark'); ?></figcaption></figure>
            </aside>
        </section>
        <section id="bus-diary" class="dxb-section" aria-labelledby="diary-heading">
            <header class="dxb-section-heading"><div><p class="dxb-eyebrow">From the bus diary</p><h2 id="diary-heading">The journey is the story.</h2></div><a href="#dxb-journeys">All journeys <span aria-hidden="true">↗</span></a></header>
            <?php if($featured): ?>
            <div class="dxb-diary dxb-panel">
                <a class="dxb-diary-photo" href="<?php echo esc_url($featured['url']); ?>"><img src="<?php echo esc_url($featured['image']); ?>" alt="<?php echo esc_attr('From the '.$featured['route'].' bus diary'); ?>" loading="lazy"><div><p class="dxb-eyebrow"><?php echo esc_html($featured['route']); ?> / Journey notes</p><h3><?php echo str_starts_with(strtoupper($featured['route']),'N')?'After the last train.':'A different view of London.'; ?></h3><span class="dxb-link">Follow the journey ↗</span></div></a>
                <div class="dxb-diary-notes"><h3>A journey, stop by stop.</h3><p class="dxb-muted">Places, photographs and moments along the way.</p><div class="dxb-journey-trace" aria-hidden="true"><svg viewBox="0 0 160 240" fill="none"><path d="M24 214L44 153L95 153L82 99L130 30" stroke="currentColor" stroke-width="2"/><circle cx="24" cy="214" r="6" fill="currentColor"/><circle cx="82" cy="99" r="6" fill="currentColor"/><circle cx="130" cy="30" r="6" fill="currentColor"/></svg></div><ol><li><span>01</span><div><strong>Boarding</strong><p><?php echo esc_html($featured['start']); ?></p></div></li><li><span>02</span><div><strong>Along the way</strong><p>Open the diary for photographs and journey details.</p></div></li><li><span>03</span><div><strong>The last stop</strong><p><?php echo esc_html($featured['end']); ?></p></div></li></ol><p class="dxb-small dxb-muted">Journey outline · not a geographic map</p></div>
            </div>
            <div id="dxb-journeys" class="dxb-journey-list"><?php foreach($journeys as $j): ?><a href="<?php echo esc_url($j['url']); ?>"><span class="dxb-journey-number"><?php echo esc_html($j['route']); ?></span><span><?php echo esc_html($j['start'].' → '.$j['end']); ?><small><?php echo esc_html($j['date']); ?> · Journey notes</small></span><span aria-hidden="true">↗</span></a><?php endforeach; ?></div>
            <?php else: ?><p>Your journey archive starts here. Published diary entries will appear in this space.</p><?php endif; ?>
        </section>
        <section id="news" class="dxb-section dxb-editorial" aria-labelledby="news-heading">
            <div><p class="dxb-eyebrow">News &amp; views</p><h2 id="news-heading">Beyond the destination.</h2>
                <?php if($articles): foreach($articles as $article): ?><a class="dxb-article" href="<?php echo esc_url(get_permalink($article)); ?>"><?php echo get_the_post_thumbnail($article,'medium',['loading'=>'lazy']); ?><div><p class="dxb-eyebrow"><?php echo esc_html(get_the_date('j M Y',$article)); ?></p><h3><?php echo esc_html($article->post_title); ?></h3><p><?php echo esc_html(wp_trim_words(get_the_excerpt($article),20)); ?></p></div><span aria-hidden="true">↗</span></a><?php endforeach; else: ?><div class="dxb-editorial-empty"><span class="dxb-eyebrow">Notes for the road ahead</span><h3>A space for a closer look.</h3><p>Route changes, tendering and the stories behind London's buses. New articles will appear here as the journal grows.</p><a href="#bus-diary">Explore the diary in the meantime ↗</a></div><?php endif; ?>
            </div><aside><p class="dxb-eyebrow">On the horizon</p><h2>Keep an eye on the network.</h2><a class="dxb-resource" href="https://tfl.gov.uk/forms/13923.aspx"><span>01</span><div><h3>Tender results</h3><p>Contract awards and tendering programmes from TfL.</p></div><span>↗</span></a><a class="dxb-resource" href="https://tfl.gov.uk/modes/buses/bus-changes"><span>02</span><div><h3>Upcoming changes</h3><p>Service changes across the London bus network.</p></div><span>↗</span></a><a class="dxb-resource" href="http://www.londonbusroutes.net/changes.htm"><span>03</span><div><h3>The wider picture</h3><p>Route and operator updates from London Bus Routes.</p></div><span>↗</span></a><p class="dxb-small dxb-muted">These links open the original sources.</p></aside>
        </section>
        <?php include __DIR__ . '/models.php'; ?>
        <section id="photography" class="dxb-section" aria-labelledby="photo-heading">
            <header class="dxb-section-heading"><div><p class="dxb-eyebrow">Through my lens</p><h2 id="photo-heading">A growing collection.</h2></div><span class="dxb-small dxb-muted"><?php echo count($photos); ?> photographs in the archive</span></header>
            <div class="dxb-gallery-toolbar"><div class="dxb-chips dx-photo-groups" aria-label="Filter photographs"><button type="button" data-photo-filter="all" aria-pressed="true">All photographs</button><button type="button" data-photo-filter="buses" aria-pressed="false">London Buses</button><button type="button" data-photo-filter="scenery" aria-pressed="false">Scenery</button></div>
 <div class="dxb-chips dx-photo-subgroups" data-photo-group="buses" hidden><?php foreach(['range-1'=>'Routes 1–100','range-101'=>'Routes 101–200','range-201'=>'Routes 201–300','range-301'=>'Routes 301–400','range-401'=>'Routes 401+','lettered'=>'Lettered Buses','night'=>'Night Buses'] as $value=>$label):?><button type="button" data-photo-filter="<?php echo esc_attr($value);?>" aria-pressed="false"><?php echo esc_html($label);?></button><?php endforeach;?></div>
 <div class="dxb-chips dx-photo-subgroups" data-photo-group="scenery" hidden><?php foreach(['city'=>'City','suburbs'=>'Suburbs','rural'=>'Rural','coast'=>'Coast'] as $value=>$label):?><button type="button" data-photo-filter="scenery-<?php echo esc_attr($value);?>" aria-pressed="false"><?php echo esc_html($label);?></button><?php endforeach;?></div><label class="dxb-sr" for="dxb-photo-search">Search photograph details</label><input id="dxb-photo-search" type="search" placeholder="Search photos, places, vehicles…"></div>
            <div class="dxb-gallery" id="dxb-gallery"><?php foreach($photos as $i=>$photo): ?><a href="<?php echo esc_url($photo['full']); ?>" data-photo="<?php echo $i; ?>" class="dxb-photo-card" data-photo-route="<?php echo esc_attr($photo['route']); ?>" data-photo-scenery="<?php echo esc_attr($photo['scenery']??''); ?>" data-photo-search="<?php echo esc_attr(strtolower(implode(' ',array_intersect_key($photo,array_flip(['title','route','caption','operator','vehicle','location']))))); ?>"><img src="<?php echo esc_url($photo['image']); ?>" alt="<?php echo esc_attr($photo['alt']); ?>" loading="lazy" decoding="async" <?php $dimensions=wp_get_attachment_image_src($photo['id'],'large'); if($dimensions) echo 'width="'.(int)$dimensions[1].'" height="'.(int)$dimensions[2].'"'; ?>><div><span class="dxb-eyebrow"><?php echo esc_html($photo['route'] ?: 'Photography'); ?></span><h3><?php echo esc_html($photo['title']); ?></h3><span aria-hidden="true">↗</span></div></a><?php endforeach; ?></div>
            <p id="dxb-gallery-empty" class="dxb-muted" hidden>No photographs match those filters. Try another route or search.</p>
        </section>
        <div class="dxb-signoff"><span>DXNDRE</span><p>A personal view of London's buses.</p><a href="#buses-overview">Back to the top ↑</a></div>
    </div>
    <dialog id="dxb-lightbox" aria-labelledby="dxb-lightbox-title"><button type="button" class="dxb-close" aria-label="Close photograph">×</button><img id="dxb-lightbox-image" alt=""><div class="dxb-lightbox-info"><p class="dxb-eyebrow" id="dxb-lightbox-route"></p><h2 id="dxb-lightbox-title"></h2><p id="dxb-lightbox-caption"></p><p id="dxb-lightbox-meta" class="dxb-muted"></p><button type="button" class="dxb-bad-photo" id="dxb-bad-photo" hidden aria-describedby="dxb-bad-explanation">💩 Bad Photo<span role="tooltip" id="dxb-bad-explanation">This is the only photo of mine I can find. I’ll replace it with a better photo in due course.</span></button><dl id="dxb-photo-technical"></dl><div id="dxb-photo-map"></div><div class="dxb-lightbox-nav"><button type="button" data-lightbox-prev aria-label="Previous photograph">← Previous</button><span id="dxb-lightbox-count"></span><button type="button" data-lightbox-next aria-label="Next photograph">Next →</button></div></div></dialog>
</div>
<?php get_footer(); ?>
