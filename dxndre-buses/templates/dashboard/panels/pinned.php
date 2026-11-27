<?php defined( 'ABSPATH' ) || exit; ?>
<header class="dxbus-view__head"><p class="dxbus-eyebrow">Keep an eye on your favourites</p><h2>Pinned buses</h2><p>Your saved registrations stay in this browser. Open a bus to see its latest predictions across the network.</p></header>
<div class="dxbus-pinned-modes dxbus-segment" aria-label="Pinned bus view">
	<button type="button" data-pinned-mode="list" aria-pressed="true">List</button>
	<button type="button" data-pinned-mode="map" aria-pressed="false">Map</button>
<button type="button" data-pinned-mode="routes" aria-pressed="false">Pinned Routes</button></div>
<form data-pinned-route-form hidden class="dxbus-pinned-route-form"><label for="dxbus-plot-route">Add a route to the map</label><div><input id="dxbus-plot-route" type="text" maxlength="6" placeholder="e.g. H32" required autocomplete="off"><button type="submit" class="dxbus-button">Plot route</button></div><p>Up to 8 routes, saved on this browser. Bus markers show next predicted stops.</p><div data-pinned-route-list aria-label="Plotted routes"></div></form>
<p id="dxbus-pinned-map-status" role="status" hidden></p>
<div class="dxbus-map dxbus-map--large dxbus-map--osm" id="dxbus-pinned-map" role="region" aria-label="Predicted stops for pinned buses" hidden></div><div class="dxbus-card"><div class="dxbus-card__body" id="dxb-marked"><p class="dxbus-empty">No pinned buses yet. Pin a registration from Live tracking or Route explorer.</p></div></div>
