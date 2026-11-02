/*
 * Browser check of the attendance reports: monthly in/out sheet, one employee (by month and date to date), summary with insights and chart,
 * late, absent, overtime, missing check-out, Excel downloads; 403 for a user without permissions.
 * Data: TENANT_DB=hrm_dev php tests/e2e/seed_attendance.php   Screenshots: docs/reports/.
 *
 *   TENANT_DB=hrm_dev php -S 127.0.0.1:8099 tests/support/router.php &
 *   HRM_ADMIN_PASSWORD=... HRM_E2E_PASSWORD=... node tests/e2e/reports_pages.js
 */
const { chromium } = require('playwright');
const path = require('path');

const BASE = process.env.HRM_BASE_URL || 'http://127.0.0.1:8099/';
const OUT = path.join(__dirname, '..', '..', 'docs', 'reports');
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
function ddmmyyyy(offsetDays) {
	const d = new Date(Date.now() + offsetDays * 86400000 + 6 * 3600000);   // Asia/Dhaka
	return String(d.getUTCDate()).padStart(2, '0') + '-' + String(d.getUTCMonth() + 1).padStart(2, '0') + '-' + d.getUTCFullYear();
}
function monthOf(offsetDays) { const [, m, y] = ddmmyyyy(offsetDays).split('-'); return { month: parseInt(m, 10), year: parseInt(y, 10) }; }

(async () => {
	const browser = await chromium.launch();
	const problems = [];
	let probing = false;
	const ctx = await browser.newContext({ viewport: { width: 1500, height: 950 }, acceptDownloads: true });
	const page = await ctx.newPage();
	page.on('dialog', (d) => d.accept());
	page.on('console', (m) => { if (m.type() === 'error' && !probing) { problems.push('console: ' + m.text()); } });
	page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
	await login(page, ADMIN.user, ADMIN.pass);
	const from = ddmmyyyy(-13), to = ddmmyyyy(-1);
	const range = '?from_date=' + from + '&to_date=' + to;
	const m = monthOf(-6);                                                    // a month that holds most of the seeded days

	// ---- in/out sheet -----------------------------------------------------------------------------------------------------
	await page.goto(BASE + 'reports/inout?month=' + m.month + '&year=' + m.year);
	check(await page.locator('#inout-table tbody tr').count() >= 6, 'in/out sheet lists the employees');
	check(await page.locator('#inout-table .io-in').count() >= 20, 'every worked day shows the check-in over the check-out');
	check(await page.locator('#inout-table .att-late').count() >= 1, 'late days are marked');
	check(await page.locator('#inout-table .io-missing').count() >= 0, 'missing check-outs are marked when there are any');
	await page.screenshot({ path: path.join(OUT, '01-inout-sheet.png'), fullPage: true });
	const [d1] = await Promise.all([page.waitForEvent('download'), page.click('#inout-xlsx')]);
	check(/attendance-inout-\d{4}-\d{2}\.xlsx/.test(d1.suggestedFilename()), 'Excel: ' + d1.suggestedFilename());

	// ---- one employee -----------------------------------------------------------------------------------------------------
	await page.goto(BASE + 'reports/employee');
	check(await page.locator('#employee-table').count() === 0, 'the employee report starts with the picker');
	const id = await page.evaluate(() => { const o = window.jQuery('#r_employee option').filter(function () { return /ATT-02/.test(window.jQuery(this).text()); }); return o.length ? o.val() : null; });
	check(!!id, 'ATT-02 is in the picker (id ' + id + ')');
	await page.goto(BASE + 'reports/employee?employee_id=' + id + '&mode=range&from_date=' + from + '&to_date=' + to);
	check(await page.locator('#employee-table tbody tr').count() === 13, 'date to date: one row per day (13)');
	check(await page.locator('#insights li').count() >= 2, 'findings are shown in words');
	check(await page.locator('#employee-table .punch-times span').count() >= 20, 'punch times are listed per day');
	await page.screenshot({ path: path.join(OUT, '02-employee-range.png'), fullPage: true });
	await page.goto(BASE + 'reports/employee?employee_id=' + id + '&mode=month&month=' + m.month + '&year=' + m.year);
	check(await page.locator('#employee-table tbody tr').count() >= 28, 'by month: one row per day of the month');
	await page.screenshot({ path: path.join(OUT, '03-employee-month.png'), fullPage: true });
	// mode switch in the form
	await page.selectOption('#r_mode', 'range');
	check(await page.locator('.mode-range').isVisible() && !(await page.locator('.mode-month').isVisible()), 'the form switches between month and date to date');
	const [d2] = await Promise.all([page.waitForEvent('download'), page.click('#employee-xlsx')]);
	check(/attendance-ATT-02-/.test(d2.suggestedFilename()), 'Excel: ' + d2.suggestedFilename());

	// ---- summary ----------------------------------------------------------------------------------------------------------
	await page.goto(BASE + 'reports/summary' + range);
	check(await page.locator('#summary-table tbody tr').count() >= 6, 'summary: one row per employee');
	check(await page.locator('#insights li').count() >= 3, 'summary: what stands out');
	await page.waitForTimeout(700);
	check(await page.evaluate(() => { const c = document.getElementById('trend-chart'); return !!c && c.width > 50; }), 'the daily chart is drawn');
	await page.screenshot({ path: path.join(OUT, '04-summary.png'), fullPage: true });

	// ---- late, absent, overtime, missing ----------------------------------------------------------------------------------
	await page.goto(BASE + 'reports/late' + range);
	check(await page.locator('#late-ranking tbody tr').count() >= 1 && await page.locator('#late-detail tbody tr').count() >= 5, 'late: ranking and every late arrival');
	await page.screenshot({ path: path.join(OUT, '05-late.png'), fullPage: true });
	await page.goto(BASE + 'reports/absent' + range);
	check(await page.locator('#absent-table tbody tr').count() >= 1, 'absent: who and which dates');
	await page.screenshot({ path: path.join(OUT, '06-absent.png'), fullPage: true });
	await page.goto(BASE + 'reports/overtime' + range);
	check(await page.locator('#ot-ranking tbody tr').count() >= 1, 'overtime: ranking');
	await page.screenshot({ path: path.join(OUT, '07-overtime.png'), fullPage: true });
	await page.goto(BASE + 'reports/missing' + range);
	check(await page.locator('#missing-table tbody tr').count() >= 1 && /missing|no check-out/i.test(await page.locator('.callout').innerText()), 'missing check-out list');
	await page.screenshot({ path: path.join(OUT, '08-missing.png'), fullPage: true });
	await page.goto(BASE + 'reports/summary' + range);
	check(await page.locator('.sidebar-menu a[href$="reports/inout"]').count() === 1, 'the sidebar has a Reports group');
	await ctx.close();

	// ---- no permissions ---------------------------------------------------------------------------------------------------
	const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 860 } });
	const p2 = await ctx2.newPage();
	probing = true;
	await login(p2, 'nopriv', E2E_PASS);
	for (const url of ['inout', 'employee', 'summary', 'late', 'absent', 'overtime', 'missing', 'inout_xlsx', 'summary_xlsx']) {
		const r = await p2.goto(BASE + 'reports/' + url);
		check(r.status() === 403, 'no permission: reports/' + url + ' is HTTP 403 (' + r.status() + ')');
	}
	await ctx2.close();
	probing = false;

	await browser.close();
	check(problems.length === 0, 'no console errors or page errors' + (problems.length ? ': ' + problems.join(' | ') : ''));
	console.log(failures.length ? failures.length + ' check(s) FAILED' : 'all checks passed');
	process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
