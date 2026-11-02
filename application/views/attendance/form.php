<?php defined('BASEPATH') OR exit('No direct script access allowed');
$is_edit = $row !== NULL;
$val = function ($k, $default = '') use ($row) { return $row !== NULL && $row[$k] !== NULL ? $row[$k] : $default; };
$statuses = array('Present', 'Absent', 'Half-day', 'Leave', 'Holiday', 'Weekly Off');
// split shift of an existing row: the periods of its shift with what was recorded in each
$edit_periods = array();
if ($is_edit && $row['shift_periods']) {
    $done = array();
    foreach ($row['periods'] as $p) {
        $done[(int) $p['segment_no']] = $p;
    }
    foreach ($row['shift_periods'] as $sp) {
        $no = (int) $sp['segment_no'];
        $edit_periods[] = array('no' => $no, 'start' => substr($sp['start_time'], 0, 5), 'end' => substr($sp['end_time'], 0, 5),
            'in' => isset($done[$no]) && $done[$no]['check_in'] !== NULL ? substr($done[$no]['check_in'], 0, 5) : '', 'out' => isset($done[$no]) && $done[$no]['check_out'] !== NULL ? substr($done[$no]['check_out'], 0, 5) : '');
    }
}
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e($is_edit ? t('att_edit') : t('att_mark')) ?><?php if ($is_edit): ?> <small><?= e($row['employee_code'] . ' - ' . trim($row['first_name'] . ' ' . $row['last_name']) . ', ' . show_date($row['attendance_date'])) ?></small><?php endif; ?></h1></section>
	<section class="content">
		<div class="row">
			<div class="col-md-6"><div class="box box-primary">
				<form id="attendance-form" class="hrm-ajax-form" method="post" data-day-info-url="<?= has_permission('attendance_add') ? site_url('attendance/day_info') : '' ?>"
					data-t-period="<?= e(t('att_period')) ?>" data-t-split="<?= e(t('att_split_shift')) ?>" data-periods="<?= e(json_encode($edit_periods)) ?>" data-redirect="<?= site_url('attendance') ?>" action="<?= $is_edit ? site_url('attendance/update/' . (int) $row['id']) : site_url('attendance/save_mark') ?>">
					<div class="box-body">
						<?php if ( ! $is_edit): ?>
						<div class="form-group"><label for="f_employee"><?= e(t('emp_name')) ?></label>
							<select id="f_employee" name="employee_id" class="form-control select2" style="width:100%" required><option value=""></option><?php foreach ($employees as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
						<div class="form-group" style="max-width:200px"><label for="f_date"><?= e(t('att_date')) ?></label><input type="text" id="f_date" name="attendance_date" class="form-control datepicker" autocomplete="off" required value="<?= e(show_date(today_local())) ?>"></div>
						<?php endif; ?>
						<div class="form-group" style="max-width:240px"><label for="f_status"><?= e(t('status')) ?></label>
							<select id="f_status" name="status" class="form-control"><?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>"<?= $val('status', 'Present') === $s ? ' selected' : '' ?>><?= e(t('att_status_' . strtolower(str_replace(array(' ', '-'), '_', $s)))) ?></option><?php endforeach; ?></select></div>
						<div class="row" id="time-fields">
							<div class="form-group col-sm-6"><label for="f_in"><?= e(t('att_in')) ?></label><input type="time" id="f_in" name="check_in" class="form-control" value="<?= e(substr($val('check_in'), 0, 5)) ?>"></div>
							<div class="form-group col-sm-6"><label for="f_out"><?= e(t('att_out')) ?></label><input type="time" id="f_out" name="check_out" class="form-control" value="<?= e(substr($val('check_out'), 0, 5)) ?>"></div>
						</div>
						<div id="period-fields" style="display:none">
							<p class="help-block" id="period-title"></p>
							<div id="period-rows"></div>
							<p class="help-block"><?= e(t('att_periods_help')) ?></p>
						</div>
						<p class="help-block" id="single-times-help"><?= e(t('att_times_help')) ?></p>
						<div class="form-group"><label for="f_remarks"><?= e(t('att_remarks')) ?></label><input type="text" id="f_remarks" name="remarks" class="form-control" maxlength="255" value="<?= e($val('remarks')) ?>"></div>
					</div>
					<div class="box-footer"><a href="<?= site_url('attendance') ?>" class="btn btn-default"><?= e(t('back')) ?></a><button type="submit" class="btn btn-primary pull-right" id="attendance-save"><?= e(t('save')) ?></button></div>
				</form>
			</div></div>
			<?php if ( ! $is_edit && has_permission('attendance_add')): ?>
			<div class="col-md-6"><div class="box box-default">
				<div class="box-header with-border"><h3 class="box-title"><?= e(t('att_add_punch')) ?></h3></div>
				<form id="punch-form" class="hrm-ajax-form" method="post" action="<?= site_url('attendance/add_punch') ?>" data-after="punch-added" data-kept="<?= e(t('att_punch_kept_manual')) ?>">
					<div class="box-body">
						<div class="form-group"><select name="employee_id" class="form-control select2" style="width:100%" required><option value=""></option><?php foreach ($employees as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
						<div class="row">
							<div class="col-sm-5 form-group"><input type="text" name="punch_date" class="form-control datepicker" autocomplete="off" required value="<?= e(show_date(today_local())) ?>"></div>
							<div class="col-sm-4 form-group"><input type="time" name="punch_time" class="form-control" required></div>
							<div class="col-sm-3 form-group"><select name="punch_state" class="form-control"><option value="255"><?= e(t('att_state_unknown')) ?></option><option value="0"><?= e(t('att_state_in')) ?></option><option value="1"><?= e(t('att_state_out')) ?></option></select></div>
						</div>
					</div>
					<div class="box-footer"><button type="submit" class="btn btn-default"><i class="fa fa-hand-pointer-o"></i> <?= e(t('att_add_punch')) ?></button></div>
				</form>
			</div></div>
			<?php endif; ?>
		</div>
	</section>
</div>
