<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('welcome_title')) ?>, <?= e($user['username']) ?></h1></section>
	<section class="content">
		<div class="callout callout-info" id="welcome-text"><p><?= e(t('welcome_text')) ?></p></div>
		<a class="btn btn-default" href="<?= site_url('auth/change_password') ?>"><?= e(t('change_password_title')) ?></a>
	</section>
</div>
