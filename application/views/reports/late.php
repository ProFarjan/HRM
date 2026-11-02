<?php defined('BASEPATH') OR exit('No direct script access allowed');
$link = function ($id) use ($p) { return site_url('reports/employee') . '?' . http_build_query(array('employee_id' => $id, 'mode' => 'range', 'from_date' => $p['from'], 'to_date' => $p['to'])); };
$rank = array();
$detail = array();
foreach ($employees as $id => $e) {
    $n = 0; $sum = 0; $max = 0;
    foreach ($stats['employees'][$id]['late_rows'] as $r) {
        if ((int) $r['late_minutes'] >= $min) {
            $n++; $sum += (int) $r['late_minutes']; $max = max($max, (int) $r['late_minutes']);
            $detail[] = array($r['attendance_date'], $id, $r);
        }
    }
    if ($n > 0) {
        $rank[$id] = array('n' => $n, 'sum' => $sum, 'max' => $max);
    }
}
uasort($rank, function ($a, $b) { return $b['n'] <=> $a['n'] ?: $b['sum'] <=> $a['sum']; });
usort($detail, function ($a, $b) { return strcmp($b[0], $a[0]) ?: $a[1] <=> $b[1]; });
$shown = array_slice($detail, 0, 2000);
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('rep_late')) ?> <small><?= e(show_date($p['from']) . ' - ' . show_date($p['to']) . ' - ' . $user['branch_name']) ?></small></h1></section>
	<section class="content">
		<?php $this->load->view('reports/_nav', array('active' => 'late')); ?>
		<div class="box box-primary no-print"><div class="box-header with-border"><?php $this->load->view('reports/_period', array('report' => 'late', 'p' => $p, 'departments' => $departments, 'min' => $min)); ?></div></div>
		<?php if ($error !== NULL): ?><div class="alert alert-warning"><?= e($error) ?></div><?php endif; ?>
		<?php $this->load->view('reports/_cards', array('cards' => array(array('rep_late_days', count($detail), count($detail) ? 'yellow' : 'gray'), array('rep_people', count($rank), 'orange'),
			array('rep_late_minutes', array_sum(array_column($rank, 'sum')), 'red')))); ?>
		<div class="row">
			<div class="col-md-5"><div class="box box-warning"><div class="box-header with-border"><h3 class="box-title"><?= e(t('rep_late_ranking')) ?></h3></div>
				<div class="box-body table-responsive"><table class="table table-condensed table-striped" id="late-ranking">
					<thead><tr><th><?= e(t('emp_name')) ?></th><th class="text-right"><?= e(t('rep_late_days')) ?></th><th class="text-right"><?= e(t('rep_late_minutes')) ?></th><th class="text-right"><?= e(t('rep_avg')) ?></th><th class="text-right"><?= e(t('rep_max')) ?></th></tr></thead>
					<tbody>
					<?php foreach ($rank as $id => $x): ?>
						<tr><td><a href="<?= e($link($id)) ?>"><?= e(trim($employees[$id]['first_name'] . ' ' . $employees[$id]['last_name'])) ?></a> <small class="text-muted"><?= e($employees[$id]['employee_code']) ?></small></td>
							<td class="text-right<?= $late_limit > 0 && $x['n'] >= $late_limit ? ' text-red text-bold' : '' ?>"><?= (int) $x['n'] ?></td><td class="text-right"><?= (int) $x['sum'] ?></td><td class="text-right"><?= (int) round($x['sum'] / $x['n']) ?></td><td class="text-right"><?= (int) $x['max'] ?></td></tr>
					<?php endforeach; ?>
					<?php if ( ! $rank): ?><tr><td colspan="5" class="text-muted"><?= e(t('rep_nothing')) ?></td></tr><?php endif; ?>
					</tbody>
				</table></div></div></div>
			<div class="col-md-7"><div class="box box-default"><div class="box-header with-border"><h3 class="box-title"><?= e(t('rep_late_detail')) ?></h3></div>
				<div class="box-body table-responsive"><table class="table table-condensed table-striped" id="late-detail">
					<thead><tr><th><?= e(t('att_date')) ?></th><th><?= e(t('emp_name')) ?></th><th><?= e(t('rep_shift_start')) ?></th><th><?= e(t('att_in')) ?></th><th class="text-right"><?= e(t('att_late')) ?></th></tr></thead>
					<tbody>
					<?php foreach ($shown as $row): list($d, $id, $r) = $row; ?>
						<tr><td><?= e(show_date($d) . ' ' . date('D', strtotime($d))) ?></td><td><?= e(trim($employees[$id]['first_name'] . ' ' . $employees[$id]['last_name'])) ?></td><td><?= e(substr((string) $r['shift_start'], 0, 5)) ?></td><td><?= e(substr((string) $r['check_in'], 0, 5)) ?></td><td class="text-right text-red"><?= (int) $r['late_minutes'] ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if (count($detail) > count($shown)): ?><p class="text-muted"><?= e(sprintf(t('rep_first_rows'), count($shown), count($detail))) ?></p><?php endif; ?></div></div></div>
		</div>
	</section>
</div>
