<?php defined('BASEPATH') OR exit('No direct script access allowed');
$link = function ($id) use ($p) { return site_url('reports/employee') . '?' . http_build_query(array('employee_id' => $id, 'mode' => 'range', 'from_date' => $p['from'], 'to_date' => $p['to'])); };
$list = array();
foreach ($employees as $id => $e) {
    if ($stats['employees'][$id]['absent_dates']) {
        $list[$id] = $stats['employees'][$id];
    }
}
uasort($list, function ($a, $b) { return $b['absent'] <=> $a['absent'] ?: $b['max_streak'] <=> $a['max_streak']; });
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('rep_absent')) ?> <small><?= e(show_date($p['from']) . ' - ' . show_date($p['to']) . ' - ' . $user['branch_name']) ?></small></h1></section>
	<section class="content">
		<?php $this->load->view('reports/_nav', array('active' => 'absent')); ?>
		<div class="box box-primary no-print"><div class="box-header with-border"><?php $this->load->view('reports/_period', array('report' => 'absent', 'p' => $p, 'departments' => $departments)); ?></div></div>
		<?php if ($error !== NULL): ?><div class="alert alert-warning"><?= e($error) ?></div><?php endif; ?>
		<?php $this->load->view('reports/_cards', array('cards' => array(array('rep_absent_days', Attendance_analytics::plain($stats['totals']['absent']), $stats['totals']['absent'] > 0 ? 'red' : 'gray'), array('rep_people', count($list), 'orange'),
			array('rep_no_record', (int) $stats['totals']['no_record'], $stats['totals']['no_record'] > 0 ? 'yellow' : 'gray')))); ?>
		<?php $this->load->view('reports/_insights', array('insights' => array_values(array_filter($stats['insights'], function ($i) { return in_array($i['key'], array('ins_top_absent', 'ins_streak', 'ins_no_record'), TRUE); })))); ?>
		<div class="box box-danger"><div class="box-body table-responsive"><table class="table table-bordered table-condensed table-striped" id="absent-table">
			<thead><tr><th><?= e(t('emp_code')) ?></th><th><?= e(t('emp_name')) ?></th><th><?= e(t('emp_department')) ?></th><th class="text-right"><?= e(t('rep_absent_days')) ?></th><th class="text-right"><?= e(t('rep_longest_streak')) ?></th><th><?= e(t('rep_dates')) ?></th></tr></thead>
			<tbody>
			<?php foreach ($list as $id => $x): $e = $employees[$id]; ?>
				<tr>
					<td><?= e($e['employee_code']) ?></td><td><a href="<?= e($link($id)) ?>"><?= e(trim($e['first_name'] . ' ' . $e['last_name'])) ?></a></td><td><?= e((string) $e['department_name']) ?></td>
					<td class="text-right text-bold"><?= e(Attendance_analytics::plain($x['absent'])) ?></td>
					<td class="text-right<?= $x['max_streak'] >= 3 ? ' text-red text-bold' : '' ?>"><?= (int) $x['max_streak'] ?></td>
					<td><?php foreach ($x['absent_dates'] as $a): ?><span class="label label-<?= $a['half'] ? 'warning' : 'danger' ?>" style="display:inline-block;margin:1px" title="<?= e(date('l', strtotime($a['date']))) ?>"><?= e(show_date($a['date'])) ?><?= $a['half'] ? ' &frac12;' : '' ?></span> <?php endforeach; ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $list): ?><tr><td colspan="6" class="text-muted"><?= e(t('rep_nothing')) ?></td></tr><?php endif; ?>
			</tbody>
		</table></div></div>
	</section>
</div>
