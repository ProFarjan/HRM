<?php defined('BASEPATH') OR exit('No direct script access allowed');
$plural = $unit . 's';
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('menu_' . $plural)) ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<div class="box box-primary">
			<div class="box-header with-border">
				<form class="form-inline" id="units-filter">
					<select name="status" class="form-control input-sm">
						<option value=""><?= e(t('status')) ?>: <?= e(t('all')) ?></option>
						<option value="Active"><?= e(t('status_active')) ?></option>
						<option value="Inactive"><?= e(t('status_inactive')) ?></option>
					</select>
				</form>
				<?php if (has_permission($plural . '_add')): ?>
				<div class="box-tools"><a href="<?= site_url($plural . '/add') ?>" class="btn btn-primary btn-sm" id="unit-add"><i class="fa fa-plus"></i> <?= e(t($plural . '_add')) ?></a></div>
				<?php endif; ?>
			</div>
			<div class="box-body table-responsive">
				<table id="units-table" class="table table-bordered table-striped" style="width:100%"
					data-url="<?= site_url($plural . '/ajax_list') ?>"
					data-edit-url="<?= has_permission($plural . '_edit') ? site_url($plural . '/edit') : '' ?>"
					data-delete-url="<?= has_permission($plural . '_delete') ? site_url($plural . '/delete') : '' ?>"
					data-confirm="<?= e(t('confirm_delete')) ?>">
					<thead><tr>
						<th><?= e(t('org_name_' . $unit)) ?></th><th><?= e(t('org_code')) ?></th>
						<th><?= e($unit === 'department' ? t('org_head') : t('org_level')) ?></th>
						<th><?= e(t('org_employees')) ?></th><th><?= e(t('status')) ?></th><th><?= e(t('actions')) ?></th>
					</tr></thead>
				</table>
			</div>
		</div>
	</section>
</div>
