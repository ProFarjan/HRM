<?php defined('BASEPATH') OR exit('No direct script access allowed');
$link = function ($id) use ($p) { return site_url('reports/employee') . '?' . http_build_query(array('employee_id' => $id, 'mode' => 'range', 'from_date' => $p['from'], 'to_date' => $p['to'])); };
$rank = array();
$detail = array();
foreach ($employees as $id => $e) {
    $x = $stats['employees'][$id];
    if ($x['ot_rows']) {
        $rank[$id] = $x;
        foreach ($x['ot_rows'] as $r) {
            $detail[] = array($r['attendance_date'], $id, $r);
        }
    }
}
uasort($rank, function ($a, $b) { return $b['ot'] <=> $a['ot']; });
usort($detail, function ($a, $b) { return strcmp($b[0], $a[0]) ?: $a[1] <=> $b[1]; });
$shown = array_slice($detail, 0, 2000);
$max_ot = $rank ? max(array_column($rank, 'ot')) : 0;
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('rep_overtime')) ?> <small><?= e(show_date($p['from']) . ' - ' . show_date($p['to']) . ' - ' . $user['branch_name']) ?></small></h1></section>
	<section class="content">
		<?php $this->load->view('reports/_nav', array('active' => 'overtime')); ?>
		<div class="box box-primary no-print"><div class="box-header with-border"><?php $this->load->view('reports/_period', array('report' => 'overtime', 'p' => $p, 'departments' => $departments)); ?></div></div>
		<?php if ($error !== NULL): ?><div class="alert alert-warning"><?= e($error) ?></div><?php endif; ?>
		<?php $this->load->view('reports/_cards', array('cards' => array(array('att_ot', number_format($stats['totals']['ot'], 2), 'purple'), array('rep_people', count($rank), 'orange'), array('rep_days', count($detail), 'blue')))); ?>
		<div class="row">
			<div class="col-md-5"><div class="box box-info"><div class="box-header with-border"><h3 class="box-title"><?= e(t('rep_ot_ranking')) ?></h3></div>
				<div class="box-body table-responsive"><table class="table table-condensed table-striped" id="ot-ranking">
					<thead><tr><th><?= e(t('emp_name')) ?></th><th class="text-right"><?= e(t('rep_days')) ?></th><th style="width:40%"><?= e(t('att_ot')) ?></th></tr></thead>
					<tbody>
					<?php foreach ($rank as $id => $x): ?>
						<tr><td><a href="<?= e($link($id)) ?>"><?= e(trim($employees[$id]['first_name'] . ' ' . $employees[$id]['last_name'])) ?></a> <small class="text-muted"><?= e($employees[$id]['employee_code']) ?></small></td><td class="text-right"><?= (int) $x['ot_days'] ?></td>
							<td><div class="progress" style="margin:0;height:16px"><div class="progress-bar progress-bar-info" style="width:<?= $max_ot > 0 ? round($x['ot'] / $max_ot * 100) : 0 ?>%"><?= e(number_format($x['ot'], 2)) ?></div></div></td></tr>
					<?php endforeach; ?>
					<?php if ( ! $rank): ?><tr><td colspan="3" class="text-muted"><?= e(t('rep_nothing')) ?></td></tr><?php endif; ?>
					</tbody>
				</table></div></div></div>
			<div class="col-md-7"><div class="box box-default"><div class="box-header with-border"><h3 class="box-title"><?= e(t('rep_ot_detail')) ?></h3></div>
				<div class="box-body table-responsive"><table class="table table-condensed table-striped" id="ot-detail">
					<thead><tr><th><?= e(t('att_date')) ?></th><th><?= e(t('emp_name')) ?></th><th><?= e(t('att_in')) ?></th><th><?= e(t('att_out')) ?></th><th class="text-right"><?= e(t('att_hours')) ?></th><th class="text-right"><?= e(t('att_ot')) ?></th></tr></thead>
					<tbody>
					<?php foreach ($shown as $row): list($d, $id, $r) = $row; ?>
						<tr><td><?= e(show_date($d) . ' ' . date('D', strtotime($d))) ?></td><td><?= e(trim($employees[$id]['first_name'] . ' ' . $employees[$id]['last_name'])) ?></td><td><?= e(substr((string) $r['check_in'], 0, 5)) ?></td><td><?= e(substr((string) $r['check_out'], 0, 5)) ?></td>
							<td class="text-right"><?= e(number_format((float) $r['working_hours'], 2)) ?></td><td class="text-right text-bold"><?= e(number_format((float) $r['overtime_hours'], 2)) ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if (count($detail) > count($shown)): ?><p class="text-muted"><?= e(sprintf(t('rep_first_rows'), count($shown), count($detail))) ?></p><?php endif; ?></div></div></div>
		</div>
	</section>
</div>
