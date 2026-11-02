<?php defined('BASEPATH') OR exit('No direct script access allowed');
$statuses = array('Present', 'Absent', 'Half-day', 'Leave', 'Holiday', 'Weekly Off');
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('menu_attendance_list')) ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<div class="box box-primary">
			<div class="box-header with-border">
				<form class="form-inline" id="attendance-filter">
					<input type="text" name="from_date" class="form-control input-sm datepicker" value="<?= e($from_date) ?>" style="width:105px" autocomplete="off">
					<input type="text" name="to_date" class="form-control input-sm datepicker" value="<?= e($to_date) ?>" style="width:105px" autocomplete="off">
					<select name="department_id" class="form-control input-sm"><option value=""><?= e(t('emp_department')) ?>: <?= e(t('all')) ?></option><?php foreach ($departments as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
					<select name="status" class="form-control input-sm"><option value=""><?= e(t('status')) ?>: <?= e(t('all')) ?></option><?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>"><?= e(t('att_status_' . strtolower(str_replace(array(' ', '-'), '_', $s)))) ?></option><?php endforeach; ?></select>
					<select name="approval_status" class="form-control input-sm"><option value=""><?= e(t('att_approval')) ?>: <?= e(t('all')) ?></option><?php foreach (array('Pending', 'Approved', 'Rejected') as $s): ?><option value="<?= $s ?>"><?= e(t('att_approval_' . strtolower($s))) ?></option><?php endforeach; ?></select>
					<label class="checkbox-inline"><input type="checkbox" name="missing_checkout" value="1" class="no-icheck" id="missing-filter"> <?= e(t('att_missing_checkout')) ?></label>
					<button type="submit" class="btn btn-default btn-sm"><i class="fa fa-filter"></i> <?= e(t('filter')) ?></button>
				</form>
			</div>
			<div class="box-body table-responsive">
				<table id="attendance-table" class="table table-bordered table-striped table-condensed" style="width:100%" data-url="<?= site_url('attendance/ajax_list') ?>"
					data-edit-url="<?= has_permission('attendance_edit') ? site_url('attendance/edit') : '' ?>"
					data-rebuild-url="<?= has_permission('attendance_edit') ? site_url('attendance/rebuild') : '' ?>"
					data-delete-url="<?= has_permission('attendance_delete') ? site_url('attendance/delete') : '' ?>"
					data-approve-url="<?= has_permission('attendance_approve') ? site_url('attendance/approve') : '' ?>"
					data-reject-url="<?= has_permission('attendance_approve') ? site_url('attendance/reject') : '' ?>"
					data-confirm="<?= e(t('confirm_delete')) ?>" data-reject-prompt="<?= e(t('att_note')) ?>"
					data-t-approve="<?= e(t('att_approve')) ?>" data-t-reject="<?= e(t('att_reject')) ?>" data-t-rebuild="<?= e(t('att_rebuild')) ?>">
					<thead><tr><th><?= e(t('att_date')) ?></th><th><?= e(t('emp_code')) ?></th><th><?= e(t('emp_name')) ?></th><th><?= e(t('emp_department')) ?></th><th><?= e(t('status')) ?></th>
						<th><?= e(t('att_in')) ?></th><th><?= e(t('att_out')) ?></th><th><?= e(t('att_hours')) ?></th><th><?= e(t('att_late')) ?></th><th><?= e(t('att_ot')) ?></th><th><?= e(t('att_approval')) ?></th><th><?= e(t('actions')) ?></th></tr></thead>
				</table>
			</div>
		</div>
	</section>
</div>
