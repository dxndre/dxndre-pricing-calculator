<?php defined( 'ABSPATH' ) || exit; ?>
<div class="dxbus-stats" aria-label="Live route statistics">
	<?php foreach ( array( 'total' => 'Buses tracking', 'single' => 'Single deckers', 'double' => 'Double deckers', 'refresh' => 'Refresh rate' ) as $key => $label ) : ?>
		<article class="dxbus-stat dxbus-stat--<?php echo esc_attr( $key ); ?>"><?php echo dxb_dashboard_icon( 'refresh' === $key ? 'clock' : 'bus' ); ?><div><strong data-dxbus-stat="<?php echo esc_attr( $key ); ?>">—</strong><span><?php echo esc_html( $label ); ?></span><small data-dxbus-stat-note="<?php echo esc_attr( $key ); ?>"><?php echo 'refresh' === $key ? 'TfL predictions' : ( 'total' === $key ? 'TfL live predictions' : 'Confirmed fleet type' ); ?></small></div></article>
	<?php endforeach; ?>
</div>
<div class="dxbus-stat-notes"><p class="dxbus-small" id="dxbus-refresh-status" data-dxbus-stat-status>Waiting for predictions.</p><p class="dxbus-small" data-dxbus-classification-note>Vehicle types are confirmed by the fleet source where available.</p></div>
