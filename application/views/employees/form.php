<?php defined('BASEPATH') OR exit('No direct script access allowed');
$is_edit = $row !== NULL;
$val = function ($k, $default = '') use ($row) { return $row !== NULL && isset($row[$k]) && $row[$k] !== NULL ? $row[$k] : $default; };
$date = function ($k) use ($val) { return show_date($val($k)); };
$input = function ($name, $label, $max, $extra = '') use ($val) {
	return '<div class="form-group"><label for="f_' . $name . '">' . e(t($label)) . '</label><input type="text" id="f_' . $name . '" name="' . $name
		. '" class="form-control" maxlength="' . (int) $max . '" value="' . e($val($name)) . '" ' . $extra . '></div>';
};
$select = function ($name, $label, array $options, $blank = TRUE) use ($val) {
	$html = '<div class="form-group"><label for="f_' . $name . '">' . e(t($label)) . '</label><select id="f_' . $name . '" name="' . $name . '" class="form-control select2" style="width:100%">';
	if ($blank) {
		$html .= '<option value="">' . e(t('org_none')) . '</option>';
	}
	foreach ($options as $value => $text) {
		$html .= '<option value="' . e($value) . '"' . ((string) $val($name) === (string) $value ? ' selected' : '') . '>' . e($text) . '</option>';
	}
	return $html . '</select></div>';
};
$dateinput = function ($name, $label, $required = FALSE) use ($date) {
	return '<div class="form-group"><label for="f_' . $name . '">' . e(t($label)) . '</label><input type="text" id="f_' . $name . '" name="' . $name
		. '" class="form-control datepicker" autocomplete="off" value="' . e($date($name)) . '"' . ($required ? ' required' : '') . '></div>';
};
$exited = $is_edit && ! in_array($row['status'], array('Active', 'On Leave'), TRUE);
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e($is_edit ? t('emp_edit') : t('emp_add')) ?><?php if ($is_edit): ?> <small><?= e($row['employee_code'] . ' - ' . $row['first_name'] . ' ' . $row['last_name']) ?></small><?php endif; ?></h1></section>
	<section class="content">
		<form id="employee-form" class="hrm-ajax-form" method="post" enctype="multipart/form-data"
			data-redirect="<?= $is_edit ? site_url('employees/view/' . (int) $row['id']) : site_url('employees') ?>"
			action="<?= $is_edit ? site_url('employees/update/' . (int) $row['id']) : site_url('employees/save') ?>">
			<div class="nav-tabs-custom">
				<ul class="nav nav-tabs">
					<li class="active"><a href="#tab-personal" data-toggle="tab"><?= e(t('emp_tab_personal')) ?></a></li>
					<li><a href="#tab-job" data-toggle="tab"><?= e(t('emp_tab_job')) ?></a></li>
					<li><a href="#tab-bank" data-toggle="tab"><?= e(t('emp_tab_bank')) ?></a></li>
				</ul>
				<div class="tab-content">
					<div class="tab-pane active" id="tab-personal"><div class="row">
						<div class="col-md-6">
							<?= $input('first_name', 'emp_first_name', 80, 'required') ?><?= $input('last_name', 'emp_last_name', 80) ?>
							<?= $select('gender', 'emp_gender', array('Male' => t('emp_gender_male'), 'Female' => t('emp_gender_female'), 'Other' => t('emp_gender_other'))) ?>
							<?= $dateinput('dob', 'emp_dob') ?>
							<?= $select('blood_group', 'emp_blood_group', array_combine($blood_groups, $blood_groups)) ?>
						</div>
						<div class="col-md-6">
							<?= $input('mobile', 'emp_mobile', 20) ?><?= $input('email', 'emp_email', 120) ?><?= $input('address', 'emp_address', 255) ?>
							<?= $input('emergency_contact_name', 'emp_emergency_name', 120) ?><?= $input('emergency_contact_number', 'emp_emergency_number', 20) ?>
							<div class="form-group"><label for="f_photo"><?= e(t('emp_photo')) ?></label><input type="file" id="f_photo" name="photo" accept="image/png,image/jpeg"></div>
						</div>
					</div></div>
					<div class="tab-pane" id="tab-job"><div class="row">
						<div class="col-md-6">
							<?= $input('employee_code', 'emp_code', 30) ?><p class="help-block" style="margin-top:-10px"><?= e(t('emp_code_help')) ?></p>
							<?= $select('department_id', 'emp_department', $departments) ?>
							<?= $select('designation_id', 'emp_designation', $designations) ?>
							<?= $select('reporting_manager_id', 'emp_manager', $managers) ?>
						</div>
						<div class="col-md-6">
							<?= $select('employment_type', 'emp_type', array_combine($types, $types), FALSE) ?>
							<?= $dateinput('join_date', 'emp_join_date', TRUE) ?><?= $dateinput('confirmation_date', 'emp_confirmation_date') ?>
							<?php if ($is_edit && ! $exited): ?>
								<?= $select('status', 'emp_status', array('Active' => t('emp_status_active'), 'On Leave' => t('emp_status_on_leave')), FALSE) ?>
							<?php endif; ?>
							<?php if ( ! $is_edit): ?>
								<?= $input('device_pin', 'emp_device_pin', 9, 'inputmode="numeric"') ?><p class="help-block" style="margin-top:-10px"><?= e(t('emp_pin_help')) ?></p>
							<?php endif; ?>
						</div>
					</div></div>
					<div class="tab-pane" id="tab-bank"><div class="row">
						<div class="col-md-6"><?= $input('bank_name', 'emp_bank_name', 100) ?><?= $input('bank_branch', 'emp_bank_branch', 100) ?></div>
						<div class="col-md-6">
							<?php if ($can_sensitive): ?>
								<?= $input('bank_account_number', 'emp_bank_account', 40) ?><?= $input('national_id', 'emp_national_id', 30) ?><?= $input('tin', 'emp_tin', 30) ?>
							<?php else: ?>
								<div class="callout callout-warning" id="sensitive-hidden"><p><?= e(t('emp_sensitive_hidden')) ?></p></div>
								<?php foreach (array('bank_account_number' => 'emp_bank_account', 'national_id' => 'emp_national_id', 'tin' => 'emp_tin') as $f => $l): ?>
									<div class="form-group"><label><?= e(t($l)) ?></label><p class="form-control-static"><?= e($val($f, '-')) ?></p></div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</div></div>
				</div>
				<div class="box-footer">
					<a href="<?= $is_edit ? site_url('employees/view/' . (int) $row['id']) : site_url('employees') ?>" class="btn btn-default"><?= e(t('back')) ?></a>
					<button type="submit" class="btn btn-primary pull-right" id="employee-save"><?= e(t('save')) ?></button>
				</div>
			</div>
		</form>
	</section>
</div>
