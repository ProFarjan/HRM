/*
 * Browser check of split shifts (duty periods): the shift form with periods, the attendance list with stacked in / out times, marking a
 * day for a split-shift employee period by period, the employee report, the in/out sheet and the missing check-out report.
 * Data: TENANT_DB=hrm_dev php tests/e2e/seed_split.php (after seed_attendance.php)   Screenshots: docs/split-shift/.
 *
 *   TENANT_DB=hrm_dev php -S 127.0.0.1:8099 tests/support/router.php &
 *   HRM_ADMIN_PASSWORD=... HRM_E2E_PASSWORD=... node tests/e2e/split_shift_pages.js
 */
const { chromium } = require('playwright');
const path = require('path');

const BASE = process.env.HRM_BASE_URL || 'http://127.0.0.1:8099/';
const OUT = path.join(__dirname, '..', '..', 'docs', 'split-shift');
const ADMIN = { user: process.env.HRM_ADMIN_USER || 'admin', pass: process.env.HRM_ADMIN_PASSWORD || '' };
const E2E_PASS = process.env.HRM_E2E_PASSWORD || 'E2e#Passw0rd';

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
	await page.waitForSelector(table + ' tbody tr', { timeout: 8000 });
	await page.waitForFunction((t) => !document.querySelector(t + '_processing') || document.querySelector(t + '_processing').style.display === 'none', table);
	await page.waitForTimeout(250);
	return page.locator(table + ' tbody tr').count();
}
function ddmmyyyy(offsetDays) {
	const d = new Date(Date.now() + offsetDays * 86400000 + 6 * 3600000);   // Asia/Dhaka
	return String(d.getUTCDate()).padStart(2, '0') + '-' + String(d.getUTCMonth() + 1).padStart(2, '0') + '-' + d.getUTCFullYear();
}

(async () => {
	const browser = await chromium.launch();
	const problems = [];
	let probing = false;
	const ctx = await browser.newContext({ viewport: { width: 1400, height: 950 } });
	const page = await ctx.newPage();
	page.on('dialog', (d) => d.accept());
	page.on('console', (m) => { if (m.type() === 'error' && !probing) { problems.push('console: ' + m.text()); } });
	page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
	await login(page, ADMIN.user, ADMIN.pass);

	// ---- the shift form --------------------------------------------------------------------------------------------
	await page.goto(BASE + 'shifts/add');
	check(await page.locator('#split-fields').isHidden() && await page.locator('#single-fields').isVisible(), 'an ordinary shift form first (start, end, break)');
	await page.check('#f_split');
	check(await page.locator('#split-fields').isVisible() && await page.locator('#single-fields').isHidden(), 'ticking Split shift swaps the start / end fields for duty periods');
	check(await page.locator('#periods-table .period-row').count() === 2, 'two periods to start with');
	check(/8\.00 h/.test(await page.locator('#periods-total').innerText()), 'the total is worked out: 8.00 h');
	await page.click('#period-add');
	check(await page.locator('#periods-table .period-row').count() === 3, 'a period can be added');
	await page.locator('#periods-table .period-remove').last().click();
	const code = 'E2E' + String(Date.now()).slice(-6);
	await page.fill('#f_shift_name', 'E2E two-part duty');
	await page.fill('#f_shift_code', code);
	await page.screenshot({ path: path.join(OUT, '01-shift-form.png'), fullPage: true });
	await Promise.all([page.waitForURL(/\/shifts$/, { timeout: 8000 }).catch(() => {}), page.click('#shift-save')]);
	check(/\/shifts$/.test(page.url()), 'the split shift is saved');
	await rows(page, '#shifts-table');
	const listText = await page.locator('#shifts-table tbody').innerText();
	check(/10:00-14:00 \+ 18:00-22:00/.test(listText) && /Split/.test(listText), 'the list shows the periods and a Split badge');
	await page.screenshot({ path: path.join(OUT, '02-shift-list.png'), fullPage: true });
	// refused: periods that overlap
	await page.goto(BASE + 'shifts/add');
	await page.check('#f_split');
	await page.fill('#f_shift_name', 'Bad periods');
	await page.fill('#f_shift_code', 'BAD' + String(Date.now()).slice(-5));
	await page.locator('#periods-table input[name="seg_end[]"]').first().fill('19:00');
	await page.click('#shift-save');
	check(/previous one has ended/.test(await toast(page)), 'overlapping periods are refused with a clear message');
	// clean up the shift created above
	await page.goto(BASE + 'shifts');
	await rows(page, '#shifts-table');
	const mine = page.locator('#shifts-table tbody tr', { hasText: code });
	await mine.locator('button.btn-danger').click();
	await toast(page);

	// ---- the attendance list ---------------------------------------------------------------------------------------
	await page.goto(BASE + 'attendance');
	await page.fill('#attendance-filter input[name=from_date]', ddmmyyyy(-6));
	await page.fill('#attendance-filter input[name=to_date]', ddmmyyyy(-1));
	await page.click('#attendance-filter button[type=submit]');
	await page.waitForTimeout(800);
	await page.fill('.dataTables_filter input', 'SPL-');
	await page.waitForTimeout(900);
	check(await rows(page, '#attendance-table') >= 6, 'the list has the split-shift days');
	check(await page.locator('#attendance-table tbody td br').count() >= 4, 'in and out show one time per duty period, stacked');
	await page.screenshot({ path: path.join(OUT, '03-attendance-list.png') });

	// ---- mark a day period by period -------------------------------------------------------------------------------
	await page.goto(BASE + 'attendance/mark');
	check(await page.locator('#time-fields').isVisible(), 'an ordinary in / out pair until an employee on a split shift is chosen');
	await page.evaluate(() => { const $s = window.jQuery('#f_employee'); $s.val($s.find('option').filter(function () { return /SPL-04/.test(window.jQuery(this).text()); }).val()).trigger('change'); });
	await page.fill('#f_date', ddmmyyyy(-1));
	await page.locator('#f_date').dispatchEvent('change');
	await page.waitForSelector('#period-rows .period-input-row', { timeout: 6000 });
	check(await page.locator('#period-rows .period-input-row').count() === 2 && await page.locator('#time-fields').isHidden(), 'the form asks for the in and out of each duty period');
	const inputs = page.locator('#period-rows input');
	await inputs.nth(0).fill('10:02'); await inputs.nth(1).fill('14:00');
	await inputs.nth(2).fill('18:20'); await inputs.nth(3).fill('21:50');
	await page.screenshot({ path: path.join(OUT, '04-mark-periods.png') });
	await Promise.all([page.waitForURL(/\/attendance$/, { timeout: 8000 }).catch(() => {}), page.click('#attendance-save')]);
	check(/\/attendance$/.test(page.url()), 'the day is saved period by period' + (/\/attendance$/.test(page.url()) ? '' : ' (' + await toast(page) + ')'));
	await page.goto(BASE + 'attendance');
	await page.fill('.dataTables_filter input', 'SPL-04');
	await page.waitForTimeout(900);
	const row = await page.locator('#attendance-table tbody tr').first().innerText();
	check(/10:02/.test(row) && /18:20/.test(row) && /7\.47/.test(row), 'SPL-04 shows 10:02 / 18:20 in and 7.47 hours (the gap is not counted)');

	// ---- reports ---------------------------------------------------------------------------------------------------
	await page.goto(BASE + 'reports/employee');
	const eid = await page.evaluate(() => { const o = window.jQuery('#r_employee option').filter(function () { return /SPL-01/.test(window.jQuery(this).text()); }); return o.length ? o.val() : null; });
	check(!!eid, 'SPL-01 is in the employee picker');
	await page.goto(BASE + 'reports/employee?employee_id=' + eid + '&mode=range&from_date=' + ddmmyyyy(-13) + '&to_date=' + ddmmyyyy(-1));
	check(await page.locator('#employee-table tbody tr').count() === 13 && await page.locator('#employee-table .label-info').count() >= 5, 'employee report: every day, marked as a split shift');
	await page.screenshot({ path: path.join(OUT, '05-employee-report.png'), fullPage: true });
	const m = new Date(Date.now() + 6 * 3600000);
	await page.goto(BASE + 'reports/inout?month=' + (m.getUTCMonth() + 1) + '&year=' + m.getUTCFullYear());
	check(await page.locator('#inout-table .io-split').count() >= 5, 'in/out sheet: a block per duty period');
	await page.screenshot({ path: path.join(OUT, '06-inout-sheet.png'), fullPage: true });
	await page.goto(BASE + 'reports/missing?from_date=' + ddmmyyyy(-13) + '&to_date=' + ddmmyyyy(-1));
	const missing = await page.locator('#missing-table tbody').innerText();
	check(/Duty period 2: no punches/.test(missing) && /No check-out/.test(missing), 'missing report names the period: no punches / no check-out');
	await page.screenshot({ path: path.join(OUT, '07-missing.png'), fullPage: true });
	await ctx.close();

	// ---- no permission ---------------------------------------------------------------------------------------------
	const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 860 } });
	const p2 = await ctx2.newPage();
	probing = true;
	await login(p2, 'nopriv', E2E_PASS);
	for (const url of ['shifts/add', 'attendance/mark', 'reports/missing']) {
		const r = await p2.goto(BASE + url);
		check(r.status() === 403, 'no permission: ' + url + ' is HTTP 403 (' + r.status() + ')');
	}
	await ctx2.close();
	probing = false;
	await browser.close();
	check(problems.length === 0, 'no console errors or page errors' + (problems.length ? ': ' + problems.join(' | ') : ''));
	console.log(failures.length ? failures.length + ' check(s) FAILED' : 'all checks passed');
	process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
