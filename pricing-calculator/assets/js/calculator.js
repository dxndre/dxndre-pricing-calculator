(() => {
	function animateCalculatorDialog(dialog) {
		if (dialog.dataset.motionReady) return;
		dialog.dataset.motionReady = 'true';
		const nativeOpen = dialog.showModal.bind(dialog);
		const nativeClose = dialog.close.bind(dialog);
		let closing;
		const reduced = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
		dialog.showModal = () => {
			if (closing) return;
			dialog.classList.remove('is-closing');
			nativeOpen();
			if (!reduced()) {
				dialog.animate([{opacity:0, transform:'translateY(16px)'},{opacity:1, transform:'translateY(0)'}], {duration:220, easing:'cubic-bezier(.22,1,.36,1)'});
				const art = dialog.querySelector('.calculator-sheet__art');
				art?.animate([{opacity:0,scale:'1.035'},{opacity:.65,scale:'1'}],{duration:1000,easing:'cubic-bezier(.22,1,.36,1)',fill:'backwards'});
				dialog.querySelectorAll('.service-detail-list li').forEach((line, index) => {
					line.animate([{opacity:0, transform:'translateY(8px)'},{opacity:1, transform:'translateY(0)'}], {duration:280, delay:70 + index * 65, fill:'backwards', easing:'cubic-bezier(.22,1,.36,1)'});
				});
			}
		};
		dialog.close = value => {
			if (!dialog.open) return Promise.resolve();
			if (closing) return closing;
			if (reduced()) { nativeClose(value); return Promise.resolve(); }
			closing = (async () => {
				dialog.classList.add('is-closing');
				if (!reduced()) await dialog.animate([{opacity:1, transform:'translateY(0)'},{opacity:0, transform:'translateY(12px)'}], {duration:180, easing:'ease-in', fill:'forwards'}).finished.catch(() => {});
				nativeClose(value);
				dialog.getAnimations().forEach(motion => motion.cancel());
				closing = null;
			})();
			return closing;
		};
		dialog.addEventListener('cancel', event => { event.preventDefault(); dialog.close(); });
	}

	function showEstimateNotice(message) {
		const existing = document.querySelector('.dx-estimate-notice');
		if (existing) {
			existing.querySelector('p').textContent = message;
			return;
		}

		const dialog = document.createElement('dialog');
		dialog.className = 'dx-estimate-notice';
		dialog.setAttribute('aria-labelledby', 'dx-estimate-notice-title');
		const title = document.createElement('h2');
		title.id = 'dx-estimate-notice-title';
		title.textContent = 'Estimate notice';
		const copy = document.createElement('p');
		copy.textContent = message;
		const close = document.createElement('button');
		close.type = 'button';
		close.textContent = 'Dismiss';
		close.addEventListener('click', () => dialog.close());
		const trigger = document.activeElement;
		dialog.addEventListener('close', () => {
			dialog.remove();
			if (trigger?.isConnected) trigger.focus({ preventScroll: true });
		}, { once: true });
		dialog.append(title, copy, close);
		document.body.append(dialog);
		animateCalculatorDialog(dialog);
		dialog.showModal();
	}


	/* ==========================
	   STATE + PRICING
	========================== */

	let multiSummary = null;
	const state = {
		service: null,
        payment_plan: 'full',
		service_category: null,
		photo_type: null,
		scope: null,
		type: null,
		features: [],
		timeline: null,
		extras: [],
		client_name: '',
		client_company: '',
		retainer_level: null,
		retainer_areas: [],
		retainer_turnaround: null,
		retainer_commitment: null,
		retainer_addons: [],
		contact: {},
		total: 0,
	};

	const emptyState = JSON.parse(JSON.stringify(state));
	const prices = DX_PRICING?.prices || {};

	const priceMaps = {
		services: prices.services || {},
		scope: prices.scope || {},
		type: prices.type || {},
		features: prices.features || {},
		timeline: prices.timeline || {},
		extras: prices.extras || {},
		retainer_level: prices.retainer_level || prices.retainer_levels || {},
		retainer_areas: prices.retainer_areas || {},
		retainer_turnaround: prices.retainer_turnaround || {},
		retainer_commitment: prices.retainer_commitment || {},
		retainer_addons: prices.retainer_addons || {},
	};

	/* ==========================
	   DOM REFERENCES
	========================== */

	const calculator = document.querySelector('.pricing-calculator');
	if (!calculator) return;

	const steps = [...calculator.querySelectorAll('.calculator-step')];
	const stepsContainer = calculator.querySelector('.steps-container');
	const breakdownEls = calculator.querySelectorAll('.summary-breakdown');
	const totalEls = calculator.querySelectorAll('.price');
	const nextBtn = calculator.querySelector('.next-step');
	const prevBtn = calculator.querySelector('.prev-step');
	const emailModal = document.getElementById('email-quote-modal');
	const progressFill = calculator.querySelector('.progress-fill');
	const progressLabel = calculator.querySelector('.progress-label');
	const successEl = calculator.querySelector('.calculator-success');
	const summaryTitleEl = calculator.querySelector('[data-summary-title]');
	const summaryIntroEl = calculator.querySelector('[data-summary-intro]');

	if (!steps.length || !stepsContainer || !nextBtn || !prevBtn) return;

	// Fetch a session token separately from the cacheable calculator page.
	let sessionToken;
	const getSessionToken = () => {
		if (!sessionToken) {
			sessionToken = fetch(DX_PRICING.ajax_url, {
				method: 'POST',
				cache: 'no-store',
				body: new URLSearchParams({ action: 'dx_pricing_session' }),
			}).then(async response => {
				const result = await response.json();
				if (!response.ok || !result.success || !result.data?.nonce) throw new Error('Calculator session unavailable');
				return result.data.nonce;
			}).catch(error => {
				sessionToken = null;
				throw error;
			});
		}
		return sessionToken;
	};

	const requestEstimate = async (fields) => {
		const send = async token => fetch(DX_PRICING.ajax_url, {
			method: 'POST',
			body: new URLSearchParams({ ...fields, _dx_pricing_nonce: await token }),
		});
		const token = getSessionToken();
		const response = await send(token);
		if (response.status !== 403) return response;
		const error = await response.clone().json().catch(() => null);
		if (error?.data?.code !== 'invalid_nonce') return response;
		// Only retry rejections that occurred before any estimate operation ran.
		if (sessionToken === token) sessionToken = null;
		return send(getSessionToken());
	};

	const stepLookup = steps.reduce((acc, step) => {
		const key = step.getAttribute('data-step');
		if (key) {
			acc[key] = step;
		}
		return acc;
	}, {});

	const flows = {
		default: ['1', '2', '3', '4', '5', '6', '7'],
		design: ['1', '2', '3', '5', '7'],
		landing_page: ['1', '3', '5', '7'],
		development: ['1', '2', '3', '4', '5', '6', '7'],
		wp_development: ['1', '2', '3', '4', '5', '6', '7'],
		comprehensive: ['1', '2', '3', '4', '5', '6', '7'],
		retainer: ['1', 'retainer_level', 'retainer_areas', 'retainer_turnaround', 'retainer_commitment', 'retainer_addons', '7'],
	};

	const summaryFlowSteps = {
		default: [
			{ key: 'service', type: 'radio', group: 'service', prices: priceMaps.services },
			{ key: 'scope', type: 'radio', group: 'scope', prices: priceMaps.scope },
			{ key: 'type', type: 'radio', group: 'type', prices: priceMaps.type },
			{ key: 'features', type: 'checkbox', group: 'features', prices: priceMaps.features },
			{ key: 'timeline', type: 'radio', group: 'timeline', prices: priceMaps.timeline },
			{ key: 'extras', type: 'checkbox', group: 'extras', prices: priceMaps.extras },
		],
		design: [
			{ key: 'service', type: 'radio', group: 'service', prices: priceMaps.services },
			{ key: 'scope', type: 'radio', group: 'scope', prices: priceMaps.scope },
			{ key: 'type', type: 'radio', group: 'type', prices: priceMaps.type },
			{ key: 'timeline', type: 'radio', group: 'timeline', prices: prices.design_timeline || priceMaps.timeline },
		],
		landing_page: [
			{ key: 'service', type: 'radio', group: 'service', prices: priceMaps.services },
			{ key: 'type', type: 'radio', group: 'type', prices: priceMaps.type },
			{ key: 'timeline', type: 'radio', group: 'timeline', prices: priceMaps.timeline },
		],
		retainer: [
			{ key: 'service', type: 'radio', group: 'service', prices: priceMaps.services },
			{ key: 'retainer_level', type: 'radio', group: 'retainer_level', prices: priceMaps.retainer_level },
			{ key: 'retainer_areas', type: 'checkbox', group: 'retainer_areas', prices: priceMaps.retainer_areas },
			{ key: 'retainer_turnaround', type: 'radio', group: 'retainer_turnaround', prices: priceMaps.retainer_turnaround },
			{ key: 'retainer_commitment', type: 'radio', group: 'retainer_commitment', prices: priceMaps.retainer_commitment },
			{ key: 'retainer_addons', type: 'checkbox', group: 'retainer_addons', prices: priceMaps.retainer_addons },
		],
	};

	let activeFlow = ['category', '1', ...flows.default.slice(1)];
	let currentStepIndex = 0;
	let finalized = false;
	let invoiceUrl = '';
	let estimateToken = '';
	let invoiceNumber = '';
	let finalizePending = null, estimateRevision = 0;

	/* ==========================
	   UTILITIES
	========================== */

	const formatPrice = (value) => {
		const numeric = Number(value) || 0;
		const formatted = Math.abs(numeric).toLocaleString('en-GB', {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		});

		return numeric < 0 ? `–£${formatted}` : `£${formatted}`;
	};

	const setButtonDisabled = (btn, disabled) => {
		if (!btn) return;
		btn.disabled = btn === nextBtn ? false : disabled;
		if(btn===nextBtn)btn.setAttribute('aria-disabled', String(disabled));
		btn.classList.toggle('is-disabled', disabled);
	};

	const getCurrentStepKey = () => activeFlow[currentStepIndex];
	const getCurrentStepEl = () => stepLookup[getCurrentStepKey()] || null;
	const getFlowKey = () => {
		if (state.service === 'retainer') return 'retainer';
		if (state.service === 'design') return 'design';
		if (state.service === 'landing_page') return 'landing_page';
		return 'default';
	};

	const getActiveSummaryConfig = () => summaryFlowSteps[getFlowKey()] || summaryFlowSteps.default;

	const getFriendlyName = () => {
		const raw = (state.client_name || '').trim();
		if (!raw) return '';
		return raw.split(' ')[0];
	};

	const getSummaryHeading = () => getFriendlyName() ? `Your project outline is ready, ${getFriendlyName()}.` : 'Your project outline is ready.';
	const getSummaryIntro = () => state.service === 'retainer' ? "Here's your estimated monthly investment based on your selected support level and add-ons. Review the breakdown and decide what you'd like to do next." : "Here's an estimate based on your selections. Review your project breakdown, explore the costs and decide what you'd like to do next.";

	const updateSummaryCopy = () => {
		updateGreeting();
		const editLink=calculator.querySelector('[data-edit-personalisation]');if(editLink)editLink.textContent=state.client_name||state.client_company?'Edit personalisation':'Add personalisation';
		if (summaryTitleEl) {
			summaryTitleEl.textContent = getSummaryHeading();
		}
		if (summaryIntroEl) {
			summaryIntroEl.textContent = getSummaryIntro();
		}
	};

	const getPriceForValue = (map, value) => {
		if (!map || value === null || value === undefined) return 0;
		const numeric = state.service === 'one_off' && ['nonprofit', 'no_rush'].includes(value) ? -5 : Number(map[value]);
		return Number.isFinite(numeric) ? numeric : 0;
	};

	const getLabelText = (group, value) => {
		if(group==='scope' && state.service==='design')return {small:'Small (1–3 Pages)',medium:'Medium (4–8 Pages)',large:'Large (9+ Pages)'}[value] || value;
		const input = calculator.querySelector(`input[name="${group}"][value="${value}"]`);
		if (!input) return value;
		if (input.dataset.label) return input.dataset.label;
		const label = input.closest('label');
		if (!label) return value;

		const cloned = label.cloneNode(true);
		cloned.querySelectorAll('input, .custom-radio, .custom-checkbox').forEach((el) => el.remove());
		return cloned.textContent.trim();
	};

	const priceAnimations = new WeakMap();
	const fadeUpdatePrice = (el, value) => {
		if (!el) return;
		const previous=priceAnimations.get(el),monthly=el.matches('.price')&&state.service==='retainer';
		if(previous?.target===value&&previous.monthly===monthly)return;
		previous?.animation?.cancel();
		const motion={target:value,monthly,animation:null};priceAnimations.set(el,motion);
		const paint=()=>{
			if(priceAnimations.get(el)!==motion)return;
			const amount=formatPrice(value),match=amount.match(/^(.*)(\.\d{2})$/);
			el.replaceChildren(document.createTextNode(match?match[1]:amount));
			if(match){const decimal=document.createElement('sub');decimal.className='price-decimal';decimal.textContent=match[2];el.append(decimal);}
			if(monthly){const suffix=document.createElement('span');suffix.className='price-suffix';suffix.textContent=' / MONTH';el.append(suffix);}
		};
		if(!previous||matchMedia('(prefers-reduced-motion: reduce)').matches){paint();return;}
		motion.animation=el.animate([{opacity:1,filter:'blur(0px)'},{opacity:0,filter:'blur(3px)'}],{duration:140,easing:'ease-in',fill:'forwards'});
		motion.animation.finished.then(()=>{if(priceAnimations.get(el)!==motion)return;paint();motion.animation.cancel();motion.animation=el.animate([{opacity:0,filter:'blur(3px)',translate:'0 3px'},{opacity:1,filter:'blur(0px)',translate:'0 0'}],{duration:320,easing:'cubic-bezier(.22,1,.36,1)'});}).catch(()=>{});
	};

	const isSummaryStep = (stepKey) => stepKey === '7';

	let wordTimer, wordVersion=0;
	function updateJourneyWord() {
		const node=calculator.querySelector('[data-journey-word]'), target={fitness:'fitness journey',photography:'memories'}[state.service_category] || 'project';
		const verb=calculator.querySelector('[data-journey-verb]');if(verb)verb.textContent=state.service_category==='photography'?'start':'starts';
		if(node.dataset.target===target)return; node.dataset.target=target; clearTimeout(wordTimer); const version=++wordVersion;
		if(matchMedia('(prefers-reduced-motion: reduce)').matches){node.textContent=target;return;}
		let deleting=true;
		const tick=()=>{if(version!==wordVersion)return; const value=node.textContent; if(deleting){node.textContent=value.slice(0,-1); if(!node.textContent)deleting=false;}else{node.textContent=target.slice(0,value.length+1);if(node.textContent===target)return;} wordTimer=setTimeout(tick,deleting?35:65);};tick();
	}
	const personalisation = calculator.querySelector('.calculator-intro-fields');
	const personalisationHome = document.createComment('Personalisation on mobile'); personalisation.before(personalisationHome);
	const layoutMedia=matchMedia('(min-width: 768px)');
	function placePersonalisation(){ if(layoutMedia.matches)calculator.querySelector('.calculator-summary').append(personalisation);else personalisationHome.after(personalisation); }
	layoutMedia.addEventListener('change',placePersonalisation);placePersonalisation();
	const greeting=document.createElement('p');greeting.className='calculator-greeting';calculator.querySelector('.calculator-progress').after(greeting);
	function updateGreeting(){greeting.textContent=getFriendlyName()?`${getFriendlyName()}, let’s make this yours.`:'';greeting.hidden=!getFriendlyName();}

	const applyActiveFlow = () => {
		activeFlow = state.service_category === 'photography' ? ['category','photo_type','photography'] : state.service_category === 'fitness' ? ['category', 'fitness'] : ['category', ...(flows[getFlowKey()] || flows.default)];
		if (currentStepIndex >= activeFlow.length) {
			currentStepIndex = activeFlow.length - 1;
		}
	};

	const resetFinalizedQuote = () => {
		finalized = false;
		estimateRevision++;
		// Retain the existing reference while marking its saved configuration dirty.

		const summary = document.getElementById('invoice-summary');
		const input = document.getElementById('invoice-link');
		const meta = document.getElementById('invoice-meta');
		const viewBtn = document.getElementById('view-invoice');
		const copyBtn = calculator.querySelector('.copy-invoice');

		if (summary) summary.classList.add('is-hidden');
		if (input) input.value = '';
		if (meta) meta.textContent = '';
		if (viewBtn) {
			viewBtn.href = '#';
			viewBtn.classList.add('is-disabled');
		}
		if (copyBtn) {
			copyBtn.disabled = true;
		}
	};

	/* ==========================
	   PROGRESS + HEIGHT
	========================== */

	const updateProgress = () => {
		if (!progressFill || !progressLabel || !activeFlow.length) return;

		const current = currentStepIndex + 1;
		const total = activeFlow.length;
		const progress = total <= 1 ? 100 : Math.round((currentStepIndex / (total - 1)) * 100);

		progressFill.style.width = `${progress}%`;
		const progressBar = progressFill.closest('[role="progressbar"]');
		progressBar.setAttribute('aria-valuemin', '1');
		progressBar.setAttribute('aria-valuemax', String(total));
		progressBar.setAttribute('aria-valuenow', String(current));
		progressBar.setAttribute('aria-valuetext', `Step ${current} of ${total}`);
		calculator.querySelector('[data-progress-count]').textContent = `${String(current).padStart(2, '0')} / ${String(total).padStart(2, '0')}`;
		const segments = calculator.querySelector('.calculator-segments');
		while (segments.children.length < total) segments.append(document.createElement('span'));
		[...segments.children].forEach((segment, index) => {
			segment.classList.toggle('is-unused', index >= total);
			segment.classList.toggle('is-complete', index < currentStepIndex);
			segment.classList.toggle('is-current', index === currentStepIndex);
		});
		const progressCount = calculator.querySelector('[data-progress-count]');
		const progressState = `${current}/${total}`;
		if (segments.dataset.state !== progressState && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
			[progressCount, calculator.querySelector('[data-dock-progress]')].forEach(element => element.animate([{opacity:0.4,translate:'0 3px'},{opacity:1,translate:'0 0'}],{duration:220,easing:'ease-out'}));
		}
		segments.dataset.state = progressState;
		calculator.classList.toggle('is-final-step', current === total);

		progressLabel.textContent = `Step ${current} of ${total} · ${getCurrentStepEl()?.querySelector('.step-title')?.textContent.trim() || ''}`;
		calculator.querySelector('[data-dock-progress]').textContent = `Step ${current}/${total}`;
		const jump = calculator.querySelector('[data-completed-steps]');
		jump.replaceChildren(new Option('Revisit a step', ''));
		activeFlow.slice(0, currentStepIndex).forEach((key, index) => {
			jump.add(new Option(stepLookup[key]?.querySelector('.step-title')?.textContent.trim() || key, String(index)));
		});
		jump.hidden = currentStepIndex === 0;
		const hasAnswers=['scope','type','timeline','photo_type','retainer_level','retainer_turnaround','retainer_commitment','client_name','client_company'].some(key=>!!state[key])||['features','extras','retainer_areas','retainer_addons'].some(key=>state[key]?.length);
		calculator.querySelector('[data-restart-calculator]').hidden = !hasAnswers;
		saveConfiguration();
	};

	const animateHeight = () => {
		if (!stepsContainer) return;
		const active = getCurrentStepEl();
		if (active) {
			stepsContainer.style.height = 'auto';
		}
	};

	/* ==========================
	   SUMMARY UPDATE
	========================== */

	const retainerConfig = prices.retainer;
	const money = value => Math.round((value + Number.EPSILON) * 100) / 100;
	const calculateRetainer = (tierKey = state.retainer_level || 'essentials') => {
		const c = retainerConfig, keys = Object.keys(c.tiers), index = keys.indexOf(tierKey), tier = c.tiers[tierKey];
		const rows = [{label:tier.name, amount:tier.price, group:'retainer_level'}];
		let subtotal = tier.price, landing = 0;
		state.retainer_areas.forEach(key => { const area=c.areas[key]; if (!area) return; const amount=area.prices[index]; subtotal+=amount; rows.push({label:area.name,amount,group:'retainer_areas'}); });
		const turn=c.turnaround[state.retainer_turnaround || 'standard']; subtotal+=turn.price; rows.push({label:`${turn.name} scheduling`,amount:turn.price,group:'retainer_turnaround'});
		state.retainer_addons.forEach(key=>{const addon=c.addons[key];if(!addon)return;subtotal+=addon.price;landing+=addon.hours||0;rows.push({label:addon.name,amount:addon.price,group:'retainer_addons'});});
		const commitment=c.commitments[state.retainer_commitment || '3_months'];
		const total=money(subtotal*commitment.multiplier),adjustment=money(total-subtotal);
		rows.push({label:'Monthly subtotal',amount:subtotal,group:''},{label:`${commitment.name} commitment adjustment`,amount:adjustment,group:'retainer_commitment'});
		return {total,subtotal,adjustment,general:tier.hours,landing,capacity:tier.hours+landing,months:commitment.months,commitmentTotal:commitment.months?money(total*commitment.months):null,rows};
	};
	let dismissedRecommendation = '', comparisonOpen = false;
	const recommendationSignature = () => JSON.stringify([state.retainer_level,[...state.retainer_areas].sort(),state.retainer_turnaround,[...state.retainer_addons].sort()]);
	const recommendPackage = () => {
		const c=retainerConfig, r=c.recommendation, keys=Object.keys(c.tiers), bands=[...r.thresholds,r.generalCapacityBands[2]];
		const score=state.retainer_areas.reduce((sum,key)=>sum+(c.areas[key]?.weight||0),0)+(c.turnaround[state.retainer_turnaround]?.weight||0);
		const dedicated=state.retainer_addons.includes('additional_landing_page') && state.retainer_areas.includes('landing_pages');
		// Dedicated capacity can cover the landing-page signal only; general work remains independent.
		const generalScore=score-(dedicated?c.areas.landing_pages.weight:0);
		const sufficient=key=>generalScore<=bands[keys.indexOf(key)] && score<=bands[keys.indexOf(key)]+(dedicated?r.landingCapacityBand:0);
		const candidate=keys.find(sufficient);
		if(!candidate || score>r.discussionThreshold)return {kind:'discussion',key:'partner'};
		const current=state.retainer_level||'essentials';
		if(!sufficient(current))return {kind:'upgrade',key:candidate};
		// Suggest a smaller package only for clearly light requirements, never simply few categories.
		if(keys.indexOf(current)>0 && score<=3 && !state.retainer_addons.length && state.retainer_turnaround==='standard')return {kind:'downgrade',key:'essentials'};
		return {kind:'suitable',key:current};
	};
	const addText = (parent,tag,text,className='') => {const el=document.createElement(tag);el.textContent=text;if(className)el.className=className;parent.append(el);return el;};
	const addAction = (parent,text,action) => {const button=addText(parent,'button',text,'summary-edit');button.type='button';button.addEventListener('click',action);return button;};
	const updateRetainerUI = () => {
		const c=retainerConfig, isRetainer=state.service==='retainer';
		calculator.querySelectorAll('[data-retainer-price]').forEach(el=>{
			const group=el.dataset.retainerPrice,key=el.dataset.value,index=Object.keys(c.tiers).indexOf(state.retainer_level||'essentials');
			if(group==='retainer_commitment'){
				const subtotal=calculateRetainer().subtotal, rolling=c.commitments.monthly.multiplier;
				el.replaceChildren();
				el.append(document.createTextNode(`${formatPrice(money(subtotal*c.commitments[key].multiplier))} / month`));
				if(key!=='monthly')
				{
					const saving=(1-c.commitments[key].multiplier/rolling)*100;
					addText(el,'span',`Save ${saving.toFixed(1).replace(/\.0$/, '')}% compared with monthly rolling`,'commitment-savings');
				}
			}
			else {const amount=group==='retainer_level'?c.tiers[key].price:group==='retainer_areas'?c.areas[key].prices[index]:group==='retainer_turnaround'?c.turnaround[key].price:c.addons[key].price;el.textContent=amount===0?'Included':`${group==='retainer_level'?'':'+'}${formatPrice(amount)} / month`;}
		});
		const capacity=calculator.querySelector('.retainer-capacity'), recommendation=calculator.querySelector('.package-recommendation'), comparison=calculator.querySelector('.package-comparison');
		capacity.hidden=!isRetainer;recommendation.hidden=true;
		if(!isRetainer && comparison.open)comparison.close();
		comparison.hidden=!comparisonOpen || !isRetainer;
		if(!isRetainer)return;
		const result=calculateRetainer();capacity.replaceChildren();
		addText(capacity,'p',`General support hours: ${result.general}/month`);
		addText(capacity,'p',`Additional landing-page hours: ${result.landing}/month`);
		addText(capacity,'p',`Total monthly capacity: ${result.capacity} hours`);
		if(result.months)addText(capacity,'p',`Estimated ${result.months}-month commitment: ${formatPrice(result.commitmentTotal)}`);
		if(result.adjustment<0)addText(capacity,'p',`You save ${formatPrice(-result.adjustment)}/month with this commitment.`);
		addText(capacity,'small','General hours are shared across selected categories. Landing-page hours are restricted to that work. Monthly allowances expire; excess work, major projects and complex integrations require separate approval and pricing. Final scope, availability and contractual terms are subject to agreement.');
		if(!state.retainer_level || !state.retainer_areas.length || !['retainer_turnaround','retainer_commitment','retainer_addons','7'].includes(getCurrentStepKey()))return;
		const rec=recommendPackage();recommendation.replaceChildren();recommendation.hidden=dismissedRecommendation===recommendationSignature();
		const current=c.tiers[state.retainer_level],target=c.tiers[rec.key];
		addText(recommendation,'h3',rec.kind==='suitable'?'Your selected package looks like a good fit.':rec.kind==='discussion'?'Your requirements may benefit from a tailored support arrangement.':rec.kind==='upgrade'?`${target.name} might be a better fit.`:`${target.name} could be enough.`);
		addText(recommendation,'p',rec.kind==='discussion'?'Your selected work spans substantial development and support. We can review its frequency and complexity together; no package guarantees completion of every selected task.':rec.kind==='suitable'?`${current.name} offers ${result.general} general hours and ${result.landing} dedicated landing-page hours. That appears a reasonable starting point for your selected areas; actual workload should be agreed.`:`${target.name} provides ${target.hours} general hours, alongside ${result.landing} dedicated landing-page hours. Restricted landing-page capacity cannot cover your other support categories. These are indicative recommendations, not measured workload or guaranteed delivery.`);
		if(rec.kind==='upgrade'||rec.kind==='downgrade'){const difference=money(calculateRetainer(rec.key).total-result.total);addText(recommendation,'p',`With your selections, ${target.name} is ${formatPrice(Math.abs(difference))} ${difference>=0?'more':'less'} per month. You may prefer your current capacity.`);}
		if(rec.kind==='discussion'){const link=addText(recommendation,'a','Discuss my requirements','summary-edit');link.href='/start-a-project/';}
		else addAction(recommendation,'Compare packages',()=>{comparisonOpen=true;updateRetainerUI();});
		if(rec.kind!=='suitable')addAction(recommendation,'Keep my current package',()=>{dismissedRecommendation=recommendationSignature();comparisonOpen=false;updateRetainerUI();});
		comparison.hidden=!comparisonOpen;if(!comparisonOpen){if(comparison.open)comparison.close();return;}comparison.replaceChildren();
		const modalHeader=addText(comparison,'div','','package-comparison__header');
		addText(modalHeader,'h2','Compare packages');
		const close=addAction(modalHeader,'×',()=>{comparisonOpen=false;updateRetainerUI();});
		close.className='package-comparison__close';close.setAttribute('aria-label','Close comparison');
		const packages=Object.entries(c.tiers).map(([key,tier])=>({key,tier,estimate:calculateRetainer(key)}));
		const wrap=addText(comparison,'div','','package-comparison__table-wrap');
		const table=addText(wrap,'table','','package-comparison__table');
		const head=addText(table,'thead',''), header=addText(head,'tr','');
		addText(header,'th','').scope='col';
		packages.forEach(({key,tier})=>{
			const cell=addText(header,'th','');cell.scope='col';
			const badge=addText(cell,'span','Suggested starting point','package-comparison__badge');
			if(key!==rec.key){badge.style.visibility='hidden';badge.setAttribute('aria-hidden','true');}
			addText(cell,'span',tier.name,'package-comparison__name');
		});
		const body=addText(table,'tbody','');
		const row=(label,values,className='')=>{
			const tr=addText(body,'tr','',className);addText(tr,'th',label).scope='row';
			values.forEach(value=>addText(tr,'td',value));
		};
		row('Monthly investment',packages.map(p=>`${formatPrice(p.estimate.total)} / month`),'package-comparison__total');
		row('General support hours',packages.map(p=>`${p.estimate.general} / month`));
		row('Additional landing-page hours',packages.map(p=>`${p.estimate.landing} / month`));
		row('Total monthly capacity',packages.map(p=>`${p.estimate.capacity} hours`));
		packages[0].estimate.rows.forEach((item,index)=>{
			row(item.group==='retainer_level'?'Package base price':item.label,packages.map(p=>{
				const entry=p.estimate.rows[index];
				return entry.amount===0 && ['retainer_areas','retainer_turnaround'].includes(entry.group)?'Included':formatPrice(entry.amount);
			}));
		});
		if(packages[0].estimate.months)row(`${packages[0].estimate.months}-month commitment total`,packages.map(p=>formatPrice(p.estimate.commitmentTotal)));
		const actions=addText(addText(table,'tfoot',''),'tr','');addText(actions,'th','Choose a package').scope='row';
		packages.forEach(({key,tier})=>{
			const cell=addText(actions,'td','');
			const select=addAction(cell,`Select ${tier.name}`,()=>{state.retainer_level=key;calculator.querySelector(`input[name="retainer_level"][value="${key}"]`).checked=true;resetFinalizedQuote();syncActiveLabels();comparisonOpen=false;updateSummary();if(isSummaryStep(getCurrentStepKey()))finalizeQuote();});
			select.className='package-comparison__select';
		});
		if(!comparison.open)comparison.showModal();
	};

	const updateSummary = () => {
		state.total = 0;
		const summaryConfig = getActiveSummaryConfig();

		summaryConfig.forEach((cfg) => {
			if (cfg.type === 'radio' && state[cfg.key]) {
				state.total += getPriceForValue(cfg.prices, state[cfg.key]);
			}

			if (cfg.type === 'checkbox' && Array.isArray(state[cfg.key]) && state[cfg.key].length) {
				state[cfg.key].forEach((value) => {
					state.total += getPriceForValue(cfg.prices, value);
				});
			}
		});

		state.total = Math.max(0, state.total);
		let rows=[];
		if(state.service==='retainer') { const estimate=calculateRetainer();state.total=estimate.total;rows=estimate.rows;state.retainer_estimate=estimate; }
		else { delete state.retainer_estimate;summaryConfig.forEach(cfg=>{const values=cfg.type==='checkbox'?state[cfg.key]||[]:state[cfg.key]?[state[cfg.key]]:[];values.forEach(value=>rows.push({label:getLabelText(cfg.group,value),amount:getPriceForValue(cfg.prices,value),group:cfg.key}));}); }
        if(state.service_category==='photography') {
          const express=!!calculator.querySelector('[data-photo-express]')?.checked;const video = calculator.querySelector('[data-photo-video]').checked;
          state.total = 40 + (express ? 10 : 0) + (video ? 15 : 0);
          fadeUpdatePrice(calculator.querySelector('.dx-photo-price'), state.total);
          const book = calculator.querySelector('[data-photo-book]');
          const enquiry = new URL(book.href);
          enquiry.searchParams.set('photo_type', state.photo_type || '');
          enquiry.searchParams.set('photo_video', video ? '1' : '0');
          enquiry.searchParams.set('photo_express', express ? '1' : '0');
          enquiry.searchParams.set('photo_delivery', express ? 'express' : 'standard');
          book.href = enquiry.href;
          calculator.querySelector('.dx-photo-deposit').textContent = `£10 deposit only after our call and your acceptance of the final quotation. Pay the remaining £${state.total - 10} after your shoot.`;
          rows = [{label: state.photo_type ? 'Photography · ' + state.photo_type : 'Photography', amount:40, group:'photo_type'}];
          if (express) rows.push({label:'24-hour delivery',amount:10,group:'photography'});
          if (video) rows.push({label:'iPhone videography · 4K/60 fps',amount:15,group:'photography'});
        }
        const six=calculator.querySelector('[data-six-option]');if(six){six.hidden=state.total<=600;six.querySelector('input').disabled=state.total<=600;if(state.total<=600&&state.payment_plan==='six'){state.payment_plan='full';calculator.querySelector('[name=payment_plan][value=full]').checked=true;}}
        const deposit=Math.min(state.service_category==='photography'?10:50,state.total),interest=state.payment_plan==='six'?Math.round(state.total*5)/100:0,balance=Math.round((state.total+interest-deposit)*100),count=state.payment_plan==='six'?6:state.payment_plan==='three'?3:1;
        const base=Math.floor(balance/count),last=balance-base*(count-1);
        const paymentSummary=calculator.querySelector('[data-payment-summary]');
        if(paymentSummary) {
          paymentSummary.replaceChildren();
          const paymentRow=(label,value)=>{
            const row=addText(paymentSummary,'div','','dx-payment-summary-row');
            addText(row,'dt',label);
            addText(row,'dd',value);
          };
          paymentRow('Deposit after final quote acceptance',formatPrice(deposit));
          paymentRow('Remaining balance',formatPrice(balance/100));
          if(state.payment_plan==='custom') {
            paymentRow('Payment schedule','Agreed during our call');
          } else if(count>1) {
            paymentRow(`First ${count-1} instalments`,`${formatPrice(base/100)} each`);
            paymentRow('Final instalment',formatPrice(last/100));
          }
          if(interest) paymentRow('One-off 5% charge',`${formatPrice(interest)} included in the balance`);
        }
        let payToday=calculator.querySelector('[data-pay-today]');
        if(!payToday){payToday=document.createElement('p');payToday.dataset.payToday='';calculator.querySelector('.summary-price').append(payToday);}
        payToday.hidden=!(isSummaryStep(getCurrentStepKey()) || state.service_category==='photography');
        payToday.textContent='AFTER FINAL QUOTE ACCEPTANCE · £'+(state.payment_plan==='full' && state.service_category!=='photography'?state.total:deposit).toFixed(2);
		breakdownEls.forEach(list=>{list.replaceChildren();rows.forEach(item=>{const row=document.createElement('li');row.dataset.summaryGroup=item.group;if(item.amount<0)row.className='is-discount';addText(row,'span',item.label,'summary-label');addText(row,'span',item.amount===0&&item.group==='retainer_areas'?'Included':formatPrice(item.amount),'summary-value');list.append(row);});if(state.client_company){const row=document.createElement('li');row.dataset.summaryGroup='client_company';addText(row,'span','Company','summary-label');addText(row,'span',state.client_company,'summary-value');list.prepend(row);}});
		updateRetainerUI();

		const summaryStepMap = {photo_type:'photo_type', photography:'photography', service:'1', scope:'2', type:'3', features:'4', timeline:'5', extras:'6', client_company:'category', retainer_level:'retainer_level', retainer_areas:'retainer_areas', retainer_turnaround:'retainer_turnaround', retainer_commitment:'retainer_commitment', retainer_addons:'retainer_addons'};
		breakdownEls.forEach(list => {
			list.querySelectorAll('[data-summary-group]').forEach(row => {
				const step = summaryStepMap[row.dataset.summaryGroup];
				if (!activeFlow.includes(step)) return;
				const button = document.createElement('button');
				button.type = 'button';
				button.className = 'summary-edit';
				button.textContent = 'Edit';
				button.setAttribute('aria-label', `Edit ${row.querySelector('.summary-label').textContent}`);
				button.addEventListener('click', () => {
					const index = activeFlow.indexOf(step);
					if (index < 0) return;
					goToStepIndex(index);
					if (row.dataset.summaryGroup === 'client_company') {
						const details = calculator.querySelector('.calculator-intro-fields');
						if(calculator.querySelector('.personalisation-toggle')?.getAttribute('aria-expanded')!=='true') calculator.querySelector('.personalisation-toggle')?.click();
						calculator.querySelector('[name="client_company"]')?.focus();
					}
				});
				row.append(button);
			});
		});

		totalEls.forEach((el) => fadeUpdatePrice(el, state.total));
		calculator.querySelector('.summary-price > p').textContent = state.service==='retainer' ? 'Your estimated monthly investment' : 'Your projected investment';
		calculator.querySelector('.summary-price').dataset.monthly = String(state.service==='retainer');
		updateSummaryCopy();
		try {
		 const summary = [...breakdownEls[0].querySelectorAll('li')].map(el => [...el.querySelectorAll('.summary-label, .summary-value')].map(part => part.textContent).join(' ').trim().replace(/\s+/g, ' '));
		 if ((state.service_category === 'digital' && state.service) || (state.service_category === 'photography' && state.photo_type)) {
		 sessionStorage.setItem('dxndre-calculator-handoff', JSON.stringify({service:state.service,total:state.total,monthly:state.service==='retainer',reference:invoiceNumber,name:state.client_name,company:state.client_company,timeline:state.timeline,summary}));
		 } else sessionStorage.removeItem('dxndre-calculator-handoff');
		} catch (_) {}
		multiSummary?.();
	};

 const paymentDialog=calculator.querySelector('.dx-payment-dialog');
 calculator.querySelector('[data-open-payment]')?.addEventListener('click',()=>paymentDialog.showModal());
 calculator.querySelector('[data-close-payment]')?.addEventListener('click',()=>paymentDialog.close());
 calculator.querySelectorAll('[name=payment_plan]').forEach(input=>input.addEventListener('change',()=>{state.payment_plan=input.value;resetFinalizedQuote();updateSummary();if(isSummaryStep(getCurrentStepKey()))finalizeQuote();}));
 calculator.querySelectorAll('[data-photo-express], [data-photo-video]').forEach(input => input.addEventListener('change', updateSummary));
 calculator.querySelectorAll('[name="photo_type"]').forEach(input => input.addEventListener('change', () => {
	state.photo_type = input.value;
	updateSummary();
	validateStep();
 }));

	/* ==========================
	   STEP VALIDATION
	========================== */

	const isStepValid = (stepKey) => {
		if (!stepKey) return;

		if (isSummaryStep(stepKey)) return true;

		let valid = false;

		switch (stepKey) {
			case 'photo_type':
				valid = !!state.photo_type;
				break;
			case 'category':
				valid = !!state.service_category;
				break;
			case '1':
				valid = !!state.service;
				break;
			case '2':
				valid = !!state.scope;
				break;
			case '3':
				valid = !!state.type;
				break;
			case '4':
				valid = true;
				break;
			case '5':
				valid = !!state.timeline;
				break;
			case '6':
				valid = true;
				break;
			case 'retainer_level':
				valid = !!state.retainer_level;
				break;
			case 'retainer_areas':
				valid = true;
				break;
			case 'retainer_turnaround':
				valid = !!state.retainer_turnaround;
				break;
			case 'retainer_commitment':
				valid = !!state.retainer_commitment;
				break;
			case 'retainer_addons':
				valid = true;
				break;
		}

		return valid;
	};

	const validateStep = () => {
		const valid = isStepValid(getCurrentStepKey());
		setButtonDisabled(nextBtn, !valid);
		if(valid)calculator.querySelector('.step-validation-message')?.remove();
		return valid;
	};

	/* ==========================
	   STEP NAVIGATION
	========================== */

	const goToStepIndex = (index) => {
		if (index < 0 || index >= activeFlow.length) return;
		const navigated=index!==currentStepIndex;
		calculator.querySelector('.step-validation-message')?.remove();

		steps.forEach((step) => {
			step.classList.remove('is-active');
			step.hidden = true;
		});

		currentStepIndex = index;
		const target = getCurrentStepEl();
		if (!target) return;

		target.hidden = false;
		target.classList.add('is-active');
		calculator.closest('.calculator-experience')?.classList.toggle('is-fitness-pane', target.dataset.step === 'fitness');
		calculator.dataset.step = getCurrentStepKey();
        personalisation.hidden = !['category','7'].includes(getCurrentStepKey());
		calculator.querySelector('.calculator-summary').classList.toggle('is-review', getCurrentStepKey() === '7');
		calculator.querySelector('.calculator-summary').hidden = false;
		calculator.querySelector('.summary-welcome').hidden = getCurrentStepKey() !== 'category';
		calculator.querySelector('.summary-fitness').hidden = getCurrentStepKey() !== 'fitness';
		calculator.querySelector('.summary-content').hidden = ['category', 'fitness'].includes(getCurrentStepKey());
		updateJourneyWord(); updateGreeting();
		nextBtn.classList.toggle('is-hidden', ['fitness','photography'].includes(getCurrentStepKey()));
		calculator.querySelector('.nav-estimate').hidden = ['category', 'fitness'].includes(getCurrentStepKey());
		const stage = calculator.closest('.calculator-experience');
		if (stage) {
			stage.dataset.glow = String(currentStepIndex % 7 + 1);
			stage.dataset.calculatorStep = getCurrentStepKey();
			const heroVideo = stage.querySelector('video.calculator-sunset');
			if (heroVideo) {
				if (getCurrentStepKey() === 'category' && !matchMedia('(prefers-reduced-motion: reduce)').matches) heroVideo.play().catch(() => {});
				else heroVideo.pause();
			}
		}
		calculator.querySelectorAll('[data-scope-label]').forEach(el=>{
			const labels=state.service==='design'?{small:'Small (1–3 Pages)',medium:'Medium (4–8 Pages)',large:'Large (9+ Pages)'}:{small:'Small (1–3 Pages / Simple Functionality)',medium:'Medium (4–8 Pages / Moderate Functionality)',large:'Large (9+ Pages / Complex Functionality)'};
			el.textContent=labels[el.dataset.scopeLabel];
		});
		updateRetainerUI();
		calculator.closest('.calculator-experience')?.classList.toggle('is-calculator-active', index > 0);
		const heading = target.querySelector('.step-title');
		if (heading) heading.tabIndex = -1;


		setButtonDisabled(prevBtn, currentStepIndex === 0);
		prevBtn.classList.toggle('is-hidden', currentStepIndex === 0);

		if (getCurrentStepKey() === 'category') {
			nextBtn.textContent = 'Continue';
		} else if (currentStepIndex === activeFlow.length - 2) {
			nextBtn.textContent = 'Review summary';
		} else if (currentStepIndex === activeFlow.length - 1) {
			nextBtn.textContent = 'Finish';
		} else {
			nextBtn.textContent = 'Continue';
		}

		updateProgress();
        updateSummary();
		validateStep();
		animateHeight();

		if (navigated && heading) {
			heading.tabIndex = -1;
			heading.focus({preventScroll:true});
			const clearance = Math.max(0, document.querySelector('#header')?.getBoundingClientRect().height || 0);
			const top=calculator.getBoundingClientRect().top+window.scrollY-clearance-16;
			window.scrollTo({top:Math.max(0,top),behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});
			if (!matchMedia('(prefers-reduced-motion: reduce)').matches) {
				target.animate([{opacity:.55},{opacity:1}], {duration:180, easing:'ease-out'});
				target.querySelectorAll('.options-group > label, .options-group > .service-option').forEach((option, index) => {
					option.animate([{opacity:0, transform:'translateY(12px)'},{opacity:1, transform:'translateY(0)'}], {duration:240, delay:Math.min(index * 35, 280), fill:'backwards', easing:'cubic-bezier(.22,1,.36,1)'});
				});
			}
		}

		if (isSummaryStep(getCurrentStepKey())) {
			if(state.service_category === 'digital') nextBtn.textContent = 'Save service and continue';
			finalizeQuote();
		}
	};

	const refreshFlowFromService = () => {
		const previousKey = getCurrentStepKey();
		applyActiveFlow();
		const nextIndex = activeFlow.indexOf(previousKey);
		currentStepIndex = nextIndex >= 0 ? nextIndex : 0;
		goToStepIndex(currentStepIndex);
	};

	/* ==========================
	   FINALIZE QUOTE
	========================== */

	const finalizeQuote = () => {
		if (finalized) return Promise.resolve();
		if (finalizePending) return finalizePending;
		finalizePending = (async () => { do { if (!await persistQuote()) break; } while (!finalized); })().catch(() => { showEstimateNotice('The estimate could not be saved. Please try again.'); }).finally(() => { finalizePending = null; });
		return finalizePending;
	};
	const persistQuote = async () => {
		const revision = estimateRevision;

		const res = await requestEstimate({
			action: 'dx_finalize_quote',
			state: JSON.stringify(exportEstimateState()),
			estimate_reference: invoiceNumber,
			estimate_token: estimateToken,
		});

		const data = await res.json();

		if (!data.success) {
			showEstimateNotice(data.data?.message || 'Could not generate estimate. Please try again.');
			return;
		}

		finalized = revision === estimateRevision;
		invoiceNumber = data.data.invoice;
		state.estimate_reference = invoiceNumber;
		estimateToken = data.data.token || estimateToken;
		invoiceUrl = data.data.url;

		const viewBtn = document.getElementById('view-invoice');
		if (viewBtn && invoiceUrl) {
			viewBtn.href = invoiceUrl;
			viewBtn.classList.remove('is-disabled');
		}

		const summary = document.getElementById('invoice-summary');
		const input = document.getElementById('invoice-link');
		const copyBtn = calculator.querySelector('.copy-invoice');
		const meta = document.getElementById('invoice-meta');

		if (summary) summary.classList.remove('is-hidden');
		if (input) input.value = invoiceNumber;
		if (meta) meta.textContent = `Estimate reference: ${invoiceNumber}`;
		if (copyBtn) copyBtn.disabled = false;
		try { const handoff=JSON.parse(sessionStorage.getItem('dxndre-calculator-handoff')||'{}');handoff.reference=invoiceNumber;sessionStorage.setItem('dxndre-calculator-handoff',JSON.stringify(handoff)); } catch (_) {}
		saveConfiguration();
		return true;
	};

	/* ==========================
	   FEATURE EXCLUSIVITY
	========================== */

	const syncFeatureExclusivity = () => {
		const noneInput = calculator.querySelector('input[name="features"][value="none"]');
		const featureInputs = [...calculator.querySelectorAll('input[name="features"]')].filter((input) => input.value !== 'none');

		if (!noneInput) return;

		if (noneInput.checked) {
			featureInputs.forEach((input) => {
				input.checked = false;
				input.disabled = true;
				input.closest('label')?.classList.remove('is-active');
			});
			state.features = ['none'];
		} else {
			featureInputs.forEach((input) => {
				input.disabled = false;
			});
			state.features = featureInputs.filter((input) => input.checked).map((input) => input.value);
		}

		syncActiveLabels();
		updateSummary();
		validateStep();
	};

	/* ==========================
	   INPUT BINDING
	========================== */

	const bindInputs = () => {
		calculator.querySelectorAll('input[name="service_category"]').forEach(input => {
			input.addEventListener('change', () => {
				state.service_category = input.value;
				updateSummary();
				applyActiveFlow();
				goToStepIndex(0);
				syncActiveLabels();
			});
		});
		calculator.querySelectorAll('input[name="service"]').forEach((input) => {
			input.addEventListener('change', () => {
				if (!hydrating) { changeServices(); return; }
				state.service = input.value;

				const incompatible = state.service === 'retainer'
					? ['scope', 'type', 'features', 'timeline', 'extras']
					: ['retainer_level', 'retainer_areas', 'retainer_turnaround', 'retainer_commitment', 'retainer_addons'];
				incompatible.forEach(key => {
					state[key] = Array.isArray(state[key]) ? [] : null;
					calculator.querySelectorAll(`input[name="${key}"]`).forEach(control => { control.checked = false; });
				});

				resetFinalizedQuote();
				updateSummary();
				refreshFlowFromService();
				syncActiveLabels();
				syncFeatureExclusivity();
			});
		});

		[
			{ name: 'scope', key: 'scope' },
			{ name: 'type', key: 'type' },
			{ name: 'timeline', key: 'timeline' },
			{ name: 'retainer_level', key: 'retainer_level' },
			{ name: 'retainer_turnaround', key: 'retainer_turnaround' },
			{ name: 'retainer_commitment', key: 'retainer_commitment' },
		].forEach((group) => {
			calculator.querySelectorAll(`input[name="${group.name}"]`).forEach((input) => {
				input.addEventListener('change', () => {
					state[group.key] = input.value;
					resetFinalizedQuote();
					updateSummary();
					validateStep();
					animateHeight();
					syncActiveLabels();
				});
			});
		});

		[
			{ name: 'extras', key: 'extras' },
			{ name: 'retainer_areas', key: 'retainer_areas' },
			{ name: 'retainer_addons', key: 'retainer_addons' },
		].forEach((group) => {
			calculator.querySelectorAll(`input[name="${group.name}"]`).forEach((input) => {
				input.addEventListener('change', () => {
					if (input.checked) {
						if (!state[group.key].includes(input.value)) {
							state[group.key].push(input.value);
						}
					} else {
						state[group.key] = state[group.key].filter((value) => value !== input.value);
					}

					resetFinalizedQuote();
					updateSummary();
					validateStep();
					animateHeight();
					syncActiveLabels();
				});
			});
		});

		calculator.querySelectorAll('input[name="features"]').forEach((input) => {
			input.addEventListener('change', () => {
				const noneInput = calculator.querySelector('input[name="features"][value="none"]');

				if (input.value === 'none' && input.checked) {
					state.features = ['none'];
				} else {
					state.features = state.features.filter((value) => value !== 'none');
					if (noneInput) noneInput.checked = false;

					if (input.checked) {
						if (!state.features.includes(input.value)) {
							state.features.push(input.value);
						}
					} else {
						state.features = state.features.filter((value) => value !== input.value);
					}
				}

				resetFinalizedQuote();
				syncFeatureExclusivity();
				animateHeight();
			});
		});

		const nameInput = calculator.querySelector('input[name="client_name"]');
		const companyInput = calculator.querySelector('input[name="client_company"]');

		nameInput?.addEventListener('input', () => {
			state.client_name = nameInput.value.trim();
			resetFinalizedQuote();
			updateSummaryCopy();
		});

		companyInput?.addEventListener('input', () => {
			state.client_company = companyInput.value.trim();
			resetFinalizedQuote();
			updateSummary();
		});
	};

	/* ==========================
	   NAV BUTTONS
	========================== */

		nextBtn.addEventListener('click', () => {
		if(!validateStep()){
			let message=calculator.querySelector('.step-validation-message');
			if(!message){message=document.createElement('p');message.className='step-validation-message';message.setAttribute('role','alert');nextBtn.parentElement.insertBefore(message,nextBtn);}
			const reasons={category:'Choose a service to continue.','1':'Choose a service to continue.','2':'Choose the size of your project to continue.','3':'Choose a project type to continue.','5':'Choose a turnaround time to continue.',retainer_level:'Choose a support package to continue.',retainer_turnaround:'Choose a scheduling option to continue.',retainer_commitment:'Choose a commitment to continue.'};
			message.textContent=reasons[getCurrentStepKey()]||'Please select an option to continue.';
			const input = getCurrentStepEl()?.querySelector('input:not(:disabled)');
			if (input) { input.focus({preventScroll:true}); input.closest('label')?.scrollIntoView({block:'nearest'}); }
			return;
		}
		if (['fitness','photography'].includes(getCurrentStepKey())) return;
		if (getCurrentStepKey() === '1' && state.service_category === 'digital') { beginServices(); return; }
		if (currentStepIndex < activeFlow.length - 1) {
			let next = currentStepIndex + 1;
			while (next < activeFlow.length - 1 && reusedSteps.has(activeFlow[next]) && isStepValid(activeFlow[next])) next++;
			goToStepIndex(next);
			return;
		}

		nextBtn.disabled = true;
		finalizeQuote().then(() => {
			if (finalized && state.service_category === 'digital') finishService();
			else if (finalized && invoiceUrl) window.location.href = invoiceUrl;
		}).finally(() => { nextBtn.disabled = false; });
	});

	prevBtn.addEventListener('click', () => {
		if (currentStepIndex > 0) {
			goToStepIndex(currentStepIndex - 1);
		}
	});

	/* ==========================
	   ACTIVE LABEL SYNC
	========================== */

	const syncActiveLabels = () => {
		calculator.querySelectorAll('.options-group').forEach((group) => {
			group.querySelectorAll('input').forEach((input) => {
				const label = input.closest('label');
				if (!label) return;

				if (input.type === 'radio' && input.checked) {
					group.querySelectorAll('label').forEach((item) => item.classList.remove('is-active'));
					label.classList.add('is-active');
				}

				if (input.type === 'checkbox') {
					label.classList.toggle('is-active', input.checked);
					label.classList.toggle('is-disabled', input.disabled);
				}
			});
		});
	};

	/* ==========================
	   SCROLL LOGIC
	========================== */

	const initScrollableHint = () => {
		calculator.querySelectorAll('.options-group.long').forEach((group) => {
			const updateState = () => {
				const scrollTop = group.scrollTop;
				const scrollHeight = group.scrollHeight;
				const clientHeight = group.clientHeight;

				const isScrollable = scrollHeight > clientHeight + 1;
				const atTop = scrollTop <= 1;
				const atBottom = scrollTop + clientHeight >= scrollHeight - 1;

				group.classList.toggle('is-scrollable', isScrollable);
				group.classList.toggle('at-top', atTop);
				group.classList.toggle('at-bottom', atBottom);
				group.classList.toggle('is-middle', isScrollable && !atTop && !atBottom);
			};

			updateState();
			group.addEventListener('scroll', updateState);
		});
	};

	/* ==========================
	   TELEPORT MODAL TO <body>
	========================== */

		document.addEventListener('DOMContentLoaded', () => {
		const modal = document.getElementById('email-quote-modal');
		if (!modal) return;

		if (!modal.dataset.teleported) {
			document.body.appendChild(modal);
			modal.dataset.teleported = 'true';
		}
	});

	/* ==========================
	   DOWNLOAD GENERATED PDF
	========================== */

	calculator.querySelector('.download-pdf')?.addEventListener('click', async () => {
		await finalizeQuote();
		if (!finalized) return;
		try {
			const response = await requestEstimate({ action: 'dx_generate_quote_pdf', state: JSON.stringify(exportEstimateState()) });
			if (!response.ok || !response.headers.get('Content-Type')?.includes('application/pdf')) {
				const error = await response.json().catch(() => null);
				throw new Error(error?.data?.message || 'The estimate PDF could not be downloaded. Please try again.');
			}

			const url = URL.createObjectURL(await response.blob());
			const link = document.createElement('a');
			link.href = url;
			link.download = 'project-proposal.pdf';
			document.body.append(link);
			link.click();
			link.remove();
			setTimeout(() => URL.revokeObjectURL(url), 60_000);
		} catch (error) {
			showEstimateNotice(error.message || 'The estimate PDF could not be downloaded. Please try again.');
		}
	});

	calculator.querySelector('.download-pdf')?.addEventListener('click', () => {
		if (typeof gtag === 'function') {
			gtag('event', 'download_proposal', {
				event_category: 'Proposal',
				event_label: state.total,
			});
		}
	});

	calculator.querySelector('.email-quote')?.addEventListener('click', () => {
		if (typeof gtag === 'function') {
			gtag('event', 'request_email_quote', {
				event_category: 'Proposal',
				event_label: state.total,
			});
		}
	});

	/* ==========================
	   COPY INVOICE LINK
	========================== */

	calculator.querySelector('.copy-invoice')?.addEventListener('click', (e) => {
		const btn = e.currentTarget;
		const input = document.getElementById('invoice-link');
		const icon = btn.querySelector('i');

		if (!input || !icon) return;

		const text = input.value;
		const status = btn.querySelector('.copy-invoice__text');

		const showSuccess = () => {
			icon.classList.remove('fa-copy');
			icon.classList.add('fa-check');
			if(status)status.textContent='Copied';
			btn.setAttribute('aria-label','Link copied');
			btn.classList.add('is-success');
			btn.disabled = true;

			setTimeout(() => {
				icon.classList.remove('fa-check');
				icon.classList.add('fa-copy');
				if(status)status.textContent='Copy';
				btn.setAttribute('aria-label','Copy link');
				btn.classList.remove('is-success');
				btn.disabled = false;
			}, 1800);
		};

		const showError = () => {
			icon.classList.remove('fa-copy');
			icon.classList.add('fa-xmark');
			if(status)status.textContent='Retry';
			btn.setAttribute('aria-label','Copy failed. Try again');

			setTimeout(() => {
				icon.classList.remove('fa-xmark');
				icon.classList.add('fa-copy');
				if(status)status.textContent='Copy';
				btn.setAttribute('aria-label','Copy link');
			}, 1800);
		};

		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(showSuccess).catch(showError);
			return;
		}

		try {
			input.focus();
			input.select();
			if(document.execCommand('copy'))showSuccess();
			else showError();
		} catch (err) {
			console.error('Copy failed', err);
			showError();
		}
	});

	/* ==========================
	   SEND QUOTE VIA EMAIL
	========================== */

	calculator.querySelector('.email-quote')?.addEventListener('click', async () => {
		if (!finalized) {
			await finalizeQuote();
		}

		emailModal?.classList.remove('is-hidden');
	});

	document.addEventListener('click', (e) => {
		const modal = document.getElementById('email-quote-modal');
		if (!modal) return;

		if (!modal.classList.contains('is-hidden') && !modal.contains(e.target) && !e.target.closest('.email-quote')) {
			modal.classList.add('is-hidden');
		}
	});

	calculator.querySelector('.send-email-confirm')?.addEventListener('click', async () => {
		const modal = document.getElementById('email-quote-modal');
		if (!modal) return;

		const sendContent = modal.querySelector('.send-content');
		const sentContent = modal.querySelector('.sent-content');
		const errorEl = modal.querySelector('.form-error');

		const nameInput = modal.querySelector('input[name="name"]');
		const emailInput = modal.querySelector('input[name="email"]');

		const name = nameInput.value.trim();
		const email = emailInput.value.trim();

		errorEl?.classList.add('is-hidden');
		nameInput.classList.remove('is-invalid');
		emailInput.classList.remove('is-invalid');

		let hasError = false;

		if (!name) {
			nameInput.classList.add('is-invalid');
			hasError = true;
		}

		if (!email || !/^\S+@\S+\.\S+$/.test(email)) {
			emailInput.classList.add('is-invalid');
			hasError = true;
		}

		if (hasError) {
			errorEl?.classList.remove('is-hidden');
			return;
		}

		try {
			const res = await requestEstimate({
				action: 'dx_email_quote_pdf',
				name,
				email,
				invoice: document.getElementById('invoice-link')?.value || '',
			});

			const data = await res.json();

			if (!data.success) {
				throw new Error('Email send failed');
			}

			sendContent.style.display = 'none';
			sentContent.style.display = 'block';
		} catch (err) {
			errorEl?.classList.remove('is-hidden');
			emailInput.classList.add('is-invalid');
		}
	});

	/* ==========================
	   DX MODAL CONTROLLER
	========================== */

	(() => {
		const modal = document.getElementById('email-quote-modal');
		if (!modal) return;

		const openButtons = calculator.querySelectorAll('.email-quote');
		const closeBtn = modal.querySelector('.dx-modal-close');
		const backdrop = modal.querySelector('.dx-modal-backdrop');
		const finishBtn = modal.querySelector('.dx-finish');

		let lastFocusedElement = null;

		const openModal = () => {
			lastFocusedElement = document.activeElement;

			modal.classList.remove('is-hidden');
			requestAnimationFrame(() => {
				modal.classList.add('is-visible');
			});

			document.body.style.overflow = 'hidden';

			const firstInput = modal.querySelector('input');
			firstInput?.focus();
		};

		const closeModal = () => {
			modal.classList.remove('is-visible');

			setTimeout(() => {
				modal.classList.add('is-hidden');
				document.body.style.overflow = '';
				lastFocusedElement?.focus();
			}, 400);
		};

		openButtons.forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				openModal();
			});
		});

		closeBtn?.addEventListener('click', closeModal);
		backdrop?.addEventListener('click', closeModal);
		finishBtn?.addEventListener('click', closeModal);

		document.addEventListener('keydown', (e) => {
			if (e.key === 'Escape' && modal.classList.contains('is-visible')) {
				closeModal();
			}
		});
	})();

	/* ==========================
	   INIT
	========================== */

	calculator.querySelectorAll('dialog').forEach(animateCalculatorDialog);
	const comparisonDialog=calculator.querySelector('.package-comparison');
	comparisonDialog.addEventListener('cancel',()=>{comparisonOpen=false;});
	comparisonDialog.addEventListener('click',event=>{if(event.target===comparisonDialog){const r=comparisonDialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom){comparisonOpen=false;updateRetainerUI();}}});
	const compact = matchMedia('(max-width: 767px)');
	const visualViewport = window.visualViewport;
	const syncKeyboard = () => {
		const editing = calculator.contains(document.activeElement) && document.activeElement.matches('input:not([type=checkbox]):not([type=radio]), textarea');
		calculator.classList.toggle('is-keyboard-open', Boolean(editing && visualViewport && innerHeight - visualViewport.height > 120));
	};
	visualViewport?.addEventListener('resize', syncKeyboard);
	calculator.addEventListener('focusin', syncKeyboard);
	calculator.addEventListener('focusout', () => requestAnimationFrame(syncKeyboard));
	const sheet = calculator.querySelector('[data-calculator-sheet]');
	const sheetTitle = sheet.querySelector('h2');
	const sheetContent = sheet.querySelector('[data-sheet-content]');
	const showSheet = (title, content, image = null) => {
		sheet.querySelector('.calculator-sheet__art')?.remove();
		sheet.querySelector('.calculator-sheet__eyebrow')?.remove();
		sheet.classList.toggle('calculator-sheet--service', Boolean(image));
		if (image) {
			const art = image.cloneNode();
			art.className = 'calculator-sheet__art';
			art.alt = '';
			art.loading = 'eager';
			art.setAttribute('aria-hidden', 'true');
			sheet.prepend(art);
			const eyebrow = document.createElement('p');
			eyebrow.className = 'dx-eyebrow calculator-sheet__eyebrow';
			eyebrow.textContent = 'Digital services / Find your fit';
			sheet.querySelector('.calculator-sheet__header').before(eyebrow);
		}
		sheetTitle.textContent = title;
		sheetContent.replaceChildren(content);
		sheet.showModal();
	};
	sheet.querySelector('button').addEventListener('click', () => sheet.close());
	sheet.addEventListener('click', event => {
		if (event.target === sheet && event.clientY < sheet.getBoundingClientRect().top) sheet.close();
	});
	calculator.querySelector('[data-estimate-breakdown]').addEventListener('click', () => {
		const estimate = calculator.querySelector('.summary-content');
		const fragment = document.createDocumentFragment();
		fragment.append(...[estimate.querySelector('.summary-breakdown'), estimate.querySelector('.summary-price')].map(element => element.cloneNode(true)));
		fragment.querySelectorAll('[id]').forEach(element => element.removeAttribute('id'));
		const originals = [...estimate.querySelectorAll('.summary-breakdown button')];
		fragment.querySelectorAll('.summary-breakdown button').forEach((button, index) => {
			button.addEventListener('click', () => { sheet.close(); originals[index]?.click(); });
		});
		showSheet('Your project', fragment);
	});
	calculator.querySelectorAll('[data-service-details]').forEach(link => {
		link.addEventListener('click', event => {
			event.preventDefault();
			event.stopPropagation();
			const option = link.closest('.service-option');
			const content = option.querySelector('.service-option__content').cloneNode(true);
			const url = new URL(link.dataset.serviceUrl, location.href);
			url.searchParams.set('from-calculator', '1');
			const cta = document.createElement('a');
			cta.className = 'dx-cta calculator-sheet__service-link';
			cta.href = url.href;
			cta.target = '_blank';
			// Retain the same-origin opener so the return action can restore this exact tab.
			cta.rel = 'opener';
			cta.textContent = 'Explore this service ↗';
			cta.setAttribute('aria-label', 'Explore ' + option.querySelector('input').dataset.label + ' (opens in a new tab)');
			content.append(cta);
			showSheet(option.querySelector('input').dataset.label, content, option.querySelector('.service-option__image'));
		});
	});
	const detailAnimations=new WeakMap();
	const defaultDisclosures = () => calculator.querySelectorAll('.calculator-optional').forEach(detail => { detail.open = detail.classList.contains('calculator-optional--always-open') || !compact.matches; });
	defaultDisclosures();
	compact.addEventListener('change', defaultDisclosures);
	calculator.querySelectorAll('.calculator-optional > summary').forEach(summary => {
		summary.addEventListener('click', event => {
			event.preventDefault();
			const detail = summary.parentElement;
			if (!detailAnimations.has(detail)) animateDetail(detail, !detail.open);
		});
	});
	const animateDetail=(detail,opening)=>{
		const old=detailAnimations.get(detail);if(old)old.cancel();
		const start=detail.getBoundingClientRect().height;
		if(opening)detail.open=true;
		const end=opening?detail.scrollHeight:detail.querySelector('summary').getBoundingClientRect().height;
		if(matchMedia('(prefers-reduced-motion: reduce)').matches){detail.open=opening;return;}
		detail.style.overflow = 'hidden';
		const motion=detail.animate([{height:`${start}px`},{height:`${end}px`}],{duration:300,easing:'cubic-bezier(.22,1,.36,1)'});
		detailAnimations.set(detail,motion);
		motion.onfinish=()=>{detail.open=opening;detail.style.overflow='';detailAnimations.delete(detail);};
	};
	calculator.querySelectorAll('details.service-option__details').forEach(detail=>{
		detail.querySelector('summary').addEventListener('click',event=>{
			event.preventDefault();
			if (compact.matches) {
				const label = detail.closest('.service-option').querySelector('input').dataset.label;
				showSheet(label, detail.querySelector('.service-option__content').cloneNode(true));
				return;
			}
			const opening=!detail.open;
			if(opening)calculator.querySelectorAll('.service-option__details[open]').forEach(other=>{if(other!==detail)animateDetail(other,false);});
			animateDetail(detail,opening);
		});
	});

	// Scroll motion is separate from the image's slow zoom to avoid transform conflicts.
	(() => {
		const stage = calculator.closest('.calculator-experience');
		const image = stage?.querySelector('.calculator-beach');
		if (!image) return;
		const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
		let frame = 0;
		const update = () => {
			frame = 0;
			const distance = Math.max(0, -stage.getBoundingClientRect().top);
			const offset = reducedMotion.matches ? 0 : Math.min(100, distance * .22);
			image.style.setProperty('--hero-parallax', `${offset}px`);
		};
		const schedule = () => { if (!frame) frame = requestAnimationFrame(update); };
		addEventListener('scroll', schedule, {passive: true});
		addEventListener('resize', schedule);
		reducedMotion.addEventListener('change', schedule);
		update();
	})();

	const personalisationToggle=calculator.querySelector('.personalisation-toggle');
	personalisationToggle?.addEventListener('click',()=>{const panel=calculator.querySelector('#personalisation-fields');const open=personalisationToggle.getAttribute('aria-expanded')!=='true';personalisationToggle.setAttribute('aria-expanded',String(open));panel.hidden=!open;});
	const editor=calculator.querySelector('.personalisation-editor');
	calculator.querySelector('[data-edit-personalisation]')?.addEventListener('click',()=>{editor.hidden=false;editor.querySelector('[data-edit-name]').value=state.client_name;editor.querySelector('[data-edit-company]').value=state.client_company;editor.querySelector('input').focus();});
	calculator.querySelector('[data-close-personalisation]')?.addEventListener('click',()=>{editor.hidden=true;calculator.querySelector('[data-edit-personalisation]').focus();});
	calculator.querySelector('[data-save-personalisation]')?.addEventListener('click',()=>{state.client_name=editor.querySelector('[data-edit-name]').value.trim();state.client_company=editor.querySelector('[data-edit-company]').value.trim();calculator.querySelector('[name="client_name"]').value=state.client_name;calculator.querySelector('[name="client_company"]').value=state.client_company;editor.hidden=true;resetFinalizedQuote();updateSummary();saveConfiguration();finalizeQuote();calculator.querySelector('[data-edit-personalisation]').focus();});
	const storageKey = 'dxndre-calculator-configuration-v1';
	const basketParams = new URL(location.href).searchParams;
	let basketSlug = basketParams.get('basket_service');
	const basketKeys = {'wordpress-development':'wp_development','one-time-fixes':'one_off'};
	const serviceKey = slug => basketKeys[slug] || slug;
	const configurationKey = slug => storageKey + ':' + slug;
	let hydrating = true;
	const savedFields = ['service_category','photo_type','service','scope','type','timeline','features','extras','retainer_level','retainer_areas','retainer_turnaround','retainer_commitment','retainer_addons','payment_plan','client_name','client_company'];
	const saveConfiguration = () => {
		try {
			if (hydrating || !state.service_category || getCurrentStepKey() === '1') return;
			const fields = Object.fromEntries(savedFields.map(key => [key, state[key]]));
			const step = getCurrentStepKey();
			const slug = state.service_category === 'photography' ? 'photography'
				: Object.keys(basketKeys).find(key => basketKeys[key] === state.service) || state.service;
			const previous = slug && JSON.parse(sessionStorage.getItem(configurationKey(slug)));
			const finalStep = activeFlow[activeFlow.length - 1];
			const valid = activeFlow.slice(0, -1).every(isStepValid);
			const configured = valid && finalized && (step === finalStep || previous?.configured === true);
			const snapshot = {fields, step, configured, total:state.total, project:projectFor(slug), reused:previous?.reused || [], reference:invoiceNumber, token:estimateToken, url:invoiceUrl, brief:JSON.parse(sessionStorage.getItem('dxndre-calculator-handoff') || '{}').summary || [], express:calculator.querySelector('[data-photo-express]').checked, video:calculator.querySelector('[data-photo-video]').checked};
			sessionStorage.setItem(storageKey, JSON.stringify(snapshot));
			if (slug && (state.service_category === 'digital' || state.service_category === 'photography')) {
				const group = state.service_category === 'photography' ? 'photo_type' : state.service === 'retainer' ? 'retainer_level' : 'scope';
				snapshot.summary = state[group] ? getLabelText(group, state[group]) : '';
				sessionStorage.setItem(configurationKey(slug), JSON.stringify(snapshot));
				if (state.service_category === 'digital' && state.service !== 'retainer') {
					for (const item of digitalBasket()) {
						if(item.slug===slug || item.slug==='retainer')continue;
						const sibling=read(configurationKey(item.slug),{});
						if(!sibling.fields || sibling.project!==snapshot.project)continue;
						let changed=false;
						for(const key of ['scope','type','timeline']) if(previous?.fields?.[key] && previous.fields[key]!==fields[key] && fields[key]) { sibling.fields[key]=fields[key]; sibling.reused=[...new Set([...(sibling.reused||[]),key])]; changed=true; }
						if(changed){sibling.total=serviceEstimate(item.slug,sibling.fields);sibling.configured=false;sessionStorage.setItem(configurationKey(item.slug),JSON.stringify(sibling));}
					}
				}
				document.dispatchEvent(new Event('dx-calculator-configuration'));
			}
		} catch (_) {}
	};

	const reusedSteps = new Set();
	const slugFor = key => Object.keys(basketKeys).find(slug => basketKeys[slug] === key) || key;
	const read = (key, fallback) => { try { return JSON.parse(sessionStorage.getItem(key)) || fallback; } catch { return fallback; } };
	const projectKey = 'dx-calculator-projects-v1';
	let projectData = read(projectKey, {mode:'same', groups:{}, accepted:''});
	const serviceControls = [...calculator.querySelectorAll('[name="service"]')];
	const titleFor = slug => serviceControls.find(input => input.value === serviceKey(slug))?.dataset.label || slug;
	const basket = () => { try { return JSON.parse(localStorage.getItem('dx-service-basket')) || []; } catch { return []; } };
	const digitalBasket = () => basket().filter(item => serviceControls.some(input => input.value === serviceKey(item.slug)));
	const projectFor = slug => {
		if(projectData.mode==='same')return 'Project 1';
		projectData.defaults ||= {};
		let next=Math.max(0,...Object.values(projectData.defaults).map(name=>Number(name.match(/^Project (\d+)$/)?.[1])||0));
		let changed=false;for(const item of digitalBasket())if(!projectData.defaults[item.slug]){projectData.defaults[item.slug]='Project '+(++next);changed=true;}
		if(changed)sessionStorage.setItem(projectKey,JSON.stringify(projectData));
		return (projectData.groups[slug]||'').trim()||projectData.defaults[slug]||'Project 1';
	};
	const multiMoney = value => new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP'}).format(value);
	const panel = document.createElement('div');
	panel.className = 'calculator-projects';
	stepLookup['1'].append(panel);
	const banner = document.createElement('p');
	banner.className = 'calculator-service-context'; banner.setAttribute('role','status');
	calculator.querySelector('.calculator-nav').before(banner);
	const saveProjects = () => {
		sessionStorage.setItem(projectKey, JSON.stringify(projectData));
		for(const item of digitalBasket()){
			const saved=read(configurationKey(item.slug),{}),project=projectFor(item.slug);
			if(!saved.fields || saved.project===project)continue;
			for(const field of saved.reused||[])delete saved.fields[field];
			saved.reused=[];saved.project=project;saved.configured=false;saved.total=serviceEstimate(item.slug,saved.fields);
			sessionStorage.setItem(configurationKey(item.slug),JSON.stringify(saved));
		}
		document.dispatchEvent(new Event('dx-calculator-configuration'));
	};
	const syncBasket = items => {
		localStorage.setItem('dx-service-basket', JSON.stringify(items));
		document.dispatchEvent(new Event('dx-service-basket-change'));
	};

	function exportEstimateState(){
		const payload=JSON.parse(JSON.stringify(state)),items=digitalBasket();
		if(state.service_category!=='digital'||items.length<2)return payload;
		payload.service_estimates=items.map(item=>{
			const saved=read(configurationKey(item.slug),{}),current=serviceKey(item.slug)===state.service;
			const fields=JSON.parse(JSON.stringify(current?state:saved.fields||{service_category:'digital',service:serviceKey(item.slug)}));
			delete fields.service_estimates;
			return {project:projectFor(item.slug),configured:current||saved.configured===true,state:fields};
		});
		return payload;
	}
	function serviceEstimate(slug, fields) {
		const key=serviceKey(slug);
		if(key==='retainer') {
			const c=retainerConfig, tierKey=fields.retainer_level||'essentials', tier=c.tiers[tierKey], index=Object.keys(c.tiers).indexOf(tierKey);
			let subtotal=tier.price;
			for(const area of fields.retainer_areas||[])subtotal+=c.areas[area]?.prices[index]||0;
			subtotal+=c.turnaround[fields.retainer_turnaround||'standard'].price;
			for(const addon of fields.retainer_addons||[])subtotal+=c.addons[addon]?.price||0;
			return money(subtotal*c.commitments[fields.retainer_commitment||'3_months'].multiplier);
		}
		let total=0;
		const config=summaryFlowSteps[key==='design'?'design':'default'];
		for(const cfg of config){
			const values=cfg.type==='checkbox' ? fields[cfg.key]||[] : fields[cfg.key] ? [fields[cfg.key]] : [];
			for(const value of values)total+=key==='one_off' && ['nonprofit','no_rush'].includes(value) ? -5 : Number(cfg.prices?.[value])||0;
		}
		return Math.max(0,money(total));
	}
	const summaryExpanded=new Map();let summaryAccordionId=0;
	multiSummary = () => {
		if(state.service_category!=='digital')return;
		const items=digitalBasket(), selecting=getCurrentStepKey()==='1';
		if(!items.length){breakdownEls.forEach(list=>list.replaceChildren());totalEls.forEach(el=>{fadeUpdatePrice(el,0);});calculator.querySelectorAll('[data-multi-monthly],[data-multi-price-note]').forEach(el=>el.hidden=true);return;}
		let oneOff=0,monthly=0;
		const entries=items.map(item=>{
			const saved=read(configurationKey(item.slug),{});
			const current=!selecting && serviceKey(item.slug)===state.service;
			const fields=current ? state : saved.fields||{service:serviceKey(item.slug)};
			const amount=current ? state.total : serviceEstimate(item.slug,fields);
			if(item.slug==='retainer')monthly+=amount;else oneOff+=amount;
			return {...item,saved,current,amount};
		});
		breakdownEls.forEach(list=>{
			if(items.length===1&&!selecting)return;
			const activeRows=[...list.children].filter(row=>!row.dataset.multiService);
			list.replaceChildren();
			for(const entry of entries){
				const group=document.createElement('li');group.dataset.multiService=entry.slug;group.className='multi-service-group';
				const header=addText(group,'div','','multi-service-heading');
				const toggle=addText(header,'button','','multi-service-toggle');toggle.type='button';addText(toggle,'strong',titleFor(entry.slug));addText(toggle,'span','⌄','multi-service-chevron');
				addText(header,'span',multiMoney(entry.amount)+(entry.slug==='retainer'?' / month':''),'multi-service-subtotal');
				const edit=addAction(header,selecting?'Configure':'Edit',()=>{summaryExpanded.set(entry.slug,true);openService(entry.slug);});edit.setAttribute('aria-label','Configure '+titleFor(entry.slug));
				addText(group,'p',projectFor(entry.slug)+(entry.saved.configured||entry.current?'':' · indicative estimate'),'multi-service-project');
				const content=addText(group,'div','','multi-service-content');content.id='service-summary-'+(++summaryAccordionId);toggle.setAttribute('aria-controls',content.id);
				let expanded=summaryExpanded.get(entry.slug)===true,motion=null,version=0;toggle.setAttribute('aria-expanded',String(expanded));content.hidden=!expanded;content.inert=!expanded;
				toggle.addEventListener('click',()=>{
					const revision=++version,start=content.hidden?0:content.getBoundingClientRect().height;motion?.cancel();expanded=!expanded;summaryExpanded.set(entry.slug,expanded);toggle.setAttribute('aria-expanded',String(expanded));content.hidden=false;content.inert=!expanded;
					const end=expanded?content.scrollHeight:0;
					if(matchMedia('(prefers-reduced-motion: reduce)').matches){content.hidden=!expanded;return;}
					motion=content.animate([{height:start+'px',opacity:expanded?.35:1},{height:end+'px',opacity:expanded?1:0}],{duration:320,easing:'cubic-bezier(.22,1,.36,1)',fill:'both'});
					motion.finished.then(()=>{if(revision!==version)return;content.hidden=!expanded;motion.cancel();motion=null;}).catch(()=>{});
				});
				const detail=addText(content,'ul','','multi-service-details');
				if(entry.current)detail.append(...activeRows);
				else{
					const fields=entry.saved.fields||{service:serviceKey(entry.slug)};
					const config=summaryFlowSteps[serviceKey(entry.slug)==='retainer'?'retainer':serviceKey(entry.slug)==='design'?'design':'default'];
					for(const cfg of config){
						const values=Array.isArray(fields[cfg.key])?fields[cfg.key]:fields[cfg.key]?[fields[cfg.key]]:[];
						for(const value of values){const row=addText(detail,'li','');const input=calculator.querySelector(`input[name="${cfg.key}"][value="${value}"]`);addText(row,'span',input?.dataset.label||getLabelText(cfg.key,value),'summary-label');}
					}
					if(!detail.children.length)addText(detail,'li','Requirements not yet configured.');
				}
				list.append(group);
			}
		});
		const payToday=calculator.querySelector('[data-pay-today]');if(payToday && items.length>1)payToday.textContent=titleFor(slugFor(state.service))+' ONLY · '+payToday.textContent;
		const label=calculator.querySelector('.summary-price > p');label.textContent=monthly && !oneOff?'Your estimated monthly investment':'Your combined one-off estimate';
		for(const el of totalEls){fadeUpdatePrice(el,oneOff||monthly);}
		calculator.querySelector('.summary-price').dataset.monthly=String(!oneOff&&!!monthly);
		let recurring=calculator.querySelector('[data-multi-monthly]');if(!recurring){recurring=document.createElement('p');recurring.dataset.multiMonthly='';calculator.querySelector('.summary-price').append(recurring);}
		recurring.hidden=!monthly;recurring.textContent=oneOff?`Plus ${multiMoney(monthly)} / month for ongoing support`:'Per month for ongoing support';
		let notice=calculator.querySelector('[data-multi-price-note]');if(!notice){notice=document.createElement('p');notice.dataset.multiPriceNote='';calculator.querySelector('.summary-price').append(notice);}
		notice.hidden=false;
		notice.textContent=items.some(item=>!read(configurationKey(item.slug),{}).configured)?'Indicative total. Configure each service to refine its requirements and price.':'All selected services included. Subject to confirming the brief.';
	};
	function developmentConflict(){const items=digitalBasket();return items.some(item=>item.slug==='wordpress-development')&&items.some(item=>item.slug==='development')&&projectFor('wordpress-development')&&projectFor('wordpress-development')===projectFor('development');}
	function conflicts() {
		const items = digitalBasket();
		return items.filter(item => item.slug === 'design' || item.slug === 'comprehensive').flatMap(item =>
			items.filter(other => ['development','wordpress-development'].includes(other.slug) && projectFor(other.slug) && projectFor(other.slug) === projectFor(item.slug))
			.map(other => [item.slug, other.slug]));
	}
	function renderProjects() {
		panel.replaceChildren();
		const items = digitalBasket();
		if(getCurrentStepKey()==='1')state.service=items[0] ? serviceKey(items[0].slug) : null;
		serviceControls.forEach(input => { input.checked = items.some(item => serviceKey(item.slug) === input.value); });
		if (items.length > 1) {
			const heading = document.createElement('h3'); heading.textContent = 'Are these services for the same project?'; panel.append(heading);
			for (const [value, text] of [['same','One project — reuse shared answers'],['different','Several projects — assign each service below']]) {
				const label = document.createElement('label'); const input = document.createElement('input'); input.type='radio'; input.name='project_grouping'; input.value=value; input.checked=projectData.mode===value;
				input.addEventListener('change',()=>{ projectData.mode=value; projectData.accepted=''; saveProjects(); renderProjects(); });
				label.className='calculator-project-choice';const caption=document.createElement('span');caption.textContent=text;label.append(input,caption); panel.append(label);
			}
			if(projectData.mode==='different') items.forEach(item=>{
				const label=document.createElement('label'); label.textContent=titleFor(item.slug)+' — Project Name';
				const input=document.createElement('input'); input.type='text'; input.maxLength=100; input.value=projectFor(item.slug); input.placeholder=projectData.defaults?.[item.slug]||'Project Name';
				input.addEventListener('change',()=>{projectData.groups[item.slug]=input.value.trim();projectData.accepted='';saveProjects();renderProjects();});label.append(input); panel.append(label);
			});
		}
		if(developmentConflict()){
			const box=document.createElement('div');box.className='calculator-package-advice calculator-development-choice';box.setAttribute('role','group');
			const heading=document.createElement('h3');heading.textContent='Which development approach suits this project?';box.append(heading);
			const copy=document.createElement('p');copy.textContent='WordPress Development and Custom Web Development are alternative ways to build the same project. Choose one approach, or assign them to separate projects above.';box.append(copy);
			for(const [slug,label] of [['wordpress-development','Choose WordPress Development'],['development','Choose Custom Web Development']]){
				const button=document.createElement('button');button.type='button';button.textContent=label;button.addEventListener('click',()=>{const remove=slug==='development'?'wordpress-development':'development';syncBasket(basket().filter(item=>item.slug!==remove));projectData.accepted='';saveProjects();renderProjects();updateSummary();validateStep();});box.append(button);
			}panel.append(box);
		}
		const pairs=conflicts(); const signature=JSON.stringify(pairs.map(pair=>[...pair,projectFor(pair[0])]));
		if(pairs.length && projectData.accepted!==signature){
			const box=document.createElement('div');box.className='calculator-package-advice';
			const copy=document.createElement('p');copy.textContent='Design and Development for one project work best as a Comprehensive package. It combines both stages and avoids overlapping scope. For separate projects, assign different project names above.';box.append(copy);
			const combine=document.createElement('button');combine.type='button';combine.textContent='Choose Comprehensive';combine.addEventListener('click',()=>{
				let items=basket();for(const pair of pairs){const group=projectFor(pair[0]);const source=read(configurationKey(pair[1]),read(configurationKey(pair[0]),{}));const existing=read(configurationKey('comprehensive'),{});if(!existing.fields && source.fields){const fields={service_category:'digital',service:'comprehensive'};for(const key of ['scope','type','timeline','client_name','client_company','features','extras'])if(source.fields[key])fields[key]=source.fields[key];sessionStorage.setItem(configurationKey('comprehensive'),JSON.stringify({fields,project:group,step:'2',configured:false,reused:['scope','type','timeline'].filter(key=>fields[key])}));}items=items.filter(item=>!pair.includes(item.slug));if(!items.some(item=>item.slug==='comprehensive'))items.push({slug:'comprehensive',title:titleFor('comprehensive')});projectData.groups.comprehensive=group;}
				projectData.accepted='';saveProjects();syncBasket(items);renderProjects();state.service=digitalBasket()[0] ? serviceKey(digitalBasket()[0].slug) : null;updateSummary();validateStep();syncActiveLabels();
			});
			const keep=document.createElement('button');keep.type='button';keep.textContent='Keep separate service estimates';keep.addEventListener('click',()=>{projectData.accepted=signature;saveProjects();renderProjects();});box.append(combine,keep);panel.append(box);
		}
		syncActiveLabels();
		multiSummary?.();
	}
	function changeServices() {
		resetFinalizedQuote();
		const selected=serviceControls.filter(input=>input.checked).map(input=>({slug:slugFor(input.value),title:input.dataset.label}));
		syncBasket([...basket().filter(item=>!serviceControls.some(input=>slugFor(input.value)===item.slug)),...selected]);
		state.service=selected[0] ? serviceKey(selected[0].slug) : null;
		projectData.accepted='';saveProjects();renderProjects();updateSummary();validateStep();
	}
	function shakeProjectAdvice(){const box=panel.querySelector('.calculator-development-choice, .calculator-package-advice')||panel;box.getAnimations().forEach(animation=>animation.cancel());if(!matchMedia('(prefers-reduced-motion: reduce)').matches)box.animate([{translate:'0'},{translate:'-9px'},{translate:'8px'},{translate:'-6px'},{translate:'5px'},{translate:'-2px'},{translate:'0'}],{duration:500,easing:'ease-in-out'});box.scrollIntoView({block:'center',behavior:'smooth'});box.querySelector('button')?.focus({preventScroll:true});}
	function beginServices() {
		const items=digitalBasket();
		if(!items.length)return;
		if(projectData.mode==='different' && items.some(item=>!projectFor(item.slug))){showEstimateNotice('Give each service a project name. Use the same name for services belonging to the same project.');return;}
		if(developmentConflict()){shakeProjectAdvice();return;}
		const pairs=conflicts();const signature=JSON.stringify(pairs.map(pair=>[...pair,projectFor(pair[0])]));
		if(pairs.length && projectData.accepted!==signature){shakeProjectAdvice();return;}
		const item=items.find(item=>!read(configurationKey(item.slug),{}).configured)||items[0];
		openService(item.slug);
	}
	function openService(slug) {
		saveConfiguration();
		const own=read(configurationKey(slug),{});let fields=own.fields||{service_category:'digital',service:serviceKey(slug)};
		for(const key of ['client_name','client_company'])if(!fields[key] && state[key])fields[key]=state[key];
		const shared=serviceKey(slug)==='retainer' ? [] : ['scope','type','timeline'];const reused=own.reused||[];
		if(own.project && own.project!==projectFor(slug)){ for(const key of reused) delete fields[key]; reused.length=0; own.configured=false; }
		for(const item of digitalBasket()){
			const source=read(configurationKey(item.slug),{});
			for(const key of ['client_name','client_company'])if(!fields[key] && source.fields?.[key])fields[key]=source.fields[key];
			if(item.slug===slug || source.project!==projectFor(slug) || !source.project)continue;
			for(const key of shared)if(!fields[key] && source.fields?.[key]){fields[key]=source.fields[key];reused.push(key);}
			for(const key of ['client_name','client_company'])if(!fields[key] && source.fields?.[key])fields[key]=source.fields[key];
		}
		sessionStorage.setItem(configurationKey(slug),JSON.stringify({...own,fields,step:own.step && own.step!=='category' && own.step!=='1' ? own.step : serviceKey(slug)==='retainer' ? 'retainer_level' : '2',project:projectFor(slug),reused}));
		const url=new URL(location.href);url.searchParams.set('basket_service',slug);url.searchParams.delete('basket_review');
		// Switch the active service in the existing calculator, retaining its saved configuration.
		basketSlug=slug;history.replaceState(history.state,'',url.href);hydrating=true;
		resetFinalizedQuote();Object.keys(state).forEach(key=>delete state[key]);Object.assign(state,JSON.parse(JSON.stringify(emptyState)));
		reusedSteps.clear();const index=restoreConfiguration();
		(read(configurationKey(slug),{}).reused||[]).forEach(key=>reusedSteps.add(({scope:'2',type:'3',timeline:'5'})[key]));
		hydrating=false;renderProjects();
		banner.textContent=titleFor(slug)+' · '+projectFor(slug)+(reusedSteps.size?' — shared project answers restored; edit them in the summary if needed.':' — configure this service, then continue to the next.');
		let next=index;while(next<activeFlow.length-1&&reusedSteps.has(activeFlow[next])&&isStepValid(activeFlow[next]))next++;
		goToStepIndex(next);syncActiveLabels();saveConfiguration();
		const review=document.querySelector('.dx-multi-review[open]');if(review)review.close();
	}
	function finishService() {
		if(developmentConflict()){
			const box=document.createElement('div');box.className='calculator-package-advice calculator-development-choice';box.setAttribute('role','group');
			const heading=document.createElement('h3');heading.textContent='Which development approach suits this project?';box.append(heading);
			const copy=document.createElement('p');copy.textContent='WordPress Development and Custom Web Development are alternative ways to build the same project. Choose one approach, or assign them to separate projects above.';box.append(copy);
			for(const [slug,label] of [['wordpress-development','Choose WordPress Development'],['development','Choose Custom Web Development']]){
				const button=document.createElement('button');button.type='button';button.textContent=label;button.addEventListener('click',()=>{const remove=slug==='development'?'wordpress-development':'development';syncBasket(basket().filter(item=>item.slug!==remove));projectData.accepted='';saveProjects();renderProjects();updateSummary();validateStep();});box.append(button);
			}panel.append(box);
		}
		const pairs=conflicts(); const signature=JSON.stringify(pairs.map(pair=>[...pair,projectFor(pair[0])]));
		if (pairs.length && projectData.accepted!==signature) { renderProjects(); goToStepIndex(activeFlow.indexOf('1')); return; }
		saveConfiguration();
		const items=digitalBasket();const next=items.find(item=>!read(configurationKey(item.slug),{}).configured);
		if(next){openService(next.slug);return;}
		const dialog=document.createElement('dialog');dialog.className='dx-multi-review';
		const heading=document.createElement('h2');heading.textContent='Review your services';dialog.append(heading);
		let oneOff=0,monthly=0;const summary=[];const services=[];
		items.forEach(item=>{
			const saved=read(configurationKey(item.slug),{});const recurring=item.slug==='retainer';const total=Number(saved.total)||0;if(recurring)monthly+=total;else oneOff+=total;
			const row=document.createElement('div');const title=document.createElement('h3');title.textContent=titleFor(item.slug);const detail=document.createElement('p');detail.textContent=`${saved.project} · ${multiMoney(total)}${recurring?' / month':''}`;
			const edit=document.createElement('button');edit.type='button';edit.textContent='Edit requirements';edit.addEventListener('click',()=>openService(item.slug));row.append(title,detail,edit);dialog.append(row);
			summary.push(`${saved.project} — ${titleFor(item.slug)}: ${multiMoney(total)}${recurring?' per month':''}`, ...(saved.brief||[]).map(line=>'  '+line),`Estimate reference: ${saved.reference||'Pending'}`);
			services.push({service:serviceKey(item.slug),project:saved.project,total,monthly:recurring,reference:saved.reference});
		});
		const total=document.createElement('p');total.textContent=`One-off estimate: ${multiMoney(oneOff)}${monthly?' · Ongoing support: '+multiMoney(monthly)+' / month':''}. Subject to confirming your brief.`;dialog.append(total);
		const proceed=document.createElement('button');proceed.type='button';proceed.textContent='Continue to project enquiry';proceed.addEventListener('click',()=>{
			sessionStorage.setItem('dxndre-calculator-handoff',JSON.stringify({service:services.length===1?services[0].service:'multiple',services,total:oneOff,monthlyTotal:monthly,summary,name:state.client_name,company:state.client_company}));
			location.href=new URL('/start-a-project/?from_calculator=1',location.origin).href;
		});const close=document.createElement('button');close.type='button';close.textContent='Back to estimate';close.addEventListener('click',()=>dialog.close());dialog.append(proceed,close);dialog.addEventListener('close',()=>dialog.remove());document.body.append(dialog);dialog.showModal();
	}
	// Cart changes also update the calculator's selection step.
	document.addEventListener('dx-service-basket-change',renderProjects);

	const restoreConfiguration = () => {
		try {
			let saved = JSON.parse(sessionStorage.getItem(basketSlug ? configurationKey(basketSlug) : storageKey));
			if (!saved && basketSlug) {
				const legacy = JSON.parse(sessionStorage.getItem(storageKey));
				const matches = basketSlug === 'photography' ? legacy?.fields?.service_category === 'photography'
					: legacy?.fields?.service_category === 'digital' && legacy.fields.service === serviceKey(basketSlug);
				if (matches) saved = legacy;
			}
			if (!saved?.fields?.service_category) return 0;
			invoiceNumber = saved.reference || ''; estimateToken = saved.token || ''; invoiceUrl = saved.url || '';
			if (invoiceNumber) state.estimate_reference = invoiceNumber;
			for (const key of savedFields) {
				const value = saved.fields[key];
				const inputs = [...calculator.querySelectorAll(`input[name="${key}"]`)];
				inputs.forEach(input => { input.checked = Array.isArray(value) ? value.includes(input.value) : value === input.value; });
				if (key === 'client_name' || key === 'client_company') { state[key] = typeof value === 'string' ? value : ''; inputs.forEach(input => input.value = state[key]); continue; }
				const selected = inputs.filter(input => input.checked).map(input => input.value);
				state[key] = Array.isArray(state[key]) ? selected : selected[0] ?? state[key];
			}
			const express = calculator.querySelector('[data-photo-express]');
			express.checked = saved.express === true;
			calculator.querySelector('[data-photo-video]').checked = saved.video === true;
			express.dispatchEvent(new Event('change'));
			applyActiveFlow();
			const requested = Math.max(0, activeFlow.indexOf(saved.step));
			const incomplete = activeFlow.slice(0, requested).findIndex(key => !isStepValid(key));
			calculator.querySelector('[data-session-status]').textContent = 'Previous selections restored.';
			// Review creates an estimate record. Resume at the last configuration step.
			const index = incomplete < 0 ? requested : incomplete;
			return isSummaryStep(activeFlow[index]) ? index - 1 : index;
		} catch (_) { return 0; }
	};
	calculator.addEventListener('change',()=>queueMicrotask(saveConfiguration));
	calculator.addEventListener('input',event=>{if(event.target.matches('[name=client_name],[name=client_company]'))queueMicrotask(()=>{updateSummary();saveConfiguration();});});
	calculator.querySelector('[data-completed-steps]').addEventListener('change', event => {
		if (event.target.value !== '') goToStepIndex(Number(event.target.value));
	});
	const restart = calculator.querySelector('[data-calculator-restart-dialog]');
	calculator.querySelectorAll('[data-restart-calculator]').forEach(button => button.addEventListener('click', () => restart.showModal()));
	restart.addEventListener('cancel', event => { event.preventDefault(); closeRestart(); });
	const closeRestart = () => restart.close();
	restart.querySelector('[data-keep-configuration]').addEventListener('click', closeRestart);
	restart.querySelector('[data-clear-configuration]').addEventListener('click', () => {
		try {
			// Reset the whole calculator journey, including every service's saved answers.
			Object.keys(sessionStorage).filter(key => key === storageKey || key.startsWith(storageKey + ':')).forEach(key => sessionStorage.removeItem(key));
			sessionStorage.removeItem(projectKey);
			sessionStorage.removeItem('dxndre-calculator-handoff');
			localStorage.removeItem('dx-service-basket');
		} catch (_) {}
		// A service link would immediately select it again on reload.
		const url = new URL(location.href);
		['basket_service', 'basket_review', 'basket_select'].forEach(key => url.searchParams.delete(key));
		url.hash = '';
		location.replace(url.href);
	});
	successEl?.classList.add('is-hidden');
	bindInputs();
	applyActiveFlow();
	let restoredIndex = restoreConfiguration();
	const basketService = basketSlug;
	const basketInput = [...calculator.querySelectorAll('[name="service"]')].find(input => input.value === (basketKeys[basketService] || basketService));
	if (basketInput || basketService === 'photography') {
		const category = calculator.querySelector(`[name="service_category"][value="${basketInput ? 'digital' : 'photography'}"]`);
		if (!category.checked) category.click();
		if (basketInput && !basketInput.checked) basketInput.click();
		const unanswered = activeFlow.findIndex(key => !isStepValid(key) || isSummaryStep(key) || key === 'photography' || stepLookup[key]?.querySelector('.calculator-optional'));
		const saved = (() => {
			try { return JSON.parse(sessionStorage.getItem(configurationKey(basketService))); } catch (_) { return null; }
		})();
		if (saved?.configured && basketParams.has('basket_review') && activeFlow.slice(0, -1).every(isStepValid)) restoredIndex = activeFlow.length - 1;
		else if (saved) restoredIndex = Math.max(0, restoredIndex);
		else restoredIndex = unanswered < 0 ? activeFlow.length - 1 : unanswered;
	}
	if(basketParams.has('basket_select')) { state.service_category='digital'; state.service=serviceKey(digitalBasket()[0]?.slug)||null; calculator.querySelector('[name=service_category][value=digital]').checked=true;applyActiveFlow();restoredIndex=activeFlow.indexOf('1'); }
	updateSummary();
	syncFeatureExclusivity();
	hydrating = false;
	if (basketSlug) {
		const items=basket(); if (basketInput && !items.some(item=>item.slug===basketSlug)) syncBasket([...items,{slug:basketSlug,title:titleFor(basketSlug)}]);
		const saved=read(configurationKey(basketSlug),{});
		(saved.reused||[]).forEach(key=>reusedSteps.add(({scope:'2',type:'3',timeline:'5'})[key]));
		banner.textContent=titleFor(basketSlug)+' · '+projectFor(basketSlug)+(reusedSteps.size ? ' — shared project answers restored; edit them in the summary if needed.' : ' — configure this service, then continue to the next.');
	} else if (state.service && !digitalBasket().length) syncBasket([{slug:slugFor(state.service),title:titleFor(slugFor(state.service))}]);
	renderProjects();
	while (restoredIndex < activeFlow.length-1 && reusedSteps.has(activeFlow[restoredIndex]) && isStepValid(activeFlow[restoredIndex])) restoredIndex++;
	goToStepIndex(restoredIndex);
	initScrollableHint();
	syncActiveLabels();
	updateSummaryCopy();

	const revealCalculator = () => {
		if(calculator.dataset.entrancePlayed)return;calculator.dataset.entrancePlayed='true';
		if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
		calculator.querySelectorAll('.calculator-progress-caption, .calculator-segments, .calculator-session > :not([hidden]), .calculator-step.is-active .step-title, .calculator-step.is-active .step-intro, .calculator-step.is-active .category-card, .calculator-step.is-active .calculator-intro-fields, .calculator-summary > :not([hidden]), .calculator-nav').forEach((element, index) => {
			element.animate([{opacity:0, transform:'translateY(16px)'},{opacity:1, transform:'translateY(0)'}], {duration:600,delay:Math.min(index*55,440),fill:'backwards',easing:'cubic-bezier(.22,1,.36,1)'});
		});
	};
	if (getCurrentStepKey() === 'category' || document.querySelector('.dx-calculator-splash')) {
		const splash = document.querySelector('.dx-calculator-splash') || document.createElement('div');
		splash.className = 'dx-calculator-splash';
		splash.setAttribute('role', 'status');
		splash.innerHTML = '<div class="dx-calculator-splash__content"><span class="dx-calculator-splash__brand">DX.</span><p class="dx-calculator-splash__eyebrow">YOUR NEXT CHAPTER</p><h2>Pricing<br><span>Calculator</span></h2><div class="dx-calculator-splash__track" aria-hidden="true"><span></span></div><p class="dx-calculator-splash__status">Preparing your project space</p></div>';
		document.body.append(splash);
		calculator.inert = true;
		const started = performance.now();
		splash.dataset.started = String(started);
		Promise.all([new Promise(resolve=>setTimeout(resolve,3000)), document.fonts?.ready || Promise.resolve(), window.dxPageReady || Promise.resolve()]).then(async () => {
			// Begin the entrance while the opaque splash still covers the calculator.
			// Starting after its fade exposed settled content, then hid it for a second entrance.
			revealCalculator();
			document.documentElement.classList.remove('dx-calculator-boot');
			if (!matchMedia('(prefers-reduced-motion: reduce)').matches) await splash.animate([{opacity:1},{opacity:0}],{duration:300,easing:'ease-out',fill:'forwards'}).finished.catch(()=>{});
			splash.remove();
			calculator.inert = false;
			calculator.dataset.splashDuration = String(performance.now()-started);
		});
	} else {document.documentElement.classList.remove('dx-calculator-boot');revealCalculator();}

})();