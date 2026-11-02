<?php defined('BASEPATH') OR exit('No direct script access allowed');
$is_edit = $row !== NULL;
$is_self = $is_edit && (int) $row['id'] === (int) $user['id'];
?>
<div class="content-wrapper">
	<section class="content-header">
		<h1><?= e($is_edit ? t('users_edit') : t('users_add')) ?><?php if ($is_edit): ?> <small><?= e($row['username']) ?></small><?php endif; ?></h1>
	</section>
	<section class="content">
		<div class="row">
			<div class="col-md-6">
				<div class="box box-primary">
					<form id="user-form" class="hrm-ajax-form" method="post" data-redirect="<?= site_url('users') ?>"
						action="<?= $is_edit ? site_url('users/update/' . (int) $row['id']) : site_url('users/save') ?>">
						<div class="box-body">
							<div class="form-group">
								<label for="username"><?= e(t('users_username')) ?></label>
								<input type="text" id="username" name="username" class="form-control" maxlength="64" value="<?= $is_edit ? e($row['username']) : '' ?>" <?= $is_edit ? 'readonly' : 'required' ?>>
								<p class="help-block"><?= e(t('users_username_help')) ?></p>
							</div>
							<?php if ( ! $is_edit): ?>
							<div class="form-group">
								<label for="password"><?= e(t('users_password')) ?></label>
								<input type="password" id="password" name="password" class="form-control" minlength="8" autocomplete="new-password" required>
							</div>
							<?php endif; ?>
							<div class="form-group">
								<label for="role_id"><?= e(t('users_role')) ?></label>
								<select id="role_id" name="role_id" class="form-control" <?= $is_self ? 'disabled' : '' ?>>
									<?php if ($is_edit && ! isset($roles[(int) $row['role_id']])): ?><option value="<?= (int) $row['role_id'] ?>" selected><?= e($row['role_name']) ?></option><?php endif; ?>
									<?php foreach ($roles as $rid => $rname): ?>
										<option value="<?= (int) $rid ?>"<?= $is_edit && (int) $row['role_id'] === (int) $rid ? ' selected' : '' ?>><?= e($rname) ?></option>
									<?php endforeach; ?>
								</select>
								<?php if ($is_self): ?><input type="hidden" name="role_id" value="<?= (int) $row['role_id'] ?>"><?php endif; ?>
							</div>
							<?php if ( ! empty($user['is_system'])): ?>
							<div class="form-group">
								<label for="branch_id"><?= e(t('branch')) ?></label>
								<select id="branch_id" name="branch_id" class="form-control" <?= $is_self ? 'disabled' : '' ?>>
									<?php foreach ($branches as $bid => $bname): ?>
										<option value="<?= (int) $bid ?>"<?= (int) $bid === (int) ($is_edit ? $row['branch_id'] : $user['branch_id']) ? ' selected' : '' ?>><?= e($bname) ?></option>
									<?php endforeach; ?>
								</select>
								<?php if ($is_self): ?><input type="hidden" name="branch_id" value="<?= (int) $row['branch_id'] ?>"><?php endif; ?>
							</div>
							<?php endif; ?>
							<div class="form-group">
								<label for="status"><?= e(t('status')) ?></label>
								<select id="status" name="status" class="form-control" <?= $is_self ? 'disabled' : '' ?>>
									<?php foreach (array('Active' => 'status_active', 'Inactive' => 'status_inactive') as $sv => $sl): ?>
										<option value="<?= $sv ?>"<?= $is_edit && $row['status'] === $sv ? ' selected' : '' ?>><?= e(t($sl)) ?></option>
									<?php endforeach; ?>
								</select>
								<?php if ($is_self): ?><input type="hidden" name="status" value="<?= e($row['status']) ?>"><?php endif; ?>
							</div>
							<?php if ( ! $is_edit): ?>
							<div class="checkbox"><label><input type="checkbox" name="must_change_password" value="1" checked> <?= e(t('users_must_change')) ?></label></div>
							<?php endif; ?>
						</div>
						<div class="box-footer">
							<a href="<?= site_url('users') ?>" class="btn btn-default"><?= e(t('back')) ?></a>
							<button type="submit" class="btn btn-primary pull-right" id="user-save"><?= e(t('save')) ?></button>
						</div>
					</form>
				</div>
			</div>
			<?php if ($is_edit): ?>
			<div class="col-md-6">
				<div class="box box-warning">
					<div class="box-header with-border"><h3 class="box-title"><?= e(t('users_reset_password')) ?></h3></div>
					<form id="reset-form" class="hrm-ajax-form" method="post" action="<?= site_url('users/reset_password/' . (int) $row['id']) ?>">
						<div class="box-body">
							<input type="password" name="new_password" class="form-control" minlength="8" autocomplete="new-password" placeholder="<?= e(t('users_password')) ?>" required>
							<p class="help-block"><?= e(t('users_reset_help')) ?></p>
						</div>
						<div class="box-footer"><button type="submit" class="btn btn-warning"><?= e(t('users_reset_password')) ?></button></div>
					</form>
				</div>
				<?php if ( ! $is_self): ?>
				<div class="box box-danger">
					<div class="box-body">
						<button type="button" class="btn btn-default hrm-post" data-url="<?= site_url('users/toggle_status/' . (int) $row['id']) ?>" data-reload="1">
							<?= e($row['status'] === 'Active' ? t('users_deactivate') : t('users_activate')) ?>
						</button>
						<?php if (has_permission('users_delete')): ?>
						<button type="button" class="btn btn-danger pull-right hrm-post" data-url="<?= site_url('users/delete/' . (int) $row['id']) ?>"
							data-confirm="<?= e(t('confirm_delete')) ?>" data-redirect="<?= site_url('users') ?>"><i class="fa fa-trash"></i> <?= e(t('delete')) ?></button>
						<?php endif; ?>
					</div>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
	</section>
</div>
