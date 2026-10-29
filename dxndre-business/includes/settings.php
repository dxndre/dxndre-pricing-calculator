<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
function dxbs_settings_defaults() {
	return array(
		'business_name' => 'DXNDRE', 'legal_name' => '', 'company_type' => '',
		'address_1' => '', 'address_2' => '', 'city' => '', 'region' => '', 'postcode' => '', 'country' => 'GB',
		'email' => '', 'phone' => '', 'website' => '', 'company_number' => '',
		'vat_registered' => false, 'vat_number' => '', 'default_vat_basis_points' => 0,
		'bank_account_holder' => '', 'bank_name' => '', 'bank_sort_code' => '', 'bank_account_number' => '',
		'currency' => 'GBP', 'timezone' => 'Europe/London', 'version' => 1,
		'reminders_enabled' => false, 'reminder_days' => 7,
	);
}
/** No client-facing use: all settings, including bank details, require the business capability. */
function dxbs_settings() {
	if ( ! dxbs_is_manager() ) {
		return array();
	}
	$defaults = dxbs_settings_defaults();
	$stored = get_option( 'dxbs_settings', array() );
	$stored = is_array( $stored ) ? array_intersect_key( $stored, $defaults ) : array();
	$out = array_merge( $defaults, $stored );
	foreach ( $out as $key => $value ) {
		if ( ! in_array( $key, array( 'vat_registered', 'default_vat_basis_points', 'version', 'reminders_enabled', 'reminder_days' ), true ) ) {
			$out[ $key ] = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
		}
	}
	$out['reminders_enabled'] = (bool) $out['reminders_enabled'];
	$out['reminder_days'] = max( 1, min( 30, absint( $out['reminder_days'] ) ) );
	$out['vat_registered'] = (bool) $out['vat_registered'];
	$out['default_vat_basis_points'] = absint( $out['default_vat_basis_points'] );
	$out['version'] = max( 1, absint( $out['version'] ) );
	$out['currency'] = 'GBP';
	$out['timezone'] = 'Europe/London';
	return $out;
}
function dxbs_save_settings( $input ) {
	global $wpdb;
	if ( ! dxbs_is_manager() ) {
		return dxbs_require_manager();
	}
	if ( ! is_array( $input ) ) {
		return dxbs_error( 'invalid_settings', 'Business settings are required.' );
	}
	foreach ( $input as $value ) {
		if ( ! is_scalar( $value ) && null !== $value ) {
			return dxbs_error( 'invalid_settings', 'A business setting has an invalid format.' );
		}
	}
	$old = dxbs_settings();
	if ( isset( $input['version'] ) && absint( $input['version'] ) !== $old['version'] ) {
		return dxbs_error( 'stale_settings', 'Business settings changed elsewhere. Refresh and review the latest settings.' );
	}
	$data = dxbs_settings_defaults();
	$lengths = array( 'business_name' => 191, 'legal_name' => 191, 'address_1' => 191, 'address_2' => 191, 'city' => 120, 'region' => 120, 'postcode' => 32, 'phone' => 80, 'company_number' => 80, 'bank_account_holder' => 191, 'bank_name' => 191 );
	foreach ( $lengths as $key => $max ) {
		$data[ $key ] = sanitize_text_field( $input[ $key ] ?? '' );
		if ( mb_strlen( $data[ $key ] ) > $max ) {
			return dxbs_error( 'settings_field_length', 'One of the business fields is too long.' );
		}
	}
	if ( ! $data['business_name'] ) {
		return dxbs_error( 'business_name_required', 'Enter the name clients know your business by.' );
	}
	$data['company_type'] = sanitize_key( $input['company_type'] ?? '' );
	if ( ! in_array( $data['company_type'], array( '', 'sole_trader', 'limited_company', 'partnership', 'other' ), true ) ) {
		return dxbs_error( 'invalid_company_type', 'Choose a valid business type.' );
	}
	$data['country'] = strtoupper( trim( (string) ( $input['country'] ?? 'GB' ) ) );
	if ( ! preg_match( '/^[A-Z]{2}$/D', $data['country'] ) ) {
		return dxbs_error( 'invalid_country', 'Use a two-letter business country code, for example GB.' );
	}
	$data['email'] = strtolower( trim( (string) ( $input['email'] ?? '' ) ) );
	if ( $data['email'] && ( ! is_email( $data['email'] ) || strlen( $data['email'] ) > 191 ) ) {
		return dxbs_error( 'invalid_email', 'Enter a valid business email address.' );
	}
	$url = trim( (string) ( $input['website'] ?? '' ) );
	if ( $url && ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! in_array( strtolower( wp_parse_url( $url, PHP_URL_SCHEME ) ?? '' ), array( 'http', 'https' ), true ) ) ) {
		return dxbs_error( 'invalid_website', 'Enter a complete HTTP or HTTPS website address.' );
	}
	$data['website'] = esc_url_raw( $url, array( 'http', 'https' ) );
	$data['vat_registered'] = ! empty( $input['vat_registered'] );
	if ( $data['vat_registered'] ) {
		$data['vat_number'] = strtoupper( sanitize_text_field( $input['vat_number'] ?? '' ) );
		$rate = (string) ( $input['default_vat_basis_points'] ?? '' );
		if ( ! $data['vat_number'] || strlen( $data['vat_number'] ) > 40 || ! preg_match( '/^[0-9]{1,5}$/D', $rate ) || (int) $rate > 10000 ) {
			return dxbs_error( 'invalid_vat', 'When VAT is enabled, enter the VAT registration number and a default tax rate in basis points (2000 = 20%).' );
		}
		$data['default_vat_basis_points'] = (int) $rate;
	}
	foreach ( array( 'bank_sort_code' => 6, 'bank_account_number' => 8 ) as $key => $digits ) {
		$raw = trim( (string) ( $input[ $key ] ?? '' ) );
		$value = preg_replace( '/[ -]/', '', $raw );
		if ( $value && ! preg_match( '/^[0-9]{' . $digits . '}$/D', $value ) ) {
			return dxbs_error( 'invalid_bank_details', 'Enter a six-digit sort code and eight-digit account number, or leave bank details blank.' );
		}
		$data[ $key ] = $value;
	}
	$has_bank = $data['bank_account_holder'] || $data['bank_name'] || $data['bank_sort_code'] || $data['bank_account_number'];
	if ( $has_bank && ( ! $data['bank_account_holder'] || ! $data['bank_sort_code'] || ! $data['bank_account_number'] ) ) {
		return dxbs_error( 'incomplete_bank_details', 'Bank instructions need the actual account-holder name, sort code and account number together.' );
	}
	$data['reminders_enabled'] = ! empty( $input['reminders_enabled'] );
	$data['reminder_days'] = max( 1, min( 30, absint( $input['reminder_days'] ?? 7 ) ) );
	$data['version'] = $old['version'] + 1;
	$changed = array();
	foreach ( $data as $key => $value ) {
		if ( $value !== $old[ $key ] && 'version' !== $key ) {
			$changed[] = $key;
		}
	}
	if ( ! $changed ) {
		return true;
	}
	$wpdb->query( 'START TRANSACTION' );
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE", 'dxbs_settings' ) );
	$locked = maybe_unserialize( $raw );
	if ( is_array( $locked ) && absint( $locked['version'] ?? 1 ) !== $old['version'] ) {
		$wpdb->query( 'ROLLBACK' );
		return dxbs_error( 'stale_settings', 'Business settings changed elsewhere. Refresh before saving.' );
	}
	$saved = update_option( 'dxbs_settings', $data, false );
	if ( ! $saved || ! dxbs_audit( 'settings_updated', 'settings', 0, array( 'fields' => $changed, 'version' => $data['version'] ) ) ) {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_delete( 'dxbs_settings', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return dxbs_error( 'settings_save_failed', 'Business settings could not be saved. No change was applied.' );
	}
	$wpdb->query( 'COMMIT' );
	return true;
}
