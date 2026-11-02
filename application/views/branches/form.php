<?php defined('BASEPATH') OR exit('No direct script access allowed');
$is_edit = $row !== NULL;
$tz = $is_edit ? $row['timezone'] : hr_setting('timezone', 'Asia/Dhaka');
$custom = $is_edit && $row['weekly_offs'] !== NULL;
$days = $custom ? array_flip(array_filter(explode(',', $row['weekly_offs']), 'strlen')) : array();
?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e($is_edit ? t('branches_edit') : t('branches_add')) ?><?php if ($is_edit): ?> <small><?= e($row['name']) ?></small><?php endif; ?></h1></section>
	<section class="content">
		<div class="box box-primary" style="max-width:760px">
			<form id="branch-form" class="hrm-ajax-form" method="post" data-redirect="<?= site_url('branches') ?>"
				action="<?= $is_edit ? site_url('branches/update/' . (int) $row['id']) : site_url('branches/save') ?>">
				<div class="box-body">
					<div class="row">
						<div class="form-group col-sm-8"><label for="name"><?= e(t('branches_name')) ?></label>
							<input type="text" id="name" name="name" class="form-control" maxlength="150" required value="<?= $is_edit ? e($row['name']) : '' ?>"></div>
						<div class="form-group col-sm-4"><label for="code"><?= e(t('branches_code')) ?></label>
							<input type="text" id="code" name="code" class="form-control" maxlength="20" required value="<?= $is_edit ? e($row['code']) : '' ?>"></div>
					</div>
					<div class="form-group"><label for="address"><?= e(t('branches_address')) ?></label>
						<input type="text" id="address" name="address" class="form-control" maxlength="255" value="<?= $is_edit ? e($row['address']) : '' ?>"></div>
					<div class="row">
						<div class="form-group col-sm-6"><label for="phone"><?= e(t('branches_phone')) ?></label>
							<input type="text" id="phone" name="phone" class="form-control" maxlength="30" value="<?= $is_edit ? e($row['phone']) : '' ?>"></div>
						<div class="form-group col-sm-6"><label for="timezone"><?= e(t('branches_timezone')) ?></label>
							<select id="timezone" name="timezone" class="form-control select2" style="width:100%">
								<?php foreach ($timezones as $zone): ?><option<?= $zone === $tz ? ' selected' : '' ?>><?= e($zone) ?></option><?php endforeach; ?>
							</select></div>
					</div>
					<div class="form-group">
						<label><?= e(t('branches_weekly_offs')) ?></label>
						<div class="radio"><label><input type="radio" name="weekly_offs_mode" value="global" <?= $custom ? '' : 'checked' ?>> <?= e(t('branches_use_global')) ?></label></div>
						<div class="radio"><label><input type="radio" name="weekly_offs_mode" value="custom" <?= $custom ? 'checked' : '' ?>> <?= e(t('branches_custom')) ?></label></div>
						<div id="weekly-days">
							<?php for ($d = 1; $d <= 7; $d++): ?>
								<label class="checkbox-inline"><input type="checkbox" name="weekly_offs[]" value="<?= $d ?>" <?= isset($days[(string) $d]) ? 'checked' : '' ?>> <?= e(t('weekday_' . $d)) ?></label>
							<?php endfor; ?>
						</div>
					</div>
					<div class="form-group" style="max-width:240px"><label for="status"><?= e(t('status')) ?></label>
						<select id="status" name="status" class="form-control">
							<?php foreach (array('Active' => 'status_active', 'Inactive' => 'status_inactive') as $sv => $sl): ?>
								<option value="<?= $sv ?>"<?= $is_edit && $row['status'] === $sv ? ' selected' : '' ?>><?= e(t($sl)) ?></option>
							<?php endforeach; ?>
						</select></div>
				</div>
				<div class="box-footer">
					<a href="<?= site_url('branches') ?>" class="btn btn-default"><?= e(t('back')) ?></a>
					<button type="submit" class="btn btn-primary pull-right" id="branch-save"><?= e(t('save')) ?></button>
				</div>
			</form>
		</div>
	</section>
</div>
