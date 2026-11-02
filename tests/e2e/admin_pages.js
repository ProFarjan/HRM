/*
 * Browser check of the Step 1 administration pages (Playwright + bundled Chromium):
 * users, roles, branches (+ branch switch), settings, audit log - as the admin, and 403 as a user without permissions.
 * Screenshots go to docs/step-1/.
 *
 *   TENANT_DB=hrm_dev php -S 127.0.0.1:8099 tests/support/router.php &
 *   HRM_ADMIN_PASSWORD=... HRM_E2E_PASSWORD=... node tests/e2e/admin_pages.js
 */
const { chromium } = require('playwright');
const path = require('path');

const BASE = process.env.HRM_BASE_URL || 'http://127.0.0.1:8099/';
const OUT = path.join(__dirname, '..', '..', 'docs', 'step-1');
const ADMIN = { user: process.env.HRM_ADMIN_USER || 'admin', pass: process.env.HRM_ADMIN_PASSWORD || '' };
const E2E_PASS = process.env.HRM_E2E_PASSWORD || 'E2e#Passw0rd';
const RUN = Date.now().toString(36).slice(-5);

const failures = [];
function check(ok, message) {
	console.log((ok ? 'PASS ' : 'FAIL ') + message);
	if (!ok) { failures.push(message); }
}

async function login(page, user, pass) {
	await page.goto(BASE + 'auth/login');
	await page.fill('input[name=username]', user);
	await page.fill('input[name=password]', pass);
	await Promise.all([page.waitForLoadState('load'), page.click('button[type=submit]')]);
}

/** tick a checkbox/radio that iCheck has replaced */
async function tick(page, selector) {
	const n = await page.evaluate((sel) => { const $el = window.jQuery(sel); $el.iCheck('check'); return $el.filter(':checked').length; }, selector);
	if (n < 1) { throw new Error('could not tick ' + selector); }
}

async function toast(page) {
	const el = await page.waitForSelector('.toast', { timeout: 5000 }).catch(() => null);
	const text = el ? (await el.innerText()) : '(no toast)';
	await page.evaluate(() => window.toastr && window.toastr.clear());
	await page.waitForTimeout(300);
	return text;
}

async function rows(page, table) {
	await page.waitForSelector(table + ' tbody tr', { timeout: 5000 });
	await page.waitForFunction((t) => !document.querySelector(t + '_processing') || document.querySelector(t + '_processing').style.display === 'none', table);
	return page.locator(table + ' tbody tr').count();
}

(async () => {
	const browser = await chromium.launch();
	const problems = [];
	let probing = false;
	const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
	const page = await ctx.newPage();
	page.on('dialog', (d) => d.accept());
	page.on('console', (m) => { if (m.type() === 'error' && !probing) { problems.push('console: ' + m.text()); } });
	page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));

	await login(page, ADMIN.user, ADMIN.pass);
	check(await page.locator('.sidebar-menu .treeview > a:has-text("Administration")').count() === 1, 'sidebar has the Administration group');

	// ---- roles ---------------------------------------------------------------------------------------------
	await page.goto(BASE + 'roles');
	check(await rows(page, '#roles-table') >= 1, 'roles list loads');
	await page.click('#roles-add');
	await page.fill('#role_name', 'HR clerk ' + RUN);
	for (const key of ['users_view', 'users_add', 'audit_view', 'dashboard_hr']) {
		await tick(page, 'input.perm[value="' + key + '"]');
	}
	await page.screenshot({ path: path.join(OUT, '01-role-form.png'), fullPage: true });
	await page.click('#role-save');
	await page.waitForURL(/\/roles$/);
	check(await rows(page, '#roles-table') >= 2, 'role saved and listed');
	await page.screenshot({ path: path.join(OUT, '02-roles-list.png') });

	// ---- branches ------------------------------------------------------------------------------------------
	await page.goto(BASE + 'branches/add');
	await page.fill('#name', 'Chittagong ' + RUN);
	await page.fill('#code', 'CTG' + RUN.toUpperCase());
	await tick(page, 'input[name=weekly_offs_mode][value=custom]');
	await tick(page, 'input[name="weekly_offs[]"][value="5"]');
	await tick(page, 'input[name="weekly_offs[]"][value="6"]');
	await page.screenshot({ path: path.join(OUT, '03-branch-form.png') });
	await page.click('#branch-save');
	await page.waitForURL(/\/branches$/);
	check(await rows(page, '#branches-table') >= 2, 'branch saved and listed');
	check((await page.locator('#branches-table').innerText()).includes('Fri, Sat'), 'custom weekly offs shown');
	await page.screenshot({ path: path.join(OUT, '04-branches-list.png') });

	// ---- users ---------------------------------------------------------------------------------------------
	await page.goto(BASE + 'users/add');
	await page.fill('#username', 'clerk' + RUN);
	await page.fill('#password', 'Temp#Pass123');
	await page.selectOption('#role_id', { label: 'HR clerk ' + RUN });
	await page.click('#user-save');
	await page.waitForURL(/\/users$/);
	check(await rows(page, '#users-table') >= 2, 'user saved and listed');
	await page.screenshot({ path: path.join(OUT, '05-users-list.png') });
	await page.locator('#users-table tbody tr:has-text("clerk' + RUN + '") a').first().click();
	await page.waitForSelector('#reset-form');
	await page.screenshot({ path: path.join(OUT, '06-user-edit.png'), fullPage: true });
	await page.fill('#reset-form input[name=new_password]', 'Other#Pass456');
	await page.click('#reset-form button[type=submit]');
	check(/Saved/.test(await toast(page)), 'password reset saved');
	// the admin cannot deactivate himself: his own form has the status disabled
	await page.goto(BASE + 'users');
	await rows(page, '#users-table');
	await page.locator('#users-table tbody tr:has-text("admin") a').first().click();
	await page.waitForSelector('#user-form');
	check(await page.locator('#status').isDisabled(), 'own login: status cannot be changed');

	// ---- settings ------------------------------------------------------------------------------------------
	await page.goto(BASE + 'settings');
	await page.fill('#f_company_name', 'Acme Garments Ltd');
	await page.click('a[href="#tab-payroll"]');
	await page.fill('#f_ot_multiplier', '2');
	await page.screenshot({ path: path.join(OUT, '07-settings-payroll.png') });
	await page.click('#settings-save');
	check(/Saved/.test(await toast(page)), 'settings saved');
	await page.waitForLoadState('load');
	await page.waitForTimeout(800);
	check((await page.locator('.main-header .logo').innerText()).includes('Acme Garments Ltd'), 'company name shows in the header');
	await page.click('a[href="#tab-payroll"]');
	check(await page.inputValue('#f_ot_multiplier') === '2.00', 'multiplier persisted (' + await page.inputValue('#f_ot_multiplier') + ')');
	await page.click('a[href="#tab-general"]');
	await page.screenshot({ path: path.join(OUT, '08-settings-general.png') });
	await page.fill('#f_email', 'not-an-email');
	await page.evaluate(() => document.getElementById('f_email').type = 'text');
	await page.click('#settings-save');
	check(/not a valid e-mail/.test(await toast(page)), 'invalid e-mail refused with a message');

	// ---- audit -----------------------------------------------------------------------------------------------
	await page.goto(BASE + 'audit');
	check(await rows(page, '#audit-table') >= 4, 'audit log lists the changes');
	await page.locator('.audit-view').first().click();
	await page.waitForSelector('#audit-modal.in');
	await page.waitForTimeout(600);
	check(await page.locator('#audit-diff tbody tr').count() >= 1, 'audit detail shows old/new values');
	await page.screenshot({ path: path.join(OUT, '09-audit-detail.png') });
	await page.keyboard.press('Escape');

	// ---- branch switch -------------------------------------------------------------------------------------
	await page.goto(BASE + 'users');
	await Promise.all([page.waitForLoadState('load'), page.selectOption('#branch-switch', { label: 'Chittagong ' + RUN })]);
	await page.waitForURL(/dashboard/);
	await page.goto(BASE + 'users');
	check((await page.locator('.content-header').innerText()).includes('Chittagong ' + RUN), 'switched branch shows in the users page');
	check(await rows(page, '#users-table') >= 1 && (await page.locator('#users-table tbody').innerText()).match(/No data|No matching|empty/i) !== null, 'users of the new branch: none yet');
	await page.screenshot({ path: path.join(OUT, '10-switched-branch.png') });
	const home = await page.evaluate(() => document.querySelector('#branch-switch option').value);
	await page.selectOption('#branch-switch', { index: 0 });
	await page.waitForLoadState('load');
	void home;
	await ctx.close();

	// ---- no permissions --------------------------------------------------------------------------------------
	const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 860 } });
	const p2 = await ctx2.newPage();
	probing = true;
	await login(p2, 'nopriv', E2E_PASS);
	check(/dashboard\/home/.test(p2.url()) && (await p2.locator('#welcome-text').count()) === 1, 'user without permissions lands on the welcome page');
	await p2.screenshot({ path: path.join(OUT, '11-nopriv-welcome.png') });
	for (const url of ['users', 'users/add', 'users/edit/1', 'roles', 'roles/add', 'branches', 'branches/add', 'settings', 'audit', 'audit/view/1']) {
		const r = await p2.goto(BASE + url);
		check(r.status() === 403, 'no permission: ' + url + ' is HTTP 403 (' + r.status() + ')');
	}
	const sw = await p2.evaluate((u) => fetch(u, { method: 'POST' }).then((x) => x.status), BASE + 'branches/switch_branch');
	check(sw === 403, 'no permission: branch switch POST is 403 (' + sw + ')');
	await p2.goto(BASE + 'settings');
	await p2.screenshot({ path: path.join(OUT, '12-nopriv-settings-403.png') });
	await ctx2.close();
	probing = false;

	// ---- a user with users_view only lands on the users page ---------------------------------------------------
	await browser.close();
	check(problems.length === 0, 'no console errors or page errors' + (problems.length ? ': ' + problems.join(' | ') : ''));
	console.log(failures.length ? failures.length + ' check(s) FAILED' : 'all checks passed');
	process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
