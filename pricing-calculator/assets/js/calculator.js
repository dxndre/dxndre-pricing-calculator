(() => {

	/* ==========================
	   STATE + PRICING
	========================== */

	const state = {
		service: null,
		service_category: null,
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
			{ key: 'timeline', type: 'radio', group: 'timeline', prices: priceMaps.timeline },
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
		btn.disabled = disabled;
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
		const numeric = Number(map[value]);
		return Number.isFinite(numeric) ? numeric : 0;
	};

	const getLabelText = (group, value) => {
		const input = calculator.querySelector(`input[name="${group}"][value="${value}"]`);
		if (!input) return value;
		if (input.dataset.label) return input.dataset.label;
		const label = input.closest('label');
		if (!label) return value;

		const cloned = label.cloneNode(true);
		cloned.querySelectorAll('input, .custom-radio, .custom-checkbox').forEach((el) => el.remove());
		return cloned.textContent.trim();
	};

	const fadeUpdatePrice = (el, value) => {
		if (!el) return;
		el.classList.add('is-updating');
		setTimeout(() => {
			el.textContent = formatPrice(value) + (state.service === 'retainer' ? ' / month' : '');
			requestAnimationFrame(() => {
				el.classList.remove('is-updating');
			});
		}, 150);
	};

	const isSummaryStep = (stepKey) => stepKey === '7';

	const applyActiveFlow = () => {
		activeFlow = state.service_category === 'fitness' ? ['category', 'fitness'] : ['category', ...(flows[getFlowKey()] || flows.default)];
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
		progressLabel.textContent = `Step ${current} of ${total}`;
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
				if(key==='monthly')el.textContent=`${formatPrice(money(subtotal*rolling))} / month`;
				else {
					const saving=(1-c.commitments[key].multiplier/rolling)*100;
					el.textContent=`Save ${saving.toFixed(1).replace(/\.0$/, '')}% compared with monthly rolling`;
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
		addText(header,'th','Your selected requirements').scope='col';
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

		let rows=[];
		if(state.service==='retainer') { const estimate=calculateRetainer();state.total=estimate.total;rows=estimate.rows;state.retainer_estimate=estimate; }
		else { delete state.retainer_estimate;summaryConfig.forEach(cfg=>{const values=cfg.type==='checkbox'?state[cfg.key]||[]:state[cfg.key]?[state[cfg.key]]:[];values.forEach(value=>rows.push({label:getLabelText(cfg.group,value),amount:getPriceForValue(cfg.prices,value),group:cfg.key}));}); }
		breakdownEls.forEach(list=>{list.replaceChildren();rows.forEach(item=>{const row=document.createElement('li');row.dataset.summaryGroup=item.group;if(item.amount<0)row.className='is-discount';addText(row,'span',item.label,'summary-label');addText(row,'span',item.amount===0&&item.group==='retainer_areas'?'Included':formatPrice(item.amount),'summary-value');list.append(row);});if(state.client_company){const row=document.createElement('li');row.dataset.summaryGroup='client_company';addText(row,'span','Company','summary-label');addText(row,'span',state.client_company,'summary-value');list.prepend(row);}});
		updateRetainerUI();

		const summaryStepMap = {service:'1', scope:'2', type:'3', features:'4', timeline:'5', extras:'6', client_company:'category', retainer_level:'retainer_level', retainer_areas:'retainer_areas', retainer_turnaround:'retainer_turnaround', retainer_commitment:'retainer_commitment', retainer_addons:'retainer_addons'};
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
					getCurrentStepEl()?.scrollIntoView({block:'start', behavior:'auto'});
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
		 sessionStorage.setItem('dxndre-calculator-handoff', JSON.stringify({service:state.service,total:state.total,monthly:state.service==='retainer',reference:invoiceNumber,name:state.client_name,company:state.client_company,summary}));
		} catch (_) {}
	};

	/* ==========================
	   STEP VALIDATION
	========================== */

	const validateStep = () => {
		const stepKey = getCurrentStepKey();
		if (!stepKey) return;

		if (isSummaryStep(stepKey)) {
			setButtonDisabled(nextBtn, false);
			return;
		}

		let valid = false;

		switch (stepKey) {
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
				valid = state.features.length > 0;
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
				valid = state.retainer_areas.length > 0;
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

		setButtonDisabled(nextBtn, !valid);
	};

	/* ==========================
	   STEP NAVIGATION
	========================== */

	const goToStepIndex = (index) => {
		if (index < 0 || index >= activeFlow.length) return;

		steps.forEach((step) => {
			step.classList.remove('is-active');
			step.hidden = true;
		});

		currentStepIndex = index;
		const target = getCurrentStepEl();
		if (!target) return;

		target.hidden = false;
		target.classList.add('is-active');
		calculator.dataset.step = getCurrentStepKey();
		calculator.querySelector('.calculator-summary').classList.toggle('is-review', getCurrentStepKey() === '7');
		calculator.querySelector('.calculator-summary').hidden = false;
		calculator.querySelector('.summary-welcome').hidden = getCurrentStepKey() !== 'category';
		calculator.querySelector('.summary-fitness').hidden = getCurrentStepKey() !== 'fitness';
		calculator.querySelector('.summary-content').hidden = ['category', 'fitness'].includes(getCurrentStepKey());
		nextBtn.classList.toggle('is-hidden', getCurrentStepKey() === 'fitness');
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
		updateRetainerUI();
		stepsContainer.scrollTop = 0;
		const heading = target.querySelector('.step-title');
		if (heading && index > 0) { heading.tabIndex = -1; heading.focus({preventScroll:true}); }


		setButtonDisabled(prevBtn, currentStepIndex === 0);
		prevBtn.classList.toggle('is-hidden', currentStepIndex === 0);

		if (getCurrentStepKey() === 'category') {
			nextBtn.textContent = 'Continue';
		} else if (currentStepIndex === activeFlow.length - 2) {
			nextBtn.textContent = 'Review summary';
		} else if (currentStepIndex === activeFlow.length - 1) {
			nextBtn.textContent = 'Finish';
		} else {
			nextBtn.textContent = 'Next';
		}

		updateProgress();
		validateStep();
		animateHeight();

		if (isSummaryStep(getCurrentStepKey())) {
			calculator.scrollIntoView({block:'start',behavior:'auto'});
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
		finalizePending = (async () => { do { if (!await persistQuote()) break; } while (!finalized); })().catch(() => { alert('The estimate could not be saved. Please try again.'); }).finally(() => { finalizePending = null; });
		return finalizePending;
	};
	const persistQuote = async () => {
		const revision = estimateRevision;

		const res = await fetch(DX_PRICING.ajax_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new URLSearchParams({
				action: 'dx_finalize_quote',
				state: JSON.stringify(state),
				estimate_reference: invoiceNumber,
				estimate_token: estimateToken,
			}),
		});

		const data = await res.json();

		if (!data.success) {
			alert(data.data?.message || 'Could not generate estimate. Please try again.');
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
		if (input) input.value = invoiceUrl;
		if (meta) meta.textContent = `Estimate reference: ${invoiceNumber}`;
		if (copyBtn) copyBtn.disabled = false;
		try { const handoff=JSON.parse(sessionStorage.getItem('dxndre-calculator-handoff')||'{}');handoff.reference=invoiceNumber;sessionStorage.setItem('dxndre-calculator-handoff',JSON.stringify(handoff)); } catch (_) {}
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
				applyActiveFlow();
				goToStepIndex(0);
				syncActiveLabels();
			});
		});
		calculator.querySelectorAll('input[name="service"]').forEach((input) => {
			input.addEventListener('change', () => {
				state.service = input.value;

				if (state.service === 'retainer') {
					state.scope = null;
					state.type = null;
					state.features = [];
					state.timeline = null;
					state.extras = [];
				} else {
					state.retainer_level = null;
					state.retainer_areas = [];
					state.retainer_turnaround = null;
					state.retainer_commitment = null;
					state.retainer_addons = [];
				}

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
		if (getCurrentStepKey() === 'fitness') return;
		if (currentStepIndex < activeFlow.length - 1) {
			goToStepIndex(currentStepIndex + 1);
			return;
		}

		finalizeQuote().then(() => { if (invoiceUrl) window.location.href = invoiceUrl; });
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
		const form = document.createElement('form');
		form.method = 'POST';
		form.action = DX_PRICING.ajax_url;
		for (const [name,value] of Object.entries({action:'dx_generate_quote_pdf',state:JSON.stringify(state)})) {
			const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.append(input);
		}
		document.body.append(form);form.submit();form.remove();
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

		const showSuccess = () => {
			icon.classList.remove('fa-copy');
			icon.classList.add('fa-check');
			btn.disabled = true;

			setTimeout(() => {
				icon.classList.remove('fa-check');
				icon.classList.add('fa-copy');
				btn.disabled = false;
			}, 1800);
		};

		const showError = () => {
			icon.classList.remove('fa-copy');
			icon.classList.add('fa-xmark');

			setTimeout(() => {
				icon.classList.remove('fa-xmark');
				icon.classList.add('fa-copy');
			}, 1800);
		};

		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(showSuccess).catch(showError);
			return;
		}

		try {
			input.focus();
			input.select();
			document.execCommand('copy');
			showSuccess();
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
			const res = await fetch(DX_PRICING.ajax_url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				},
				body: new URLSearchParams({
					action: 'dx_email_quote_pdf',
					name,
					email,
					invoice: document.getElementById('invoice-link')?.value || '',
				}),
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

	const comparisonDialog=calculator.querySelector('.package-comparison');
	comparisonDialog.addEventListener('cancel',()=>{comparisonOpen=false;});
	comparisonDialog.addEventListener('click',event=>{if(event.target===comparisonDialog){const r=comparisonDialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom){comparisonOpen=false;updateRetainerUI();}}});
	const detailAnimations=new WeakMap();
	const animateDetail=(detail,opening)=>{
		const old=detailAnimations.get(detail);if(old)old.cancel();
		const start=detail.getBoundingClientRect().height;
		if(opening)detail.open=true;
		const end=opening?detail.scrollHeight:detail.querySelector('summary').getBoundingClientRect().height;
		if(matchMedia('(prefers-reduced-motion: reduce)').matches){detail.open=opening;return;}
		const motion=detail.animate([{height:`${start}px`},{height:`${end}px`}],{duration:300,easing:'cubic-bezier(.22,1,.36,1)'});
		detailAnimations.set(detail,motion);
		motion.onfinish=()=>{detail.open=opening;detailAnimations.delete(detail);};
	};
	calculator.querySelectorAll('.service-option__details').forEach(detail=>{
		detail.querySelector('summary').addEventListener('click',event=>{
			event.preventDefault();const opening=!detail.open;
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
	calculator.querySelector('[data-save-personalisation]')?.addEventListener('click',()=>{state.client_name=editor.querySelector('[data-edit-name]').value.trim();state.client_company=editor.querySelector('[data-edit-company]').value.trim();calculator.querySelector('[name="client_name"]').value=state.client_name;calculator.querySelector('[name="client_company"]').value=state.client_company;editor.hidden=true;resetFinalizedQuote();updateSummary();finalizeQuote();calculator.querySelector('[data-edit-personalisation]').focus();});
	successEl?.classList.add('is-hidden');
	bindInputs();
	applyActiveFlow();
	updateSummary();
	syncFeatureExclusivity();
	goToStepIndex(0);
	initScrollableHint();
	syncActiveLabels();
	updateSummaryCopy();

})();