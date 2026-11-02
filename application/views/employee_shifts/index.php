<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('menu_employee_shifts')) ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<?php if (has_permission('rosters_manage')): ?>
		<div class="box box-default collapsed-box">
			<div class="box-header with-border"><h3 class="box-title"><?= e(t('shift_bulk')) ?></h3><div class="box-tools"><button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-plus"></i></button></div></div>
			<div class="box-body">
				<form id="bulk-form" class="hrm-ajax-form form-inline" method="post" data-reload="1" data-message="<?= e(t('shift_bulk_done')) ?>" action="<?= site_url('employee_shifts/bulk') ?>">
					<select name="department_id" class="form-control"><option value=""><?= e(t('shift_bulk_all')) ?></option><?php foreach ($departments as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
					<select name="shift_id" class="form-control" required><?php foreach ($shifts as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
					<input type="text" name="effective_from" class="form-control datepicker" placeholder="<?= e(t('shift_since')) ?>" autocomplete="off" required>
					<button type="submit" class="btn btn-primary"><?= e(t('shift_assign')) ?></button>
					<p class="help-block"><?= e(t('shift_assign_help')) ?></p>
				</form>
			</div>
		</div>
		<?php endif; ?>
		<div class="box box-primary">
			<div class="box-header with-border">
				<form class="form-inline" id="assign-filter">
					<select name="department_id" class="form-control input-sm"><option value=""><?= e(t('emp_department')) ?>: <?= e(t('all')) ?></option><?php foreach ($departments as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
					<select name="shift_id" class="form-control input-sm"><option value=""><?= e(t('shift_current')) ?>: <?= e(t('all')) ?></option><?php foreach ($shifts as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
					<select name="shift" class="form-control input-sm"><option value=""></option><option value="none"><?= e(t('shift_filter_none')) ?></option></select>
				</form>
			</div>
			<div class="box-body table-responsive">
				<table id="assign-table" class="table table-bordered table-striped table-condensed" style="width:100%" data-url="<?= site_url('employee_shifts/ajax_list') ?>" data-employee-url="<?= site_url('employee_shifts/employee') ?>">
					<thead><tr><th><?= e(t('emp_code')) ?></th><th><?= e(t('emp_name')) ?></th><th><?= e(t('emp_department')) ?></th><th><?= e(t('shift_current')) ?></th><th><?= e(t('shift_since')) ?></th></tr></thead>
				</table>
			</div>
		</div>
	</section>
</div>
