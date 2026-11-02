<?php defined('BASEPATH') OR exit('No direct script access allowed'); $statuses = array('Present', 'Absent', 'Half-day', 'Leave', 'Holiday', 'Weekly Off'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('att_bulk')) ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<div class="box box-primary" style="max-width:640px">
			<form id="bulk-attendance-form" class="hrm-ajax-form" method="post" action="<?= site_url('attendance/save_bulk') ?>" data-message="<?= e(t('att_bulk_done')) ?>">
				<div class="box-body">
					<div class="row">
						<div class="form-group col-sm-5"><label><?= e(t('att_date')) ?></label><input type="text" name="attendance_date" class="form-control datepicker" autocomplete="off" required value="<?= e(show_date(today_local())) ?>"></div>
						<div class="form-group col-sm-7"><label><?= e(t('emp_department')) ?></label><select name="department_id" class="form-control"><option value=""><?= e(t('shift_bulk_all')) ?></option><?php foreach ($departments as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
					</div>
					<div class="row">
						<div class="form-group col-sm-4"><label><?= e(t('status')) ?></label><select name="status" class="form-control" id="f_status"><?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>"><?= e(t('att_status_' . strtolower(str_replace(array(' ', '-'), '_', $s)))) ?></option><?php endforeach; ?></select></div>
						<div class="form-group col-sm-4" id="time-fields"><label><?= e(t('att_in')) ?></label><input type="time" name="check_in" class="form-control"></div>
						<div class="form-group col-sm-4"><label><?= e(t('att_out')) ?></label><input type="time" name="check_out" class="form-control"></div>
					</div>
					<div class="form-group"><label><?= e(t('att_remarks')) ?></label><input type="text" name="remarks" class="form-control" maxlength="255"></div>
					<div class="checkbox"><label><input type="checkbox" name="overwrite" value="1"> <?= e(t('att_overwrite')) ?></label></div>
					<p class="help-block"><?= e(t('att_times_help')) ?></p>
				</div>
				<div class="box-footer"><button type="submit" class="btn btn-primary" id="bulk-save"><?= e(t('save')) ?></button></div>
			</form>
		</div>
	</section>
</div>
