<?php
/**
 * Plugin Name: DXNDRE Business
 * Description: Private business directory, service categories and versioned financial foundation for the existing DXNDRE client space.
 * Version: 1.0.0
 * Author: DXNDRE
 * Requires PHP: 8.1
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
define( 'DXBS_VERSION', '1.0.0' );
define( 'DXBS_SCHEMA_VERSION', 2 );
define( 'DXBS_MAX_MINOR', 999999999999 );
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/directory.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/finance.php';
require_once __DIR__ . '/includes/documents.php';
require_once __DIR__ . '/includes/finance-handlers.php';
require_once __DIR__ . '/includes/handlers.php';

/** Administrator-only by default. A role may be deliberately granted the dedicated capability. */
function dxbs_is_manager( $user_id = 0 ) {
	$user_id = $user_id ?: get_current_user_id();
	$user = $user_id ? get_userdata( $user_id ) : false;
	return $user && 'dxndre' === $user->user_login && user_can( $user_id, 'manage_options' );
}
function dxbs_error( $code, $message ) {
	return new WP_Error( $code, $message );
}
function dxbs_require_manager() {
	return dxbs_is_manager() ? true : dxbs_error( 'forbidden', 'You do not have permission to manage business records.' );
}
function dxbs_now() {
	return current_time( 'mysql', true );
}
function dxbs_activate() {
	$result = dxbs_install();
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ), 'DXNDRE Business installation', array( 'response' => 500 ) );
	}
	// Financial authority is tied to the explicitly authorised dxndre account; no role grants.
}
register_activation_hook( __FILE__, 'dxbs_activate' );
add_action( 'init', function () {
	if ( dxbs_is_manager() && (int) get_option( 'dxbs_schema_version', 0 ) < DXBS_SCHEMA_VERSION ) {
		dxbs_install();
	}
} );

/** Exact decimal-to-pence conversion. Floats and exponential notation are deliberately rejected. */
function dxbs_minor_units( $amount ) {
	if ( ! is_string( $amount ) && ! is_int( $amount ) ) {
		return dxbs_error( 'invalid_amount', 'Enter a decimal amount with no more than two decimal places.' );
	}
	$value = trim( (string) $amount );
	if ( ! preg_match( '/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', $value, $match ) ) {
		return dxbs_error( 'invalid_amount', 'Enter a positive decimal amount with no more than two decimal places.' );
	}
	$minor = ( (int) $match[1] * 100 ) + (int) str_pad( $match[2] ?? '', 2, '0' );
	return $minor <= DXBS_MAX_MINOR ? $minor : dxbs_error( 'amount_limit', 'The amount exceeds the supported limit.' );
}
/** Half-up integer percentage; e.g. 3000 basis points means 30%. */
function dxbs_percent_amount( $minor, $basis_points ) {
	if ( ! is_int( $minor ) || ! is_int( $basis_points ) || $minor < 0 || $minor > DXBS_MAX_MINOR || $basis_points < 0 || $basis_points > 10000 ) {
		return dxbs_error( 'invalid_percentage', 'Enter valid integer pence and a percentage from 0 to 100.' );
	}
	return intdiv( $minor * $basis_points + 5000, 10000 );
}
/** Quantities have three decimal places; multiplication stays in integer arithmetic. */
function dxbs_line_total( $unit_minor, $quantity_millis = 1000 ) {
	if ( ! is_int( $unit_minor ) || ! is_int( $quantity_millis ) || $unit_minor < 0 || $quantity_millis < 1 || $unit_minor > DXBS_MAX_MINOR || $quantity_millis > 1000000 ) {
		return dxbs_error( 'invalid_line', 'Enter a valid integer price and quantity.' );
	}
	$total = intdiv( $unit_minor * $quantity_millis + 500, 1000 );
	return $total <= DXBS_MAX_MINOR ? $total : dxbs_error( 'amount_limit', 'The line total exceeds the supported limit.' );
}
/** Sum/installments in pence, preserving any remainder in the final installment. */
function dxbs_split_minor( $minor, $count ) {
	if ( ! is_int( $minor ) || ! is_int( $count ) || $minor < 0 || $minor > DXBS_MAX_MINOR || $count < 1 || $count > 120 ) {
		return dxbs_error( 'invalid_schedule', 'Enter a valid amount and installment count.' );
	}
	$part = intdiv( $minor, $count );
	$out = array_fill( 0, $count, $part );
	$out[ $count - 1 ] += $minor - ( $part * $count );
	return $out;
}
