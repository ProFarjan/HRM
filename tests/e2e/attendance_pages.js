/*
 * Browser check of Step 4 (attendance): list + missing check-out filter, mark, manual punch, edit + rebuild, bulk mark,
 * raw punches (unmatched), calendar, daily and monthly reports + Excel; 403 for a user without permissions.
 * Data: TENANT_DB=hrm_dev php tests/e2e/seed_attendance.php   Screenshots: docs/step-4/.
 *
 *   TENANT_DB=hrm_dev php -S 127.0.0.1:8099 tests/support/router.php &
 *   HRM_ADMIN_PASSWORD=... HRM_E2E_PASSWORD=... node tests/e2e/attendance_pages.js
 */
const { chromium } = require('playwright');
const path = require('path');

const BASE = process.env.HRM_BASE_URL || 'http://127.0.0.1:8099/';
const OUT = path.join(__dirname, '..', '..', 'docs', 'step-4');
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
	await page.waitForTimeout(200);
	return page.locator(table + ' tbody tr').count();
}
async function pickSelect2(page, selector, part) {
	await page.evaluate(({ s, p }) => { const $s = window.jQuery(s); $s.val($s.find('option').filter(function () { return window.jQuery(this).text().indexOf(p) !== -1; }).val()).trigger('change'); }, { s: selector, p: part });
}
function ddmmyyyy(offsetDays) {
	const d = new Date(Date.now() + offsetDays * 86400000 + 6 * 3600000);   // Asia/Dhaka
	return String(d.getUTCDate()).padStart(2, '0') + '-' + String(d.getUTCMonth() + 1).padStart(2, '0') + '-' + d.getUTCFullYear();
}

(async () => {
	const browser = await chromium.launch();
	const problems = [];
	let probing = false;
	const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 }, acceptDownloads: true });
	const page = await ctx.newPage();
	page.on('dialog', (d) => d.accept());
	page.on('console', (m) => { if (m.type() === 'error' && !probing) { problems.push('console: ' + m.text()); } });
	page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
	await login(page, ADMIN.user, ADMIN.pass);

	// ---- list ---------------------------------------------------------------------------------------------------
	await page.goto(BASE + 'attendance');
	check(await rows(page, '#attendance-table') >= 10, 'attendance list shows the last 7 days');
	await page.screenshot({ path: path.join(OUT, '01-attendance-list.png') });
	await page.check('#missing-filter');
	await page.waitForTimeout(800);
	const missing = await page.locator('#attendance-table tbody').innerText();
	check(/Sumi/.test(missing) && /missing check-out/.test(missing), 'missing check-out filter finds the forgotten punch');
	await page.screenshot({ path: path.join(OUT, '02-missing-checkout.png') });

	// ---- mark + manual punch ------------------------------------------------------------------------------------
	await page.goto(BASE + 'attendance/mark');
	await pickSelect2(page, '#f_employee', 'ATT-06');
	await page.fill('#f_date', ddmmyyyy(-1));   // yesterday: the day close made it Absent; a manual mark replaces that row
	await page.fill('#f_in', '09:20');
	await page.fill('#f_out', '17:30');
	await page.screenshot({ path: path.join(OUT, '03-mark-form.png') });
	await page.click('#attendance-save');
	await page.waitForURL(/\/attendance$/, { timeout: 6000 }).catch(() => {});
	check(/\/attendance$/.test(page.url()), 'manual mark replaces the day-close Absent row'
		+ (/\/attendance$/.test(page.url()) ? '' : ' (' + await toast(page) + ')'));
	await page.goto(BASE + 'attendance/mark');
	await pickSelect2(page, '#punch-form select[name=employee_id]', 'ATT-01');
	// a minute that changes between runs, so a re-run against the same demo data stores a new punch
	await page.fill('#punch-form input[name=punch_time]', '07:' + String(new Date().getMinutes()).padStart(2, '0'));
	await page.click('#punch-form button[type=submit]');
	const punchToast = await toast(page);
	check(/Saved/.test(punchToast), 'manual punch stored');

	// ---- edit + rebuild ---------------------------------------------------------------------------------------------
	await page.goto(BASE + 'attendance');
	await rows(page, '#attendance-table');
	const editHref = await page.locator('#attendance-table tbody tr:has-text("ATT-02") a').first().getAttribute('href');
	await page.goto(editHref);
	await page.selectOption('#f_status', 'Half-day');
	await page.fill('#f_out', '13:00');
	await page.click('#attendance-save');
	await page.waitForURL(/\/attendance$/, { timeout: 6000 }).catch(() => {});
	await rows(page, '#attendance-table');
	const rowText = await page.locator('#attendance-table tbody tr:has-text("ATT-02")').first().innerText();
	check(/Half-day/.test(rowText), 'edited to Half-day');
	await page.locator('#attendance-table tbody tr:has-text("ATT-02") button[title="Rebuild from punches"]').first().click();
	check(/Saved/.test(await toast(page)), 'rebuild from punches');
	await page.waitForTimeout(800);
	check(/Present/.test(await page.locator('#attendance-table tbody tr:has-text("ATT-02")').first().innerText()), 'rebuilt from the punches: Present again');

	// ---- bulk ------------------------------------------------------------------------------------------------------
	await page.goto(BASE + 'attendance/bulk');
	await page.selectOption('select[name=department_id]', { label: 'Sewing floor' });
	await page.fill('input[name=check_in]', '09:00');
	await page.fill('input[name=check_out]', '18:00');
	await page.screenshot({ path: path.join(OUT, '04-bulk.png') });
	await page.click('#bulk-save');
	const bulk = await toast(page);
	check(/created/.test(bulk) && /skipped/.test(bulk), 'bulk mark: ' + bulk);

	// ---- punches -----------------------------------------------------------------------------------------------------
	await page.goto(BASE + 'attendance/punches');
	await page.fill('#punches-filter input[name=from_date]', ddmmyyyy(-6));
	await page.check('#punches-filter input[name=unmatched]');
	await page.waitForTimeout(800);
	check(await rows(page, '#punches-table') >= 3 && /Unmatched PIN/.test(await page.locator('#punches-table').innerText()), 'unmatched PIN punches listed');
	await page.screenshot({ path: path.join(OUT, '05-unmatched-punches.png') });
	await page.uncheck('#punches-filter input[name=unmatched]');
	await page.waitForTimeout(800);
	check(/Ignored/.test(await page.locator('#punches-table').innerText()), 'double taps shown as ignored');

	// ---- calendar ------------------------------------------------------------------------------------------------------
	const att01 = await page.evaluate(() => window.jQuery('#punches-filter select[name=employee_id] option').filter(function () { return window.jQuery(this).text().indexOf('ATT-01') !== -1; }).val());
	await page.goto(BASE + 'attendance/calendar/' + att01);
	await page.waitForSelector('.fc-event', { timeout: 8000 });
	check(await page.locator('.fc-event').count() >= 5, 'calendar shows the days');
	await page.screenshot({ path: path.join(OUT, '06-calendar.png') });

	// ---- reports -------------------------------------------------------------------------------------------------------
	await page.goto(BASE + 'reports/daily?date=' + ddmmyyyy(-1));
	check(await page.locator('#daily-table tbody tr').count() >= 6, 'daily report lists employees');
	await page.screenshot({ path: path.join(OUT, '07-daily-report.png') });
	await page.goto(BASE + 'reports/monthly');
	check(await page.locator('#monthly-table tbody tr').count() >= 6 && await page.locator('#monthly-table td.att-late').count() >= 1, 'monthly grid with late marks');
	await page.screenshot({ path: path.join(OUT, '08-monthly-report.png'), fullPage: true });
	const [download] = await Promise.all([page.waitForEvent('download'), page.click('#monthly-xlsx')]);
	check(/attendance-\d{4}-\d{2}\.xlsx/.test(download.suggestedFilename()), 'Excel export ' + download.suggestedFilename());
	await ctx.close();

	// ---- no permissions --------------------------------------------------------------------------------------------------
	const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 860 } });
	const p2 = await ctx2.newPage();
	probing = true;
	await login(p2, 'nopriv', E2E_PASS);
	for (const url of ['attendance', 'attendance/mark', 'attendance/bulk', 'attendance/punches', 'attendance/my_day', 'reports/daily', 'reports/monthly', 'reports/monthly_xlsx']) {
		const r = await p2.goto(BASE + url);
		check(r.status() === 403, 'no permission: ' + url + ' is HTTP 403 (' + r.status() + ')');
	}
	await p2.goto(BASE + 'reports/monthly');
	await p2.screenshot({ path: path.join(OUT, '09-nopriv-report-403.png') });
	await ctx2.close();
	probing = false;

	await browser.close();
	check(problems.length === 0, 'no console errors or page errors' + (problems.length ? ': ' + problems.join(' | ') : ''));
	console.log(failures.length ? failures.length + ' check(s) FAILED' : 'all checks passed');
	process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
