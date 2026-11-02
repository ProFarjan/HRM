<?php defined('BASEPATH') OR exit('No direct script access allowed');
$e = $report ? $report['employee'] : NULL;
$s = $report ? $report['stats'] : NULL;
$query = http_build_query(array_filter(array('employee_id' => $p['employee_id'], 'mode' => $p['mode'], 'month' => $p['month'], 'year' => $p['year'], 'from_date' => $p['from'], 'to_date' => $p['to']), function ($v) { return $v !== NULL && $v !== ''; }));
$row_class = array('Present' => '', 'Half-day' => 'warning', 'Absent' => 'danger', 'Leave' => 'info', 'Holiday' => 'holiday', 'Weekly Off' => 'weekoff');
?>
<style media="print">@page { size: A4 portrait; margin: 10mm; }</style>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('rep_employee')) ?> <small><?= e($e ? $e['employee_code'] . ' - ' . trim($e['first_name'] . ' ' . $e['last_name']) : $user['branch_name']) ?></small></h1></section>
	<section class="content">
		<?php $this->load->view('reports/_nav', array('active' => 'employee')); ?>
		<div class="box box-primary">
			<div class="box-header with-border no-print">
				<form class="form-inline" method="get" action="<?= site_url('reports/employee') ?>" id="employee-filter">
					<select name="employee_id" id="r_employee" class="form-control input-sm select2" style="width:260px"><option value=""><?= e(t('rep_choose_employee')) ?></option><?php foreach ($employees as $id => $label): ?><option value="<?= (int) $id ?>"<?= (int) $p['employee_id'] === (int) $id ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
					<select name="mode" id="r_mode" class="form-control input-sm"><option value="month"<?= $p['mode'] === 'month' ? ' selected' : '' ?>><?= e(t('rep_by_month')) ?></option><option value="range"<?= $p['mode'] === 'range' ? ' selected' : '' ?>><?= e(t('rep_by_range')) ?></option></select>
					<span class="mode-month">
						<select name="month" class="form-control input-sm"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"<?= $m === (int) $p['month'] ? ' selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $m, 1, 2000))) ?></option><?php endfor; ?></select>
						<input type="number" name="year" class="form-control input-sm" value="<?= (int) $p['year'] ?>" style="width:90px">
					</span>
					<span class="mode-range">
						<input type="text" name="from_date" class="form-control input-sm datepicker" value="<?= e(show_date($p['from'])) ?>" style="width:105px" autocomplete="off">
						<input type="text" name="to_date" class="form-control input-sm datepicker" value="<?= e(show_date($p['to'])) ?>" style="width:105px" autocomplete="off">
					</span>
					<button type="submit" class="btn btn-default btn-sm"><?= e(t('rep_show')) ?></button>
					<?php if ($report): ?>
						<button type="button" class="btn btn-default btn-sm" onclick="window.print()"><i class="fa fa-print"></i> <?= e(t('rep_print')) ?></button>
						<?php if (has_permission('export_hr')): ?><a class="btn btn-default btn-sm" id="employee-xlsx" href="<?= site_url('reports/employee_xlsx') . '?' . e($query) ?>"><i class="fa fa-file-excel-o"></i> <?= e(t('rep_excel')) ?></a><?php endif; ?>
					<?php endif; ?>
				</form>
			</div>
			<div class="box-body">
				<?php if ($error !== NULL): ?><div class="alert alert-warning"><?= e($error) ?></div><?php endif; ?>
				<?php if ($p['employee_id'] !== NULL && $error === NULL && ! $report): ?><div class="alert alert-warning"><?= e(t('not_found')) ?></div><?php endif; ?>
				<?php if ( ! $report && $error === NULL && $p['employee_id'] === NULL): ?><p class="text-muted"><?= e(t('rep_choose_employee_help')) ?></p><?php endif; ?>
				<?php if ($report): ?>
					<p>
						<strong><?= e(trim($e['first_name'] . ' ' . $e['last_name'])) ?></strong> (<?= e($e['employee_code']) ?>)
						<?= $e['department_name'] ? ' &middot; ' . e($e['department_name']) : '' ?><?= $e['designation_name'] ? ' &middot; ' . e($e['designation_name']) : '' ?>
						&middot; <?= e(t('emp_device_pin')) ?>: <?= e((string) $e['device_pin'] !== '' ? $e['device_pin'] : '-') ?>
						&middot; <?= e(show_date($p['from']) . ' - ' . show_date($p['to'])) ?>
					</p>
					<?php
					$cards = array(
						array('rep_present', Attendance_analytics::plain($s['present']), 'green'), array('rep_absent_days', Attendance_analytics::plain($s['absent']), $s['absent'] > 0 ? 'red' : 'gray'),
						array('rep_leave', $s['leave'], 'aqua'), array('rep_late_days', $s['late_days'], $s['late_days'] > 0 ? 'yellow' : 'gray'),
						array('att_hours', number_format($s['hours'], 1), 'blue'), array('att_ot', number_format($s['ot'], 2), 'purple'),
						array('rep_avg_in', $s['avg_in'] !== '' ? $s['avg_in'] : '-', 'teal'), array('rep_avg_out', $s['avg_out'] !== '' ? $s['avg_out'] : '-', 'teal'),
						array('rep_attendance_pct', $s['pct'] === NULL ? '-' : number_format($s['pct'], 1) . '%', 'green'),
					);
					$this->load->view('reports/_cards', array('cards' => $cards));
					$this->load->view('reports/_insights', array('insights' => $report['insights']));
					?>
					<div class="table-responsive"><table class="table table-bordered table-condensed" id="employee-table">
						<thead><tr><th><?= e(t('att_date')) ?></th><th><?= e(t('rep_weekday')) ?></th><th><?= e(t('rep_shift')) ?></th><th><?= e(t('status')) ?></th><th><?= e(t('att_in')) ?></th><th><?= e(t('att_out')) ?></th>
							<th class="text-right"><?= e(t('att_break')) ?></th><th class="text-right"><?= e(t('att_hours')) ?></th><th class="text-right"><?= e(t('att_late')) ?></th><th class="text-right"><?= e(t('att_early')) ?></th><th class="text-right"><?= e(t('att_ot')) ?></th><th><?= e(t('rep_punches')) ?></th></tr></thead>
						<tbody>
						<?php foreach ($report['days'] as $d => $day): $r = $day['row']; $plan = $day['plan']; $off = ! $r && $plan && $plan['employed']; ?>
							<tr class="<?= $r ? $row_class[$r['status']] : ($off && $plan['day_type'] === 'holiday' ? 'holiday' : ($off && $plan['day_type'] === 'weekly_off' ? 'weekoff' : '')) ?>">
								<td><?= e(show_date($d)) ?></td><td><?= e(date('D', strtotime($d))) ?></td>
								<td><?= $r && $r['shift_name'] ? e($r['shift_name']) . ' <small class="text-muted">' . e(substr($r['shift_start'], 0, 5) . '-' . substr($r['shift_end'], 0, 5)) . '</small>' : '' ?></td>
								<td>
									<?php if ($r): ?>
										<?= Attendance_model::status_label($r['status']) ?><?= $r['approval_status'] !== 'Approved' ? ' <small class="text-muted">' . e(t('att_approval_' . strtolower($r['approval_status']))) . '</small>' : '' ?>
										<?= $r['remarks'] ? ' <small class="text-muted">' . e($r['remarks']) . '</small>' : '' ?>
									<?php elseif ($plan && ! $plan['employed']): ?><span class="text-muted"><?= e(t('rep_not_employed')) ?></span>
									<?php elseif ($plan && $plan['day_type'] === 'holiday'): ?><?= Attendance_model::status_label('Holiday') ?> <small class="text-muted"><?= e((string) $plan['holiday_name']) ?></small>
									<?php elseif ($plan && $plan['day_type'] === 'weekly_off'): ?><?= Attendance_model::status_label('Weekly Off') ?>
									<?php else: ?><span class="text-muted"><?= e($d >= today_local() ? t('rep_upcoming') : t('rep_no_row')) ?></span><?php endif; ?>
								</td>
								<td><?= $r ? e(substr((string) $r['check_in'], 0, 5)) : '' ?></td>
								<td><?= $r && in_array($r['status'], array('Present', 'Half-day'), TRUE) && $r['check_out'] === NULL && $r['check_in'] !== NULL ? '<span class="text-yellow">?</span>' : ($r ? e(substr((string) $r['check_out'], 0, 5)) : '') ?></td>
								<td class="text-right"><?= $r && (int) $r['break_minutes'] ? (int) $r['break_minutes'] : '' ?></td>
								<td class="text-right"><?= $r && (float) $r['working_hours'] > 0 ? e(number_format((float) $r['working_hours'], 2)) : '' ?></td>
								<td class="text-right<?= $r && (int) $r['late_minutes'] ? ' text-red' : '' ?>"><?= $r && (int) $r['late_minutes'] ? (int) $r['late_minutes'] : '' ?></td>
								<td class="text-right"><?= $r && (int) $r['early_leave_minutes'] ? (int) $r['early_leave_minutes'] : '' ?></td>
								<td class="text-right"><?= $r && (float) $r['overtime_hours'] > 0 ? e(number_format((float) $r['overtime_hours'], 2)) : '' ?></td>
								<td class="punch-times"><?php foreach ($day['punches'] as $pt): ?><span class="<?= (int) $pt['ignored'] ? 'text-muted' : '' ?>" title="<?= e($pt['source'] . ((int) $pt['ignored'] ? ' - ' . t('att_ignored') : '')) ?>"><?= (int) $pt['ignored'] ? '<s>' . e(substr($pt['punch_at'], 11, 5)) . '</s>' : e(substr($pt['punch_at'], 11, 5)) ?></span> <?php endforeach; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
						<tfoot><tr><th colspan="7"><?= e(t('rep_total')) ?></th><th class="text-right"><?= e(number_format($s['hours'], 2)) ?></th><th class="text-right"><?= (int) $s['late_minutes'] ?></th><th class="text-right"><?= (int) $s['early_minutes'] ?></th><th class="text-right"><?= e(number_format($s['ot'], 2)) ?></th><th></th></tr></tfoot>
					</table></div>
				<?php endif; ?>
			</div>
		</div>
	</section>
</div>
