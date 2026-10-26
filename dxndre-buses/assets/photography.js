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
		$('#dxb-gallery-empty').hidden = count > 0;
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
})();