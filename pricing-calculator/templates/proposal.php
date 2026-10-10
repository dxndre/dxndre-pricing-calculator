<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">

	<title>Proposal <?= esc_html($invoice); ?></title>

	<!-- Google Fonts -->
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600&display=swap" rel="stylesheet">

	<link rel="stylesheet" href="<?= DX_PC_URL ?>assets/css/proposal.css">
</head>

<body class="proposal">

	<div class="container proposal-inner">

		<header class="logo-header">
			<a href="<?= esc_url(home_url('/')); ?>" target="_blank" rel="noopener">
				<strong style="font-size:24px;color:#000">dxndre.co.uk</strong>
			</a>
		</header>

		<section class="header-grid">
			<div>
				<pre class="headline">Pricing Calculator</pre>
				<h1>Online Estimate</h1>
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

		<footer class="footer">
			<div class="footer-inner">
				<h3>Important to note</h3>
				<p>This quotation is valid for 30 days.</p>
				<p><?= ($state['service'] ?? '') === 'retainer' ? 'Hours are shared across selected categories and expire monthly. Additional landing-page capacity is restricted to landing-page work. Scheduling targets do not guarantee completion. Overages, major builds and incident work require separate agreement.' : 'Payment terms: 33.33% upfront, balance on completion.' ?></p>
			</div>
		</footer>

	</div>

	<div class="next-steps">
		<h3>Let’s turn this estimate into a real plan.</h3>
		<p>This estimate gives us a strong starting point. The next step is a short call to confirm scope, timelines, and priorities — ensuring everything aligns before work begins.</p>
		<a href="https://calendly.com/dxndre" target="_blank">Confirm Scope & Book Call</a>
		<span>Nothing proceeds without your approval.</span>
	</div>
</body>
</html>