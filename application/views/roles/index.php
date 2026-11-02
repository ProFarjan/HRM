<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('menu_roles')) ?></h1></section>
	<section class="content">
		<div class="box box-primary">
			<div class="box-header with-border">
				<div class="box-tools"><a href="<?= site_url('roles/add') ?>" class="btn btn-primary btn-sm" id="roles-add"><i class="fa fa-plus"></i> <?= e(t('roles_add')) ?></a></div>
			</div>
			<div class="box-body table-responsive">
				<table id="roles-table" class="table table-bordered table-striped" style="width:100%"
					data-url="<?= site_url('roles/ajax_list') ?>" data-edit-url="<?= site_url('roles/edit') ?>" data-delete-url="<?= site_url('roles/delete') ?>"
					data-confirm="<?= e(t('confirm_delete')) ?>">
					<thead><tr>
						<th><?= e(t('roles_name')) ?></th><th><?= e(t('roles_users')) ?></th><th><?= e(t('roles_permissions')) ?></th>
						<th><?= e(t('users_created')) ?></th><th><?= e(t('actions')) ?></th>
					</tr></thead>
				</table>
			</div>
		</div>
	</section>
</div>
