<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('att_import')) ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<div class="callout callout-info"><p><?= e(t('imp_help')) ?></p></div>
		<div class="row">
			<div class="col-md-5">
				<div class="box box-primary">
					<div class="box-header with-border"><h3 class="box-title"><?= e(t('imp_step1')) ?></h3></div>
					<form id="import-form" method="post" enctype="multipart/form-data" action="<?= site_url('attendance/import_preview') ?>">
						<div class="box-body">
							<div class="form-group"><label for="f_device"><?= e(t('att_device')) ?></label>
								<select id="f_device" name="device_id" class="form-control" required><?php foreach ($devices as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
								<p class="help-block"><?= e(t('imp_device_help')) ?></p></div>
							<div class="form-group"><label for="f_file"><?= e(t('imp_file')) ?></label><input type="file" id="f_file" name="punch_file" accept=".dat,.txt,.csv" required></div>
						</div>
						<div class="box-footer"><button type="submit" class="btn btn-primary" id="import-preview"><i class="fa fa-search"></i> <?= e(t('imp_preview')) ?></button></div>
					</form>
				</div>
			</div>
			<div class="col-md-7">
				<div class="box box-success" id="preview-box" style="display:none">
					<div class="box-header with-border"><h3 class="box-title"><?= e(t('imp_step2')) ?></h3></div>
					<div class="box-body">
						<table class="table table-condensed" id="preview-summary"></table>
						<div id="preview-unmatched" style="display:none"><h4><?= e(t('imp_unmatched')) ?></h4><p class="text-muted"><?= e(t('imp_unmatched_help')) ?></p><p id="preview-unmatched-list"></p></div>
						<h4><?= e(t('imp_sample')) ?></h4><table class="table table-condensed" id="preview-sample"></table>
					</div>
					<div class="box-footer">
						<form id="commit-form" method="post" data-done="<?= e(t('imp_done')) ?>" action="<?= site_url('attendance/import_commit') ?>"><input type="hidden" name="token" id="commit-token">
							<button type="submit" class="btn btn-success" id="import-commit"><i class="fa fa-upload"></i> <?= e(t('imp_commit')) ?></button></form>
					</div>
				</div>
			</div>
		</div>
	</section>
</div>
<script>window.HRM_LABELS = <?= json_encode(array('device' => t('att_device'), 'rows' => t('imp_rows'), 'valid' => t('imp_valid'), 'invalid' => t('imp_invalid'), 'range' => t('imp_range'), 'newp' => t('imp_new'), 'already' => t('imp_already')), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
