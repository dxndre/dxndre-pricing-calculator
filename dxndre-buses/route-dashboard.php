<?php
/** Standalone route dashboard routing and shared presentation data. */
defined( 'ABSPATH' ) || exit;

function dxb_dashboard_views() {
	return array( 'overview' => 'Overview', 'tracking' => 'Live tracking', 'explorer' => 'Route explorer', 'pinned' => 'Pinned buses', 'gallery' => 'Gallery', 'stories' => 'Stories', 'updates' => 'Updates', 'discussion' => 'Discussion' );
}

function dxb_dashboard_normalize_route( $value ) {
	if ( ! is_string( $value ) ) {
		return '';
	}
	$value = trim( $value );
	return preg_match( '/^[a-zA-Z0-9]{1,6}$/D', $value ) ? strtolower( $value ) : '';
}

function dxb_dashboard_view( $value ) {
	$aliases = array( 'arrivals' => 'tracking', 'line' => 'explorer', 'photos' => 'gallery', 'news' => 'updates' );
	$value = is_string( $value ) ? strtolower( trim( $value ) ) : 'overview';
	$value = $aliases[ $value ] ?? $value;
	return isset( dxb_dashboard_views()[ $value ] ) ? $value : 'overview';
}

function dxb_dashboard_route_url( $route, $view = 'overview' ) {
	$route = dxb_dashboard_normalize_route( $route );
	if ( ! $route ) {
		return home_url( '/buses/#routes' );
	}
	$url = home_url( '/buses/routes/' . rawurlencode( $route ) . '/' );
	$view = dxb_dashboard_view( $view );
	return 'overview' === $view ? $url : add_query_arg( 'view', $view, $url );
}

function dxb_is_route_dashboard() {
	return is_string( get_query_var( 'dxb_route' ) ) && '' !== get_query_var( 'dxb_route' );
}

function dxb_dashboard_rewrites() {
	add_rewrite_rule( '^buses/routes/([a-zA-Z0-9]{1,6})/?$', 'index.php?dxb_route=$matches[1]', 'top' );
}
add_action( 'init', 'dxb_dashboard_rewrites', 11 );
add_filter( 'query_vars', static function ( $vars ) {
	$vars[] = 'dxb_route';
	return $vars;
} );

// Add this feature's rules once after an update, never on every page load.
add_action( 'init', static function () {
	if ( '1' !== get_option( 'dxb_dashboard_rewrite_version' ) ) {
		flush_rewrite_rules( false );
		update_option( 'dxb_dashboard_rewrite_version', '1', false );
	}
}, 99 );

/** Validate against the same current network and archive sources as the existing explorer. */
function dxb_dashboard_validate_route( $route ) {
	$route = dxb_dashboard_normalize_route( $route );
	if ( ! $route ) {
		return new WP_Error( 'route_not_found', 'Enter a route number such as 112, N118 or 18.', array( 'status' => 404 ) );
	}
	$valid = dxb_route_exists( $route );
	if ( ! is_wp_error( $valid ) || 'route_not_found' !== $valid->get_error_code() ) {
		return $valid;
	}
	$profile = dxb_route_profile( $route, false );
	if ( ! is_wp_error( $profile ) && ! empty( $profile['listingType'] ) ) {
		return true;
	}
	foreach ( dxb_journeys() as $journey ) {
		if ( strtolower( $journey['route'] ) === $route ) {
			return true;
		}
	}
	return is_wp_error( $profile ) ? $profile : $valid;
}

add_action( 'template_redirect', static function () {
	if ( is_page( 'buses' ) && isset( $_GET['route'] ) ) {
		$raw = is_string( $_GET['route'] ) ? wp_unslash( $_GET['route'] ) : '';
		$route = dxb_dashboard_normalize_route( $raw );
		if ( $route ) {
			$view = dxb_dashboard_view( isset( $_GET['view'] ) ? wp_unslash( $_GET['view'] ) : ( isset( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : 'overview' ) );
			wp_safe_redirect( dxb_dashboard_route_url( $route, $view ), 302 );
			exit;
		}
	}
	if ( ! dxb_is_route_dashboard() ) {
		return;
	}
	$route = dxb_dashboard_normalize_route( get_query_var( 'dxb_route' ) );
	$valid = dxb_dashboard_validate_route( $route );
	$GLOBALS['dxb_dashboard_error'] = is_wp_error( $valid ) ? $valid : null;
	if ( is_wp_error( $valid ) ) {
		$status = (int) ( $valid->get_error_data()['status'] ?? 503 );
		status_header( $status );
		if ( 404 === $status ) {
			$GLOBALS['wp_query']->set_404();
		}
		nocache_headers();
	} else {
		$GLOBALS['wp_query']->is_404 = false;
		status_header( 200 );
		$view = dxb_dashboard_view( isset( $_GET['view'] ) ? wp_unslash( $_GET['view'] ) : ( isset( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : 'overview' ) );
		$canonical = dxb_dashboard_route_url( $route, $view );
		$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$canonical_path = wp_parse_url( $canonical, PHP_URL_PATH );
		if ( $path !== $canonical_path ) {
			wp_safe_redirect( $canonical, 301 );
			exit;
		}
	}
}, 1 );

add_filter( 'redirect_canonical', static function ( $redirect ) {
	return dxb_is_route_dashboard() ? false : $redirect;
} );
add_filter( 'template_include', static function ( $template ) {
	return dxb_is_route_dashboard() ? __DIR__ . '/templates/route-dashboard.php' : $template;
}, 100 );
add_filter( 'body_class', static function ( $classes ) {
	if ( dxb_is_route_dashboard() ) {
		$classes = array_values( array_diff( $classes, array( 'is-frontend', 'dxb-page', 'dxcp-page' ) ) );
		$classes[] = 'dxbus-page';
	}
	return $classes;
}, PHP_INT_MAX );
add_filter( 'show_admin_bar', static function ( $show ) {
	return dxb_is_route_dashboard() ? false : $show;
}, PHP_INT_MAX );
add_filter( 'document_title_parts', static function ( $parts ) {
	if ( dxb_is_route_dashboard() ) {
		$parts['title'] = 'Route ' . strtoupper( dxb_dashboard_normalize_route( get_query_var( 'dxb_route' ) ) ) . ' · DXNDRE Buses';
	}
	return $parts;
} );
add_action( 'wp_head', static function () {
	if ( dxb_is_route_dashboard() ) {
		echo '<link rel="canonical" href="' . esc_url( dxb_dashboard_route_url( get_query_var( 'dxb_route' ) ) ) . '">' . "\n";
	}
}, 1 );

function dxb_dashboard_context() {
	static $context = null;
	if ( null !== $context ) {
		return $context;
	}
	$route = dxb_dashboard_normalize_route( get_query_var( 'dxb_route' ) );
	$all_photos = dxb_photos();
	$photos = array();
	foreach ( $all_photos as $index => $photo ) {
		if ( strtolower( trim( $photo['route'] ) ) === $route ) {
			$photo['index'] = $index;
			$photos[] = $photo;
		}
	}
	$journeys = array_values( array_filter( dxb_journeys(), static function ( $journey ) use ( $route ) {
		return strtolower( trim( $journey['route'] ) ) === $route;
	} ) );
	$error = $GLOBALS['dxb_dashboard_error'] ?? null;
	$is_current = $error ? false : ! is_wp_error( dxb_route_exists( $route ) );
	$profile = $error ? array() : dxb_route_profile( $route, $is_current );
	$profile = is_wp_error( $profile ) ? array() : $profile;
	$type = $profile['badgeType'] ?? ( preg_match( '/^n[0-9]+$/', $route ) ? 'night' : 'regular' );
	$hero = $photos[0] ?? null;
	$termini = $profile['listingEndpoints'] ?? ( $profile['workings'][0]['destinations'] ?? '' );
	if ( ! $termini && $journeys ) {
		$termini = trim( $journeys[0]['start'] . ' ↔ ' . $journeys[0]['end'], ' ↔' );
	}
	$view = dxb_dashboard_view( isset( $_GET['view'] ) ? wp_unslash( $_GET['view'] ) : ( isset( $_GET['tab'] ) ? wp_unslash( $_GET['tab'] ) : 'overview' ) );
	$context = compact( 'route', 'all_photos', 'photos', 'journeys', 'error', 'profile', 'type', 'hero', 'termini', 'view' );
	return $context;
}

function dxb_dashboard_part( $part, $context ) {
	$path = __DIR__ . '/templates/dashboard/' . $part . '.php';
	if ( is_file( $path ) ) {
		require $path;
	}
}

function dxb_dashboard_image( $photo, $size = 'medium_large', $attributes = array() ) {
	if ( empty( $photo['id'] ) ) {
		return '';
	}
	return wp_get_attachment_image( (int) $photo['id'], $size, false, array_merge( array( 'alt' => $photo['alt'] ?? $photo['title'] ?? '', 'loading' => 'lazy', 'decoding' => 'async' ), $attributes ) );
}

function dxb_dashboard_icon( $name ) {
	$paths = array(
		'overview' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 3v18"/>',
		'tracking' => '<path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
		'explorer' => '<path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2V5Zm6-2v16m6-14v16"/>',
		'pinned' => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
		'gallery' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-6 5 8"/>',
		'stories' => '<path d="M5 3h14v18H5zM8 7h8M8 11h8M8 15h5"/>',
		'updates' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10M7 12h6M7 16h10"/>',
		'discussion' => '<path d="M21 3H3v15h5l4 3 4-3h5V3ZM7 8h10M7 12h7"/>',
		'search' => '<circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/>',
		'bus' => '<rect x="5" y="3" width="14" height="17" rx="3"/><path d="M5 10h14M8 20v2m8-2v2M8 15h1m6 0h1"/>',
		'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
		'arrow' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
		'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>',
	);
	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? $paths['arrow'] ) . '</svg>';
}

add_action( 'wp_enqueue_scripts', static function () {
	if ( ! dxb_is_route_dashboard() ) {
		return;
	}
	$context = dxb_dashboard_context();
	wp_localize_script( 'dxndre-theme', 'DXB', array(
		'api' => esc_url_raw( rest_url( 'dx-buses/v1/' ) ),
		'app' => true,
		'route' => $context['route'],
		'view' => $context['view'],
		'error' => $context['error'] ? array( 'code' => $context['error']->get_error_code(), 'message' => $context['error']->get_error_message() ) : false,
		'dashboardBase' => home_url( '/buses/routes/' ),
		'lookupURL' => home_url( '/buses/#routes' ),
		'journeys' => dxb_journeys(),
		'photos' => $context['all_photos'],
	) );
	wp_enqueue_style( 'leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
	wp_enqueue_script( 'leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );
}, 101 );

add_action( 'rest_api_init', static function () {
	register_rest_route( 'dx-buses/v1', '/status/(?P<route>[a-zA-Z0-9]{1,6})', array(
		'methods' => 'GET',
		'permission_callback' => '__return_true',
		'callback' => static function ( $request ) {
			$route = strtolower( $request['route'] );
			$valid = dxb_route_exists( $route );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$data = dxb_tfl( '/Line/' . rawurlencode( $route ) . '/Status', MINUTE_IN_SECONDS );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$statuses = array();
			foreach ( $data as $line ) {
				if ( strtolower( $line['id'] ?? '' ) !== $route ) {
					continue;
				}
				foreach ( $line['lineStatuses'] ?? array() as $status ) {
					$statuses[] = array( 'severity' => isset( $status['statusSeverity'] ) ? (int) $status['statusSeverity'] : null, 'description' => sanitize_text_field( $status['statusSeverityDescription'] ?? '' ), 'reason' => sanitize_textarea_field( $status['reason'] ?? '' ), 'validity' => $status['validityPeriods'] ?? array() );
				}
			}
			return array( 'statuses' => $statuses, 'source' => 'https://tfl.gov.uk/bus/status/', 'checked' => gmdate( 'c' ), 'retrieved' => gmdate( 'c' ) );
		},
	) );
} );
