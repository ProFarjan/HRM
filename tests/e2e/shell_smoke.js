/*
 * Browser check of the application shell (Playwright + the bundled Chromium):
 *   - anonymous visitors are sent to the login page
 *   - the admin sees the dashboard, the sidebar, the user menu; AJAX POSTs carry the CSRF token
 *   - a user with no permissions gets 403 on every page (dashboard, helper URLs), but can still change their password
 *   - the pages load without console errors or failed asset requests
 * Screenshots go to docs/step-0/.
 *
 *   php -S 127.0.0.1:8099 tests/support/router.php &     (with TENANT_DB=hrm_dev)
 *   HRM_ADMIN_PASSWORD=... HRM_E2E_PASSWORD=... node tests/e2e/shell_smoke.js
 */
const { chromium } = require('playwright');
const path = require('path');

const BASE = process.env.HRM_BASE_URL || 'http://127.0.0.1:8099/';
const OUT = path.join(__dirname, '..', '..', 'docs', 'step-0');
const ADMIN = { user: process.env.HRM_ADMIN_USER || 'admin', pass: process.env.HRM_ADMIN_PASSWORD || '' };
const E2E_PASS = process.env.HRM_E2E_PASSWORD || 'E2e#Passw0rd';

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

(async () => {
	const browser = await chromium.launch();
	const problems = [];
	let probing = false;          // while true, 4xx answers are expected (negative tests) and not counted as problems
	function watch(page, label) {
		page.on('console', (m) => { if (m.type() === 'error' && !probing) { problems.push(label + ' console: ' + m.text()); } });
		page.on('pageerror', (e) => problems.push(label + ' pageerror: ' + e.message));
		page.on('requestfailed', (r) => problems.push(label + ' request failed: ' + r.url()));
		page.on('response', (r) => { if (r.status() >= 400 && /\/theme\//.test(r.url())) { problems.push(label + ' asset ' + r.status() + ': ' + r.url()); } });
	}

	// ---- anonymous ------------------------------------------------------------------------------------------
	let ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
	let page = await ctx.newPage();
	watch(page, 'anonymous');
	await page.goto(BASE + 'dashboard');
	check(/auth\/login/.test(page.url()), 'anonymous /dashboard redirects to the login page (' + page.url() + ')');
	await page.screenshot({ path: path.join(OUT, '01-login.png') });
	await login(page, ADMIN.user, 'definitely-wrong');
	check(await page.locator('#login-error').count() === 1, 'a wrong password shows one error and stays on the form');
	await ctx.close();

	// ---- admin ----------------------------------------------------------------------------------------------
	ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
	page = await ctx.newPage();
	watch(page, 'admin');
	await login(page, ADMIN.user, ADMIN.pass);
	check(/\/dashboard$/.test(page.url()), 'admin lands on /dashboard (' + page.url() + ')');
	check(await page.locator('#dash-branch').count() === 1, 'dashboard placeholder is rendered');
	check(await page.locator('.sidebar-menu a:has-text("Dashboard")').count() === 1, 'sidebar shows the Dashboard entry');
	check((await page.title()).includes('Dashboard'), 'page title: ' + (await page.title()));
	await page.screenshot({ path: path.join(OUT, '02-admin-dashboard.png') });

	await page.click('.user-menu > a');
	await page.waitForSelector('.user-footer', { state: 'visible' });
	await page.screenshot({ path: path.join(OUT, '03-admin-user-menu.png') });

	const ping = await page.evaluate(() => new Promise((resolve) => {
		window.jQuery.post(window.HRM.pingUrl).always((a, b, c) => resolve({ status: (c && c.status) || (a && a.status) }));
	}));
	check(ping.status === 200, 'jQuery POST gets the CSRF token from app.js and succeeds (HTTP ' + ping.status + ')');
	const iCheck = await page.evaluate(() => typeof window.jQuery.fn.iCheck + '/' + typeof window.jQuery.fn.dataTable + '/' + typeof window.jQuery.fn.select2 + '/' + typeof window.jQuery.fn.datepicker + '/' + typeof window.toastr);
	check(iCheck === 'function/function/function/function/object', 'iCheck, DataTables, select2, datepicker, toastr are loaded (' + iCheck + ')');

	await page.goto(BASE + 'auth/change_password');
	check(await page.locator('input[name=new_password]').count() === 1, 'change password page opens');
	await page.screenshot({ path: path.join(OUT, '04-change-password.png') });

	// helpers are not URLs - even for the admin
	probing = true;
	for (const name of ['load_info', 'deny', 'belong_to', 'render']) {
		const r = await page.goto(BASE + 'dashboard/' + name);
		check([403, 404].includes(r.status()), 'dashboard/' + name + ' is not served for the admin (HTTP ' + r.status() + ')');
	}
	for (const url of ['cron/ping', 'migrate']) {
		const r = await page.goto(BASE + url);
		check(r.status() === 404, url + ' is 404 over HTTP');
	}

	probing = false;
	// logout is POST only: the menu button works
	await page.goto(BASE + 'dashboard');
	await page.click('.user-menu > a');
	await Promise.all([page.waitForLoadState('load'), page.click('#logout-form button')]);
	check(/auth\/login/.test(page.url()), 'logout returns to the login page (' + page.url() + ')');
	await ctx.close();

	// ---- user without permissions -----------------------------------------------------------------------------
	ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
	page = await ctx.newPage();
	watch(page, 'nopriv');
	probing = true;               // after login the app redirects to /dashboard, which is 403 for this user
	await login(page, 'nopriv', E2E_PASS);
	let r = await page.goto(BASE + 'dashboard');
	check(r.status() === 403, 'no-permission user: /dashboard is HTTP 403 (' + r.status() + ')');
	check((await page.content()).toLowerCase().includes('permission'), '403 page explains that permission is missing');
	await page.screenshot({ path: path.join(OUT, '05-nopriv-dashboard-403.png') });
	r = await page.goto(BASE + 'dashboard/load_info');
	check(r.status() === 403, 'no-permission user: dashboard/load_info is HTTP 403 (' + r.status() + ')');
	r = await page.goto(BASE + 'auth/change_password');
	check(r.status() === 200, 'no-permission user can still change their own password');
	const ajax = await page.evaluate((url) => fetch(url, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then((x) => x.status), BASE + 'dashboard/ping');
	check(ajax === 405, 'AJAX GET on a POST-only endpoint is HTTP 405 (' + ajax + ')');
	await ctx.close();
	probing = false;

	// ---- viewer (dashboard_hr only) on a phone-sized screen --------------------------------------------------------
	ctx = await browser.newContext({ viewport: { width: 390, height: 780 } });
	page = await ctx.newPage();
	watch(page, 'viewer');
	await login(page, 'viewer', E2E_PASS);
	r = await page.goto(BASE + 'dashboard');
	check(r.status() === 200, 'user holding dashboard_hr gets the dashboard (HTTP ' + r.status() + ')');
	await page.screenshot({ path: path.join(OUT, '06-viewer-dashboard-mobile.png') });
	await ctx.close();

	await browser.close();
	check(problems.length === 0, 'no console errors, page errors or failed asset requests' + (problems.length ? ': ' + problems.join(' | ') : ''));
	console.log(failures.length ? failures.length + ' check(s) FAILED' : 'all checks passed');
	process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
