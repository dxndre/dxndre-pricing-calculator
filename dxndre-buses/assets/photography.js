(()=>{const $=s=>document.querySelector(s),$$=s=>[...document.querySelectorAll(s)];const text=(tag,value,cls='')=>{const e=document.createElement(tag);e.textContent=value;e.className=cls;return e;};
	let photoFilter = 'all',
		photoIndex = 0,
		lastFocus = null;
	const lightbox = $('#dxb-lightbox');
	function matchesPhoto(card, filter) {
 const route=card.dataset.photoRoute.toUpperCase(),scenery=card.dataset.photoScenery||'';
 if(filter==='all')return true;
 if(filter==='buses')return Boolean(route);
 if(filter==='scenery')return !route;
 if(filter.startsWith('scenery-'))return !route&&scenery===filter.slice(8);
 if(filter==='night')return /^N\d/.test(route);
 if(filter==='lettered')return /[A-Z]/.test(route)&&!/^N\d/.test(route);
 if(filter.startsWith('range-')){const min=Number(filter.slice(6));return /^\d+$/.test(route)&&Number(route)>=min&&(min===401||Number(route)<min+100);}
 return route===filter;
}

	function filterPhotos() {
		const query = $('#dxb-photo-search').value.trim().toLowerCase();
		let count = 0;
		$$('#dxb-gallery [data-photo]').forEach((card) => {
			const show =
				matchesPhoto(card, photoFilter) &&
				card.dataset.photoSearch.includes(query);
			card.hidden = !show;
			if (show) count++;
		});
		$('#dxb-gallery-empty').hidden = photoView !== 'gallery' || count > 0;
		if (archiveMap) refreshPhotoMap();
	}
	$$('[data-photo-filter]').forEach((button) =>
		button.addEventListener('click', () => {
			photoFilter = button.dataset.photoFilter;
 const group=photoFilter==='all'?'':photoFilter.startsWith('scenery')?'scenery':'buses';document.querySelectorAll('[data-photo-group]').forEach(el=>el.hidden=el.dataset.photoGroup!==group);
			$$('[data-photo-filter]').forEach((b) =>
				b.setAttribute('aria-pressed', String(b === button)),
			);
			filterPhotos();
		}),
	);
	$('#dxb-photo-search').addEventListener('input', filterPhotos);
	function renderPhoto() {
		const p = DXB.photos[photoIndex];
		if (!p) return;
		window.dxSetPhoto?.($('#dxb-lightbox-image'), p);
		$('#dxb-lightbox-route').textContent = p.route
			? `Route ${p.route}`
			: 'Bus photography';
		$('#dxb-lightbox-title').textContent = p.title;
		$('#dxb-lightbox-caption').textContent = p.caption;
		$('#dxb-lightbox-meta').textContent = [
			p.vehicle,
			p.operator,
			p.location,
			p.date,
		]
			.filter(Boolean)
			.join(' · ');
		const technical=$('#dxb-photo-technical'), map=$('#dxb-photo-map');
        technical.replaceChildren();map.replaceChildren();
        $('#dxb-bad-photo').hidden=!p.bad;
        lightbox.classList.remove('is-zoomed');lightbox.querySelector('#dxb-lightbox-image').style.transform='';
        photoImage.setAttribute('aria-pressed', 'false');
        photoImage.setAttribute('aria-label', 'Zoom photograph');
        const rawDate=p.captured || p.date;
        const captured=new Date(rawDate), cutoff=new Date();cutoff.setFullYear(cutoff.getFullYear()-5);cutoff.setHours(0,0,0,0);
        let old=$('#dxb-old-photo');if(!old){old=text('span','◷ Old Photo');old.id='dxb-old-photo';$('#dxb-bad-photo').after(old);}
        old.hidden=!(Number.isFinite(captured.getTime())&&captured<cutoff);
        old.title='Photographed more than five calendar years ago.';
        Object.entries(p.technical || {}).forEach(([label,value])=>{if(label!=='gps'){technical.append(text('dt',label),text('dd',String(value)));}});
        const gps=p.technical?.gps;
        if(gps){
            window.dxRenderPhotoMap?.(map,gps,p);
        } else if(p.location){ const label=text('p','📍 '+p.location);label.className='dx-photo-location';map.append(label); }
        $('#dxb-lightbox-count').textContent = `${photoIndex + 1} / ${
			DXB.photos.length
		}`;
	}
	function openPhoto(index) {
		photoIndex = index;
		renderPhoto();
		if (!lightbox.open) {
			lastFocus = document.activeElement;
			lightbox.showModal();
			document.body.classList.add('dxb-lightbox-open');
		}
	}
	$$('#dxb-gallery [data-photo]').forEach((card) =>
		card.addEventListener('click', (event) => {
			event.preventDefault();
			openPhoto(Number(card.dataset.photo));
		}),
	);
const photoImage=$('#dxb-lightbox-image');
 const photoViewport=document.createElement('div');photoViewport.className='dxb-photo-viewport';photoImage.before(photoViewport);photoViewport.append(photoImage);
photoImage.tabIndex=0;photoImage.setAttribute('role','button');photoImage.setAttribute('aria-label','Zoom photograph');
let panX = 0,
		panY = 0,
		drag = null,
		moved = false;
	const paintPan = () => {
		photoImage.style.transform = lightbox.classList.contains("is-zoomed")
			? `translate(${panX}px, ${panY}px) scale(2)`
			: "";
	};
	const toggleZoom = (event) => {
		if (moved) {
			moved = false;
			return;
		}
		const zoomed = lightbox.classList.toggle("is-zoomed");
		const r = photoViewport.getBoundingClientRect();
		panX = event?.clientX ? r.left + r.width / 2 - event.clientX : 0;
		panY = event?.clientY ? r.top + r.height / 2 - event.clientY : 0;
		paintPan();
		photoImage.setAttribute(
			"aria-label",
			zoomed ? "Fit photograph. Drag to explore." : "Zoom photograph",
		);
		photoImage.setAttribute("aria-pressed", String(zoomed));
	};
	photoViewport.addEventListener("click", toggleZoom);
	photoViewport.addEventListener("pointerdown", (e) => {
		if (!lightbox.classList.contains("is-zoomed")) return;
		drag = { x: e.clientX, y: e.clientY, px: panX, py: panY };
		moved = false;
		photoImage.style.transition = "none";
		photoViewport.setPointerCapture(e.pointerId);
	});
	photoViewport.addEventListener("pointermove", (e) => {
		if (!drag) return;
		const dx = e.clientX - drag.x,
			dy = e.clientY - drag.y;
		if (Math.abs(dx) + Math.abs(dy) > 5) moved = true;
		panX = Math.max(
			-photoViewport.clientWidth / 2,
			Math.min(photoViewport.clientWidth / 2, drag.px + dx),
		);
		panY = Math.max(
			-photoViewport.clientHeight / 2,
			Math.min(photoViewport.clientHeight / 2, drag.py + dy),
		);
		paintPan();
	});
	["pointerup", "pointercancel"].forEach((type) =>
		photoViewport.addEventListener(type, () => {
			drag = null;
			photoImage.style.transition = "";
		}),
	);
	photoImage.addEventListener("keydown", (e) => {
		if (e.key === "Enter" || e.key === " ") {
			e.preventDefault();
			toggleZoom();
		}
	});
	function movePhoto(delta) {
		photoIndex = (photoIndex + delta + DXB.photos.length) % DXB.photos.length;
		renderPhoto();
	}
	$('[data-lightbox-prev]').addEventListener('click', () => movePhoto(-1));
	$('[data-lightbox-next]').addEventListener('click', () => movePhoto(1));
	$('.dxb-close').addEventListener('click', () => lightbox.close());
	lightbox.addEventListener('close', () => {
		document.body.classList.remove('dxb-lightbox-open');
		lastFocus?.focus();
	});
	lightbox.addEventListener('click', (event) => {
		if (event.target === lightbox) {
			const r = lightbox.getBoundingClientRect();
			if (
				event.clientX < r.left ||
				event.clientX > r.right ||
				event.clientY < r.top ||
				event.clientY > r.bottom
			)
				lightbox.close();
		}
	});
	lightbox.addEventListener('keydown', (event) => {
		if (event.key === 'ArrowLeft') movePhoto(-1);
		if (event.key === 'ArrowRight') movePhoto(1);
	});
	// One lazy map shares the archive filters and the existing enlarged-photo viewer.
	const mapPanel = $('#dx-photo-map-panel'), viewTabs = $$('[data-photo-view]');
	let archiveMap = null, photoMarkers = null, preview = null, previewTimer = 0, photoView = 'gallery';
	const validGPS = photo => {
		const gps = photo.technical?.gps;
		if (!gps || gps.lat === '' || gps.lon === '' || gps.lat == null || gps.lon == null) return null;
		const lat = Number(gps.lat), lon = Number(gps.lon);
		return Number.isFinite(lat) && Number.isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180 ? [lat, lon] : null;
	};
	const photoLabel = photo => photo.route ? `Route ${photo.route}` : photo.title || 'Photograph';
	function hideMapPreview() {
		clearTimeout(previewTimer);
		previewTimer = setTimeout(() => {
			if (document.activeElement?.closest('.dx-photo-map-pin, .dx-photo-map-tooltip')) return;
			preview?.getElement()?.classList.remove('is-visible');
		}, 160);
	}
	function showMapPreview(point, indices) {
		clearTimeout(previewTimer);
		const first = DXB.photos[indices[0]], content = text('div', '', 'dx-photo-map-preview');
		const open = text('button', '', 'dx-photo-map-preview__photo'); open.type = 'button';
		const image = document.createElement('img'); image.src = first.image; image.alt = first.alt || first.title; image.decoding = 'async';
		const caption = text('span', '', 'dx-photo-map-preview__caption');
		caption.append(text('strong', photoLabel(first)));
		if (first.location) caption.append(text('small', first.location));
		open.append(image, caption); open.addEventListener('click', () => openPhoto(indices[0])); content.append(open);
		if (indices.length > 1) {
			const choices = text('div', '', 'dx-photo-map-preview__choices');
			choices.setAttribute('aria-label', 'Photographs at this location');
			indices.forEach((index, order) => {
				const button = text('button', `${order + 1}. ${photoLabel(DXB.photos[index])}`); button.type = 'button';
				button.addEventListener('click', () => openPhoto(index)); choices.append(button);
			}); content.append(choices);
		}
		if (!preview) {
			preview = L.tooltip({ permanent: true, interactive: true, direction: 'top', offset: [0, -14], className: 'dx-photo-map-tooltip' });
			preview.setLatLng(point).setContent(content).addTo(archiveMap);
			const element = preview.getElement();
			element.addEventListener('mouseenter', () => clearTimeout(previewTimer));
			element.addEventListener('mouseleave', hideMapPreview);
			element.addEventListener('focusin', () => clearTimeout(previewTimer));
			element.addEventListener('focusout', hideMapPreview);
		} else preview.setLatLng(point).setContent(content);
		requestAnimationFrame(() => {
			const element = preview.getElement(), bounds = archiveMap.getContainer().getBoundingClientRect();
			element.style.marginLeft = ''; element.style.marginTop = '';
			const rect = element.getBoundingClientRect();
			const x = Math.max(0, bounds.left + 12 - rect.left) + Math.min(0, bounds.right - 12 - rect.right);
			const y = Math.max(0, bounds.top + 12 - rect.top);
			element.style.marginLeft = `${x}px`; element.style.marginTop = `${y}px`;
			element.classList.add('is-visible');
		});
	}
	function refreshPhotoMap(fit = true) {
		if (!archiveMap) return;
		photoMarkers.clearLayers(); preview?.getElement()?.classList.remove('is-visible');
		const indices = $$('#dxb-gallery [data-photo]').filter(card => !card.hidden).map(card => Number(card.dataset.photo));
		const groups = new Map(); let located = 0;
		indices.forEach(index => {
			const point = validGPS(DXB.photos[index]); if (!point) return;
			located++;
			const key = point.map(value => value.toFixed(5)).join(',');
			if (!groups.has(key)) groups.set(key, { point, indices: [] });
			groups.get(key).indices.push(index);
		});
		groups.forEach(({ point, indices }) => {
			const marker = L.marker(point, {
				icon: L.divIcon({ className: 'dx-photo-map-pin', html: `<span aria-hidden="true"><b>${indices.length > 1 ? indices.length : ''}</b></span>`, iconSize: [28, 34], iconAnchor: [14, 32] }),
				title: '', keyboard: true, autoPanOnFocus: false, alt: indices.length === 1 ? photoLabel(DXB.photos[indices[0]]) : `${indices.length} photographs at this location`,
			}).addTo(photoMarkers);
			marker.on('mouseover', () => showMapPreview(point, indices)); marker.on('mouseout', hideMapPreview);
			marker.on('click', () => {
				if (indices.length === 1) openPhoto(indices[0]);
				else { showMapPreview(point, indices); requestAnimationFrame(() => preview.getElement().querySelector('button')?.focus()); }
			});
			const element = marker.getElement();
			element.setAttribute('aria-label', `${photoLabel(DXB.photos[indices[0]])}${indices.length > 1 ? ` and ${indices.length - 1} more photographs` : ''}. Open photograph.`);
			element.addEventListener('focus', () => showMapPreview(point, indices)); element.addEventListener('blur', hideMapPreview);
		});
		const missing = indices.length - located;
		$('#dx-photo-map-count').textContent = `${located} photograph${located === 1 ? '' : 's'} with saved locations${missing ? ` · ${missing} without location data` : ''}.`;
		if (fit && groups.size) archiveMap.fitBounds([...groups.values()].map(group => group.point), { padding: [40, 50], maxZoom: 13, animate: false });
	}
	function showPhotoView(view) {
		photoView = view;
		viewTabs.forEach(button => { const active = button.dataset.photoView === view; button.setAttribute('aria-selected', String(active)); button.tabIndex = active ? 0 : -1; });
		$('#dxb-gallery').hidden = view !== 'gallery'; mapPanel.hidden = view !== 'map';
		$('#dxb-gallery-empty').hidden = view !== 'gallery' || $$('#dxb-gallery [data-photo]').some(card => !card.hidden);
		if (view !== 'map') return;
		if (!window.L) { $('#dx-photo-map-count').textContent = 'The map could not load. You can still browse every photograph in Gallery view.'; return; }
		if (!archiveMap) {
			archiveMap = L.map($('#dx-photo-archive-map'), { scrollWheelZoom: false }).setView([51.5, -.12], 10);
			const tileUrl = "https://basemaps.cartocdn.com/rastertiles/dark_all/{z}/{x}/{y}.png?key=cb1_49v4_1_6f4e11cc72c4a39104c6f6c9";
			const baseLayer = L.tileLayer(tileUrl.replace('dark_all', 'dark_nolabels'), { maxZoom: 20, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>' }).addTo(archiveMap);
			archiveMap.createPane('photo-archive-labels').style.zIndex = '350';
			const labels = L.tileLayer(tileUrl.replace('dark_all', 'dark_only_labels'), { maxZoom: 20, pane: 'photo-archive-labels' });
			labels.once('tileerror', () => { labels.remove(); baseLayer.setUrl(tileUrl); });
			labels.addTo(archiveMap);
			photoMarkers = L.layerGroup().addTo(archiveMap);
			archiveMap.getContainer().addEventListener('keydown', event => { if (event.key === 'Escape') { clearTimeout(previewTimer); preview?.getElement()?.classList.remove('is-visible'); } });
			archiveMap.on('movestart', () => preview?.getElement()?.classList.remove('is-visible'));
		}
		requestAnimationFrame(() => { archiveMap.invalidateSize(); refreshPhotoMap(); });
	}
	viewTabs.forEach((button, index) => {
		button.addEventListener('click', () => showPhotoView(button.dataset.photoView));
		button.addEventListener('keydown', event => {
			let target = null;
			if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') target = viewTabs[(index + (event.key === 'ArrowLeft' ? -1 : 1) + viewTabs.length) % viewTabs.length];
			else if (event.key === 'Home') target = viewTabs[0]; else if (event.key === 'End') target = viewTabs[viewTabs.length - 1];
			if (target) { event.preventDefault(); target.click(); target.focus(); }
		});
	});

})();