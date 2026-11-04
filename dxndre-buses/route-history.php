<?php
/** Keep distinct operating periods separate when sources use lists or line breaks. */
defined( 'ABSPATH' ) || exit;

function dxb_operating_dates( $value ) {
	$html = $value instanceof DOMNode ? $value->ownerDocument->saveHTML( $value ) : (string) $value;
	$html = preg_replace( '~<sup\b[^>]*>.*?</sup>|<span\b[^>]*class=["\'][^"\']*\bbday\b[^"\']*["\'][^>]*>.*?</span>~is', ' ', $html );
	$text = html_entity_decode( preg_replace( '~<[^>]+>~', ' ', $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/\[[^\]]*\]|\([^)]*\)/', ' ', $text );
	$months = 'January|February|March|April|May|June|July|August|September|October|November|December';
	preg_match_all( '/(?:\d{1,2}\s*)?(?:' . $months . ')\s*(?:18|19|20)\d{2}|\d{1,2}\/\d{1,2}\/(?:18|19|20)\d{2}|(?<!\d)(?:18|19|20)\d{2}(?!\d)/iu', $text, $matches );
	$dates = array();
	foreach ( $matches[0] as $date ) {
		$date = preg_replace( array( '/(\d)([A-Za-z])/', '/([A-Za-z])(\d)/', '/\s+/u' ), array( '$1 $2', '$1 $2', ' ' ), trim( $date ) );
		if ( preg_match( '/^\d{1,2} [A-Za-z]+ \d{4}$/', $date ) ) {
			$parsed = DateTimeImmutable::createFromFormat( '!j F Y', $date, new DateTimeZone( 'UTC' ) );
			if ( ! $parsed || $parsed->format( 'j F Y' ) !== ltrim( $date, '0' ) ) { continue; }
		}
		$dates[] = $date;
	}
	return array_values( array_unique( $dates ) );
}

function dxb_operating_date_year( $date ) {
	return preg_match( '/(?:18|19|20)\d{2}/', $date, $match ) ? (int) $match[0] : 0;
}

function dxb_operating_date_order( $start, $end ) {
	if ( dxb_operating_date_year( $start ) > dxb_operating_date_year( $end ) ) { return false; }
	if ( preg_match( '/^\d{1,2} [A-Za-z]+ \d{4}$/', $start ) && preg_match( '/^\d{1,2} [A-Za-z]+ \d{4}$/', $end ) ) {
		return strtotime( $start . ' UTC' ) <= strtotime( $end . ' UTC' );
	}
	return true;
}

function dxb_route_history_fallback( $profile ) {
	$dates = ! empty( $profile['withdrawnDates'] ) ? $profile['withdrawnDates'] : dxb_operating_dates( $profile['withdrawnDate'] ?? '' );
	$history = array( 'end' => $dates ? end( $dates ) : '', 'source' => $profile['listingSource'] ?? '' );
	if ( count( $dates ) > 1 ) { $history['periods'] = array_map( static function ( $date ) { return array( 'end' => $date ); }, $dates ); }
	return $history;
}

/** A matched list is paired in source order; unmatched dates are labelled individually. */
function dxb_parse_operating_history( $html, $profile ) {
	$fallback = dxb_route_history_fallback( $profile );
	$x = dxb_doc( $html );
	$starts = array(); $ends = array();
	foreach ( $x->query( '//table[contains(concat(" ",normalize-space(@class)," ")," infobox ")]//tr' ) as $row ) {
		$th = $x->query( './th', $row )->item( 0 ); $td = $x->query( './td', $row )->item( 0 );
		if ( ! $th || ! $td ) { continue; }
		$label = strtolower( dxb_clean( $th ) );
		if ( in_array( $label, array( 'began service', 'commenced service', 'first service', 'introduced' ), true ) ) { $starts = array_merge( $starts, dxb_operating_dates( $td ) ); }
		if ( in_array( $label, array( 'ended service', 'last service', 'withdrawn' ), true ) ) { $ends = array_merge( $ends, dxb_operating_dates( $td ) ); }
	}
	$starts = array_values( array_unique( $starts ) ); $ends = array_values( array_unique( $ends ) );
	if ( ! $starts || ! $ends ) { return $fallback; }
	if ( ! empty( $fallback['end'] ) && dxb_operating_date_year( $fallback['end'] ) && dxb_operating_date_year( $fallback['end'] ) !== dxb_operating_date_year( end( $ends ) ) ) { return $fallback; }
	$paired = count( $starts ) === count( $ends );
	if ( $paired ) { foreach ( $starts as $index => $start ) { if ( ! dxb_operating_date_order( $start, $ends[ $index ] ) ) { $paired = false; break; } } }
	$periods = array();
	if ( $paired ) {
		foreach ( $starts as $index => $start ) { $periods[] = array( 'start' => $start, 'end' => $ends[ $index ] ); }
	} else {
		foreach ( $starts as $start ) { $periods[] = array( 'start' => $start ); }
		foreach ( $ends as $end ) { $periods[] = array( 'end' => $end ); }
	}
	$history = array( 'periods' => $periods, 'source' => $profile['historySource'] ?? $fallback['source'] );
	if ( $paired ) {
		$history['tenure'] = implode( ' · ', array_map( static function ( $period ) { return dxb_operating_date_year( $period['start'] ) . '–' . dxb_operating_date_year( $period['end'] ); }, $periods ) );
	}
	// Backward-compatible scalar fields only describe one continuous operating period.
	if ( count( $periods ) === 1 && $paired ) { $history['start'] = $starts[0]; $history['end'] = $ends[0]; }
	return $history;
}

/** Read explicit operating dates only; never infer an opening year from a route number. */
function dxb_route_history( $id, $profile ) {
	if ( '82' === $id ) {
		return array( 'tenure' => '1986–2017', 'start' => '21 June 1986', 'end' => '1 April 2017', 'periods' => array( array( 'start' => '21 June 1986', 'end' => '1 April 2017' ) ), 'source' => 'https://www.londonbuses.co.uk/_routes/withdrawn/082-3.html' );
	}
	if ( empty( $profile['historySource'] ) ) { return dxb_route_history_fallback( $profile ); }
	$html = dxb_public_source( 'history_' . strtolower( $id ), $profile['historySource'] );
	return is_wp_error( $html ) ? dxb_route_history_fallback( $profile ) : dxb_parse_operating_history( $html, $profile );
}
