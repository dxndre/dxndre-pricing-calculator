<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
function dxbs_form_notice() {
	if ( ! dxbs_is_manager() ) {
		return null;
	}
	$notice = get_transient( 'dxbs_notice_' . get_current_user_id() );
	delete_transient( 'dxbs_notice_' . get_current_user_id() );
	return is_array( $notice ) && isset( $notice['type'], $notice['message'] ) ? $notice : null;
}
function dxbs_form_redirect( $view, $result ) {
	$is_error = is_wp_error( $result );
	set_transient( 'dxbs_notice_' . get_current_user_id(), array( 'type' => $is_error ? 'error' : 'success', 'message' => $is_error ? $result->get_error_message() : 'Your Business record has been saved.' ), 5 * MINUTE_IN_SECONDS );
	$args = array( 'section' => 'business', 'business_view' => $view, 'business_notice' => $is_error ? 'error' : 'saved' );
	wp_safe_redirect( add_query_arg( $args, home_url( '/dashboard/' ) ) );
	exit;
}
function dxbs_handle_form( $action, $field, $callback, $view ) {
	if ( ! dxbs_is_manager() ) {
		wp_die( 'You do not have permission to manage business records.', 'Permission denied', array( 'response' => 403 ) );
	}
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), $action ) ) {
		wp_die( 'Your session expired. Refresh the dashboard and try again.', 'Invalid request', array( 'response' => 403 ) );
	}
	$input = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : array();
	dxbs_form_redirect( $view, call_user_func( $callback, $input ) );
}
add_action( 'admin_post_dxbs_save_client', function () {
	dxbs_handle_form( 'dxbs_save_client', 'client', 'dxbs_save_client', 'clients' );
} );
add_action( 'admin_post_dxbs_save_category', function () {
	dxbs_handle_form( 'dxbs_save_category', 'category', 'dxbs_save_category', 'settings' );
} );
add_action( 'admin_post_dxbs_save_settings', function () {
	dxbs_handle_form( 'dxbs_save_settings', 'settings', 'dxbs_save_settings', 'settings' );
} );
