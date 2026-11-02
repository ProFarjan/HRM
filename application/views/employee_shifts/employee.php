<?php defined('BASEPATH') OR exit('No direct script access allowed'); $id = (int) $emp['id']; ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('shift_assignments')) ?> <small><?= e($emp['employee_code'] . ' - ' . trim($emp['first_name'] . ' ' . $emp['last_name'])) ?></small></h1></section>
	<section class="content">
		<p><a href="<?= site_url('employee_shifts') ?>" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i> <?= e(t('emp_back_to_list')) ?></a>
			<?php if (has_permission('employees_view')): ?><a href="<?= site_url('employees/view/' . $id) ?>" class="btn btn-default btn-sm"><?= e(t('emp_profile')) ?></a><?php endif; ?></p>
		<div class="row">
			<div class="col-md-7"><div class="box box-primary"><div class="box-body">
				<table class="table table-condensed" id="assign-history">
					<thead><tr><th><?= e(t('shift_name')) ?></th><th><?= e(t('emp_valid_from')) ?></th><th><?= e(t('emp_valid_to')) ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ($history as $i => $h): ?>
						<tr<?= $h['effective_to'] === NULL ? ' class="success"' : '' ?>>
							<td><?= e($h['shift_name'] . ' (' . substr($h['start_time'], 0, 5) . '-' . substr($h['end_time'], 0, 5) . ')') ?></td>
							<td><?= e(show_date($h['effective_from'])) ?></td>
							<td><?= $h['effective_to'] === NULL ? '<span class="label label-success">' . e(t('salary_current')) . '</span>' : e(show_date($h['effective_to'])) ?></td>
							<td><?php if ($i === 0 && has_permission('rosters_manage')): ?><button type="button" class="btn btn-xs btn-danger hrm-post" data-reload="1" data-confirm="<?= e(t('confirm_delete')) ?>" data-url="<?= site_url('employee_shifts/remove/' . $id . '/' . (int) $h['id']) ?>"><i class="fa fa-trash"></i></button><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $history): ?><tr><td colspan="4" class="text-muted"><?= e(t('shift_none')) ?></td></tr><?php endif; ?>
					</tbody>
				</table>
			</div></div></div>
			<?php if (has_permission('rosters_manage')): ?>
			<div class="col-md-5"><div class="box box-success">
				<div class="box-header with-border"><h3 class="box-title"><?= e(t('shift_assign')) ?></h3></div>
				<form id="assign-form" class="hrm-ajax-form" method="post" data-reload="1" action="<?= site_url('employee_shifts/assign/' . $id) ?>">
					<div class="box-body">
						<div class="form-group"><select name="shift_id" class="form-control" required><?php foreach ($shifts as $sid => $name): ?><option value="<?= (int) $sid ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
						<div class="form-group"><input type="text" name="effective_from" class="form-control datepicker" autocomplete="off" required placeholder="<?= e(t('shift_since')) ?>" value="<?= e(show_date(today_local())) ?>"></div>
						<p class="help-block"><?= e(t('shift_assign_help')) ?></p>
					</div>
					<div class="box-footer"><button type="submit" class="btn btn-success"><?= e(t('shift_assign')) ?></button></div>
				</form>
			</div></div>
			<?php endif; ?>
		</div>
	</section>
</div>
