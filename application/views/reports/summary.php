<?php defined('BASEPATH') OR exit('No direct script access allowed');
$t = $stats['totals'];
$link = function ($id) use ($p) { return site_url('reports/employee') . '?' . http_build_query(array('employee_id' => $id, 'mode' => 'range', 'from_date' => $p['from'], 'to_date' => $p['to'])); };
$chart = array('labels' => array(), 'present' => array(), 'absent' => array());
foreach ($stats['daily'] as $d => $x) {
    $chart['labels'][] = date('d D', strtotime($d));
    $chart['present'][] = $x['expected'] > 0 ? round($x['present'] / $x['expected'] * 100, 1) : NULL;
    $chart['absent'][] = $x['expected'] > 0 ? round($x['absent'] / $x['expected'] * 100, 1) : NULL;
}
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('rep_summary')) ?> <small><?= e(show_date($p['from']) . ' - ' . show_date($p['to']) . ' - ' . $user['branch_name']) ?></small></h1></section>
	<section class="content">
		<?php $this->load->view('reports/_nav', array('active' => 'summary')); ?>
		<div class="box box-primary no-print"><div class="box-header with-border"><?php $this->load->view('reports/_period', array('report' => 'summary', 'p' => $p, 'departments' => $departments)); ?></div></div>
		<?php if ($error !== NULL): ?><div class="alert alert-warning"><?= e($error) ?></div><?php endif; ?>
		<?php
		$cards = array(
			array('rep_attendance_pct', $t['pct'] === NULL ? '-' : number_format($t['pct'], 1) . '%', 'green'), array('rep_present', Attendance_analytics::plain($t['present']), 'green'),
			array('rep_absent_days', Attendance_analytics::plain($t['absent']), $t['absent'] > 0 ? 'red' : 'gray'), array('rep_leave', $t['leave'], 'aqua'),
			array('rep_late_days', $t['late_days'], $t['late_days'] > 0 ? 'yellow' : 'gray'), array('att_ot', number_format($t['ot'], 1), 'purple'),
		);
		$this->load->view('reports/_cards', array('cards' => $cards));
		$this->load->view('reports/_insights', array('insights' => $stats['insights']));
		?>
		<?php if (count($days) > 1): ?>
		<div class="box box-default no-print"><div class="box-header with-border"><h3 class="box-title"><?= e(t('rep_daily_trend')) ?></h3></div>
			<div class="box-body"><div style="height:200px"><canvas id="trend-chart" data-chart="<?= e(json_encode($chart)) ?>" data-present="<?= e(t('rep_present')) ?> %" data-absent="<?= e(t('rep_absent_days')) ?> %"></canvas></div></div></div>
		<?php endif; ?>
		<div class="box box-primary">
			<div class="box-body"><div class="table-responsive"><table class="table table-bordered table-condensed table-striped js-datatable" id="summary-table">
				<thead><tr><th><?= e(t('emp_code')) ?></th><th><?= e(t('emp_name')) ?></th><th><?= e(t('emp_department')) ?></th><th class="text-right"><?= e(t('rep_present')) ?></th><th class="text-right"><?= e(t('rep_absent_days')) ?></th><th class="text-right"><?= e(t('rep_leave')) ?></th>
					<th class="text-right"><?= e(t('rep_attendance_pct')) ?></th><th class="text-right"><?= e(t('rep_late_days')) ?></th><th class="text-right"><?= e(t('rep_late_minutes')) ?></th><th class="text-right"><?= e(t('att_hours')) ?></th><th class="text-right"><?= e(t('att_ot')) ?></th>
					<th><?= e(t('rep_avg_in')) ?></th><th><?= e(t('rep_avg_out')) ?></th><th class="text-right"><?= e(t('rep_missing_out')) ?></th></tr></thead>
				<tbody>
				<?php foreach ($employees as $id => $e): $x = $stats['employees'][$id]; ?>
					<tr>
						<td><?= e($e['employee_code']) ?></td><td><a href="<?= e($link($id)) ?>"><?= e(trim($e['first_name'] . ' ' . $e['last_name'])) ?></a></td><td><?= e((string) $e['department_name']) ?></td>
						<td class="text-right"><?= e(Attendance_analytics::plain($x['present'])) ?></td><td class="text-right<?= $x['absent'] > 0 ? ' text-red' : '' ?>"><?= e(Attendance_analytics::plain($x['absent'])) ?></td><td class="text-right"><?= (int) $x['leave'] ?></td>
						<td class="text-right" data-order="<?= $x['pct'] === NULL ? -1 : (float) $x['pct'] ?>"><?= $x['pct'] === NULL ? '-' : '<span class="label label-' . ($x['pct'] >= 90 ? 'success' : ($x['pct'] >= 75 ? 'warning' : 'danger')) . '">' . e(number_format($x['pct'], 1)) . '%</span>' ?></td>
						<td class="text-right<?= $late_limit > 0 && $x['late_days'] >= $late_limit ? ' text-red text-bold' : '' ?>"><?= (int) $x['late_days'] ?></td><td class="text-right"><?= (int) $x['late_minutes'] ?></td>
						<td class="text-right"><?= e(number_format($x['hours'], 1)) ?></td><td class="text-right"><?= e(number_format($x['ot'], 2)) ?></td>
						<td><?= e($x['avg_in']) ?></td><td><?= e($x['avg_out']) ?></td><td class="text-right<?= $x['missing_out'] ? ' text-yellow' : '' ?>"><?= (int) $x['missing_out'] ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div></div>
		</div>
	</section>
</div>
