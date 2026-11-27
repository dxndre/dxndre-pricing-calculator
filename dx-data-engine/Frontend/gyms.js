import {loadDataset} from './loader.js';
const defaults = {view:'overview',q:'',chain:'',location:'',facility:'',price:'',open24:'',sort:'overall',page:1,compare:[],display:'map'};
const views = ['overview','league','explorer','compare','finder','map','reviews','insights'];
const categories = {gym:'Gym',swim:'Wetside',spa:'Spa',cafe:'Café',clean:'Cleanliness',parking:'Parking'};
const limits = {view:16,q:80,chain:64,location:120,facility:16,price:16,open24:1,sort:16,page:4,compare:64,display:8};
const fold = text => String(text).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
export function parseState(input, data) {
	const url = input instanceof URL ? input : new URL(input, location.href);
	const raw = url.hash.startsWith('#') && url.hash.length > 1 ? url.hash.slice(1) : url.search.slice(1);
	const state = {...defaults,compare:[]};
	if (raw.length > 2048 || /%(?![a-f0-9]{2})/i.test(raw)) return state;
	const params = new URLSearchParams(raw); const entries = [...params]; if (entries.length > 16) return state;
	for (const [key,value] of entries) if (Object.hasOwn(limits,key) && params.getAll(key).length === 1 && value.length <= limits[key] && !/[\x00-\x1f\x7f]/.test(value)) state[key] = value;
	if (!views.includes(state.view)) state.view = defaults.view;
	if (!['overall','name','recent',...Object.keys(categories)].includes(state.sort)) state.sort = defaults.sort;
	if (!['budget','midrange','premium','luxury'].includes(state.price)) state.price = '';
	if (![...Object.keys(categories),'padel'].includes(state.facility)) state.facility = '';
	if (!data.gyms.some(g => g.chainKey === state.chain)) state.chain = '';
	if (!data.gyms.some(g => g.location === state.location)) state.location = '';
	state.q = state.q.trim().replace(/\s+/g,' '); state.open24 = state.open24 === '1' ? '1' : '';
	state.display = state.display === 'list' ? 'list' : 'map';
	const known = new Set(data.gyms.map(g => g.id));
	state.compare = typeof state.compare === 'string' && /^\d{1,9}(,\d{1,9}){0,20}$/.test(state.compare) ? [...new Set(state.compare.split(',').map(Number))].filter(id => known.has(id)).sort((a,b)=>a-b).slice(0,3) : [];
	state.page = /^\d{1,4}$/.test(String(state.page)) ? Math.max(1,Math.min(9999,Number(state.page))) : 1;
	return state;
}
export function stateURL(state, base, fragment = true) {
	const url = new URL(base, location.href); url.search = ''; url.hash = ''; const params = new URLSearchParams();
	for (const key of Object.keys(defaults)) {
		const value = key === 'compare' ? [...state.compare].sort((a,b)=>a-b).join(',') : state[key];
		if (String(value) !== String(defaults[key]) && value !== '') params.set(key,String(value));
	}
	if (fragment) url.hash = params.toString(); else url.search = params.toString();
	return url;
}
export function filterRecords(records, state) {
	const query = fold(state.q);
	return records.filter(g => (!query || fold(`${g.name} ${g.location} ${g.chain}`).includes(query)) && (!state.chain || g.chainKey === state.chain) && (!state.location || g.location === state.location) && (!state.price || g.price === state.price) && (!state.open24 || g.open24 === true) && (!state.facility || (state.facility === 'padel' ? g.padel === 'available' : typeof g.scores[state.facility] === 'number'))).sort((a,b) => {
		if (state.sort === 'name') return a.name.localeCompare(b.name,'en',{sensitivity:'base'});
		if (state.sort === 'recent') return (b.dateISO || b.publishedISO).localeCompare(a.dateISO || a.publishedISO) || b.id-a.id;
		const av = state.sort === 'overall' ? a.overall : a.scores[state.sort]; const bv = state.sort === 'overall' ? b.overall : b.scores[state.sort];
		return (typeof bv === 'number'?bv:-1)-(typeof av === 'number'?av:-1) || a.name.localeCompare(b.name,'en',{sensitivity:'base'}) || a.id-b.id;
	});
}
function validate(data) {
	if (!data || !Array.isArray(data.gyms) || !data.gyms.length || data.gyms.length > 2000 || !Array.isArray(data.reviews) || data.reviews.length > 2000 || !Array.isArray(data.fragments) || !views.every(key => typeof data.views?.[key] === 'string')) throw new Error('Invalid gym dataset');
	for (const g of [...data.gyms,...data.reviews]) if (!Number.isSafeInteger(g.id) || g.id < 1 || typeof g.name !== 'string' || typeof g.url !== 'string' || !g.scores || !g.hours || (g.lat !== null && (!Number.isFinite(g.lat) || Math.abs(g.lat)>90)) || (g.lng !== null && (!Number.isFinite(g.lng) || Math.abs(g.lng)>180))) throw new Error('Invalid public gym record');
	if (new Set(data.gyms.map(g=>g.id)).size !== data.gyms.length) throw new Error('Duplicate gym identity');
}
const template = html => { const node = document.createElement('template'); node.innerHTML = html; return node; };
export async function createGymClient(manifestURL, base) {
	const envelope = await loadDataset(manifestURL, {id:'gyms',schemaVersion:1,validate});
	const data = envelope.data; const fragments = new Map(data.fragments.map(f=>[f.id,f])); const templates = new Map();
	const get = (id, key) => { const cache = `${id}:${key}`; if (!templates.has(cache)) templates.set(cache,template(fragments.get(id)?.[key] || '')); return templates.get(cache).content.cloneNode(true); };
	const normal = input => parseState(input,data);
	const canonical = input => { const url = new URL(input,base); if (url.origin!==new URL(base).origin || url.pathname!==new URL(base).pathname) return null; return stateURL(normal(url),base,false); };
	const publicURL = input => stateURL(normal(input),base,true);
	const render = input => {
		const started = performance.now(); const state = normal(input); const view = state.view;
		const root = template(data.views[view]).content;
		const records = view === 'reviews' ? data.reviews : data.gyms;
		const results = filterRecords(records,state); const size = view === 'league' ? 10 : view === 'compare' ? 12 : 9;
		const pages = Math.max(1,Math.ceil(results.length/size)); state.page = Math.min(state.page,pages);
		const batch = results.slice((state.page-1)*size,state.page*size);
		root.querySelectorAll('[data-gym-filters]').forEach(form => { for (const key of Object.keys(defaults)) { const field = form.elements.namedItem(key); if (!field) continue; if (field.type==='checkbox') field.checked=!!state[key]; else field.value=key==='compare'?state.compare.join(','):String(state[key]); } });
		const summary = root.querySelector('[data-gym-list-summary]');
		if (summary) {
			const unit = view==='reviews'?'recorded visits':results.length===1?'gym':'gyms'; summary.dataset.total=results.length; summary.dataset.sort=state.sort;
			const descriptions = [state.q,state.chain&&data.gyms.find(g=>g.chainKey===state.chain)?.chain,state.location,state.facility&&categories[state.facility],state.price,state.open24&&'24/7'].filter(Boolean).join(' · ');
			summary.dataset.filters=descriptions; const old=summary.querySelector('[data-summary-filter]'); if(old)old.remove();
			if(descriptions){const label=document.createElement('span');label.dataset.summaryFilter='';label.className='dxgym-summary-filter';label.textContent=' matching '+descriptions;summary.querySelector('[data-gym-summary-count]').closest('label').after(label);}
			const count=summary.querySelector('[data-gym-summary-count]');const available=filterRecords(records,{...state,chain:''});const counts=new Map();for(const g of available){if(g.chainKey){const entry=counts.get(g.chainKey)||{name:g.chain,count:0};entry.count++;counts.set(g.chainKey,entry);}}
			count.replaceChildren(new Option(`All Chains · ${available.length}`,''));count.options[0].dataset.summaryLabel=`${results.length} ${unit}`;
			for(const [key,c] of [...counts].sort((a,b)=>a[1].name.localeCompare(b[1].name))){const option=new Option(`${c.name} · ${c.count}`,key);option.dataset.summaryLabel=`${results.length} ${unit}`;count.append(option);}count.value=state.chain;
			summary.querySelector('[data-gym-summary-sort]').value=state.sort;
		}
		const counter = root.querySelector('.dxgym-results-count'); if(counter)counter.textContent=results.length?`Showing ${(state.page-1)*size+1}–${Math.min(results.length,state.page*size)} of ${results.length} ${view==='reviews'?'recorded visits':'gyms'}`:'No matching gyms';
		const list = root.querySelector('.dxgym-table tbody, .dxgym-card-grid, .dxgym-compare-options');
		if(list){list.replaceChildren();batch.forEach((g,i)=>{if(view==='compare'){const row=document.createElement('div');const a=document.createElement('a');a.href=g.url;a.textContent=g.name;row.append(a);const f=get(g.id,'card');const score=f.querySelector('.dxgym-score');if(score)row.append(score);const control=f.querySelector('[data-compare-id]');if(control)row.append(control);list.append(row);}else{const f=get(g.id,view==='league'?'row':view==='reviews'?'reviewCard':'card');const item=f.firstElementChild;if(item)item.dataset.gymRecord=g.id;if(view==='league'&&item){item.querySelector('.dxgym-table-rank').textContent=String((state.page-1)*size+i+1).padStart(2,'0');item.querySelectorAll('.is-league-sort-column').forEach(n=>n.classList.remove('is-league-sort-column'));const column=state.sort==='name'?1:state.sort==='overall'?9:Object.keys(categories).indexOf(state.sort)+3;item.children[column]?.classList.add('is-league-sort-column');}list.append(f);}});
			if(!results.length){const empty=document.createElement('div');empty.className='dxgym-empty';empty.innerHTML='<h2>No gyms match these filters.</h2><p>Try a different gym, chain or location, or reset your filters.</p>';list.closest('.dxgym-table-scroll')?.after(empty);if(list.tagName!=='TBODY')list.append(empty);}
		}
		root.querySelectorAll('.dxgym-pagination,[data-gym-incremental]').forEach(e=>e.remove());
		if(list && pages>1){const target=list.closest('.dxgym-table-scroll')||list;const nav=document.createElement('nav');nav.className='dxgym-pagination';nav.setAttribute('aria-label','Gym results pages');for(let page=1;page<=pages;page++){const a=document.createElement('a');a.dataset.gymNav='';a.href=stateURL({...state,page},base).href;a.textContent=page;if(page===state.page)a.setAttribute('aria-current','page');nav.append(a);}target.after(nav);if(state.page<pages){const controls=document.createElement('div');controls.className='dxgym-incremental';controls.dataset.gymIncremental='';controls.dataset.page=state.page;controls.dataset.start=(state.page-1)*size+1;controls.dataset.total=results.length;controls.innerHTML='<a class="dxgym-button" data-gym-load-more>Load More Gyms ↓</a><p data-gym-more-status role="status"></p><span data-gym-load-sentinel class="dxgym-load-sentinel" aria-hidden="true"></span>';controls.querySelector('a').href=stateURL({...state,page:state.page+1},base).href;nav.before(controls);}}
		if(view==='compare'){
			const selected=state.compare.map(id=>data.gyms.find(g=>g.id===id));const toolbar=root.querySelector('.dxgym-compare-toolbar');toolbar.querySelector('p').textContent=`${selected.length} of 3 gyms selected`;toolbar.querySelector('[data-gym-share]').disabled=!selected.length;
			root.querySelector('.dxgym-comparison, .dxgym-empty')?.remove();
			if(selected.length){const cards=document.createElement('div');cards.className='dxgym-comparison';cards.style.setProperty('--compare-count',selected.length);selected.forEach(g=>{const f=get(g.id,'compareCard');const select=f.querySelector('[data-compare-replace]');[...select.options].forEach(o=>{if(state.compare.includes(Number(o.value))&&Number(o.value)!==g.id)o.remove();});f.querySelectorAll('.dxgym-compare-scores>div').forEach((row,i)=>{const key=Object.keys(categories)[i];const scores=selected.map(g=>g.scores[key]).filter(x=>typeof x==='number');const best=typeof g.scores[key]==='number'&&g.scores[key]===Math.max(...scores);row.classList.toggle('is-category-best',best);row.querySelector('.screen-reader-text')?.remove();if(best&&selected.length>1){const label=document.createElement('span');label.className='screen-reader-text';label.textContent=' — joint or highest selected score';row.querySelector('dt').append(label);}});cards.append(f);});toolbar.after(cards);}else{const empty=document.createElement('div');empty.className='dxgym-empty';empty.innerHTML='<h2>Start With a Gym You Know.</h2><p>Search below, then add up to three gyms to see their recorded scores together.</p>';toolbar.after(empty);}
		}
		if(view==='map'){const map=root.querySelector('[data-gym-map]');if(map){map.hidden=state.display==='list';const markers=results.filter(g=>Number.isFinite(g.lat)&&Number.isFinite(g.lng));map.querySelector('.dxgym-markers').textContent=JSON.stringify(markers);map.querySelector('.dxgym-map-message').textContent=markers.length?'Loading gym locations…':'No precise coordinates are recorded for these results. Use the gym list below.';const note=map.nextElementSibling;note.textContent=`${markers.length} of ${results.length} gyms have confirmed map coordinates. Unlocated gyms remain in the list.`;}root.querySelectorAll('.dxgym-view-switch a').forEach(a=>{a.href=stateURL({...state,display:a.textContent.trim().toLowerCase()},base).href;if(a.textContent.trim().toLowerCase()===state.display)a.setAttribute('aria-current','true');else a.removeAttribute('aria-current');});}
		root.querySelectorAll('[data-compare-id]').forEach(control=>{const chosen=state.compare.includes(Number(control.dataset.compareId));control.setAttribute('aria-pressed',String(chosen));control.textContent=chosen?'Remove comparison −':'Compare +';});
		root.querySelectorAll('[data-gym-nav],[data-compare-id]').forEach(control=>{if(control.tagName==='A'){let target=canonical(control.href);if(!target)return;target.searchParams.set('compare',control.classList.contains('dxgym-clear-comparison')?'':state.compare.join(','));const href=publicURL(target).href;if(control.hasAttribute('data-compare-id')||control.closest('.dxgym-table thead')){const button=document.createElement('button');button.type='button';for(const attr of control.attributes)if(attr.name!=='href')button.setAttribute(attr.name,attr.value);button.classList.add('dxgym-state-control');button.dataset.gymTarget=href;button.innerHTML=control.innerHTML;control.replaceWith(button);}else control.href=href;}});
		const div=document.createElement('div');div.append(root);return {html:div.innerHTML,view,compare:state.compare,state,url:stateURL(state,base),renderMs:performance.now()-started};
	};
	return {data,envelope,normal,canonical,publicURL,render,filter:filterRecords};
}
