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
				<img src="<?= DX_PC_URL ?>assets/img/dxndre.co.uk-black.png" alt="DXNDRE" width="150">
			</a>
		</header>

		<section class="header-grid">
			<div>
				<pre class="headline">Pricing Calculator</pre>
				<h1>Online Quotation</h1>

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
					<h4>Invoice</h4>
					<span><?= esc_html($invoice); ?></span>
				</div>
			</div>
		</section>

		<table class="items">
			<thead>
				<tr>
					<th>Description</th>
					<th>Amount</th>
				</tr>
			</thead>

			<tbody>
				<?php foreach ($state as $key => $value) :
					if (is_array($value)) continue; ?>
					<tr>
						<td><?= esc_html(ucfirst($key)); ?></td>
						<td>£<?= esc_html($value); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="total">
			<strong>Total £<?= esc_html($total); ?></strong>
		</div>

		<footer class="footer">
			<div class="footer-inner">
				<h3>Important to note</h3>
				<p>This quotation is valid for 30 days.</p>
				<p>Payment terms: 33.33% upfront, balance on completion.</p>
			</div>
		</footer>

	</div>

</body>
</html>