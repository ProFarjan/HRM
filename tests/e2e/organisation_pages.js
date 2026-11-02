/*
 * Browser check of Step 2 (organisation): departments, designations, employees (form, profile, salary, documents, PIN,
 * exit) as the admin; masking and hidden salary for a clerk; 403 for a user without permissions. Screenshots: docs/step-2/.
 *
 *   TENANT_DB=hrm_dev php -S 127.0.0.1:8099 tests/support/router.php &
 *   HRM_ADMIN_PASSWORD=... HRM_E2E_PASSWORD=... node tests/e2e/organisation_pages.js
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.HRM_BASE_URL || 'http://127.0.0.1:8099/';
const OUT = path.join(__dirname, '..', '..', 'docs', 'step-2');
const ADMIN = { user: process.env.HRM_ADMIN_USER || 'admin', pass: process.env.HRM_ADMIN_PASSWORD || '' };
const E2E_PASS = process.env.HRM_E2E_PASSWORD || 'E2e#Passw0rd';
const RUN = Date.now().toString(36).slice(-4).toUpperCase();

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
async function pick(page, selector, label) {        // select2-enhanced <select>
	await page.evaluate(({ s, l }) => { const $s = window.jQuery(s); const v = $s.find('option').filter(function () { return window.jQuery(this).text().indexOf(l) !== -1; }).val(); $s.val(v).trigger('change'); }, { s: selector, l: label });
}
async function tab(page, href) {
	await page.click('a[href="' + href + '"]');
	await page.waitForTimeout(300);
}

(async () => {
	const browser = await chromium.launch();
	const problems = [];
	let probing = false;
	const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
	const page = await ctx.newPage();
	page.on('dialog', (d) => d.accept());
	page.on('console', (m) => { if (m.type() === 'error' && !probing) { problems.push('console: ' + m.text()); } });
	page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
	await login(page, ADMIN.user, ADMIN.pass);

	// ---- departments / designations ---------------------------------------------------------------------------
	await page.goto(BASE + 'departments/add');
	await page.fill('#f_name', 'Sewing ' + RUN);
	await page.fill('#f_code', 'SEW' + RUN);
	await page.screenshot({ path: path.join(OUT, '01-department-form.png') });
	await page.click('#unit-save');
	await page.waitForURL(/\/departments$/);
	check(await rows(page, '#units-table') >= 1, 'department saved and listed');
	await page.goto(BASE + 'designations/add');
	await page.fill('#f_name', 'Operator ' + RUN);
	await page.fill('#f_code', 'OP' + RUN);
	await page.fill('#f_level', '2');
	await page.click('#unit-save');
	await page.waitForURL(/\/designations$/);
	check((await page.locator('#units-table').innerText()).includes('Operator ' + RUN), 'designation saved and listed');
	await page.screenshot({ path: path.join(OUT, '02-designations-list.png') });

	// ---- employee form ------------------------------------------------------------------------------------------
	await page.goto(BASE + 'employees/add');
	await page.fill('#f_first_name', 'নুসরাত');
	await page.fill('#f_last_name', 'Jahan ' + RUN);
	await page.fill('#f_mobile', '+880 1711-555000');
	await tab(page, '#tab-job');
	await pick(page, '#f_department_id', 'Sewing ' + RUN);
	await pick(page, '#f_designation_id', 'Operator ' + RUN);
	await page.fill('#f_join_date', '01-01-2025');
	const pin = String(7000 + Math.floor(Math.random() * 2000));
	await page.fill('#f_device_pin', pin);
	await page.screenshot({ path: path.join(OUT, '03-employee-form-job.png') });
	await tab(page, '#tab-bank');
	await page.fill('#f_bank_name', 'Dutch-Bangla Bank');
	await page.fill('#f_bank_account_number', '1051100012345');
	await page.fill('#f_national_id', '19951234567890');
	await page.click('#employee-save');
	const saved = await Promise.race([page.waitForURL(/\/employees$/).then(() => 'ok'), toast(page)]);
	if (saved !== 'ok') { throw new Error('employee save failed: ' + saved); }
	check(await rows(page, '#employees-table') >= 1, 'employee saved and listed');
	await page.screenshot({ path: path.join(OUT, '04-employees-list.png') });
	await page.locator('#employees-table a:has-text("Jahan ' + RUN + '")').click();
	await page.waitForSelector('#emp-personal');
	const empUrl = page.url();
	check((await page.locator('#emp-job').innerText()).includes('1051100012345'), 'admin sees the bank account in clear');
	await page.screenshot({ path: path.join(OUT, '05-employee-profile.png') });

	// ---- salary -----------------------------------------------------------------------------------------------
	await tab(page, '#tab-salary');
	await page.fill('#salary-form input[name=effective_from]', '01-01-2025');
	await page.fill('#salary-form input[name=basic_salary]', '18000');
	await page.fill('#salary-form input[name=house_rent]', '7200');
	await page.fill('#salary-form input[name=medical_allowance]', '1250');
	await page.fill('#salary-form input[name=pf_deduction]', '900');
	check(await page.locator('#salary-net').innerText() === '25550.00', 'live net preview');
	await page.click('#salary-save');
	check(/Saved/.test(await toast(page)), 'salary structure saved');
	await page.waitForLoadState('load');
	await page.waitForTimeout(600);
	await tab(page, '#tab-salary');
	await page.fill('#salary-form input[name=effective_from]', '01-07-2025');
	await page.fill('#salary-form input[name=basic_salary]', '20000');
	await page.fill('#salary-form input[name=house_rent]', '8000');
	await page.click('#salary-save');
	await toast(page);
	await page.waitForLoadState('load');
	await page.waitForTimeout(600);
	await tab(page, '#tab-salary');
	check(await page.locator('#salary-table tbody tr').count() === 2, 'two structures, the older one closed');
	check((await page.locator('#salary-table tbody tr').nth(1).innerText()).includes('30-06-2025'), 'previous ends the day before');
	await page.screenshot({ path: path.join(OUT, '06-salary-history.png') });

	// ---- documents ----------------------------------------------------------------------------------------------
	await tab(page, '#tab-documents');
	const pdf = path.join(__dirname, '..', '_output', 'e2e-contract.pdf');
	fs.writeFileSync(pdf, '%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n');
	await page.fill('#document-form input[name=title]', 'Appointment letter');
	await page.setInputFiles('#document-form input[name=document]', pdf);
	await page.click('#document-form button[type=submit]');
	check(/Saved/.test(await toast(page)), 'document uploaded');
	await page.waitForLoadState('load');
	await page.waitForTimeout(600);
	await tab(page, '#tab-documents');
	check(await page.locator('#documents-table tbody tr').count() === 1, 'document listed');
	const [download] = await Promise.all([page.waitForEvent('download'), page.click('#documents-table a')]);
	check(/\.pdf$/.test(download.suggestedFilename()), 'document downloads through the controller (' + download.suggestedFilename() + ')');
	await page.screenshot({ path: path.join(OUT, '07-documents.png') });

	// ---- PIN change + exit --------------------------------------------------------------------------------------
	await tab(page, '#tab-pin');
	await page.fill('#pin-form input[name=new_pin]', String(Number(pin) + 1));
	await page.fill('#pin-form input[name=effective_from]', '01-03-2025');
	await page.click('#pin-form button[type=submit]');
	check(/Saved/.test(await toast(page)), 'PIN changed');
	await page.waitForLoadState('load');
	await page.waitForTimeout(600);
	await tab(page, '#tab-pin');
	check((await page.locator('#pin-history').innerText()).includes(pin), 'old PIN in the history');
	await page.screenshot({ path: path.join(OUT, '08-pin-history.png') });
	await tab(page, '#tab-overview');

	// ---- clerk: masked, no salary ---------------------------------------------------------------------------------
	const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 900 } });
	const p2 = await ctx2.newPage();
	p2.on('pageerror', (e) => problems.push('clerk pageerror: ' + e.message));
	await login(p2, 'clerk', E2E_PASS);
	await p2.goto(empUrl);
	const job = await p2.locator('#emp-job').innerText();
	check(job.includes('*********2345') && !job.includes('1051100012345'), 'clerk sees the account masked');
	check(await p2.locator('#tab-salary-link').count() === 0, 'clerk has no salary tab');
	await p2.screenshot({ path: path.join(OUT, '09-clerk-masked-profile.png') });
	await ctx2.close();

	// exit (admin)
	await page.goto(empUrl);
	await page.click('.collapsed-box [data-widget=collapse]');
	await page.fill('#exit-form input[name=exit_date]', '31-10-2026');
	await page.fill('#exit-form input[name=exit_reason]', 'Moved to another city');
	await page.click('#exit-form button[type=submit]');
	check(/Saved/.test(await toast(page)), 'exit recorded');
	await page.waitForLoadState('load');
	await page.waitForTimeout(600);
	check((await page.locator('#emp-status').innerText()).trim() === 'Resigned', 'status Resigned');
	await page.screenshot({ path: path.join(OUT, '10-after-exit.png') });
	await ctx.close();

	// ---- no permissions -------------------------------------------------------------------------------------------
	const ctx3 = await browser.newContext({ viewport: { width: 1280, height: 860 } });
	const p3 = await ctx3.newPage();
	probing = true;
	await login(p3, 'nopriv', E2E_PASS);
	const id = empUrl.split('/').pop();
	for (const url of ['employees', 'employees/add', 'employees/view/' + id, 'employees/edit/' + id, 'employees/photo/' + id, 'departments', 'departments/add', 'designations', 'designations/add']) {
		const r = await p3.goto(BASE + url);
		check(r.status() === 403, 'no permission: ' + url + ' is HTTP 403 (' + r.status() + ')');
	}
	await p3.goto(BASE + 'employees/view/' + id);
	await p3.screenshot({ path: path.join(OUT, '11-nopriv-employee-403.png') });
	await ctx3.close();
	probing = false;

	await browser.close();
	try { fs.unlinkSync(pdf); } catch (e) { /* ignore */ }
	check(problems.length === 0, 'no console errors or page errors' + (problems.length ? ': ' + problems.join(' | ') : ''));
	console.log(failures.length ? failures.length + ' check(s) FAILED' : 'all checks passed');
	process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
