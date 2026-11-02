<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('menu_devices')) ?> <small><?= e($user['branch_name']) ?></small></h1></section>
	<section class="content">
		<p>
			<?php if (has_permission('devices_keys')): ?><a href="<?= site_url('devices/keys') ?>" class="btn btn-default btn-sm" id="link-keys"><i class="fa fa-key"></i> <?= e(t('dev_keys')) ?></a><?php endif; ?>
			<?php if (has_permission('devices_commands')): ?><a href="<?= site_url('devices/commands') ?>" class="btn btn-default btn-sm" id="link-commands"><i class="fa fa-terminal"></i> <?= e(t('dev_commands')) ?></a><?php endif; ?>
			<a href="<?= site_url('devices/logs') ?>" class="btn btn-default btn-sm" id="link-logs"><i class="fa fa-list-alt"></i> <?= e(t('dev_logs')) ?></a>
			<?php if (has_permission('devices_manage')): ?><a href="<?= site_url('devices/add') ?>" class="btn btn-primary btn-sm pull-right" id="device-add"><i class="fa fa-plus"></i> <?= e(t('dev_add')) ?></a><?php endif; ?>
		</p>

		<?php if ($pending): ?>
		<div class="box box-warning" id="pending-box">
			<div class="box-header with-border"><h3 class="box-title"><?= e(t('dev_pending')) ?></h3></div>
			<div class="box-body">
				<p class="text-muted"><?= e(t('dev_pending_help')) ?></p>
				<table class="table table-condensed" id="pending-table">
					<thead><tr><th><?= e(t('dev_serial')) ?></th><th><?= e(t('dev_ip')) ?></th><th><?= e(t('dev_first_seen')) ?></th><th><?= e(t('dev_last_seen')) ?></th><th><?= e(t('dev_hits')) ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ($pending as $p): ?>
						<tr>
							<td><code><?= e($p['serial_number']) ?></code></td><td><?= e($p['ip']) ?></td>
							<td><?= e(show_date($p['first_seen_at']) . ' ' . substr($p['first_seen_at'], 11, 5)) ?></td><td><?= e(show_date($p['last_seen_at']) . ' ' . substr($p['last_seen_at'], 11, 5)) ?></td>
							<td><?= (int) $p['hits'] ?></td>
							<td class="text-right">
								<a class="btn btn-xs btn-success" href="<?= site_url('devices/approve/' . rawurlencode($p['serial_number'])) ?>"><i class="fa fa-check"></i> <?= e(t('dev_approve')) ?></a>
								<button type="button" class="btn btn-xs btn-default hrm-post-fields" data-url="<?= site_url('devices/dismiss') ?>" data-serial="<?= e($p['serial_number']) ?>" data-confirm="<?= e(t('dev_dismiss_confirm')) ?>"><i class="fa fa-times"></i> <?= e(t('dev_dismiss')) ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php endif; ?>

		<div class="box box-primary">
			<div class="box-body">
				<table id="devices-table" class="table table-bordered table-striped" style="width:100%" data-url="<?= site_url('devices/ajax_list') ?>"
					data-edit-url="<?= has_permission('devices_manage') ? site_url('devices/edit') : '' ?>" data-delete-url="<?= has_permission('devices_manage') ? site_url('devices/delete') : '' ?>"
					data-allow-url="<?= has_permission('devices_manage') ? site_url('devices/allow_ip') : '' ?>" data-confirm="<?= e(t('confirm_delete')) ?>">
					<thead><tr>
						<th><?= e(t('dev_name')) ?></th><th><?= e(t('dev_mode')) ?></th><th><?= e(t('dev_serial')) ?> / <?= e(t('dev_ip')) ?></th><th><?= e(t('status')) ?></th><th><?= e(t('dev_health')) ?></th>
						<th><?= e(t('dev_last_seen')) ?></th><th><?= e(t('dev_last_punch')) ?></th><th><?= e(t('dev_punches_today')) ?></th><th></th><th><?= e(t('actions')) ?></th>
					</tr></thead>
				</table>
				<p class="help-block"><?= e(t('dev_service_hint')) ?></p>
			</div>
		</div>
	</section>
</div>
