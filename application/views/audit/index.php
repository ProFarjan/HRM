<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('menu_audit')) ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<div class="box box-primary">
			<div class="box-header with-border">
				<form class="form-inline" id="audit-filter">
					<input type="text" name="from_date" class="form-control input-sm datepicker" value="<?= e($from_date) ?>" placeholder="<?= e(t('audit_from')) ?>" style="width:110px">
					<input type="text" name="to_date" class="form-control input-sm datepicker" value="<?= e($to_date) ?>" placeholder="<?= e(t('audit_to')) ?>" style="width:110px">
					<select name="module" class="form-control input-sm">
						<option value=""><?= e(t('audit_module')) ?>: <?= e(t('all')) ?></option>
						<?php foreach ($modules as $m): ?><option value="<?= e($m) ?>"><?= e($m) ?></option><?php endforeach; ?>
					</select>
					<select name="user_id" class="form-control input-sm">
						<option value=""><?= e(t('audit_user')) ?>: <?= e(t('all')) ?></option>
						<?php foreach ($users as $uid => $uname): ?><option value="<?= (int) $uid ?>"><?= e($uname) ?></option><?php endforeach; ?>
					</select>
					<input type="text" name="action" class="form-control input-sm" maxlength="40" placeholder="<?= e(t('audit_action')) ?>">
					<button type="submit" class="btn btn-default btn-sm"><i class="fa fa-filter"></i> <?= e(t('filter')) ?></button>
				</form>
			</div>
			<div class="box-body table-responsive">
				<table id="audit-table" class="table table-bordered table-striped table-condensed" style="width:100%"
					data-url="<?= site_url('audit/ajax_list') ?>" data-view-url="<?= site_url('audit/view') ?>">
					<thead><tr>
						<th><?= e(t('audit_when')) ?></th><th><?= e(t('audit_user')) ?></th><th><?= e(t('audit_module')) ?></th>
						<th><?= e(t('audit_action')) ?></th><th><?= e(t('audit_record')) ?></th><th><?= e(t('audit_ip')) ?></th><th></th>
					</tr></thead>
				</table>
			</div>
		</div>
	</section>
</div>
<div class="modal fade" id="audit-modal" tabindex="-1" role="dialog">
	<div class="modal-dialog modal-lg" role="document"><div class="modal-content">
		<div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><?= e(t('audit_details')) ?></h4></div>
		<div class="modal-body">
			<p id="audit-meta"></p>
			<table class="table table-bordered table-condensed" id="audit-diff">
				<thead><tr><th></th><th><?= e(t('audit_old')) ?></th><th><?= e(t('audit_new')) ?></th></tr></thead><tbody></tbody>
			</table>
		</div>
	</div></div>
</div>
