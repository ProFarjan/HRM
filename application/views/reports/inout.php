<?php defined('BASEPATH') OR exit('No direct script access allowed');
$query = http_build_query(array('month' => $month, 'year' => $year, 'department_id' => $department_id));
$css = array('P' => 'att-p', 'A' => 'att-a', '½' => 'att-h2', 'L' => 'att-l', 'H' => 'att-hol', 'W' => 'att-w');
?>
<style media="print">@page { size: A4 landscape; margin: 8mm; }</style>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('rep_inout')) ?> <small><?= e(date('F Y', mktime(0, 0, 0, $month, 1, $year)) . ' - ' . $user['branch_name']) ?></small></h1></section>
	<section class="content">
		<?php $this->load->view('reports/_nav', array('active' => 'inout')); ?>
		<div class="box box-primary">
			<div class="box-header with-border no-print">
				<form class="form-inline" method="get" action="<?= site_url('reports/inout') ?>" id="inout-filter">
					<select name="month" class="form-control input-sm"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"<?= $m === (int) $month ? ' selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $m, 1, 2000))) ?></option><?php endfor; ?></select>
					<input type="number" name="year" class="form-control input-sm" value="<?= (int) $year ?>" style="width:90px">
					<select name="department_id" class="form-control input-sm"><option value=""><?= e(t('emp_department')) ?>: <?= e(t('all')) ?></option><?php foreach ($departments as $id => $dname): ?><option value="<?= (int) $id ?>"<?= (int) $department_id === (int) $id ? ' selected' : '' ?>><?= e($dname) ?></option><?php endforeach; ?></select>
					<button type="submit" class="btn btn-default btn-sm"><?= e(t('rep_show')) ?></button>
					<button type="button" class="btn btn-default btn-sm" onclick="window.print()"><i class="fa fa-print"></i> <?= e(t('rep_print')) ?></button>
					<?php if (has_permission('export_hr')): ?><a class="btn btn-default btn-sm" id="inout-xlsx" href="<?= site_url('reports/inout_xlsx') . '?' . e($query) ?>"><i class="fa fa-file-excel-o"></i> <?= e(t('rep_excel')) ?></a><?php endif; ?>
				</form>
			</div>
			<div class="box-body">
				<?php if ($error !== NULL): ?><div class="alert alert-warning"><?= e($error) ?></div><?php endif; ?>
				<p class="help-block"><?= e(t('rep_inout_legend')) ?></p>
				<div class="table-responsive"><table class="table table-bordered table-condensed monthly-grid inout-grid" id="inout-table">
					<thead><tr><th><?= e(t('emp_name')) ?></th>
						<?php foreach ($days as $d): ?><th class="text-center<?= date('N', strtotime($d)) == 5 ? ' att-w' : '' ?>"><?= (int) substr($d, 8, 2) ?><br><small><?= e(substr(date('D', strtotime($d)), 0, 2)) ?></small></th><?php endforeach; ?>
						<th><?= e(t('rep_present')) ?></th><th><?= e(t('rep_absent_days')) ?></th><th><?= e(t('rep_leave')) ?></th><th><?= e(t('rep_late_days')) ?></th><th><?= e(t('att_hours')) ?></th><th><?= e(t('att_ot')) ?></th></tr></thead>
					<tbody>
					<?php foreach ($employees as $id => $e): $x = $stats['employees'][$id]; ?>
						<tr>
							<th style="white-space:nowrap"><a href="<?= site_url('reports/employee') . '?' . e(http_build_query(array('employee_id' => $id, 'mode' => 'month', 'month' => $month, 'year' => $year))) ?>"><?= e(trim($e['first_name'] . ' ' . $e['last_name'])) ?></a><br><small class="text-muted"><?= e($e['employee_code']) ?></small></th>
							<?php foreach ($days as $d): $r = isset($grid[$id][$d]) ? $grid[$id][$d] : NULL; $l = $r ? Attendance_reports_model::letter($r['status']) : ''; $worked = $r && in_array($r['status'], array('Present', 'Half-day'), TRUE); ?>
								<?php if ($worked): $late = (int) $r['late_minutes'] > 0; ?>
									<td class="text-center io-cell<?= $late ? ' att-late' : '' ?><?= $r['status'] === 'Half-day' ? ' att-h2' : '' ?>" title="<?= e($r['status'] . ($late ? ' - ' . sprintf(t('rep_late_by'), (int) $r['late_minutes']) : '')) ?>">
										<span class="io-in<?= $late ? ' text-red' : '' ?>"><?= e(substr((string) $r['check_in'], 0, 5)) ?></span><br><span class="io-out<?= $r['check_out'] === NULL ? ' io-missing' : '' ?>"><?= $r['check_out'] !== NULL ? e(substr($r['check_out'], 0, 5)) : '?' ?></span>
									</td>
								<?php else: ?>
									<td class="text-center <?= $l !== '' ? $css[$l] : '' ?>"><?= e($l) ?></td>
								<?php endif; ?>
							<?php endforeach; ?>
							<td><?= e(Attendance_analytics::plain($x['present'])) ?></td><td><?= e(Attendance_analytics::plain($x['absent'])) ?></td><td><?= (int) $x['leave'] ?></td>
							<td class="<?= $late_limit > 0 && $x['late_days'] >= $late_limit ? 'text-red text-bold' : '' ?>"><?= (int) $x['late_days'] ?></td><td><?= e(number_format($x['hours'], 1)) ?></td><td><?= e(number_format($x['ot'], 2)) ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			</div>
		</div>
	</section>
</div>
