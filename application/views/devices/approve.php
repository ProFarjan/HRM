<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('dev_approve')) ?> <small><code><?= e($pending['serial_number']) ?></code></small></h1></section>
	<section class="content">
		<div class="box box-success" style="max-width:640px">
			<form id="approve-form" class="hrm-ajax-form" method="post" data-redirect="<?= site_url('devices') ?>" action="<?= site_url('devices/save_approve') ?>">
				<input type="hidden" name="serial_number" value="<?= e($pending['serial_number']) ?>">
				<div class="box-body">
					<p><?= e(sprintf(t('dev_approve_info'), (string) $pending['ip'], $pending['hits'])) ?></p>
					<div class="form-group"><label for="f_name"><?= e(t('dev_name')) ?></label><input type="text" id="f_name" name="name" class="form-control" maxlength="100" required></div>
					<div class="form-group"><label for="f_branch"><?= e(t('dev_branch')) ?></label>
						<select id="f_branch" name="branch_id" class="form-control"><?php foreach ($branches as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
					<div class="form-group"><label for="f_tz"><?= e(t('dev_timezone')) ?></label><input type="text" id="f_tz" name="timezone" class="form-control" maxlength="64" placeholder="<?= e($user['branch_timezone']) ?>"><p class="help-block"><?= e(t('dev_timezone_help')) ?></p></div>
					<div class="checkbox"><label><input type="checkbox" name="restrict_ip" value="1" checked> <?= e(sprintf(t('dev_restrict_ip'), (string) $pending['ip'])) ?></label></div>
				</div>
				<div class="box-footer"><a href="<?= site_url('devices') ?>" class="btn btn-default"><?= e(t('back')) ?></a><button type="submit" class="btn btn-success pull-right" id="approve-save"><?= e(t('dev_approve')) ?></button></div>
			</form>
		</div>
	</section>
</div>
