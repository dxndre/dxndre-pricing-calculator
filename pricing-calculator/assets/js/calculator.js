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
	const emailModal = document.getElementById('email-quote-modal');
	const progressFill = document.querySelector('.progress-fill');
	const progressLabel = document.querySelector('.progress-label');
	const successEl = calculator?.querySelector('.calculator-success');
	

	if (!calculator || !steps.length) return;

	let currentStep = 1;
	const PRICING_STEPS = 6;
	const maxStep = steps.length; // still used for navigation

	/* ==========================
	TELEPORT MODAL TO <body>
	========================== */

	document.addEventListener('DOMContentLoaded', () => {
		const modal = document.getElementById('email-quote-modal');
		if (!modal) return;

		// Prevent moving it more than once
		if (!modal.dataset.teleported) {
			document.body.appendChild(modal);
			modal.dataset.teleported = 'true';
		}
	});

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

	document.querySelector('.copy-invoice')?.addEventListener('click', (e) => {
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

		// Modern clipboard API (HTTPS only)
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text)
				.then(showSuccess)
				.catch(showError);
			return;
		}

		// Fallback for HTTP / local / Safari
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

	// Send quote via Email (Open, Close and Send)

	document.querySelector('.email-quote')?.addEventListener('click', async () => {
		if (!finalized) {
			await finalizeQuote();
		}

		emailModal?.classList.remove('is-hidden');
	});

	document.addEventListener('click', (e) => {
		const modal = document.getElementById('email-quote-modal');
		if (!modal) return;

		if (
			!modal.classList.contains('is-hidden') &&
			!modal.contains(e.target) &&
			!e.target.closest('.email-quote')
		) {
			modal.classList.add('is-hidden');
		}
	});

	document.querySelector('.send-email-confirm')?.addEventListener('click', async () => {
		const modal = document.getElementById('email-quote-modal');
		if (!modal) return;

		const sendContent = modal.querySelector('.send-content');
		const sentContent = modal.querySelector('.sent-content');
		const errorEl = modal.querySelector('.form-error');

		const nameInput = modal.querySelector('input[name="name"]');
		const emailInput = modal.querySelector('input[name="email"]');

		const name = nameInput.value.trim();
		const email = emailInput.value.trim();

		// Reset state
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
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: new URLSearchParams({
					action: 'dx_email_quote_pdf',
					name,
					email,
					invoice: document.getElementById('invoice-link')?.value || ''
				})
			});

			const data = await res.json();

			if (!data.success) {
				throw new Error('Email send failed');
			}

			// ✅ Success state
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

		const openButtons = document.querySelectorAll('.email-quote');
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

		openButtons.forEach(btn => {
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

	successEl?.classList.add('is-hidden');
	bindInputs();
	updateSummary();
	goToStep(1);
	initScrollableHint();

})();