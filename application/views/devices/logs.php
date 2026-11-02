<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('dev_logs')) ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<div class="box box-primary"><div class="box-body">
			<form id="logs-filter" class="form-inline" style="margin-bottom:10px">
				<input type="text" name="from_date" class="form-control datepicker" autocomplete="off" style="width:120px" placeholder="<?= e(t('dev_from')) ?>" value="<?= e(show_date(date('Y-m-d', strtotime(today_local() . ' -2 days')))) ?>">
				<input type="text" name="to_date" class="form-control datepicker" autocomplete="off" style="width:120px" placeholder="<?= e(t('dev_to')) ?>" value="<?= e(show_date(today_local())) ?>">
				<select name="device_id" class="form-control"><option value=""><?= e(t('dev_name')) ?>: <?= e(t('all')) ?></option><?php foreach ($devices as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
				<label class="checkbox-inline"><input type="checkbox" name="errors_only" value="1" id="errors-only"> <?= e(t('dev_errors_only')) ?></label>
				<button type="submit" class="btn btn-default"><i class="fa fa-filter"></i> <?= e(t('filter')) ?></button>
			</form>
			<table id="logs-table" class="table table-bordered table-striped table-condensed" style="width:100%" data-url="<?= site_url('devices/logs_list') ?>">
				<thead><tr><th><?= e(t('log_time')) ?></th><th><?= e(t('dev_name')) ?></th><th></th><th><?= e(t('log_endpoint')) ?></th><th><?= e(t('log_code')) ?></th><th><?= e(t('log_records')) ?></th><th><?= e(t('dev_ip')) ?></th><th><?= e(t('log_message')) ?></th></tr></thead>
			</table>
		</div></div>
	</section>
</div>
