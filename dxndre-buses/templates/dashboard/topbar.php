<?php defined( 'ABSPATH' ) || exit; ?>
<header class="dxbus-topbar">
	<div class="dxbus-topbar__start"><button class="dxbus-mobile-toggle" type="button" data-dxbus-menu aria-controls="dxbus-sidebar" aria-expanded="false" aria-label="Open route menu"><?php echo dxb_dashboard_icon( 'menu' ); ?></button><span class="dxbus-breadcrumb"><a href="<?php echo esc_url( home_url( '/buses/' ) ); ?>">Buses</a><span aria-hidden="true">/</span><strong data-dxbus-breadcrumb><?php echo esc_html( strtoupper( $context['route'] ) ); ?></strong></span></div>
	<div class="dxbus-topbar__actions"><button type="button" class="dxbus-button dxbus-button--quiet" id="dxb-share">Copy route link ↗</button><button type="button" class="dxbus-button dxbus-button--quiet" data-dxbus-switch>Change route <span aria-hidden="true">⇄</span></button></div>
</header>
