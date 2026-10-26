<?php $portal_quote=function_exists('dxcp_quote_data') ? dxcp_quote_data($invoice) : null; $is_final=!empty($portal_quote) && $portal_quote['kind']==='final'; add_filter('body_class',function($classes){$classes[]='proposal';return $classes;});
wp_enqueue_style('dx-proposal',DX_PC_URL.'assets/css/proposal.css',[],filemtime(DX_PC_PATH.'assets/css/proposal.css'));
get_header(); ?>
<div class="proposal-tools"><?php if($portal_quote): ?><a href="<?= esc_url(dxcp_portal_url('payments',$portal_quote['project_id'])); ?>">Your client space ↗</a><?php endif; ?><button type="button" data-proposal-theme aria-pressed="false">Light mode</button></div>

	<div class="container proposal-inner">

		<header class="logo-header">
			<a href="<?= esc_url(home_url('/')); ?>" target="_blank" rel="noopener">
				<img src="<?= esc_url(DX_PC_URL . 'assets/img/dxndre-logo-black.png'); ?>" alt="DXNDRE" width="220" style="max-width:100%;height:auto;">
			</a>
		</header>

		<section class="header-grid">
			<div>
				<pre class="headline">Pricing Calculator</pre>
				<h1><?= $is_final ? 'Final quotation' : 'Online Estimate'; ?></h1>
				<?php if (!empty($state['client_name']) || !empty($state['client_company'])) : ?><p>Prepared for <?= esc_html(trim(($state['client_name'] ?? '').' · '.($state['client_company'] ?? ''), ' ·')); ?></p><?php endif; ?>

				<p class="author">
					<strong>D’André Phillips</strong><br>
					<a href="mailto:dandrephillips@outlook.com?subject=Online Quotation Enquiry | <?= esc_html($invoice); ?>">dandrephillips@outlook.com</a> <br>
                    <a href="mailto:hello@dxndre.co.uk?subject=Online Quotation Enquiry | <?= esc_html($invoice); ?>">hello@dxndre.co.uk</a>
				</p>
			</div>

			<div class="header-meta">
				<div>
					<h4>Quotation Generation Date</h4>
					<span><?= esc_html(date('d F Y', strtotime($quote->created_at))); ?></span>
				</div>

				<div>
					<h4>Quotation Expiry Date</h4>
					<span><?= esc_html($expiry_date); ?></span>
				</div>

				<div>
					<h4>Estimate reference</h4>
					<span><?= esc_html($invoice); ?></span>
				</div>
			</div>
		</section>

		<table class="items">
			<thead>
                <tr>
                    <th class="col-category">Description</th>
                    <th class="col-selection"></th>
                    <th class="col-amount">Amount</th>
                </tr>
            </thead>

			<tbody>
				<?php echo dx_estimate_breakdown_html($state, 3); ?>
			</tbody>
		</table>

		<?php if (!isset($state['estimate_breakdown']) && !isset($state['retainer_estimate'])) : ?><p>This estimate was generated using earlier pricing. Individual item prices were not saved; the original estimate total is retained.</p><?php endif; ?>
		<div class="total">
			<strong><?= ($state['service'] ?? '') === 'retainer' ? 'Monthly estimate' : 'Total' ?> £<?= esc_html($total); ?><?= ($state['service'] ?? '') === 'retainer' ? ' / month' : '' ?></strong>
		</div>

		<footer class="proposal-notes">
			<div class="proposal-notes-inner">
				<h3>Important to note</h3>
				<p>This quotation is valid for 30 days.</p>
				<p><?= ($state['service'] ?? '') === 'retainer' ? 'Hours are shared across selected categories and expire monthly. Additional landing-page capacity is restricted to landing-page work. Scheduling targets do not guarantee completion. Overages, major builds and incident work require separate agreement.' : '' ?><?= ' '.esc_html(dx_payment_terms($state)) ?></p>
			</div>
		</footer>

	</div>

<section class="dx-estimate-next">
<p class="headline">Your next chapter</p><h2>From possibility.<br>To a plan.</h2>
<div class="dx-estimate-next-grid"><div><h3>01 / Make it yours.</h3><p>We’ll discuss your scope, timings and priorities in an initial call. There is nothing to pay at this stage.</p><a class="dx-cta" href="https://calendly.com/dxndre" target="_blank" rel="noopener">Confirm scope & book a call ↗</a></div>
<div><h3>02 / Review your final quotation.</h3><p><?= $is_final ? 'Your final quotation is ready. Review its scope and payment terms, then return to your client space to record your acceptance.' : 'After our call, I’ll issue your final quotation with the agreed scope and payment schedule. Review and accept it before any deposit or payment is required.'; ?></p><p class="dx-step-note">Your approval comes first.</p></div>
<div><h3>03 / Your payment plan.</h3><p><?= esc_html(dx_payment_terms($state)) ?></p>
<?php if(($state['service_category']??'')!=='photography' && ($state['service']??'')!=='retainer'): ?><form method="post"><?php wp_nonce_field('dx_plan_'.$invoice,'dx_plan_nonce'); ?><label for="estimate-plan">Choose a payment option</label><select id="estimate-plan" name="payment_plan"><?php foreach(['full'=>'Pay in full','three'=>'Pay in 3','six'=>'Pay in 6 · 5% on the full quote','custom'=>'Custom Instalment Plan · discuss on our call'] as $key=>$label){if($key==='six'&&(float)$quote->total<=600)continue;echo '<option value="'.esc_attr($key).'" '.selected($state['payment_plan']??'full',$key,false).'>'.esc_html($label).'</option>';} ?></select><button class="dx-cta" type="submit">Update payment preference ↗</button></form><?php endif; ?></div>
<div><h3>04 / Keep a copy.</h3><p>Download a paper-friendly PDF of your estimate, including your selected payment terms.</p><a class="dx-cta" href="<?= esc_url(add_query_arg('pdf','1')) ?>">Download estimate PDF ↓</a></div></div></section>
<script>
(()=>{const b=document.querySelector('[data-proposal-theme]');if(!b)return;b.dataset.bound='true';b.addEventListener('click',()=>{const light=document.body.classList.toggle('is-light');b.textContent=light?'Dark mode':'Light mode';b.setAttribute('aria-pressed',String(light));});})();
</script>
<?php get_footer(); ?>
