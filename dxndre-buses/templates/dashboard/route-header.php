<?php
defined( 'ABSPATH' ) || exit;
$operator_label = ! empty( $context['profile']['operators'] ) ? implode( ' / ', $context['profile']['operators'] ) : 'Operator not confirmed';
$garages = array_values( array_filter( $context['profile']['garages'] ?? array(), 'is_string' ) );
if ( $garages ) {
	$operator_label .= ' · ' . ( count( $garages ) > 1 ? 'Garages: ' : 'Garage: ' ) . implode( ' / ', $garages );
}
?>
<header class="dxbus-hero dxbus-route-header<?php echo $context['hero'] ? ' has-image' : ''; ?>">
	<?php if ( $context['hero'] ) : ?><div class="dxbus-hero__image" aria-hidden="true"><?php echo dxb_dashboard_image( $context['hero'], 'large', array( 'loading' => 'eager', 'fetchpriority' => 'high', 'alt' => '' ) ); ?></div><?php endif; ?>
	<div class="dxbus-hero__content">
		<div class="dxbus-route-identity"><span class="dxbus-route-badge" id="dxb-route-number" data-service-type="<?php echo esc_attr( $context['type'] ); ?>"><?php echo esc_html( strtoupper( $context['route'] ) ); ?></span><div><p class="dxbus-eyebrow"><?php echo 'night' === $context['type'] ? 'NIGHT BUS' : 'LONDON BUS ROUTE'; ?></p><h1 data-dxbus-route-title><?php echo esc_html( $context['termini'] ?: 'Route ' . strtoupper( $context['route'] ) ); ?></h1><p id="dxb-route-description"><?php echo 'night' === $context['type'] ? 'Explore the route after dark.' : 'Another way to see the city.'; ?></p></div></div>
		<div class="dxbus-meta"><span id="dxb-route-type"><?php echo 'night' === $context['type'] ? 'Night service' : 'Day service'; ?></span><span data-dxbus-operator><?php echo esc_html( $operator_label ); ?></span><span data-dxbus-allocation><?php echo esc_html( $context['profile']['vehicle'] ?? 'Vehicle allocation loading…' ); ?></span></div>
	</div>
</header>
