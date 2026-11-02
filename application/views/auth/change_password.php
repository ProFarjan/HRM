<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= e(t('change_password_title')) ?> | <?= e($company_name) ?></title>
	<link rel="stylesheet" href="<?= base_url('theme/bootstrap/css/bootstrap.min.css') ?>">
	<link rel="stylesheet" href="<?= base_url('theme/font-awesome/css/font-awesome.min.css') ?>">
	<link rel="stylesheet" href="<?= base_url('theme/adminlte/css/AdminLTE.min.css') ?>">
</head>
<body class="hold-transition login-page">
<div class="login-box">
	<div class="login-logo"><b><?= e($company_name) ?></b></div>
	<div class="login-box-body">
		<p class="login-box-msg"><?= e(t('change_password_title')) ?></p>
		<?php if ($forced): ?><div class="alert alert-warning"><?= e(t('change_password_forced')) ?></div><?php endif; ?>
		<?php if ($error !== ''): ?><div class="alert alert-danger" id="password-error"><?= nl2br(e($error)) ?></div><?php endif; ?>
		<form method="post" action="<?= site_url('auth/change_password') ?>" autocomplete="off">
			<input type="hidden" name="<?= e($csrf_name) ?>" value="<?= e($csrf_hash) ?>">
			<div class="form-group"><input type="password" name="current_password" class="form-control" placeholder="<?= e(t('password_current')) ?>" required></div>
			<div class="form-group"><input type="password" name="new_password" class="form-control" placeholder="<?= e(t('password_new')) ?>" minlength="8" required></div>
			<div class="form-group"><input type="password" name="confirm_password" class="form-control" placeholder="<?= e(t('password_confirm')) ?>" minlength="8" required></div>
			<button type="submit" class="btn btn-primary btn-block btn-flat"><?= e(t('password_save')) ?></button>
		</form>
		<?php if ( ! $forced): ?><p style="margin-top:10px"><a href="<?= site_url('dashboard') ?>">&larr; <?= e(t('menu_dashboard')) ?></a></p><?php endif; ?>
	</div>
</div>
</body>
</html>
