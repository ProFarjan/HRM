<?php defined('BASEPATH') OR exit('No direct script access allowed');
$id = (int) $emp['id'];
$current = in_array($emp['status'], array('Active', 'On Leave'), TRUE);
$can_edit = has_permission('employees_edit');
$can_salary = has_permission('employees_salary');
$labels = array('Active' => 'success', 'On Leave' => 'info', 'Resigned' => 'default', 'Terminated' => 'danger');
$field = function ($label, $value) {
	return '<tr><th style="width:40%">' . e(t($label)) . '</th><td>' . e($value === NULL || $value === '' ? '-' : $value) . '</td></tr>';
};
?>
<div class="content-wrapper">
	<section class="content-header">
		<h1><?= e(trim($emp['first_name'] . ' ' . $emp['last_name'])) ?> <small><?= e($emp['employee_code']) ?></small>
			<span class="label label-<?= $labels[$emp['status']] ?>" id="emp-status"><?= e(t('emp_status_' . strtolower(str_replace(' ', '_', $emp['status'])))) ?></span></h1>
	</section>
	<section class="content">
		<p>
			<a href="<?= site_url('employees') ?>" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i> <?= e(t('emp_back_to_list')) ?></a>
			<?php if ($can_edit): ?><a href="<?= site_url('employees/edit/' . $id) ?>" class="btn btn-primary btn-sm" id="emp-edit"><i class="fa fa-pencil"></i> <?= e(t('edit')) ?></a><?php endif; ?>
			<?php if (has_permission('employees_delete')): ?>
				<button type="button" class="btn btn-danger btn-sm pull-right hrm-post" data-url="<?= site_url('employees/delete/' . $id) ?>" data-confirm="<?= e(t('confirm_delete')) ?>" data-redirect="<?= site_url('employees') ?>"><i class="fa fa-trash"></i> <?= e(t('delete')) ?></button>
			<?php endif; ?>
		</p>
		<div class="nav-tabs-custom">
			<ul class="nav nav-tabs">
				<li class="active"><a href="#tab-overview" data-toggle="tab"><?= e(t('emp_tab_overview')) ?></a></li>
				<?php if ($can_salary): ?><li><a href="#tab-salary" data-toggle="tab" id="tab-salary-link"><?= e(t('emp_tab_salary')) ?></a></li><?php endif; ?>
				<?php if ($shift_history !== NULL): ?><li><a href="#tab-shift" data-toggle="tab"><?= e(t('emp_tab_shift')) ?></a></li><?php endif; ?>
				<li><a href="#tab-documents" data-toggle="tab"><?= e(t('emp_tab_documents')) ?></a></li>
				<li><a href="#tab-pin" data-toggle="tab"><?= e(t('emp_tab_pin')) ?></a></li>
				<li><a href="#tab-login" data-toggle="tab"><?= e(t('emp_tab_login')) ?></a></li>
			</ul>
			<div class="tab-content">
				<div class="tab-pane active" id="tab-overview">
					<div class="row">
						<div class="col-md-2 text-center">
							<?php if ($emp['photo']): ?><img src="<?= site_url('employees/photo/' . $id) ?>" alt="" class="img-thumbnail" style="max-width:140px" id="emp-photo"><?php else: ?><i class="fa fa-user-circle fa-5x text-muted"></i><?php endif; ?>
						</div>
						<div class="col-md-5"><table class="table table-condensed" id="emp-personal">
							<?= $field('emp_gender', $emp['gender']) ?><?= $field('emp_dob', show_date($emp['dob'])) ?><?= $field('emp_mobile', $emp['mobile']) ?>
							<?= $field('emp_email', $emp['email']) ?><?= $field('emp_address', $emp['address']) ?><?= $field('emp_blood_group', $emp['blood_group']) ?>
							<?= $field('emp_emergency_name', trim($emp['emergency_contact_name'] . ' ' . $emp['emergency_contact_number'])) ?>
						</table></div>
						<div class="col-md-5"><table class="table table-condensed" id="emp-job">
							<?= $field('emp_department', $emp['department_name']) ?><?= $field('emp_designation', $emp['designation_name']) ?>
							<?= $field('emp_manager', trim((string) $emp['manager_name'])) ?><?= $field('emp_type', $emp['employment_type']) ?>
							<?= $field('emp_join_date', show_date($emp['join_date'])) ?><?= $field('emp_confirmation_date', show_date($emp['confirmation_date'])) ?>
							<?php if ( ! $current): ?><?= $field('emp_exit_date', show_date($emp['exit_date'])) ?><?= $field('emp_exit_reason', $emp['exit_reason']) ?><?php endif; ?>
							<?= $field('emp_bank_name', trim($emp['bank_name'] . ' ' . $emp['bank_branch'])) ?>
							<?= $field('emp_bank_account', $emp['bank_account_number']) ?><?= $field('emp_national_id', $emp['national_id']) ?><?= $field('emp_tin', $emp['tin']) ?>
						</table></div>
					</div>
					<?php if ($can_edit): ?>
					<div class="box box-<?= $current ? 'danger' : 'success' ?> collapsed-box" style="margin-top:10px">
						<div class="box-header with-border"><h3 class="box-title"><?= e($current ? t('emp_exit') : t('emp_rehire')) ?></h3>
							<div class="box-tools"><button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-plus"></i></button></div></div>
						<div class="box-body">
						<?php if ($current): ?>
							<form id="exit-form" class="hrm-ajax-form form-inline" method="post" data-reload="1" action="<?= site_url('employees/record_exit/' . $id) ?>">
								<input type="text" name="exit_date" class="form-control datepicker" placeholder="<?= e(t('emp_exit_date')) ?>" autocomplete="off" required>
								<select name="exit_status" class="form-control"><option value="Resigned"><?= e(t('emp_status_resigned')) ?></option><option value="Terminated"><?= e(t('emp_status_terminated')) ?></option></select>
								<input type="text" name="exit_reason" class="form-control" maxlength="255" placeholder="<?= e(t('emp_exit_reason')) ?>" required style="min-width:260px">
								<button type="submit" class="btn btn-danger"><?= e(t('emp_exit')) ?></button>
								<p class="help-block"><?= e(t('emp_exit_help')) ?></p>
							</form>
						<?php else: ?>
							<form id="rehire-form" class="hrm-ajax-form form-inline" method="post" data-reload="1" action="<?= site_url('employees/rehire/' . $id) ?>">
								<input type="text" name="rehire_date" class="form-control datepicker" placeholder="<?= e(t('emp_rehire_date')) ?>" autocomplete="off" required>
								<button type="submit" class="btn btn-success"><?= e(t('emp_rehire')) ?></button>
							</form>
						<?php endif; ?>
						</div>
					</div>
					<?php endif; ?>
				</div>

				<?php if ($can_salary): ?>
				<div class="tab-pane" id="tab-salary">
					<?php if ( ! $salaries): ?><p class="text-muted"><?= e(t('salary_none')) ?></p><?php else: ?>
					<div class="table-responsive"><table class="table table-bordered table-condensed" id="salary-table">
						<thead><tr><th><?= e(t('salary_effective_from')) ?></th><th><?= e(t('salary_effective_to')) ?></th><th class="text-money"><?= e(t('salary_basic')) ?></th>
							<th class="text-money"><?= e(t('salary_house_rent')) ?></th><th class="text-money"><?= e(t('salary_medical')) ?></th><th class="text-money"><?= e(t('salary_transport')) ?></th>
							<th class="text-money"><?= e(t('salary_other_allowances')) ?></th><th class="text-money"><?= e(t('salary_gross')) ?></th><th class="text-money"><?= e(t('salary_net')) ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ($salaries as $i => $s): ?>
							<tr<?= $s['effective_to'] === NULL ? ' class="success"' : '' ?>>
								<td><?= e(show_date($s['effective_from'])) ?></td>
								<td><?= $s['effective_to'] === NULL ? '<span class="label label-success">' . e(t('salary_current')) . '</span>' : e(show_date($s['effective_to'])) ?></td>
								<?php foreach (array('basic_salary', 'house_rent', 'medical_allowance', 'transport_allowance', 'other_allowances', 'gross_salary', 'net_salary') as $f): ?>
									<td class="text-money"><?= e(money_fmt($s[$f])) ?></td>
								<?php endforeach; ?>
								<td><?php if ($i === 0 && $can_edit): ?><button type="button" class="btn btn-xs btn-danger hrm-post" data-reload="1" data-confirm="<?= e(t('confirm_delete')) ?>"
									data-url="<?= site_url('employees/salary_delete/' . $id . '/' . (int) $s['id']) ?>"><i class="fa fa-trash"></i></button><?php endif; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table></div>
					<?php endif; ?>
					<?php if ($can_edit && $current): ?>
					<h4><?= e(t('salary_add')) ?></h4>
					<form id="salary-form" class="hrm-ajax-form" method="post" data-reload="1" action="<?= site_url('employees/salary_add/' . $id) ?>">
						<div class="row">
							<div class="col-sm-3 form-group"><label><?= e(t('salary_effective_from')) ?></label><input type="text" name="effective_from" class="form-control datepicker" autocomplete="off" required></div>
							<?php foreach (array('basic_salary' => 'salary_basic', 'house_rent' => 'salary_house_rent', 'medical_allowance' => 'salary_medical', 'transport_allowance' => 'salary_transport',
								'other_allowances' => 'salary_other_allowances', 'pf_deduction' => 'salary_pf', 'esi_deduction' => 'salary_esi', 'tax_deduction' => 'salary_tax', 'other_deductions' => 'salary_other_deductions') as $f => $l): ?>
								<div class="col-sm-3 form-group"><label><?= e(t($l)) ?></label><input type="number" name="<?= $f ?>" class="form-control salary-part" min="0" step="0.01" data-kind="<?= strpos($f, 'deduction') !== FALSE ? 'minus' : 'plus' ?>" <?= $f === 'basic_salary' ? 'required' : '' ?>></div>
							<?php endforeach; ?>
						</div>
						<p><strong><?= e(t('salary_gross')) ?>:</strong> <span id="salary-gross">0.00</span> &nbsp; <strong><?= e(t('salary_net')) ?>:</strong> <span id="salary-net">0.00</span></p>
						<p class="help-block"><?= e(t('salary_add_help')) ?></p>
						<button type="submit" class="btn btn-primary" id="salary-save"><?= e(t('save')) ?></button>
					</form>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<?php if ($shift_history !== NULL): ?>
				<div class="tab-pane" id="tab-shift">
					<table class="table table-condensed" id="shift-history">
						<thead><tr><th><?= e(t('shift_name')) ?></th><th><?= e(t('emp_valid_from')) ?></th><th><?= e(t('emp_valid_to')) ?></th></tr></thead>
						<tbody>
						<?php foreach ($shift_history as $h): ?>
							<tr<?= $h['effective_to'] === NULL ? ' class="success"' : '' ?>><td><?= e($h['shift_name'] . ' (' . substr($h['start_time'], 0, 5) . '-' . substr($h['end_time'], 0, 5) . ')') ?></td>
								<td><?= e(show_date($h['effective_from'])) ?></td><td><?= $h['effective_to'] === NULL ? e(t('salary_current')) : e(show_date($h['effective_to'])) ?></td></tr>
						<?php endforeach; ?>
						<?php if ( ! $shift_history): ?><tr><td colspan="3" class="text-muted"><?= e(t('shift_none')) ?></td></tr><?php endif; ?>
						</tbody>
					</table>
					<?php if (has_permission('rosters_manage') && $current): ?><a href="<?= site_url('employee_shifts/employee/' . $id) ?>" class="btn btn-default btn-sm"><?= e(t('shift_assign')) ?></a><?php endif; ?>
				</div>
				<?php endif; ?>

				<div class="tab-pane" id="tab-documents">
					<?php if ( ! $documents): ?><p class="text-muted"><?= e(t('emp_no_documents')) ?></p><?php else: ?>
					<table class="table table-condensed" id="documents-table">
						<thead><tr><th><?= e(t('emp_doc_title')) ?></th><th><?= e(t('emp_doc_expires')) ?></th><th><?= e(t('users_created')) ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ($documents as $d): $expired = $d['expires_on'] !== NULL && $d['expires_on'] < today_local(); ?>
							<tr<?= $expired ? ' class="danger"' : '' ?>>
								<td><a href="<?= site_url('employees/document/' . (int) $d['id']) ?>"><i class="fa fa-paperclip"></i> <?= e($d['title']) ?></a> <small class="text-muted"><?= e((string) $d['original_name']) ?></small></td>
								<td><?= e(show_date($d['expires_on'])) ?></td><td><?= e(show_date($d['created_at'])) ?></td>
								<td><?php if ($can_edit): ?><button type="button" class="btn btn-xs btn-danger hrm-post" data-reload="1" data-confirm="<?= e(t('confirm_delete')) ?>" data-url="<?= site_url('employees/document_delete/' . (int) $d['id']) ?>"><i class="fa fa-trash"></i></button><?php endif; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php endif; ?>
					<?php if ($can_edit): ?>
					<form id="document-form" class="hrm-ajax-form form-inline" method="post" enctype="multipart/form-data" data-reload="1" action="<?= site_url('employees/document_add/' . $id) ?>">
						<input type="text" name="title" class="form-control" maxlength="150" placeholder="<?= e(t('emp_doc_title')) ?>" required>
						<input type="text" name="expires_on" class="form-control datepicker" placeholder="<?= e(t('emp_doc_expires')) ?>" autocomplete="off">
						<input type="file" name="document" accept="application/pdf,image/png,image/jpeg" required style="display:inline-block">
						<button type="submit" class="btn btn-primary"><i class="fa fa-upload"></i> <?= e(t('emp_doc_upload')) ?></button>
						<p class="help-block"><?= e(t('emp_doc_file')) ?></p>
					</form>
					<?php endif; ?>
				</div>

				<div class="tab-pane" id="tab-pin">
					<p id="current-pin"><strong><?= e(t('emp_device_pin')) ?>:</strong>
						<?= $emp['device_pin'] !== NULL ? e($emp['device_pin']) . ' <small class="text-muted">(' . e(t('emp_valid_from')) . ' ' . e(show_date($emp['device_pin_from'])) . ')</small>' : e(t('emp_pin_none')) ?></p>
					<?php if ($can_edit && $current): ?>
					<form id="pin-form" class="hrm-ajax-form form-inline" method="post" data-reload="1" action="<?= site_url('employees/change_pin/' . $id) ?>">
						<input type="text" name="new_pin" class="form-control" maxlength="9" inputmode="numeric" placeholder="<?= e(t('emp_new_pin')) ?>">
						<input type="text" name="effective_from" class="form-control datepicker" autocomplete="off" placeholder="<?= e(t('emp_pin_effective')) ?>" value="<?= e(show_date(today_local())) ?>">
						<button type="submit" class="btn btn-primary"><?= e(t('emp_change_pin')) ?></button>
					</form>
					<?php endif; ?>
					<h4><?= e(t('emp_pin_history')) ?></h4>
					<table class="table table-condensed" id="pin-history">
						<thead><tr><th><?= e(t('emp_device_pin')) ?></th><th><?= e(t('emp_valid_from')) ?></th><th><?= e(t('emp_valid_to')) ?></th></tr></thead>
						<tbody><?php foreach ($pins as $p): ?><tr><td><?= e($p['device_pin']) ?></td><td><?= e(show_date($p['valid_from'])) ?></td><td><?= e(show_date($p['valid_to'])) ?></td></tr><?php endforeach; ?></tbody>
					</table>
				</div>

				<div class="tab-pane" id="tab-login">
					<?php if ($emp['user_id'] !== NULL): ?>
						<p id="linked-login"><i class="fa fa-user"></i> <?= e($emp['username']) ?>
						<?php if (has_permission('users_edit') && $can_edit): ?>
							<button type="button" class="btn btn-xs btn-default hrm-post" data-reload="1" data-url="<?= site_url('employees/unlink_login/' . $id) ?>"><?= e(t('emp_login_unlink')) ?></button>
						<?php endif; ?></p>
					<?php else: ?>
						<p class="text-muted"><?= e(t('emp_login_none')) ?></p>
						<?php if ($linkable): ?>
						<form id="link-form" class="hrm-ajax-form form-inline" method="post" data-reload="1" action="<?= site_url('employees/link_login/' . $id) ?>">
							<select name="user_id" class="form-control"><?php foreach ($linkable as $uid => $uname): ?><option value="<?= (int) $uid ?>"><?= e($uname) ?></option><?php endforeach; ?></select>
							<button type="submit" class="btn btn-primary"><?= e(t('emp_login_link')) ?></button>
						</form>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</section>
</div>
