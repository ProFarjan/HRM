/*
 * Browser check of Step 3 (time): shifts (incl. a night shift), shift assignments (single + bulk), roster grid (set cells,
 * save, reload), holidays (add, year view, copy fixed dates), default shift setting; 403 for a user without permissions.
 * Screenshots: docs/step-3/. Needs at least one current employee in the branch (the step 2 browser run creates some).
 *
 *   TENANT_DB=hrm_dev php -S 127.0.0.1:8099 tests/support/router.php &
 *   HRM_ADMIN_PASSWORD=... HRM_E2E_PASSWORD=... node tests/e2e/time_pages.js
 */
const { chromium } = require('playwright');
const path = require('path');

const BASE = process.env.HRM_BASE_URL || 'http://127.0.0.1:8099/';
const OUT = path.join(__dirname, '..', '..', 'docs', 'step-3');
const ADMIN = { user: process.env.HRM_ADMIN_USER || 'admin', pass: process.env.HRM_ADMIN_PASSWORD || '' };
const E2E_PASS = process.env.HRM_E2E_PASSWORD || 'E2e#Passw0rd';
const RUN = Date.now().toString(36).slice(-4).toUpperCase();

const failures = [];
function check(ok, message) { console.log((ok ? 'PASS ' : 'FAIL ') + message); if (!ok) { failures.push(message); } }
async function login(page, user, pass) {
	await page.goto(BASE + 'auth/login');
	await page.fill('input[name=username]', user);
	await page.fill('input[name=password]', pass);
	await Promise.all([page.waitForLoadState('load'), page.click('button[type=submit]')]);
}
async function toast(page) {
	const el = await page.waitForSelector('.toast', { timeout: 6000 }).catch(() => null);
	const text = el ? (await el.innerText()) : '(no toast)';
	await page.evaluate(() => window.toastr && window.toastr.clear());
	await page.waitForTimeout(300);
	return text;
}
async function rows(page, table) {
	await page.waitForSelector(table + ' tbody tr', { timeout: 6000 });
	await page.waitForFunction((t) => !document.querySelector(t + '_processing') || document.querySelector(t + '_processing').style.display === 'none', table);
	return page.locator(table + ' tbody tr').count();
}
/** choose the option whose text contains `part` (option labels carry the times, e.g. "General X (09:00-18:00)") */
async function pickOption(locator, part) {
	const value = await locator.evaluate((el, p) => { const o = Array.from(el.options).find((x) => x.text.indexOf(p) !== -1); return o ? o.value : null; }, part);
	if (value === null) { throw new Error('no option containing ' + part); }
	await locator.selectOption(value);
}
async function shiftForm(page, name, code, start, end, hours) {
	await page.goto(BASE + 'shifts/add');
	await page.fill('#f_shift_name', name);
	await page.fill('#f_shift_code', code);
	await page.fill('#f_start', start);
	await page.fill('#f_end', end);
	await page.fill('#f_hours', hours);
}

(async () => {
	const browser = await chromium.launch();
	const problems = [];
	let probing = false;
	const ctx = await browser.newContext({ viewport: { width: 1360, height: 900 } });
	const page = await ctx.newPage();
	page.on('dialog', (d) => d.accept());
	page.on('console', (m) => { if (m.type() === 'error' && !probing) { problems.push('console: ' + m.text()); } });
	page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
	await login(page, ADMIN.user, ADMIN.pass);

	// ---- shifts ------------------------------------------------------------------------------------------------
	await shiftForm(page, 'General ' + RUN, 'G' + RUN, '09:00', '18:00', '8');
	await page.click('#shift-save');
	await page.waitForURL(/\/shifts$/);
	await shiftForm(page, 'Night ' + RUN, 'N' + RUN, '22:00', '06:00', '0');
	check(await page.locator('#night-hint').isVisible(), 'night-shift hint shown when end < start');
	await page.screenshot({ path: path.join(OUT, '01-night-shift-form.png') });
	await page.click('#shift-save');
	await page.waitForURL(/\/shifts$/);
	check(await rows(page, '#shifts-table') >= 2, 'shifts listed');
	check((await page.locator('#shifts-table').innerText()).includes('Night'), 'night label in the list');
	await page.screenshot({ path: path.join(OUT, '02-shifts-list.png') });

	// ---- assignments -------------------------------------------------------------------------------------------
	await page.goto(BASE + 'employee_shifts');
	await page.click('.collapsed-box [data-widget=collapse]');
	await pickOption(page.locator('#bulk-form select[name=shift_id]'), 'General ' + RUN);
	await page.fill('#bulk-form input[name=effective_from]', '01-01-2026');
	await page.click('#bulk-form button[type=submit]');
	const bulk = await toast(page);
	check(/assigned/.test(bulk), 'bulk assignment: ' + bulk);
	await page.waitForLoadState('load');
	await page.waitForTimeout(600);
	check(await rows(page, '#assign-table') >= 1 && (await page.locator('#assign-table').innerText()).includes('General ' + RUN), 'employees on the new shift');
	await page.screenshot({ path: path.join(OUT, '03-assignments.png') });
	await page.locator('#assign-table tbody a').first().click();
	await page.waitForSelector('#assign-form');
	await pickOption(page.locator('#assign-form select[name=shift_id]'), 'Night ' + RUN);
	await page.fill('#assign-form input[name=effective_from]', '01-07-2026');
	await page.click('#assign-form button[type=submit]');
	check(/Saved/.test(await toast(page)), 'single assignment saved');
	await page.waitForLoadState('load');
	await page.waitForTimeout(600);
	check((await page.locator('#assign-history').innerText()).includes('30-06-2026'), 'previous assignment closed the day before');
	await page.screenshot({ path: path.join(OUT, '04-employee-assignments.png') });

	// ---- roster ------------------------------------------------------------------------------------------------
	await page.goto(BASE + 'rosters');
	await page.fill('#roster-filter input[name=from_date]', '06-07-2026');
	await page.click('#roster-load');
	await page.waitForSelector('#roster-grid tbody tr');
	const cells = page.locator('#roster-grid .roster-cell');
	await cells.nth(0).selectOption('OFF');
	await pickOption(cells.nth(1), 'General ' + RUN);
	check(await page.locator('#roster-grid td.roster-changed').count() === 2, 'changed cells highlighted');
	await page.screenshot({ path: path.join(OUT, '05-roster-editing.png') });
	await page.click('#roster-save');
	check(/changed/.test(await toast(page)), 'roster saved');
	await page.waitForTimeout(800);
	check(await page.locator('#roster-grid .roster-cell').nth(0).inputValue() === 'OFF', 'saved cell reloaded as OFF');
	check((await page.locator('#roster-grid tbody tr').first().locator('td').first().innerText()).includes('Off'), 'what applies: Off');
	await page.screenshot({ path: path.join(OUT, '06-roster-saved.png') });

	// ---- holidays ----------------------------------------------------------------------------------------------
	await page.goto(BASE + 'holidays/add');
	await page.fill('#f_hname', 'Victory Day');
	await page.fill('#f_hdate', '16-12-2026');
	await page.evaluate(() => window.jQuery('input[name=is_fixed_date]').iCheck('check'));
	const saved = await Promise.race([page.waitForURL(/\/holidays$/).then(() => 'ok'), page.click('#holiday-save').then(() => toast(page))]);
	if (!/\/holidays$/.test(page.url())) { await page.waitForURL(/\/holidays$/, { timeout: 4000 }).catch(() => {}); }
	check(/\/holidays$/.test(page.url()) || saved === 'ok' || /already/.test(saved), 'holiday saved (' + saved + ')');
	await page.goto(BASE + 'holidays/index/2026');
	check((await page.locator('#year-view').innerText()).includes('Victory Day'), 'year view shows the holiday');
	await page.screenshot({ path: path.join(OUT, '07-holidays-year.png') });
	await page.goto(BASE + 'holidays/index/2027');
	await page.fill('#copy-year-form input[name=from_year]', '2026');
	await page.fill('#copy-year-form input[name=to_year]', '2027');
	await page.click('#copy-year-form button[type=submit]');
	check(/copied/.test(await toast(page)), 'fixed-date holidays copied');
	await page.waitForLoadState('load');
	await page.waitForTimeout(600);
	check((await page.locator('#year-view').innerText()).includes('Victory Day'), '2027 has the copied holiday');

	// ---- default shift in the settings -------------------------------------------------------------------------
	await page.goto(BASE + 'settings');
	await page.click('a[href="#tab-attendance"]');
	await page.evaluate((label) => { const $s = window.jQuery('#f_default_shift_id'); $s.val($s.find('option').filter(function () { return window.jQuery(this).text().indexOf(label) !== -1; }).val()); }, 'General ' + RUN);
	await page.screenshot({ path: path.join(OUT, '08-settings-default-shift.png') });
	await page.click('#settings-save');
	check(/Saved/.test(await toast(page)), 'default shift saved');
	await ctx.close();

	// ---- no permissions ------------------------------------------------------------------------------------------
	const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 860 } });
	const p2 = await ctx2.newPage();
	probing = true;
	await login(p2, 'nopriv', E2E_PASS);
	for (const url of ['shifts', 'shifts/add', 'employee_shifts', 'rosters', 'holidays', 'holidays/add']) {
		const r = await p2.goto(BASE + url);
		check(r.status() === 403, 'no permission: ' + url + ' is HTTP 403 (' + r.status() + ')');
	}
	await p2.goto(BASE + 'rosters');
	await p2.screenshot({ path: path.join(OUT, '09-nopriv-roster-403.png') });
	await ctx2.close();
	probing = false;

	await browser.close();
	check(problems.length === 0, 'no console errors or page errors' + (problems.length ? ': ' + problems.join(' | ') : ''));
	console.log(failures.length ? failures.length + ' check(s) FAILED' : 'all checks passed');
	process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
