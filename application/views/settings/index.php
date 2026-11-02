<?php defined('BASEPATH') OR exit('No direct script access allowed');
// small field renderers (all output escaped)
$text = function ($name, $max, $type = 'text') use ($values) {
	return '<div class="form-group"><label for="f_' . $name . '">' . e(t('set_' . $name)) . '</label>'
		. '<input type="' . $type . '" id="f_' . $name . '" name="' . $name . '" class="form-control" maxlength="' . (int) $max . '" value="' . e($values[$name]) . '"></div>';
};
$number = function ($name, $min, $max, $step = '1') use ($values) {
	return '<div class="form-group"><label for="f_' . $name . '">' . e(t('set_' . $name)) . '</label>'
		. '<input type="number" id="f_' . $name . '" name="' . $name . '" class="form-control" min="' . e($min) . '" max="' . e($max) . '" step="' . e($step) . '" value="' . e($values[$name]) . '" required></div>';
};
$select = function ($name, array $options) use ($values) {
	$html = '<div class="form-group"><label for="f_' . $name . '">' . e(t('set_' . $name)) . '</label><select id="f_' . $name . '" name="' . $name . '" class="form-control">';
	foreach ($options as $value => $label) {
		$html .= '<option value="' . e($value) . '"' . ((string) $values[$name] === (string) $value ? ' selected' : '') . '>' . e($label) . '</option>';
	}
	return $html . '</select></div>';
};
$flag = function ($name) use ($values) {
	return '<div class="checkbox"><label><input type="checkbox" name="' . $name . '" value="1"' . ((int) $values[$name] ? ' checked' : '') . '> ' . e(t('set_' . $name)) . '</label></div>';
};
$auto_manual = array('auto' => t('opt_auto'), 'manual' => t('opt_manual'));
$basis = array('basic_salary' => t('opt_basic_salary'), 'gross_salary' => t('opt_gross_salary'));
$offs = array_flip(array_filter(explode(',', (string) $values['weekly_offs']), 'strlen'));
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('menu_settings')) ?></h1></section>
	<section class="content">
		<form id="settings-form" class="hrm-ajax-form" method="post" enctype="multipart/form-data" action="<?= site_url('settings/save') ?>" data-reload="1">
			<div class="nav-tabs-custom">
				<ul class="nav nav-tabs">
					<?php foreach (array('general', 'attendance', 'payroll', 'leave', 'devices', 'notifications') as $i => $tab): ?>
						<li<?= $i === 0 ? ' class="active"' : '' ?>><a href="#tab-<?= $tab ?>" data-toggle="tab"><?= e(t('settings_tab_' . $tab)) ?></a></li>
					<?php endforeach; ?>
				</ul>
				<div class="tab-content">
					<div class="tab-pane active" id="tab-general">
						<div class="row">
							<div class="col-md-6">
								<?= $text('company_name', 150) ?><?= $text('address', 255) ?>
								<div class="row"><div class="col-sm-6"><?= $text('phone', 30) ?></div><div class="col-sm-6"><?= $text('email', 120, 'email') ?></div></div>
								<div class="form-group"><label for="f_logo"><?= e(t('settings_logo')) ?></label>
									<?php if ($has_logo): ?><p><img src="<?= site_url('settings/logo') ?>" alt="" style="max-height:60px" id="logo-preview"></p><?php endif; ?>
									<input type="file" id="f_logo" name="logo" accept="image/png,image/jpeg">
									<?php if ($has_logo): ?><div class="checkbox"><label><input type="checkbox" name="remove_logo" value="1"> <?= e(t('settings_remove_logo')) ?></label></div><?php endif; ?>
								</div>
							</div>
							<div class="col-md-6">
								<div class="form-group"><label for="f_timezone"><?= e(t('set_timezone')) ?></label>
									<select id="f_timezone" name="timezone" class="form-control select2" style="width:100%">
										<?php foreach ($timezones as $zone): ?><option<?= $zone === $values['timezone'] ? ' selected' : '' ?>><?= e($zone) ?></option><?php endforeach; ?>
									</select></div>
								<?= $select('date_format', array_combine($date_formats, array_map(function ($f) { return $f . '  (' . date($f) . ')'; }, $date_formats))) ?>
								<div class="row">
									<div class="col-sm-4"><?= $text('currency_symbol', 10) ?></div>
									<div class="col-sm-5"><?= $select('currency_position', array('left' => t('opt_left'), 'right' => t('opt_right'))) ?></div>
									<div class="col-sm-3"><?= $number('decimals', 0, 3) ?></div>
								</div>
								<?= $number('idle_logout_minutes', 0, 1440) ?>
							</div>
						</div>
					</div>
					<div class="tab-pane" id="tab-attendance">
						<div class="form-group"><label><?= e(t('set_weekly_offs')) ?></label><div>
							<?php for ($d = 1; $d <= 7; $d++): ?>
								<label class="checkbox-inline"><input type="checkbox" name="weekly_offs[]" value="<?= $d ?>" <?= isset($offs[(string) $d]) ? 'checked' : '' ?>> <?= e(t('weekday_' . $d)) ?></label>
							<?php endfor; ?>
						</div></div>
						<div class="row"><div class="col-md-6">
							<?= $flag('deduct_break') ?><?= $flag('early_leave_counts_as_late') ?>
							<?= $number('ot_min_minutes', 0, 240) ?><?= $number('late_days_per_absent', 0, 31) ?>
							<?= $select('default_shift_id', array('' => t('org_none')) + $shifts) ?>
						</div></div>
					</div>
					<div class="tab-pane" id="tab-payroll">
						<div class="row">
							<div class="col-md-6">
								<?= $select('overtime_source', array('attendance' => t('opt_attendance'), 'approved_requests' => t('opt_approved_requests'))) ?>
								<?= $select('overtime_basis', $basis) ?>
								<div class="row"><div class="col-sm-6"><?= $number('ot_divisor_hours', 1, 744, '0.01') ?></div><div class="col-sm-6"><?= $number('ot_multiplier', 0, 10, '0.01') ?></div></div>
								<?= $select('overtime_mode', $auto_manual) ?>
							</div>
							<div class="col-md-6">
								<?= $select('late_deduction_mode', $auto_manual) ?>
								<?= $select('absent_deduction_basis', $basis) ?>
								<?= $select('absent_deduction_mode', $auto_manual) ?>
								<?= $flag('allow_negative_net') ?><?= $flag('allow_negative_account') ?>
							</div>
						</div>
						<?= $text('payslip_footer', 500) ?><?= $flag('payslip_show_attendance') ?>
					</div>
					<div class="tab-pane" id="tab-leave"><?= $flag('self_approve_leave') ?></div>
					<div class="tab-pane" id="tab-devices"><div class="row"><div class="col-md-6">
						<?= $number('punch_debounce_seconds', 0, 3600) ?><?= $number('device_gap_hours', 1, 168) ?>
					</div></div></div>
					<div class="tab-pane" id="tab-notifications">
						<?= $flag('notify_leave_sms') ?><?= $flag('notify_payslip_email') ?><?= $flag('notify_absent_sms') ?>
					</div>
				</div>
				<div class="box-footer"><button type="submit" class="btn btn-primary" id="settings-save"><?= e(t('save')) ?></button></div>
			</div>
		</form>
	</section>
</div>
