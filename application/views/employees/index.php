<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('menu_employees')) ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<div class="box box-primary">
			<div class="box-header with-border">
				<form class="form-inline" id="employees-filter">
					<select name="department_id" class="form-control input-sm">
						<option value=""><?= e(t('emp_department')) ?>: <?= e(t('all')) ?></option>
						<?php foreach ($departments as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?>
					</select>
					<select name="designation_id" class="form-control input-sm">
						<option value=""><?= e(t('emp_designation')) ?>: <?= e(t('all')) ?></option>
						<?php foreach ($designations as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?>
					</select>
					<select name="employment_type" class="form-control input-sm">
						<option value=""><?= e(t('emp_type')) ?>: <?= e(t('all')) ?></option>
						<?php foreach ($types as $type): ?><option value="<?= e($type) ?>"><?= e($type) ?></option><?php endforeach; ?>
					</select>
					<select name="status" class="form-control input-sm">
						<option value="current"><?= e(t('emp_status_current')) ?></option>
						<option value=""><?= e(t('emp_status')) ?>: <?= e(t('all')) ?></option>
						<?php foreach (array('Active', 'On Leave', 'Resigned', 'Terminated') as $s): ?>
							<option value="<?= e($s) ?>"><?= e(t('emp_status_' . strtolower(str_replace(' ', '_', $s)))) ?></option>
						<?php endforeach; ?>
					</select>
				</form>
				<?php if (has_permission('employees_add')): ?>
				<div class="box-tools"><a href="<?= site_url('employees/add') ?>" class="btn btn-primary btn-sm" id="emp-add"><i class="fa fa-plus"></i> <?= e(t('emp_add')) ?></a></div>
				<?php endif; ?>
			</div>
			<div class="box-body table-responsive">
				<table id="employees-table" class="table table-bordered table-striped table-condensed" style="width:100%"
					data-url="<?= site_url('employees/ajax_list') ?>" data-view-url="<?= site_url('employees/view') ?>">
					<thead><tr>
						<th><?= e(t('emp_code')) ?></th><th><?= e(t('emp_name')) ?></th><th><?= e(t('emp_department')) ?></th><th><?= e(t('emp_designation')) ?></th>
						<th><?= e(t('emp_type')) ?></th><th><?= e(t('emp_join_date')) ?></th><th><?= e(t('emp_status')) ?></th><th><?= e(t('emp_device_pin')) ?></th>
					</tr></thead>
				</table>
			</div>
		</div>
	</section>
</div>
