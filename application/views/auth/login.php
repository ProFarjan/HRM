<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= e(t('login_title')) ?> | <?= e($company_name) ?></title>
	<link rel="stylesheet" href="<?= base_url('theme/bootstrap/css/bootstrap.min.css') ?>">
	<link rel="stylesheet" href="<?= base_url('theme/font-awesome/css/font-awesome.min.css') ?>">
	<link rel="stylesheet" href="<?= base_url('theme/adminlte/css/AdminLTE.min.css') ?>">
</head>
<body class="hold-transition login-page">
<div class="login-box">
	<div class="login-logo"><b><?= e($company_name) ?></b></div>
	<div class="login-box-body">
		<p class="login-box-msg"><?= e(t('login_title')) ?></p>
		<?php if ($notice !== ''): ?><div class="alert alert-info"><?= e($notice) ?></div><?php endif; ?>
		<?php if ($error !== ''): ?><div class="alert alert-danger" id="login-error"><?= nl2br(e($error)) ?></div><?php endif; ?>
		<form method="post" action="<?= site_url('auth/login') ?>" autocomplete="off">
			<input type="hidden" name="<?= e($csrf_name) ?>" value="<?= e($csrf_hash) ?>">
			<div class="form-group has-feedback">
				<input type="text" name="username" class="form-control" placeholder="<?= e(t('login_username')) ?>" maxlength="64" required autofocus>
				<span class="fa fa-user form-control-feedback"></span>
			</div>
			<div class="form-group has-feedback">
				<input type="password" name="password" class="form-control" placeholder="<?= e(t('login_password')) ?>" maxlength="255" required>
				<span class="fa fa-lock form-control-feedback"></span>
			</div>
			<button type="submit" class="btn btn-primary btn-block btn-flat"><?= e(t('login_button')) ?></button>
		</form>
	</div>
</div>
</body>
</html>
