<?php defined( 'ABSPATH' ) || exit; ?>
<button class="dxbus-sidebar-scrim" type="button" data-dxbus-close aria-label="Close route menu" tabindex="-1" hidden></button>
<aside class="dxbus-sidebar" id="dxbus-sidebar" aria-label="Route dashboard navigation">
	<a class="dxbus-brand" href="<?php echo esc_url( home_url( '/buses/' ) ); ?>" aria-label="DXNDRE Buses"><span class="dxbus-brand__mark" aria-hidden="true">dx<span>.</span></span><small class="dxbus-brand__name">BUSES</small></a>
	<form class="dxbus-sidebar__search" id="dxb-search" action="<?php echo esc_url( home_url( '/buses/' ) ); ?>" method="get" role="search" aria-label="Change bus route" data-dxbus-route-switcher>
		<label class="dxbus-sr" for="dxb-route-input">Search London bus routes</label>
		<?php echo dxb_dashboard_icon( 'search' ); ?>
		<input id="dxb-route-input" name="route" type="search" maxlength="6" placeholder="Search routes…" autocomplete="off" spellcheck="false" list="dxb-route-options" data-dxbus-route-input>
		<datalist id="dxb-route-options"></datalist>
		<button type="submit" class="dxbus-sr">Open route</button>
		<p class="dxbus-sr" data-dxbus-lookup-status role="status"></p>
	</form>
	<div class="dxbus-sidebar__body">
		<div class="dxbus-sidebar__route-tools"><p class="dxbus-sidebar__label">MY ROUTES</p><a class="dxbus-sidebar__all" href="<?php echo esc_url( home_url( '/buses/#routes' ) ); ?>"><?php echo dxb_dashboard_icon( 'search' ); ?> All routes</a></div>
		<div class="dxbus-recent dxb-recent" aria-label="Recently viewed routes"></div>
		<p class="dxbus-sidebar__label" data-dxbus-route-label>ROUTE <?php echo esc_html( strtoupper( $context['route'] ) ); ?></p>
		<nav class="dxbus-nav" aria-label="Route sections" role="tablist">
			<?php $dxbus_nav_index = 0; foreach ( dxb_dashboard_views() as $view => $label ) : ?>
				<?php $legacy = array( 'tracking' => 'arrivals', 'explorer' => 'line', 'gallery' => 'photos', 'updates' => 'news' )[ $view ] ?? $view; $active = $view === $context['view']; ?>
				<a class="dxbus-nav-link" id="tab-<?php echo esc_attr( $legacy ); ?>" href="<?php echo esc_url( dxb_dashboard_route_url( $context['route'], $view ) ); ?>" style="--dxbus-nav-index:<?php echo (int) $dxbus_nav_index++; ?>" data-tab="<?php echo esc_attr( $legacy ); ?>" data-dxbus-nav="<?php echo esc_attr( $view ); ?>" role="tab" aria-controls="panel-<?php echo esc_attr( $legacy ); ?>" aria-selected="<?php echo $active ? 'true' : 'false'; ?>"<?php echo $active ? ' aria-current="page"' : ''; ?> tabindex="0"><?php echo dxb_dashboard_icon( $view ); ?><span><?php echo esc_html( $label ); ?></span><?php if ( 'pinned' === $view ) : ?><small data-dxbus-pin-count data-dxbus-pinned-count hidden>0</small><?php endif; ?></a>
			<?php endforeach; ?>
		</nav>
	</div>
	<div class="dxbus-sidebar__bottom"><a href="<?php echo esc_url( home_url( '/buses/' ) ); ?>">← Back to Buses</a><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Visit dxndre.co.uk ↗</a><small>A personal view of London’s buses.</small></div>
</aside>
