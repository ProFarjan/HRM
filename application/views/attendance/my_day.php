<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
	<section class="content-header"><h1><?= e(t('att_mark_self')) ?> <small><?= e(show_date(today_local())) ?></small></h1></section>
	<section class="content">
		<?php if ( ! $has_employee): ?>
			<div class="callout callout-warning"><p><?= e(t('att_no_employee')) ?></p></div>
		<?php else: ?>
		<div class="box box-primary" style="max-width:520px">
			<form id="self-form" class="hrm-ajax-form" method="post" data-reload="1" action="<?= site_url('attendance/save_my_day') ?>">
				<div class="box-body">
					<div class="row">
						<div class="form-group col-sm-6"><label for="s_in"><?= e(t('att_in')) ?></label><input type="time" id="s_in" name="check_in" class="form-control" required value="<?= e(date('H:i')) ?>"></div>
						<div class="form-group col-sm-6"><label for="s_out"><?= e(t('att_out')) ?></label><input type="time" id="s_out" name="check_out" class="form-control"></div>
					</div>
					<div class="form-group"><label for="s_remarks"><?= e(t('att_remarks')) ?></label><input type="text" id="s_remarks" name="remarks" class="form-control" maxlength="255"></div>
					<p class="help-block"><?= e(t('att_self_help')) ?></p>
				</div>
				<div class="box-footer"><button type="submit" class="btn btn-primary" id="self-save"><?= e(t('save')) ?></button>
					<?php if (has_permission('attendance_view')): ?><a href="<?= site_url('attendance/calendar') ?>" class="btn btn-default pull-right"><i class="fa fa-calendar"></i> <?= e(t('att_calendar')) ?></a><?php endif; ?></div>
			</form>
		</div>
		<?php endif; ?>
	</section>
</div>
