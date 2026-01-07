(() => {

	/* ==========================
	   STATE + PRICING
	========================== */

	const state = {
		service: null,
		scope: null,
		type: null,
		features: [],
		timeline: null,
		extras: [],
		contact: {},
		total: 0
	};

	const prices = DX_PRICING.prices;

	/* ==========================
	   DOM REFERENCES
	========================== */

	const calculator = document.querySelector('.pricing-calculator');
	const steps = document.querySelectorAll('.calculator-step');
	const stepsContainer = document.querySelector('.steps-container');
	const breakdownEl = document.querySelector('.summary-breakdown');
	const totalEl = document.querySelector('.price');
	const nextBtn = document.querySelector('.next-step');
	const prevBtn = document.querySelector('.prev-step');

	const progressFill = document.querySelector('.progress-fill');
	const progressLabel = document.querySelector('.progress-label');
	const successEl = calculator?.querySelector('.calculator-success');

	if (!calculator || !steps.length) return;

	let currentStep = 1;
	const maxStep = steps.length;

	/* ==========================
	   UTILITIES
	========================== */

	const formatPrice = (value) => {
		const formatted = Math.abs(value).toLocaleString('en-GB', {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2
		});

		return value < 0 ? `–£${formatted}` : `£${formatted}`;
	};

	const setButtonDisabled = (btn, disabled) => {
		if (!btn) return;
		btn.disabled = disabled;
		btn.classList.toggle('is-disabled', disabled);
	};

	const getLabelText = (group, value) => {
		const input = document.querySelector(
			`input[name="${group}"][value="${value}"]`
		);
		return input ? input.closest('label')?.textContent.trim() : value;
	};

	/* ==========================
	   FADE PRICE UPDATE (NO COUNTING)
	========================== */

	const fadeUpdatePrice = (el, value) => {
		if (!el) return;

		// Force reflow to ensure transition always runs
		el.classList.add('is-updating');

		setTimeout(() => {
			el.textContent = formatPrice(value);

			// Next frame = fade back in
			requestAnimationFrame(() => {
				el.classList.remove('is-updating');
			});
		}, 150);
	};

	/* ==========================
	   STEP CONFIG
	========================== */

	const stepConfig = [
		{ step: 1, name: 'service', type: 'radio', prices: prices.services },
		{ step: 2, name: 'scope', type: 'radio', prices: prices.scope },
		{ step: 3, name: 'type', type: 'radio', prices: prices.type },
		{ step: 4, name: 'features', type: 'checkbox', prices: prices.features },
		{ step: 5, name: 'timeline', type: 'radio', prices: prices.timeline },
		{ step: 6, name: 'extras', type: 'checkbox', prices: prices.extras }
	];

	/* ==========================
	   PROGRESS + HEIGHT
	========================== */

	const updateProgress = (step) => {
		if (!progressFill || !progressLabel || maxStep <= 1) return;

		const progress = Math.round(((step - 1) / (maxStep - 1)) * 100);
		progressFill.style.width = `${progress}%`;
		progressLabel.textContent = `Step ${step} of ${maxStep}`;
	};

	const animateHeight = () => {
		if (!stepsContainer) return;
		const active = document.querySelector('.calculator-step.is-active');
		if (active) {
			stepsContainer.style.height = `${active.offsetHeight}px`;
		}
	};

	/* ==========================
	   SUMMARY UPDATE
	========================== */

	const updateSummary = () => {
		if (!breakdownEl || !totalEl) return;

		breakdownEl.innerHTML = '';
		state.total = 0;

		stepConfig.forEach(cfg => {
			const { name, type, prices: priceMap } = cfg;

			if (type === 'radio' && state[name]) {
				const value = state[name];
				const price = priceMap[value];
				state.total += price;

				breakdownEl.insertAdjacentHTML(
					'beforeend',
					`<li class="${price < 0 ? 'is-discount' : ''}">
						<span class="summary-label">${getLabelText(name, value)}</span>
						<span class="summary-value">${formatPrice(price)}</span>
					</li>`
				);
			}

			if (type === 'checkbox' && state[name].length) {
				state[name].forEach(value => {
					const price = priceMap[value];
					state.total += price;

					breakdownEl.insertAdjacentHTML(
						'beforeend',
						`<li class="${price < 0 ? 'is-discount' : ''}">
							<span class="summary-label">${getLabelText(name, value)}</span>
							<span class="summary-value">${formatPrice(price)}</span>
						</li>`
					);
				});
			}
		});

		// Fade-only update (no iteration)
		fadeUpdatePrice(totalEl, state.total);

		/* ==========================
		   SUMMARY DENSITY CLASSES
		========================== */

		[...breakdownEl.classList].forEach(cls => {
			if (cls.startsWith('items-')) breakdownEl.classList.remove(cls);
		});

		const itemCount = breakdownEl.children.length;
		if (itemCount >= 6) {
			breakdownEl.classList.add(`items-${itemCount}`);
		}
	};

	/* ==========================
	   STEP VALIDATION
	========================== */

	const validateStep = (step) => {
		let valid = false;

		switch (step) {
			case 1: valid = !!state.service; break;
			case 2: valid = !!state.scope; break;
			case 3: valid = !!state.type; break;
			case 4: valid = state.features.length > 0; break;
			case 5: valid = !!state.timeline; break;
			default: valid = true;
		}

		setButtonDisabled(nextBtn, !valid);
	};

	/* ==========================
	   STEP NAVIGATION
	========================== */

	const goToStep = (step) => {
		steps.forEach(s => s.classList.remove('is-active'));
		const target = document.querySelector(`.calculator-step[data-step="${step}"]`);
		if (!target) return;

		target.classList.add('is-active');
		currentStep = step;

		setButtonDisabled(prevBtn, step === 1);
		prevBtn?.classList.toggle('is-hidden', step === 1);

		nextBtn.textContent = step === maxStep ? 'Finish' : 'Next';

		updateProgress(step);
		validateStep(step);
		animateHeight();
	};

	/* ==========================
	   INPUT BINDING
	========================== */

	const bindInputs = () => {
		stepConfig.forEach(cfg => {
			document.querySelectorAll(`input[name="${cfg.name}"]`).forEach(input => {
				input.addEventListener('change', () => {

					if (cfg.type === 'radio') {
						state[cfg.name] = input.value;
					}

					if (cfg.type === 'checkbox') {
						input.checked
							? state[cfg.name].push(input.value)
							: state[cfg.name] = state[cfg.name].filter(v => v !== input.value);
					}

					updateSummary();
					validateStep(cfg.step);
					animateHeight();
					syncActiveLabels();
				});
			});
		});
	};

	/* ==========================
	   NAV BUTTONS
	========================== */

	nextBtn?.addEventListener('click', () => {
		if (currentStep < maxStep) {
			goToStep(currentStep + 1);
			return;
		}

		fetch(DX_PRICING.ajax_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: new URLSearchParams({
				action: 'dx_send_quote',
				state: JSON.stringify(state)
			})
		})
		.then(res => res.json())
		.then(data => {
			if (!data.success) throw new Error();

			calculator.querySelector('.calculator-step-container')?.classList.add('is-hidden');
			calculator.querySelector('.calculator-nav')?.classList.add('is-hidden');
			successEl?.classList.remove('is-hidden');
		})
		.catch(() => alert('Something went wrong. Please try again.'));
	});

	prevBtn?.addEventListener('click', () => {
		if (currentStep > 1) goToStep(currentStep - 1);
	});

	/* ==========================
	   ACTIVE LABEL SYNC
	========================== */

	const syncActiveLabels = () => {
		document.querySelectorAll('.options-group').forEach(group => {
			group.querySelectorAll('input').forEach(input => {
				const label = input.closest('label');
				if (!label) return;

				if (input.type === 'radio' && input.checked) {
					group.querySelectorAll('label').forEach(l => l.classList.remove('is-active'));
					label.classList.add('is-active');
				}

				if (input.type === 'checkbox') {
					label.classList.toggle('is-active', input.checked);
				}
			});
		});
	};

	// Scroll logic for long lists

	const initScrollableHint = () => {
		document.querySelectorAll('.options-group.long').forEach(group => {

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
				group.classList.toggle(
					'is-middle',
					isScrollable && !atTop && !atBottom
				);
			};

			// Initial state
			updateState();

			// Update on scroll
			group.addEventListener('scroll', updateState);
		});
	};

	/* ==========================
	   INIT
	========================== */

	successEl?.classList.add('is-hidden');
	bindInputs();
	updateSummary();
	goToStep(1);
	initScrollableHint();

})();