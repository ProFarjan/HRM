<?php defined('BASEPATH') OR exit('No direct script access allowed'); $query = http_build_query(array('date' => show_date($date), 'department_id' => $department_id)); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('rep_daily')) ?> <small><?= e(show_date($date) . ' - ' . $user['branch_name']) ?></small></h1></section>
	<section class="content">
		<div class="box box-primary">
			<div class="box-header with-border no-print">
				<form class="form-inline" method="get" action="<?= site_url('reports/daily') ?>" id="daily-filter">
					<input type="text" name="date" class="form-control input-sm datepicker" value="<?= e(show_date($date)) ?>" style="width:105px" autocomplete="off">
					<select name="department_id" class="form-control input-sm"><option value=""><?= e(t('emp_department')) ?>: <?= e(t('all')) ?></option><?php foreach ($departments as $id => $name): ?><option value="<?= (int) $id ?>"<?= (int) $department_id === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select>
					<button type="submit" class="btn btn-default btn-sm"><?= e(t('rep_show')) ?></button>
					<button type="button" class="btn btn-default btn-sm" onclick="window.print()"><i class="fa fa-print"></i> <?= e(t('rep_print')) ?></button>
					<?php if (has_permission('export_hr')): ?><a class="btn btn-default btn-sm" id="daily-xlsx" href="<?= site_url('reports/daily_xlsx') . '?' . e($query) ?>"><i class="fa fa-file-excel-o"></i> <?= e(t('rep_excel')) ?></a><?php endif; ?>
				</form>
			</div>
			<div class="box-body">
				<p id="daily-totals">
					<?php foreach (array('Present', 'Half-day', 'Absent', 'Leave', 'Holiday', 'Weekly Off') as $s): ?>
						<?= Attendance_model::status_label($s) ?> <strong><?= (int) $totals[$s] ?></strong> &nbsp;
					<?php endforeach; ?>
					<span class="text-red"><?= e(t('rep_late_days')) ?> <strong><?= (int) $totals['late'] ?></strong></span> &nbsp; <?= e(t('rep_no_row')) ?> <strong><?= (int) $totals['none'] ?></strong>
				</p>
				<div class="table-responsive"><table class="table table-bordered table-condensed table-striped" id="daily-table">
					<thead><tr><th><?= e(t('emp_code')) ?></th><th><?= e(t('emp_name')) ?></th><th><?= e(t('emp_department')) ?></th><th><?= e(t('status')) ?></th><th><?= e(t('att_in')) ?></th><th><?= e(t('att_out')) ?></th>
						<th class="text-right"><?= e(t('att_hours')) ?></th><th class="text-right"><?= e(t('att_late')) ?></th><th class="text-right"><?= e(t('att_ot')) ?></th></tr></thead>
					<tbody>
					<?php foreach ($employees as $id => $e): $r = isset($rows[$id]) ? $rows[$id] : NULL; ?>
						<tr>
							<td><?= e($e['employee_code']) ?></td><td><?= e(trim($e['first_name'] . ' ' . $e['last_name'])) ?></td><td><?= e((string) $e['department_name']) ?></td>
							<td><?= $r ? Attendance_model::status_label($r['status']) . ($r['remarks'] ? ' <small class="text-muted">' . e($r['remarks']) . '</small>' : '') : '<span class="text-muted">' . e(t('rep_no_row')) . '</span>' ?></td>
							<td><?= $r ? e(substr((string) $r['check_in'], 0, 5)) : '' ?></td><td><?= $r ? e(substr((string) $r['check_out'], 0, 5)) : '' ?></td>
							<td class="text-right"><?= $r ? e(number_format((float) $r['working_hours'], 2)) : '' ?></td>
							<td class="text-right<?= $r && (int) $r['late_minutes'] ? ' text-red' : '' ?>"><?= $r ? (int) $r['late_minutes'] : '' ?></td>
							<td class="text-right"><?= $r ? e(number_format((float) $r['overtime_hours'], 2)) : '' ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			</div>
		</div>
	</section>
</div>
