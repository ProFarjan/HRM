<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('menu_branches')) ?></h1></section>
	<section class="content">
		<div class="box box-primary">
			<div class="box-header with-border">
				<div class="box-tools"><a href="<?= site_url('branches/add') ?>" class="btn btn-primary btn-sm" id="branches-add"><i class="fa fa-plus"></i> <?= e(t('branches_add')) ?></a></div>
			</div>
			<div class="box-body table-responsive">
				<table id="branches-table" class="table table-bordered table-striped" style="width:100%"
					data-url="<?= site_url('branches/ajax_list') ?>" data-edit-url="<?= site_url('branches/edit') ?>">
					<thead><tr>
						<th><?= e(t('branches_name')) ?></th><th><?= e(t('branches_code')) ?></th><th><?= e(t('branches_timezone')) ?></th>
						<th><?= e(t('branches_weekly_offs')) ?></th><th><?= e(t('roles_users')) ?></th><th><?= e(t('status')) ?></th><th><?= e(t('actions')) ?></th>
					</tr></thead>
				</table>
			</div>
		</div>
	</section>
</div>
