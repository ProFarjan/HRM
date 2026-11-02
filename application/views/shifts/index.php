<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('menu_shifts')) ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<div class="box box-primary">
			<div class="box-header with-border">
				<form class="form-inline" id="shifts-filter">
					<select name="status" class="form-control input-sm">
						<option value=""><?= e(t('status')) ?>: <?= e(t('all')) ?></option>
						<option value="Active"><?= e(t('status_active')) ?></option><option value="Inactive"><?= e(t('status_inactive')) ?></option>
					</select>
				</form>
				<?php if (has_permission('shifts_add')): ?><div class="box-tools"><a href="<?= site_url('shifts/add') ?>" class="btn btn-primary btn-sm" id="shift-add"><i class="fa fa-plus"></i> <?= e(t('shifts_add')) ?></a></div><?php endif; ?>
			</div>
			<div class="box-body table-responsive">
				<table id="shifts-table" class="table table-bordered table-striped" style="width:100%" data-url="<?= site_url('shifts/ajax_list') ?>"
					data-edit-url="<?= has_permission('shifts_edit') ? site_url('shifts/edit') : '' ?>" data-delete-url="<?= has_permission('shifts_delete') ? site_url('shifts/delete') : '' ?>"
					data-confirm="<?= e(t('confirm_delete')) ?>">
					<thead><tr><th><?= e(t('shift_name')) ?></th><th><?= e(t('org_code')) ?></th><th><?= e(t('shift_start')) ?></th><th><?= e(t('shift_end')) ?></th>
						<th><?= e(t('shift_hours_short')) ?></th><th><?= e(t('shift_grace')) ?></th><th><?= e(t('org_employees')) ?></th><th><?= e(t('status')) ?></th><th><?= e(t('actions')) ?></th></tr></thead>
				</table>
			</div>
		</div>
	</section>
</div>
