<?php defined('BASEPATH') OR exit('No direct script access allowed');
// filter of a period report: $report (key), $p (from, to, department_id), $departments; optional $min_minutes
$query = http_build_query(array_filter(array('from_date' => $p['from'], 'to_date' => $p['to'], 'department_id' => $p['department_id'], 'min_minutes' => isset($min) ? $min : NULL), function ($v) { return $v !== NULL && $v !== ''; }));
?>
<form class="form-inline" method="get" action="<?= site_url('reports/' . $report) ?>" id="<?= e($report) ?>-filter">
	<input type="text" name="from_date" class="form-control input-sm datepicker" value="<?= e(show_date($p['from'])) ?>" style="width:105px" autocomplete="off">
	<input type="text" name="to_date" class="form-control input-sm datepicker" value="<?= e(show_date($p['to'])) ?>" style="width:105px" autocomplete="off">
	<select name="department_id" class="form-control input-sm"><option value=""><?= e(t('emp_department')) ?>: <?= e(t('all')) ?></option><?php foreach ($departments as $id => $dname): ?><option value="<?= (int) $id ?>"<?= (int) $p['department_id'] === (int) $id ? ' selected' : '' ?>><?= e($dname) ?></option><?php endforeach; ?></select>
	<?php if (isset($min)): ?><label class="text-muted" style="font-weight:normal"><?= e(t('rep_min_late')) ?> <input type="number" name="min_minutes" class="form-control input-sm" min="1" max="600" value="<?= (int) $min ?>" style="width:70px"></label><?php endif; ?>
	<button type="submit" class="btn btn-default btn-sm"><?= e(t('rep_show')) ?></button>
	<button type="button" class="btn btn-default btn-sm" onclick="window.print()"><i class="fa fa-print"></i> <?= e(t('rep_print')) ?></button>
	<?php if (has_permission('export_hr')): ?><a class="btn btn-default btn-sm" id="<?= e($report) ?>-xlsx" href="<?= site_url('reports/' . $report . '_xlsx') . '?' . e($query) ?>"><i class="fa fa-file-excel-o"></i> <?= e(t('rep_excel')) ?></a><?php endif; ?>
</form>
