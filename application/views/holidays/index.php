<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('menu_holidays')) ?> <?= (int) $year ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<p>
			<a href="<?= site_url('holidays/index/' . ($year - 1)) ?>" class="btn btn-default btn-sm"><i class="fa fa-chevron-left"></i> <?= $year - 1 ?></a>
			<a href="<?= site_url('holidays/index/' . ($year + 1)) ?>" class="btn btn-default btn-sm"><?= $year + 1 ?> <i class="fa fa-chevron-right"></i></a>
			<?php if (has_permission('holidays_add')): ?><a href="<?= site_url('holidays/add') ?>" class="btn btn-primary btn-sm pull-right" id="holiday-add"><i class="fa fa-plus"></i> <?= e(t('holidays_add')) ?></a><?php endif; ?>
		</p>
		<div class="nav-tabs-custom">
			<ul class="nav nav-tabs">
				<li class="active"><a href="#tab-year" data-toggle="tab"><?= e(t('holiday_year_view')) ?></a></li>
				<li><a href="#tab-list" data-toggle="tab"><?= e(t('holiday_list')) ?></a></li>
			</ul>
			<div class="tab-content">
				<div class="tab-pane active" id="tab-year"><div class="row" id="year-view">
					<?php foreach ($months as $m => $items): ?>
					<div class="col-md-3 col-sm-4 col-xs-6"><div class="box box-solid box-default" style="min-height:120px">
						<div class="box-header"><h3 class="box-title"><?= e(date('F', mktime(0, 0, 0, $m, 1, $year))) ?></h3></div>
						<div class="box-body" style="padding-top:0">
							<?php foreach ($items as $h): ?>
								<div class="<?= (int) $h['is_optional'] || $h['status'] !== 'Active' ? 'text-muted' : '' ?>"><strong><?= (int) substr($h['holiday_date'], 8, 2) ?></strong> <?= e($h['holiday_name']) ?><?= (int) $h['is_optional'] ? ' <small>(' . e(t('holiday_optional')) . ')</small>' : '' ?></div>
							<?php endforeach; ?>
						</div></div></div>
					<?php endforeach; ?>
				</div></div>
				<div class="tab-pane" id="tab-list">
					<form id="holidays-filter"><input type="hidden" name="year" value="<?= (int) $year ?>"></form>
					<table id="holidays-table" class="table table-bordered table-striped" style="width:100%" data-url="<?= site_url('holidays/ajax_list') ?>"
						data-edit-url="<?= has_permission('holidays_edit') ? site_url('holidays/edit') : '' ?>" data-delete-url="<?= has_permission('holidays_delete') ? site_url('holidays/delete') : '' ?>" data-confirm="<?= e(t('confirm_delete')) ?>">
						<thead><tr><th><?= e(t('holiday_date')) ?></th><th><?= e(t('holiday_name')) ?></th><th></th><th></th><th><?= e(t('status')) ?></th><th><?= e(t('actions')) ?></th></tr></thead>
					</table>
				</div>
			</div>
		</div>
		<?php if (has_permission('holidays_add')): ?>
		<div class="box box-default" style="max-width:640px">
			<div class="box-header with-border"><h3 class="box-title"><?= e(t('holiday_copy')) ?></h3></div>
			<form id="copy-year-form" class="hrm-ajax-form form-inline" method="post" data-reload="1" data-message="<?= e(t('holiday_copied')) ?>" action="<?= site_url('holidays/copy_year') ?>">
				<div class="box-body">
					<label><?= e(t('holiday_copy_from')) ?></label> <input type="number" name="from_year" class="form-control" value="<?= $year - 1 ?>" style="width:100px">
					<label><?= e(t('holiday_copy_to')) ?></label> <input type="number" name="to_year" class="form-control" value="<?= (int) $year ?>" style="width:100px">
					<button type="submit" class="btn btn-default"><i class="fa fa-copy"></i> <?= e(t('holiday_copy')) ?></button>
				</div>
			</form>
		</div>
		<?php endif; ?>
	</section>
</div>
