<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header">
		<h1><?= e(t('menu_users')) ?> <small><?= e($user['branch_name']) ?></small></h1>
	</section>
	<section class="content">
		<div class="box box-primary">
			<div class="box-header with-border">
				<form class="form-inline" id="users-filter">
					<select name="role_id" class="form-control input-sm">
						<option value=""><?= e(t('users_role')) ?>: <?= e(t('all')) ?></option>
						<?php foreach ($roles as $rid => $rname): ?><option value="<?= (int) $rid ?>"><?= e($rname) ?></option><?php endforeach; ?>
					</select>
					<select name="status" class="form-control input-sm">
						<option value=""><?= e(t('status')) ?>: <?= e(t('all')) ?></option>
						<option value="Active"><?= e(t('status_active')) ?></option>
						<option value="Inactive"><?= e(t('status_inactive')) ?></option>
					</select>
				</form>
				<?php if (has_permission('users_add')): ?>
				<div class="box-tools"><a href="<?= site_url('users/add') ?>" class="btn btn-primary btn-sm" id="users-add"><i class="fa fa-plus"></i> <?= e(t('users_add')) ?></a></div>
				<?php endif; ?>
			</div>
			<div class="box-body table-responsive">
				<table id="users-table" class="table table-bordered table-striped" style="width:100%"
					data-url="<?= site_url('users/ajax_list') ?>" data-edit-url="<?= has_permission('users_edit') ? site_url('users/edit') : '' ?>">
					<thead><tr>
						<th><?= e(t('users_username')) ?></th><th><?= e(t('users_role')) ?></th><th><?= e(t('status')) ?></th>
						<th><?= e(t('users_last_login')) ?></th><th><?= e(t('users_created')) ?></th><th><?= e(t('actions')) ?></th>
					</tr></thead>
				</table>
			</div>
		</div>
	</section>
</div>
