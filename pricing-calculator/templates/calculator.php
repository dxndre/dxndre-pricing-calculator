<div class="calculator-hero-media" aria-hidden="true"><img class="calculator-beach" src="<?php echo esc_url(home_url('/wp-content/uploads/2026/10/calculator-bus-electroliners-v2.png')); ?>" alt="" decoding="async" fetchpriority="high"></div>
<div class="calculator-glow calculator-glow--off-black" aria-hidden="true"></div>
<div class="pricing-calculator" data-step="1">


	<div class="calculator-step-container">
		<div class="calculator-question-panel">
    <div class="calculator-progress">
		<div class="progress-bar">
			<span class="progress-fill"></span>
		</div>
		<pre class="progress-label headline"></pre>
	</div>

		<div class="steps-container">

<div class="calculator-step is-active" data-step="category">
	<h2 class="step-title">Where would you like to start?</h2>
	<p class="step-intro">Choose the kind of support you’re looking for.</p>
	<div class="options-group options-group--categories">
		<label class="category-card category-card--digital">
			<img class="category-card__image" src="<?php echo esc_url(get_the_post_thumbnail_url(229, 'large')); ?>" alt="" loading="eager">
			<input type="radio" name="service_category" value="digital" aria-label="Digital services"><span class="custom-radio"></span>
			<span class="category-card__content"><span class="category-card__eyebrow">DESIGN &amp; TECHNOLOGY</span><strong>Digital services</strong><span class="category-card__description">Considered design. Powerful websites.<br>Ongoing technical support.</span><span class="category-card__action">Plan your project <span aria-hidden="true">↗</span></span></span>
		</label>
		<label class="category-card category-card--fitness">
			<img class="category-card__image" src="<?php echo esc_url(home_url('/wp-content/uploads/2026/09/fitness.png')); ?>" alt="" loading="eager">
			<input type="radio" name="service_category" value="fitness" aria-label="Fitness coaching"><span class="custom-radio"></span>
			<span class="category-card__content"><span class="category-card__eyebrow">N118 FITNESS <span class="category-card__badge">Coming soon</span></span><strong>Fitness coaching</strong><span class="category-card__description">Personal coaching built around<br>your goals, your pace and your potential.</span><span class="category-card__action">Discover what’s next <span aria-hidden="true">↗</span></span></span>
		</label>
	</div>
<div class="calculator-intro-fields"><button type="button" class="personalisation-toggle" aria-expanded="false" aria-controls="personalisation-fields">Personalise your estimate (optional)</button><div id="personalisation-fields" hidden><h3 class="calculator-intro-fields__title">Make this estimate yours.</h3><p>Add your name and company to personalise your estimate. It's completely optional, and you can always add these details later.</p><div class="calculator-intro-fields__grid"><div><label for="calculator-client-name">Your name (optional)</label><input id="calculator-client-name" name="client_name" type="text" placeholder="e.g. D'André"></div><div><label for="calculator-client-company">Company / organisation (optional)</label><input id="calculator-client-company" name="client_company" type="text" placeholder="e.g. DXNDRE Studio"></div></div></div></div>
</div>
<div class="calculator-step" data-step="fitness">
	<div class="fitness-preview">
		<img class="fitness-preview__image" src="<?php echo esc_url(home_url('/wp-content/uploads/2026/09/fitness.png')); ?>" alt="" loading="eager">
		<div class="fitness-preview__content">
			<p class="fitness-status">N118 FITNESS <span>Coming soon</span></p>
			<h2 class="step-title">Your next stop.<br><span>A stronger you.</span></h2>
			<p class="step-intro">In-person and online coaching built around your goals, your starting point and your pace.</p>
			<a class="btn fitness-waitlist" href="<?php echo esc_url(home_url('/n118-fitness/')); ?>">Join the waitlist <span aria-hidden="true">↗</span></a>
			<p class="fitness-preview__note">Be among the first to hear when coaching launches.</p>
			<div class="fitness-preview__meta"><span>IN PERSON &amp; ONLINE</span><span>PERSONAL COACHING</span></div>
		</div>
	</div>
</div>
<div class="calculator-step" data-step="1">
	<h2 class="step-title">What would you like to create?</h2>

	<p class="step-intro">Choose the service that best fits your project. Explore the details whenever you need them.</p>
	<div class="options-group options-group--services">
		<?php foreach (dx_calculator_services() as $index => $service) : ?>
		<div class="service-option">
			<label>
				<input type="radio" name="service" value="<?php echo esc_attr($service['key']); ?>" data-label="<?php echo esc_attr($service['title']); ?>" aria-label="<?php echo esc_attr($service['title']); ?>">
				<span class="service-option__number" aria-hidden="true"><?php echo esc_html(sprintf('%02d', $index + 1)); ?></span>
				<?php if ($service['image']) : ?><img class="service-option__image" src="<?php echo esc_url($service['image']); ?>" alt="" loading="lazy"><?php endif; ?>
				<span class="service-option__copy"><strong><?php echo esc_html($service['title']); ?></strong><span><?php echo esc_html($service['excerpt']); ?></span></span>
				<span class="service-option__price"><?php echo $service['fixed'] ? '' : 'From '; ?>£<?php echo esc_html(number_format($service['price'], 0)); ?><?php if ($service['monthly']) : ?><small>/ month</small><?php endif; ?></span>
				<span class="custom-radio"></span>
			</label>
			<details class="service-option__details"><summary aria-label="Explore details for <?php echo esc_attr($service['title']); ?>">Explore details</summary><div class="service-option__content"><ul class="service-detail-list"><?php foreach (preg_split('/\r\n|\r|\n/', $service['features'] ?: $service['excerpt']) as $detail) : if (!trim($detail)) continue; ?><li><?php echo esc_html(trim($detail)); ?></li><?php endforeach; ?></ul></div></details>
		</div>
		<?php endforeach; ?>
	</div>


</div>

			<div class="calculator-step" data-step="2">
				<h2 class="step-title">What best describes the scope?</h2>

				<div class="options-group">
					<label>
						<input type="radio" name="scope" value="small">
						<span class="custom-radio"></span>
						Small (1–3 Pages / Simple Functionality)
					</label>

					<label>
						<input type="radio" name="scope" value="medium">
						<span class="custom-radio"></span>
						Medium (4–8 Pages / Moderate Functionality)
					</label>

					<label>
						<input type="radio" name="scope" value="large">
						<span class="custom-radio"></span>
						Large (9+ Pages / Complex Functionality)
					</label>
				</div>
			</div>

			<div class="calculator-step" data-step="3">
				<h2 class="step-title">What’s your project type?</h2>

				<div class="options-group long">
					<label>
						<input type="radio" name="type" value="business">
						<span class="custom-radio"></span>
						Business
					</label>

					<label>
						<input type="radio" name="type" value="blog">
						<span class="custom-radio"></span>
						Blog
					</label>

					<label>
						<input type="radio" name="type" value="education">
						<span class="custom-radio"></span>
						Education
					</label>

					<label>
						<input type="radio" name="type" value="forum">
						<span class="custom-radio"></span>
						Forum
					</label>

					<label>
						<input type="radio" name="type" value="personal">
						<span class="custom-radio"></span>
						Personal
					</label>

					<label>
						<input type="radio" name="type" value="entertainment">
						<span class="custom-radio"></span>
						Entertainment
					</label>

					<label>
						<input type="radio" name="type" value="nonprofit">
						<span class="custom-radio"></span>
						Non-Profit Charity
					</label>

					<label>
						<input type="radio" name="type" value="event">
						<span class="custom-radio"></span>
						Event
					</label>

					<label>
						<input type="radio" name="type" value="portfolio">
						<span class="custom-radio"></span>
						Portfolio
					</label>
				</div>
			</div>

            <div class="calculator-step" data-step="4">
				<h2 class="step-title">What added functionality do you require?</h2>

				<div class="options-group">
					<label>
						<input type="checkbox" name="features" value="user_account">
						<span class="custom-checkbox"></span>
						User Accounts
					</label>

					<label>
						<input type="checkbox" name="features" value="ecommerce">
						<span class="custom-checkbox"></span>
						eCommerce
					</label>

					<label>
						<input type="checkbox" name="features" value="integration">
						<span class="custom-checkbox"></span>
						External Integrations
					</label>

                    <label>
						<input type="checkbox" name="features" value="none">
						<span class="custom-checkbox"></span>
						No added functionality
					</label>
				</div>
			</div>

			<div class="calculator-step" data-step="5">
				<h2 class="step-title">What’s your ideal timeline?</h2>

				<div class="options-group">
					<label>
						<input type="radio" name="timeline" value="no_rush">
						<span class="custom-radio"></span>
						No Rush (12+ Weeks Turnaround)
					</label>

					<label>
						<input type="radio" name="timeline" value="flexible">
						<span class="custom-radio"></span>
						Standard (6-12 Weeks Turnaround)
					</label>

					<label>
						<input type="radio" name="timeline" value="priority">
						<span class="custom-radio"></span>
						Priority (1-4 Weeks Turnaround)
					</label>

					<label>
						<input type="radio" name="timeline" value="urgent">
						<span class="custom-radio"></span>
						Urgent (&lt;7 Day Turnaround)
					</label>
				</div>
			</div>

			<div class="calculator-step" data-step="6">
				<h2 class="step-title">Do you need ongoing support?</h2>

				<div class="options-group">
					<label>
						<input type="checkbox" name="extras" value="maintenance">
						<span class="custom-checkbox"></span>
						Ongoing Maintenance & Updates
					</label>

					<label>
						<input type="checkbox" name="extras" value="seo">
						<span class="custom-checkbox"></span>
						Basic SEO setup & Optimisation
					</label>

					<label>
						<input type="checkbox" name="extras" value="hosting">
						<span class="custom-checkbox"></span>
						Hosting & Monitoring
					</label>
				</div>
			</div>

<?php $retainer = dx_retainer_config(); ?>
<div class="calculator-step" data-step="retainer_level"><h2 class="step-title">What level of monthly support do you need?</h2><div class="options-group">
<?php foreach ($retainer['tiers'] as $key => $option) : ?><label><input type="radio" name="retainer_level" value="<?php echo esc_attr($key); ?>" data-label="<?php echo esc_attr($option['name']); ?>"><span class="custom-radio"></span><span class="retainer-option-copy"><strong><?php echo esc_html($option['name']); ?></strong><span class="retainer-option-price" data-retainer-price="retainer_level" data-value="<?php echo esc_attr($key); ?>"></span><?php if (isset($option['hours'])) : ?><small><?php echo esc_html($option['hours']); ?> general support hours/month</small><?php endif; ?><?php if (isset($option['description'])) : ?><small><?php echo esc_html($option['description']); ?></small><?php endif; ?></span></label><?php endforeach; ?>
</div><p class="retainer-note">Essentials suits routine maintenance and minor improvements. Growth supports more regular development and optimisation. Partner offers an ongoing technical and creative partnership.</p></div>
<div class="calculator-step" data-step="retainer_areas"><h2 class="step-title">What would you like ongoing support with?</h2><div class="options-group">
<?php foreach ($retainer['areas'] as $key => $option) : ?><label><input type="checkbox" name="retainer_areas" value="<?php echo esc_attr($key); ?>" data-label="<?php echo esc_attr($option['name']); ?>"><span class="custom-checkbox"></span><span class="retainer-option-copy"><strong><?php echo esc_html($option['name']); ?></strong><span class="retainer-option-price" data-retainer-price="retainer_areas" data-value="<?php echo esc_attr($key); ?>"></span><?php if (isset($option['hours'])) : ?><small><?php echo esc_html($option['hours']); ?> dedicated landing-page hours/month</small><?php endif; ?><?php if (isset($option['description'])) : ?><small><?php echo esc_html($option['description']); ?></small><?php endif; ?></span></label><?php endforeach; ?>
</div><p class="retainer-note">Selected categories share your general monthly hours. Category fees cover availability, planning and management; they add no hours. Landing-page work does not guarantee a complete page each month.</p></div>
<div class="calculator-step" data-step="retainer_turnaround"><h2 class="step-title">What turnaround speed do you need?</h2><div class="options-group">
<?php foreach ($retainer['turnaround'] as $key => $option) : ?><label><input type="radio" name="retainer_turnaround" value="<?php echo esc_attr($key); ?>" data-label="<?php echo esc_attr($option['name']); ?>"><span class="custom-radio"></span><span class="retainer-option-copy"><strong><?php echo esc_html($option['name']); ?></strong><span class="retainer-option-price" data-retainer-price="retainer_turnaround" data-value="<?php echo esc_attr($key); ?>"></span><?php if (isset($option['hours'])) : ?><small><?php echo esc_html($option['hours']); ?> dedicated landing-page hours/month</small><?php endif; ?><?php if (isset($option['description'])) : ?><small><?php echo esc_html($option['description']); ?></small><?php endif; ?></span></label><?php endforeach; ?>
</div><p class="retainer-note">These are scheduling targets during published business hours, excluding weekends and public holidays, not guaranteed completion times. Delivery depends on complexity, available hours and agreed scope.</p></div>
<div class="calculator-step" data-step="retainer_commitment"><h2 class="step-title">What commitment are you comfortable with?</h2><div class="options-group">
<?php foreach ($retainer['commitments'] as $key => $option) : ?><label><input type="radio" name="retainer_commitment" value="<?php echo esc_attr($key); ?>" data-label="<?php echo esc_attr($option['name']); ?>"><span class="custom-radio"></span><span class="retainer-option-copy"><strong><?php echo esc_html($option['name']); ?></strong><span class="retainer-option-price" data-retainer-price="retainer_commitment" data-value="<?php echo esc_attr($key); ?>"></span><?php if (isset($option['hours'])) : ?><small><?php echo esc_html($option['hours']); ?> dedicated landing-page hours/month</small><?php endif; ?><?php if (isset($option['description'])) : ?><small><?php echo esc_html($option['description']); ?></small><?php endif; ?></span></label><?php endforeach; ?>
</div><p class="retainer-note">Longer commitments reduce monthly pricing. Fixed terms and cancellation conditions are subject to agreement.</p></div>
<div class="calculator-step" data-step="retainer_addons"><h2 class="step-title">Would you like any additional monthly add-ons?</h2><div class="options-group">
<?php foreach ($retainer['addons'] as $key => $option) : ?><label><input type="checkbox" name="retainer_addons" value="<?php echo esc_attr($key); ?>" data-label="<?php echo esc_attr($option['name']); ?>"><span class="custom-checkbox"></span><span class="retainer-option-copy"><strong><?php echo esc_html($option['name']); ?></strong><span class="retainer-option-price" data-retainer-price="retainer_addons" data-value="<?php echo esc_attr($key); ?>"></span><?php if (isset($option['hours'])) : ?><small><?php echo esc_html($option['hours']); ?> dedicated landing-page hours/month</small><?php endif; ?><?php if (isset($option['description'])) : ?><small><?php echo esc_html($option['description']); ?></small><?php endif; ?></span></label><?php endforeach; ?>
</div></div>
			<div class="calculator-step" data-step="7">
				<h2 class="step-title" data-summary-title>Your project outline is ready.</h2>

				<p class="step-intro" data-summary-intro>
					This estimate reflects the scope, priorities, and level of craftsmanship you’ve selected.
					It’s a considered starting point - with no obligation and no pressure to proceed.
				</p>

<div class="final-mobile-estimate"><p>Estimated investment</p><span class="price">£0.00</span></div><button type="button" class="summary-edit" data-edit-personalisation>Add personalisation</button>
<div class="personalisation-editor" hidden><h3>Make this estimate yours</h3><p>Add your details for a personalised proposal.</p><div class="personalisation-editor__fields"><label>Your name (optional)<input type="text" data-edit-name></label><label>Company / organisation (optional)<input type="text" data-edit-company></label></div><div class="personalisation-editor__actions"><button type="button" class="btn" data-save-personalisation>Save details</button><button type="button" class="summary-edit" data-close-personalisation>Cancel</button></div></div>

				<div class="invoice-summary is-hidden" id="invoice-summary">
					<label for="invoice-link">Your estimate reference</label>
					<p>Keep this reference handy if you'd like to discuss your estimate with me.</p>

					<div class="copy-field">
						<input
							id="invoice-link"
							type="text"
							value=""
							readonly
						>

						<button
							type="button"
							class="btn btn-outline copy-invoice"
							aria-label="Copy link"
							aria-live="polite"
						>
							<i class="fa-solid fa-copy"></i>
						</button>

						<a
							href="#"
							target="_blank"
							rel="noopener"
							class="btn btn-outline view-invoice is-disabled"
							id="view-invoice"
						>
							View
							<i class="fa-solid fa-file-invoice"></i>
						</a>
					</div>
				</div>

				<!-- Email Modal -->
				<div class="dx-modal email-quote-modal is-hidden" id="email-quote-modal">
					<div class="dx-modal-backdrop"></div>

					<div class="dx-modal-panel" role="dialog" aria-modal="true">
						<button type="button" class="dx-modal-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>

						<div class="content-side">
							<div class="send-content">
								<h3>Email your proposal</h3>
								<p>Enter your details and I’ll send your PDF to your inbox.</p>

								<span class="form-error is-hidden" role="alert">
									<i class="fa-solid fa-circle-exclamation"></i> Please double-check the form and try again.
								</span>

								<input type="text" name="name" placeholder="Your Name" required>
								<input type="email" name="email" placeholder="Your Email" required>

								<button type="button" class="btn send-email-confirm">
									Send Proposal <i class="fa-solid fa-paper-plane"></i>
								</button>
							</div>
							<div class="sent-content">
								<h3>Proposal Sent</h3>
								<p>Brilliant - your proposal has been sent! Please check your inbox.</p>

								<button type="button" class="btn dx-finish">
									Finish <i class="fa-solid fa-check"></i>
								</button>
							</div>
						</div>
						<div class="graphic-side">
							<img src="<?php echo esc_url( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/img/206-crop.png' ); ?>" alt="206 Bus">
						</div>
						
					</div>
				</div>

				<h4>What would you like to do next?</h4>

				<div class="summary-actions"><a class="btn calculator-enquire" href="<?php echo esc_url(home_url('/start-a-project/?from_calculator=1')); ?>">Start this project ↗</a>			
					<button class="btn download-pdf">
						Download estimate (PDF)
						<i class="fa-solid fa-file-pdf"></i>
					</button>

					<button class="btn email-quote">
						Email me a copy
						<i class="fa-solid fa-envelope"></i>
					</button>

					<a
						href="https://calendly.com/dxndre/30min?source=pricing-calculator"
						class="btn btn-outline js-calendly-popup"
						target="_blank"
						rel="noopener noreferrer"
					>
						Discuss this project
						<span class="meta">
							30-minute call · No obligation
						</span>
					</a>

					<p class="summary-note">
						💡 You’ll speak directly with me — no sales process, no intermediaries.
						Feel free to download or reference this estimate during our conversation.
					</p>
				</div>
			</div>
		</div>

	<div class="calculator-nav">
		<div class="nav-estimate" hidden><span>Estimated investment</span><strong class="price">£0</strong></div>
		<button class="prev-step" disabled>Back</button>
		<button class="next-step" disabled>Next</button>
	</div>
		</div>
		<div class="calculator-summary">
			<div class="summary-welcome"><p class="summary-kicker">YOUR NEXT CHAPTER</p><h2>Your project<br>starts here.</h2><p>Choose your direction and shape a plan around what matters to you.</p><span>DESIGN · TECHNOLOGY · PERSONAL GROWTH</span></div>
			<div class="summary-fitness"><img src="<?php echo esc_url(home_url('/wp-content/uploads/2026/09/fitness.png')); ?>" alt=""><span>N118 FITNESS · LONDON BUILT</span></div>
			<div class="summary-content"><div class="retainer-capacity" hidden></div><div class="package-recommendation" hidden aria-live="polite"></div><dialog class="package-comparison" aria-label="Compare monthly support packages" hidden></dialog>
				<ul class="summary-breakdown"></ul>
				<div class="summary-price">
					<p>Your projected investment</p>
					<span class="price">£0</span>
				</div>
			</div>
		</div>
	</div>


    <div class="calculator-success is-hidden">
        <h2>Thank you 🎉</h2>
        <p>Your quote has been sent successfully. I’ll be in touch shortly.</p>
    </div>

</div>