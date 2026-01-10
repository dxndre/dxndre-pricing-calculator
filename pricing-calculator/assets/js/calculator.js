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
	const breakdownEls = document.querySelectorAll('.summary-breakdown');
	const totalEls = document.querySelectorAll('.price');
	const nextBtn = document.querySelector('.next-step');
	const prevBtn = document.querySelector('.prev-step');

	const progressFill = document.querySelector('.progress-fill');
	const progressLabel = document.querySelector('.progress-label');
	const successEl = calculator?.querySelector('.calculator-success');

	if (!calculator || !steps.length) return;

	let currentStep = 1;
	const PRICING_STEPS = 6;
	const maxStep = steps.length; // still used for navigation

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
		state.total = 0;

		// Calculate total once
		stepConfig.forEach(cfg => {
			const { name, type, prices: priceMap } = cfg;

			if (type === 'radio' && state[name]) {
				state.total += priceMap[state[name]];
			}

			if (type === 'checkbox' && state[name].length) {
				state[name].forEach(value => {
					state.total += priceMap[value];
				});
			}
		});

		// Update every summary breakdown
		breakdownEls.forEach(breakdownEl => {
			breakdownEl.innerHTML = '';

			stepConfig.forEach(cfg => {
				const { name, type, prices: priceMap } = cfg;

				if (type === 'radio' && state[name]) {
					const price = priceMap[state[name]];

					breakdownEl.insertAdjacentHTML(
						'beforeend',
						`<li class="${price < 0 ? 'is-discount' : ''}">
							<span class="summary-label">
								${getLabelText(name, state[name])}
							</span>
							<span class="summary-value">
								${formatPrice(price)}
							</span>
						</li>`
					);
				}

				if (type === 'checkbox' && state[name].length) {
					state[name].forEach(value => {
						const price = priceMap[value];

						breakdownEl.insertAdjacentHTML(
							'beforeend',
							`<li>
								<span>${getLabelText(name, value)}</span>
								<span>${formatPrice(price)}</span>
							</li>`
						);
					});
				}
			});
		});

		// Update every price display
		totalEls.forEach(el => fadeUpdatePrice(el, state.total));
	};

	/* ==========================
	   STEP VALIDATION
	========================== */

	const validateStep = (step) => {
		// Summary step is always valid
		if (step > PRICING_STEPS) {
			setButtonDisabled(nextBtn, false);
			return;
		}

		let valid = false;

		switch (step) {
			case 1: valid = !!state.service; break;
			case 2: valid = !!state.scope; break;
			case 3: valid = !!state.type; break;
			case 4: valid = state.features.length > 0; break;
			case 5: valid = !!state.timeline; break;
			case 6: valid = true; break;
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

		if (step === PRICING_STEPS) {
			nextBtn.textContent = 'Review summary';
		} else if (step === maxStep) {
			nextBtn.textContent = 'Finish';
		} else {
			nextBtn.textContent = 'Next';
		}

		updateProgress(step);
		validateStep(step);
		animateHeight();

		// When reaching summary step, generate invoice once
		if (step === maxStep) {
			finalizeQuote();
		}
	};

	// Finalize quote when arriving on the last step

	let finalized = false;
	let invoiceUrl = '';
	let invoiceNumber = '';

	const finalizeQuote = async () => {
		if (finalized) return;

		const res = await fetch(DX_PRICING.ajax_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new URLSearchParams({
				action: 'dx_finalize_quote',
				state: JSON.stringify(state)
			})
		});

		const data = await res.json();

		if (!data.success) {
			alert(data.data?.message || 'Could not generate invoice. Please try again.');
			return;
		}

		finalized = true;
		invoiceNumber = data.data.invoice;
		invoiceUrl = data.data.url;

		const viewBtn = document.getElementById('view-invoice');
		if (viewBtn && invoiceUrl) {
			viewBtn.href = invoiceUrl;
			viewBtn.classList.remove('is-disabled');
		}

		// Populate the UI
		const summary = document.getElementById('invoice-summary');
		const input = document.getElementById('invoice-link');
		const copyBtn = document.querySelector('.copy-invoice');
		const meta = document.getElementById('invoice-meta');

		if (summary) summary.classList.remove('is-hidden');
		if (input) input.value = invoiceUrl;
		if (meta) meta.textContent = `Invoice reference: ${invoiceNumber}`;

		if (copyBtn) {
			copyBtn.disabled = false;
			copyBtn.textContent = copyBtn.dataset.defaultText || 'Copy link';
		}
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

		// Final submit (summary step only)
		fetch(DX_PRICING.ajax_url, {
			method: 'POST',
			body: new URLSearchParams({
				action: 'dx_finalize_quote',
				state: JSON.stringify(state)
			})
		})
		.then(res => res.json())
		.then(res => {
			if (res.success) {
				window.location.href = res.data.url;
			}
		});
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

	// Download generated PDF

	document.querySelector('.download-pdf')?.addEventListener('click', () => {
		const form = document.createElement('form');
		form.method = 'POST';
		form.action = DX_PRICING.ajax_url;

		form.innerHTML = `
			<input type="hidden" name="action" value="dx_generate_quote_pdf">
			<input type="hidden" name="state" value='${JSON.stringify(state)}'>
		`;

		document.body.appendChild(form);
		form.submit();
		form.remove();
	});

	// Google Analytics event on final stepp

	document.querySelector('.download-pdf')?.addEventListener('click', () => {
		gtag('event', 'download_proposal', {
			event_category: 'Proposal',
			event_label: state.total
		});
	});

	document.querySelector('.email-quote')?.addEventListener('click', () => {
		gtag('event', 'request_email_quote', {
			event_category: 'Proposal',
			event_label: state.total
		});
	});

	// Copy Invoice Reference Link

	document.querySelector('.copy-invoice')?.addEventListener('click', async (e) => {
		const btn = e.currentTarget;
		const input = document.getElementById('invoice-link');
		if (!input || !input.value) return;

		const originalText = btn.dataset.defaultText || btn.textContent;

		try {
			// Try modern clipboard first
			if (navigator.clipboard && window.isSecureContext) {
			await navigator.clipboard.writeText(input.value);
			} else {
			// Fallback for http/local
			input.select();
			input.setSelectionRange(0, input.value.length);
			document.execCommand('copy');
			window.getSelection()?.removeAllRanges();
			}

			btn.textContent = 'Link copied';
			btn.disabled = true;

			setTimeout(() => {
			btn.textContent = originalText;
			btn.disabled = false;
			}, 2000);
		} catch (err) {
			console.error(err);
			btn.textContent = 'Failed to copy';
			setTimeout(() => btn.textContent = originalText, 2000);
		}
	});

	// Send quote via Email (Open, Close and Send)

	const emailModal = document.getElementById('email-quote-modal');

	document.querySelector('.email-quote')?.addEventListener('click', () => {
		if (!finalized) {
			alert('Just generating your invoice, one moment…');
			finalizeQuote();
			return;
		}
		emailModal?.classList.remove('is-hidden');
	});

	document.querySelector('.close-email-modal')?.addEventListener('click', () => {
		emailModal?.classList.add('is-hidden');
	});

	document.querySelector('.send-email-confirm')?.addEventListener('click', async () => {
		const name = emailModal.querySelector('input[name="name"]').value.trim();
		const email = emailModal.querySelector('input[name="email"]').value.trim();

		if (!name || !email) {
			alert('Please enter your name and email');
			return;
		}

		const res = await fetch(DX_PRICING.ajax_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: new URLSearchParams({
				action: 'dx_email_quote_pdf',
				name,
				email,
				invoice: document.getElementById('invoice-link').value
			})
		});

		const data = await res.json();

		if (data.success) {
			alert('Proposal sent! Check your inbox.');
			emailModal.classList.add('is-hidden');
		} else {
			alert('Something went wrong. Please try again.');
		}
	});

	/* ==========================
	   INIT
	========================== */

	successEl?.classList.add('is-hidden');
	bindInputs();
	updateSummary();
	goToStep(1);
	initScrollableHint();

})();