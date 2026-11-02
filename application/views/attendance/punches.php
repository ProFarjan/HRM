<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('att_punches')) ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<div class="box box-primary">
			<div class="box-header with-border">
				<form class="form-inline" id="punches-filter">
					<input type="text" name="from_date" class="form-control input-sm datepicker" value="<?= e($from_date) ?>" style="width:105px" autocomplete="off">
					<input type="text" name="to_date" class="form-control input-sm datepicker" value="<?= e($to_date) ?>" style="width:105px" autocomplete="off">
					<select name="employee_id" class="form-control input-sm"><option value=""><?= e(t('emp_name')) ?>: <?= e(t('all')) ?></option><?php foreach ($employees as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
					<label class="checkbox-inline"><input type="checkbox" name="unmatched" value="1" class="no-icheck"> <?= e(t('att_unmatched_only')) ?></label>
					<button type="submit" class="btn btn-default btn-sm"><i class="fa fa-filter"></i> <?= e(t('filter')) ?></button>
				</form>
			</div>
			<div class="box-body table-responsive">
				<table id="punches-table" class="table table-bordered table-striped table-condensed" style="width:100%" data-url="<?= site_url('attendance/punches_list') ?>">
					<thead><tr><th><?= e(t('att_punch_time')) ?></th><th><?= e(t('emp_name')) ?></th><th><?= e(t('emp_device_pin')) ?></th><th><?= e(t('att_device')) ?></th><th><?= e(t('att_state')) ?></th><th><?= e(t('att_source')) ?></th><th></th></tr></thead>
				</table>
			</div>
		</div>
	</section>
</div>
