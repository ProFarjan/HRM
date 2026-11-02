<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('menu_rosters')) ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<div class="box box-primary">
			<div class="box-header with-border">
				<form class="form-inline" id="roster-filter" data-url="<?= site_url('rosters/grid') ?>">
					<select name="department_id" class="form-control"><option value=""><?= e(t('emp_department')) ?>: <?= e(t('all')) ?></option><?php foreach ($departments as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
					<select name="view" class="form-control"><option value="week"><?= e(t('roster_week')) ?></option><option value="month"><?= e(t('roster_month')) ?></option></select>
					<input type="text" name="from_date" class="form-control datepicker" autocomplete="off" value="<?= e($week_start) ?>" style="width:120px">
					<button type="submit" class="btn btn-default" id="roster-load"><i class="fa fa-refresh"></i> <?= e(t('roster_load')) ?></button>
				</form>
			</div>
			<div class="box-body">
				<p class="help-block"><?= e(t('roster_legend')) ?></p>
				<div class="table-responsive" style="max-height:65vh;overflow:auto">
					<table class="table table-bordered table-condensed roster-grid" id="roster-grid" data-off="<?= e(t('roster_off')) ?>"><thead></thead><tbody></tbody></table>
				</div>
				<select id="roster-shift-options" style="display:none"><option value=""></option><option value="OFF"><?= e(t('roster_off')) ?></option><?php foreach ($shifts as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
			</div>
			<div class="box-footer"><button type="button" class="btn btn-primary" id="roster-save" data-url="<?= site_url('rosters/save') ?>" data-message="<?= e(t('roster_saved')) ?>"><?= e(t('roster_save')) ?></button></div>
		</div>
		<div class="row">
			<div class="col-md-6"><div class="box box-default"><div class="box-header with-border"><h3 class="box-title"><?= e(t('roster_copy')) ?></h3></div>
				<form id="copy-form" class="hrm-ajax-form" method="post" action="<?= site_url('rosters/copy_week') ?>" data-after="roster-reload" data-message="<?= e(t('roster_saved')) ?>">
					<div class="box-body">
						<div class="row"><div class="col-sm-6 form-group"><label><?= e(t('roster_copy_from')) ?></label><input type="text" name="source_from" class="form-control datepicker" autocomplete="off" required></div>
							<div class="col-sm-6 form-group"><label><?= e(t('roster_copy_to')) ?></label><input type="text" name="target_from" class="form-control datepicker" autocomplete="off" required></div></div>
						<select name="department_id" class="form-control"><option value=""><?= e(t('shift_bulk_all')) ?></option><?php foreach ($departments as $id => $name): ?><option value="<?= (int) $id ?>"><?= e($name) ?></option><?php endforeach; ?></select>
						<div class="checkbox"><label><input type="checkbox" name="overwrite" value="1"> <?= e(t('roster_overwrite')) ?></label></div>
					</div>
					<div class="box-footer"><button type="submit" class="btn btn-default"><i class="fa fa-copy"></i> <?= e(t('roster_copy')) ?></button></div>
				</form></div></div>
			<div class="col-md-6"><div class="box box-default"><div class="box-header with-border"><h3 class="box-title"><?= e(t('roster_import')) ?></h3></div>
				<form id="import-form" class="hrm-ajax-form" method="post" enctype="multipart/form-data" action="<?= site_url('rosters/import') ?>" data-after="roster-reload" data-message="<?= e(t('roster_saved')) ?>">
					<div class="box-body"><input type="file" name="roster_file" accept=".xlsx,.csv" required><p class="help-block"><?= e(t('roster_import_help')) ?></p></div>
					<div class="box-footer"><button type="submit" class="btn btn-default"><i class="fa fa-upload"></i> <?= e(t('roster_import')) ?></button></div>
				</form></div></div>
		</div>
	</section>
</div>
