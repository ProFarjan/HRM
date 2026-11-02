<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('dev_keys')) ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<div class="alert alert-success" id="new-key-box" style="display:none">
			<strong><?= e(t('key_new_title')) ?></strong>
			<p><?= e(t('key_new_help')) ?></p>
			<pre id="new-key" style="user-select:all"></pre>
		</div>
		<div class="row">
			<div class="col-md-8">
				<div class="box box-primary"><div class="box-body">
					<table id="keys-table" class="table table-bordered table-striped" style="width:100%" data-url="<?= site_url('devices/keys_list') ?>" data-revoke-url="<?= site_url('devices/revoke_key') ?>" data-confirm="<?= e(t('key_revoke_confirm')) ?>">
						<thead><tr><th><?= e(t('key_label')) ?></th><th><?= e(t('dev_name')) ?></th><th><?= e(t('key_hint')) ?></th><th><?= e(t('key_created')) ?></th><th><?= e(t('key_last_used')) ?></th><th><?= e(t('status')) ?></th><th></th></tr></thead>
					</table>
				</div></div>
			</div>
			<div class="col-md-4">
				<div class="box box-default">
					<div class="box-header with-border"><h3 class="box-title"><?= e(t('key_create')) ?></h3></div>
					<form id="key-form" class="hrm-ajax-form" method="post" data-after="key-created" action="<?= site_url('devices/create_key') ?>">
						<div class="box-body">
							<div class="form-group"><label for="f_label"><?= e(t('key_label')) ?></label><input type="text" id="f_label" name="label" class="form-control" maxlength="100" required></div>
							<div class="form-group"><label for="f_device"><?= e(t('key_device')) ?></label>
								<select id="f_device" name="device_id" class="form-control"><option value=""><?= e(t('key_any_device')) ?></option><?php foreach ($devices as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
						</div>
						<div class="box-footer"><button type="submit" class="btn btn-primary" id="key-save"><i class="fa fa-key"></i> <?= e(t('key_create')) ?></button></div>
					</form>
				</div>
				<div class="callout callout-info"><p><?= e(t('key_service_help')) ?></p></div>
			</div>
		</div>
	</section>
</div>
