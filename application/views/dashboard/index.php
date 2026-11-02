<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header">
		<h1><?= e(t('menu_dashboard')) ?> <small><?= e(t('dashboard_welcome')) ?>, <?= e($user['username']) ?></small></h1>
	</section>
	<section class="content">
		<div class="row">
			<div class="col-md-4 col-sm-6">
				<div class="info-box">
					<span class="info-box-icon bg-aqua"><i class="fa fa-building"></i></span>
					<div class="info-box-content">
						<span class="info-box-text"><?= e(t('dashboard_branch')) ?></span>
						<span class="info-box-number" id="dash-branch"><?= e($branch_name) ?></span>
					</div>
				</div>
			</div>
			<div class="col-md-4 col-sm-6">
				<div class="info-box">
					<span class="info-box-icon bg-green"><i class="fa fa-calendar"></i></span>
					<div class="info-box-content">
						<span class="info-box-text"><?= e(t('dashboard_today')) ?></span>
						<span class="info-box-number" id="dash-today"><?= e($today) ?></span>
					</div>
				</div>
			</div>
		</div>
		<div class="callout callout-info"><p><?= e(t('dashboard_placeholder')) ?></p></div>
	</section>
</div>
