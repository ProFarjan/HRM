<?php defined('BASEPATH') OR exit('No direct script access allowed');
$query = http_build_query(array('month' => $month, 'year' => $year, 'department_id' => $department_id));
$css = array('P' => 'att-p', 'A' => 'att-a', '½' => 'att-h2', 'L' => 'att-l', 'H' => 'att-hol', 'W' => 'att-w');
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('rep_monthly')) ?> <small><?= e(date('F Y', mktime(0, 0, 0, $month, 1, $year)) . ' - ' . $user['branch_name']) ?></small></h1></section>
	<section class="content">
		<?php $this->load->view('reports/_nav', array('active' => 'monthly')); ?>
		<div class="box box-primary">
			<div class="box-header with-border no-print">
				<form class="form-inline" method="get" action="<?= site_url('reports/monthly') ?>" id="monthly-filter">
					<select name="month" class="form-control input-sm"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"<?= $m === (int) $month ? ' selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $m, 1, 2000))) ?></option><?php endfor; ?></select>
					<input type="number" name="year" class="form-control input-sm" value="<?= (int) $year ?>" style="width:90px">
					<select name="department_id" class="form-control input-sm"><option value=""><?= e(t('emp_department')) ?>: <?= e(t('all')) ?></option><?php foreach ($departments as $id => $name): ?><option value="<?= (int) $id ?>"<?= (int) $department_id === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select>
					<button type="submit" class="btn btn-default btn-sm"><?= e(t('rep_show')) ?></button>
					<button type="button" class="btn btn-default btn-sm" onclick="window.print()"><i class="fa fa-print"></i> <?= e(t('rep_print')) ?></button>
					<?php if (has_permission('export_hr')): ?><a class="btn btn-default btn-sm" id="monthly-xlsx" href="<?= site_url('reports/monthly_xlsx') . '?' . e($query) ?>"><i class="fa fa-file-excel-o"></i> <?= e(t('rep_excel')) ?></a><?php endif; ?>
				</form>
			</div>
			<div class="box-body">
				<p class="help-block"><?= e(t('rep_legend')) ?></p>
				<div class="table-responsive"><table class="table table-bordered table-condensed monthly-grid" id="monthly-table">
					<thead><tr><th><?= e(t('emp_name')) ?></th>
						<?php foreach ($days as $d): ?><th class="text-center"><?= (int) substr($d, 8, 2) ?><br><small><?= e(substr(date('D', strtotime($d)), 0, 2)) ?></small></th><?php endforeach; ?>
						<th>P</th><th>A</th><th>L</th><th>H</th><th>W</th><th><?= e(t('rep_late_days')) ?></th><th><?= e(t('att_ot')) ?></th></tr></thead>
					<tbody>
					<?php foreach ($employees as $id => $e): $tot = $totals[$id]; ?>
						<tr>
							<th style="white-space:nowrap"><?= e(trim($e['first_name'] . ' ' . $e['last_name'])) ?><br><small class="text-muted"><?= e($e['employee_code']) ?></small></th>
							<?php foreach ($days as $d): $r = isset($grid[$id][$d]) ? $grid[$id][$d] : NULL; $l = $r ? Attendance_reports_model::letter($r['status']) : ''; ?>
								<td class="text-center <?= $l !== '' ? $css[$l] : '' ?><?= $r && (int) $r['late_minutes'] > 0 ? ' att-late' : '' ?>" title="<?= $r ? e(trim(substr((string) $r['check_in'], 0, 5) . ' ' . substr((string) $r['check_out'], 0, 5))) : '' ?>"><?= e($l) ?></td>
							<?php endforeach; ?>
							<td><?= e(rtrim(rtrim(number_format($tot['P'], 1), '0'), '.')) ?></td><td><?= e(rtrim(rtrim(number_format($tot['A'], 1), '0'), '.')) ?></td><td><?= (int) $tot['L'] ?></td><td><?= (int) $tot['H'] ?></td><td><?= (int) $tot['W'] ?></td>
							<td class="<?= $late_limit > 0 && $tot['late'] >= $late_limit ? 'text-red text-bold' : '' ?>"><?= (int) $tot['late'] ?></td><td><?= e(number_format($tot['ot'], 2)) ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			</div>
		</div>
	</section>
</div>
