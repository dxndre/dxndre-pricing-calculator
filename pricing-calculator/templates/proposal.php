<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">

	<title>Proposal <?= esc_html($invoice); ?></title>

	<link rel="stylesheet" href="<?= DX_PC_URL ?>assets/css/calculator.css">
	<link rel="stylesheet" href="<?= DX_PC_URL ?>assets/css/proposal.css">

	<style>
		@page {
			size: A4;
			margin: 24mm;
		}
	</style>
</head>

<body class="proposal">

	<header class="proposal-header">
		<img src="<?= DX_PC_URL ?>/assets/img/dxndre.co.uk-black.png" alt="DXNDRE">
		<div class="meta">
			<div>Invoice: <?= esc_html($invoice); ?></div>
			<div>Date: <?= date('d F Y'); ?></div>
		</div>
	</header>

	<main class="proposal-body">
		<!-- Line items -->
	</main>

	<footer class="proposal-footer">
		<span class="page-number"></span>
	</footer>

</body>
</html>