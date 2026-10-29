<?php
/** Public standalone DXNDRE Buses application document. */
defined( 'ABSPATH' ) || exit;
$context = dxb_dashboard_context();
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="color-scheme" content="dark">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
	<?php wp_body_open(); ?>
	<a class="dxbus-skip" href="#dxbus-main">Skip to route workspace</a>
	<div class="dxbus-app" data-route="<?php echo esc_attr( $context['route'] ); ?>" data-service-type="<?php echo esc_attr( $context['type'] ); ?>" data-route-theme="<?php echo 'night' === $context['type'] ? 'night' : 'day'; ?>" data-route-view="<?php echo esc_attr( $context['view'] ); ?>">
		<?php dxb_dashboard_part( 'sidebar', $context ); ?>
		<main class="dxbus-main" id="dxbus-main" tabindex="-1">
			<?php dxb_dashboard_part( 'topbar', $context ); ?>
			<noscript><nav class="dxbus-fallback-nav" aria-label="Route sections without JavaScript"><?php foreach ( dxb_dashboard_views() as $fallback_view => $fallback_label ) : ?><a class="dxbus-button dxbus-button--quiet" href="<?php echo esc_url( dxb_dashboard_route_url( $context['route'], $fallback_view ) ); ?>"><?php echo esc_html( $fallback_label ); ?></a><?php endforeach; ?></nav><form action="<?php echo esc_url( home_url( '/buses/' ) ); ?>" method="get"><label>Change route<input name="route" maxlength="6" required placeholder="112, N118, 18"></label><button class="dxbus-button" type="submit">Open route</button></form></noscript>
			<div id="routes" class="dxbus-route-workspace">
				<p id="dxb-status" class="dxbus-status" role="status" aria-live="polite"><?php echo $context['error'] ? esc_html( $context['error']->get_error_message() ) : 'Loading route information…'; ?></p>
				<div id="dxb-route-state" class="dxbus-empty"<?php echo $context['error'] ? '' : ' hidden'; ?>><?php if ( $context['error'] ) : ?><h1><?php echo 'route_not_found' === $context['error']->get_error_code() ? 'Route not found.' : 'Route temporarily unavailable.'; ?></h1><p><?php echo esc_html( $context['error']->get_error_message() ); ?></p><a class="dxbus-button" href="<?php echo esc_url( home_url( '/buses/#routes' ) ); ?>">Find another route ↗</a><?php endif; ?></div>
				<div id="dxb-result"<?php echo $context['error'] ? ' hidden' : ''; ?>>
					<?php dxb_dashboard_part( 'route-header', $context ); ?>
					<?php dxb_dashboard_part( 'tracking-stats', $context ); ?>
					<?php foreach ( dxb_dashboard_views() as $view => $label ) : ?>
						<?php $panel_ids = array( 'tracking' => 'arrivals', 'explorer' => 'line', 'gallery' => 'photos', 'updates' => 'news' ); $legacy = $panel_ids[ $view ] ?? $view; ?>
						<section class="dxbus-view dxb-tab-panel" id="panel-<?php echo esc_attr( $legacy ); ?>" role="tabpanel" aria-labelledby="tab-<?php echo esc_attr( $legacy ); ?>" data-dxbus-view="<?php echo esc_attr( $view ); ?>" tabindex="-1"<?php echo $view !== $context['view'] ? ' hidden' : ''; ?>>
							<?php dxb_dashboard_part( 'panels/' . $view, $context ); ?>
						</section>
					<?php endforeach; ?>
				</div>
				<noscript><p class="dxbus-empty">Live predictions and the interactive map need JavaScript. Route navigation, published photos, stories and the TfL links remain available.</p></noscript>
			</div>
			<p class="dxbus-attribution">Route and prediction data: <a href="https://tfl.gov.uk/modes/buses/">Transport for London</a>. Fleet information: <a href="https://bustimes.org/">bustimes.org</a>. Predictions and estimated positions are not live GPS.</p>
			<?php get_template_part( 'inc/site-footer' ); ?>
		</main>
		<?php dxb_dashboard_part( 'lightbox', $context ); ?>
	</div>
	<?php get_template_part( 'inc/hire-modals' ); ?>
	<?php wp_footer(); ?>
</body>
</html>
