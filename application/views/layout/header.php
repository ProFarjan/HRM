<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<header class="main-header">
	<a href="<?= site_url('dashboard/home') ?>" class="logo">
		<span class="logo-mini"><b>HR</b></span>
		<span class="logo-lg"><?php if ($has_logo): ?><img src="<?= site_url('settings/logo') ?>" alt="" style="max-height:34px;margin-right:6px"><?php endif; ?><b><?= e($company_name) ?></b></span>
	</a>
	<nav class="navbar navbar-static-top">
		<a href="#" class="sidebar-toggle" data-toggle="push-menu" role="button"><span class="sr-only">Toggle navigation</span></a>
		<div class="navbar-custom-menu">
			<ul class="nav navbar-nav">
				<?php if ( ! empty($switch_branches)): ?>
				<li>
					<form id="branch-switch-form" class="navbar-form" method="post" action="<?= site_url('branches/switch_branch') ?>" title="<?= e(t('switch_branch')) ?>">
						<input type="hidden" name="<?= e($csrf_name) ?>" value="<?= e($csrf_hash) ?>">
						<i class="fa fa-building" style="color:#fff"></i>
						<select name="branch_id" class="form-control input-sm" id="branch-switch" aria-label="<?= e(t('switch_branch')) ?>">
							<?php foreach ($switch_branches as $bid => $bname): ?>
								<option value="<?= (int) $bid ?>"<?= (int) $bid === (int) $user['branch_id'] ? ' selected' : '' ?>><?= e($bname) ?></option>
							<?php endforeach; ?>
						</select>
						<noscript><button type="submit" class="btn btn-default btn-sm">OK</button></noscript>
					</form>
				</li>
				<?php else: ?>
				<li class="hidden-xs"><a><i class="fa fa-building"></i> <?= e($user['branch_name']) ?></a></li>
				<?php endif; ?>
				<li class="dropdown user user-menu">
					<a href="#" class="dropdown-toggle" data-toggle="dropdown">
						<i class="fa fa-user-circle"></i>
						<span class="hidden-xs"><?= e($user['username']) ?> <small>(<?= e($user['role_name']) ?>)</small></span>
					</a>
					<ul class="dropdown-menu">
						<li class="user-header">
							<p><?= e($user['username']) ?>
								<small><?= e($user['role_name']) ?> &middot; <?= e($user['branch_name']) ?></small>
							</p>
						</li>
						<li class="user-footer">
							<div class="pull-left"><a href="<?= site_url('auth/change_password') ?>" class="btn btn-default btn-flat"><?= e(t('change_password_title')) ?></a></div>
							<div class="pull-right">
								<form id="logout-form" method="post" action="<?= site_url('auth/logout') ?>" style="display:inline">
									<input type="hidden" name="<?= e($csrf_name) ?>" value="<?= e($csrf_hash) ?>">
									<button type="submit" class="btn btn-default btn-flat"><?= e(t('logout')) ?></button>
								</form>
							</div>
						</li>
					</ul>
				</li>
			</ul>
		</div>
	</nav>
</header>
