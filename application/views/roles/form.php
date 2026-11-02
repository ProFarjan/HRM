<?php defined('BASEPATH') OR exit('No direct script access allowed');
$is_edit = $row !== NULL;
$held = $is_edit ? array_flip($row['permissions']) : array();
$locked = $is_edit && (int) $row['is_system'] === 1;
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e($is_edit ? t('roles_edit') : t('roles_add')) ?><?php if ($is_edit): ?> <small><?= e($row['role_name']) ?></small><?php endif; ?></h1></section>
	<section class="content">
		<?php if ($locked): ?><div class="callout callout-warning"><p><?= e(t('roles_system_locked')) ?></p></div><?php endif; ?>
		<form id="role-form" class="hrm-ajax-form" method="post" data-redirect="<?= site_url('roles') ?>"
			action="<?= $is_edit ? site_url('roles/update/' . (int) $row['id']) : site_url('roles/save') ?>">
			<div class="box box-primary">
				<div class="box-body">
					<div class="form-group" style="max-width:420px">
						<label for="role_name"><?= e(t('roles_name')) ?></label>
						<input type="text" id="role_name" name="role_name" class="form-control" maxlength="80" required value="<?= $is_edit ? e($row['role_name']) : '' ?>" <?= $locked ? 'disabled' : '' ?>>
					</div>
				</div>
			</div>
			<div class="row">
				<?php foreach ($catalog as $group => $keys): ?>
				<div class="col-md-4 col-sm-6">
					<div class="box box-default permission-group">
						<div class="box-header with-border">
							<h3 class="box-title"><?= e($group) ?></h3>
							<div class="box-tools"><label class="small"><input type="checkbox" class="group-toggle" <?= $locked ? 'disabled' : '' ?>> <?= e(t('roles_select_all')) ?></label></div>
						</div>
						<div class="box-body">
							<?php foreach ($keys as $key): $can = isset($grantable[$key]); ?>
							<div class="checkbox" <?= $can ? '' : 'title="' . e(t('roles_not_grantable')) . '"' ?>>
								<label class="<?= $can ? '' : 'text-muted' ?>">
									<input type="checkbox" name="permissions[]" value="<?= e($key) ?>" class="perm"
										<?= isset($held[$key]) || $locked ? 'checked' : '' ?> <?= ($can && ! $locked) ? '' : 'disabled' ?>>
									<code><?= e($key) ?></code>
								</label>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<div class="box"><div class="box-footer">
				<a href="<?= site_url('roles') ?>" class="btn btn-default"><?= e(t('back')) ?></a>
				<?php if ( ! $locked): ?><button type="submit" class="btn btn-primary pull-right" id="role-save"><?= e(t('save')) ?></button><?php endif; ?>
			</div></div>
		</form>
	</section>
</div>
