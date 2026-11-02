<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<aside class="main-sidebar">
	<section class="sidebar">
		<ul class="sidebar-menu" data-widget="tree">
			<li class="header"><?= e(t('menu_main')) ?></li>
			<?php foreach ($menu as $item): ?>
				<?php if (isset($item['children'])): ?>
					<li class="treeview">
						<a href="#"><i class="fa <?= e($item['icon']) ?>"></i> <span><?= e(t($item['label'])) ?></span>
							<span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
						<ul class="treeview-menu">
							<?php foreach ($item['children'] as $child): ?>
								<li><a href="<?= site_url($child['url']) ?>"><i class="fa fa-circle-o"></i> <?= e(t($child['label'])) ?></a></li>
							<?php endforeach; ?>
						</ul>
					</li>
				<?php else: ?>
					<li<?= ($active_menu !== '' && $active_menu === $item['url']) ? ' class="active"' : '' ?>>
						<a href="<?= site_url($item['url']) ?>"><i class="fa <?= e($item['icon']) ?>"></i> <span><?= e(t($item['label'])) ?></span></a>
					</li>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>
	</section>
</aside>
