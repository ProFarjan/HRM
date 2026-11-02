<?php defined('BASEPATH') OR exit('No direct script access allowed');
$link = function ($id) use ($p) { return site_url('reports/employee') . '?' . http_build_query(array('employee_id' => $id, 'mode' => 'range', 'from_date' => $p['from'], 'to_date' => $p['to'])); };
$detail = array();
foreach ($employees as $id => $e) {
    foreach ($stats['employees'][$id]['missing_rows'] as $r) {
        $detail[] = array($r['attendance_date'], $id, $r);
    }
}
usort($detail, function ($a, $b) { return strcmp($b[0], $a[0]) ?: $a[1] <=> $b[1]; });
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('rep_missing')) ?> <small><?= e(show_date($p['from']) . ' - ' . show_date($p['to']) . ' - ' . $user['branch_name']) ?></small></h1></section>
	<section class="content">
		<?php $this->load->view('reports/_nav', array('active' => 'missing')); ?>
		<div class="box box-primary no-print"><div class="box-header with-border"><?php $this->load->view('reports/_period', array('report' => 'missing', 'p' => $p, 'departments' => $departments)); ?></div></div>
		<?php if ($error !== NULL): ?><div class="alert alert-warning"><?= e($error) ?></div><?php endif; ?>
		<div class="callout callout-info"><p><?= e(t('rep_missing_help')) ?></p></div>
		<div class="box box-warning"><div class="box-body table-responsive"><table class="table table-bordered table-condensed table-striped" id="missing-table">
			<thead><tr><th><?= e(t('att_date')) ?></th><th><?= e(t('emp_code')) ?></th><th><?= e(t('emp_name')) ?></th><th><?= e(t('rep_shift')) ?></th><th><?= e(t('att_in')) ?></th><th><?= e(t('att_remarks')) ?></th><th class="no-print"></th></tr></thead>
			<tbody>
			<?php foreach ($detail as $row): list($d, $id, $r) = $row; $e = $employees[$id]; ?>
				<tr>
					<td><?= e(show_date($d) . ' ' . date('D', strtotime($d))) ?></td><td><?= e($e['employee_code']) ?></td><td><a href="<?= e($link($id)) ?>"><?= e(trim($e['first_name'] . ' ' . $e['last_name'])) ?></a></td>
					<td><?= e((string) $r['shift_name']) ?></td><td><?= e(substr((string) $r['check_in'], 0, 5)) ?></td><td><?= e((string) $r['remarks']) ?></td>
					<td class="no-print"><?php if ($can_edit && ! (int) $r['locked']): ?><a class="btn btn-xs btn-default" href="<?= site_url('attendance/edit/' . (int) $r['id']) ?>"><i class="fa fa-pencil"></i></a><?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $detail): ?><tr><td colspan="7" class="text-muted"><?= e(t('rep_nothing')) ?></td></tr><?php endif; ?>
			</tbody>
		</table></div></div>
	</section>
</div>
