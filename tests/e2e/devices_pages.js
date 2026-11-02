/*
 * Browser check of Step 5 (devices): list with health + pending box, add device, approve a pending ADMS serial number, API keys (shown
 * once, works, revoked), command queue + enrol an employee, call log, unmatched punches + link a PIN, punches for closed days,
 * import punches (preview, import, again); 403 for a user without permissions.
 * Data: seed_attendance.php then seed_devices.php.   Screenshots: docs/step-5/.
 *
 *   TENANT_DB=hrm_dev php -S 127.0.0.1:8099 tests/support/router.php &
 *   HRM_ADMIN_PASSWORD=... HRM_E2E_PASSWORD=... node tests/e2e/devices_pages.js
 */
const { chromium } = require('playwright');
const fs = require('fs');
const os = require('os');
const path = require('path');

const BASE = process.env.HRM_BASE_URL || 'http://127.0.0.1:8099/';
const OUT = path.join(__dirname, '..', '..', 'docs', 'step-5');
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
async function setSelect(page, selector, part) {
	await page.evaluate(({ s, p }) => { const $s = window.jQuery(s); $s.val($s.find('option').filter(function () { return window.jQuery(this).text().indexOf(p) !== -1; }).val()).trigger('change'); }, { s: selector, p: part });
}
function ddmmyyyy(offsetDays) {
	const d = new Date(Date.now() + offsetDays * 86400000 + 6 * 3600000);   // Asia/Dhaka
	return String(d.getUTCDate()).padStart(2, '0') + '-' + String(d.getUTCMonth() + 1).padStart(2, '0') + '-' + d.getUTCFullYear();
}
function iso(offsetDays) { const [d, m, y] = ddmmyyyy(offsetDays).split('-'); return y + '-' + m + '-' + d; }

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

	// ---- list, health, pending ------------------------------------------------------------------------------------------
	await page.goto(BASE + 'devices');
	check(await rows(page, '#devices-table') >= 3, 'devices list shows the registered devices');
	const text = await page.locator('#devices-table tbody').innerText();
	check(/Online/.test(text) && /Delayed/.test(text) && /Offline/.test(text), 'health: online, delayed and offline');
	check(/Possible lost punches/.test(text), 'the lost-punch flag is shown');
	check(/clock \+15 min/.test(text), 'clock drift is shown');
	check(await page.locator('#pending-box').count() === 1 && /PENDING9001/.test(await page.locator('#pending-box').innerText()), 'the unknown serial number waits for approval');
	await page.screenshot({ path: path.join(OUT, '01-devices.png'), fullPage: true });

	// ---- add a device -----------------------------------------------------------------------------------------------------
	await page.goto(BASE + 'devices/add');
	await page.fill('#f_name', 'Warehouse door');
	await page.selectOption('#f_mode', 'adms_push');
	await page.fill('#f_serial', 'WAREHOUSE01');
	await page.fill('#f_ip', '192.168.1.80');
	await page.screenshot({ path: path.join(OUT, '02-device-form.png') });
	await Promise.all([page.waitForURL(/\/devices$/, { timeout: 8000 }).catch(() => {}), page.click('#device-save')]);
	check(/\/devices$/.test(page.url()), 'a device is added');
	check(await rows(page, '#devices-table') >= 4 && /Warehouse door/.test(await page.locator('#devices-table tbody').innerText()), 'it is in the list');
	await page.goto(BASE + 'devices/add');
	await page.fill('#f_name', 'Warehouse door');
	await page.selectOption('#f_mode', 'adms_push');
	await page.fill('#f_serial', 'WAREHOUSE01');
	await page.click('#device-save');
	check(/already exists/.test(await toast(page)), 'a duplicate name is refused');

	// ---- approve the pending serial number --------------------------------------------------------------------------------
	await page.goto(BASE + 'devices');
	await page.click('#pending-table a.btn-success');
	await page.fill('#f_name', 'Gate 2');
	await page.screenshot({ path: path.join(OUT, '03-approve.png') });
	await Promise.all([page.waitForURL(/\/devices$/, { timeout: 8000 }).catch(() => {}), page.click('#approve-save')]);
	check(/\/devices$/.test(page.url()) && await page.locator('#pending-box').count() === 0, 'the pending serial number is approved and gone from the list');
	await rows(page, '#devices-table');
	await page.waitForFunction(() => /Gate 2/.test(document.querySelector('#devices-table tbody').innerText), null, { timeout: 8000 }).catch(() => {});
	check(/Gate 2/.test(await page.locator('#devices-table tbody').innerText()), 'Gate 2 is a registered device');

	// ---- API key: shown once, works, revoked ------------------------------------------------------------------------------
	await page.goto(BASE + 'devices/keys');
	await page.fill('#f_label', 'Factory PC');
	await page.click('#key-save');
	await page.waitForSelector('#new-key-box', { state: 'visible', timeout: 6000 });
	const key = (await page.locator('#new-key').innerText()).trim();
	check(/^hrmk_[a-f0-9]{40}$/.test(key), 'the new key is shown once: ' + key.slice(0, 9) + '...');
	await page.screenshot({ path: path.join(OUT, '04-api-key.png') });
	const ok = await page.request.post(BASE + 'api/v1/pull_attendance', { headers: { 'X-API-Key': key }, data: '{"test": true}' });
	check(ok.status() === 200 && /Connection OK/.test(await ok.text()), 'the key authenticates the Windows Service endpoint');
	await page.goto(BASE + 'devices/keys');
	check(await rows(page, '#keys-table') >= 1 && !(await page.content()).includes(key), 'the key is not on the page any more');
	await page.click('#keys-table button.btn-danger');
	await toast(page);
	const refused = await page.request.post(BASE + 'api/v1/pull_attendance', { headers: { 'X-API-Key': key }, data: '{"test": true}' });
	check(refused.status() === 401, 'a revoked key is refused (401)');

	// ---- commands + enrol -------------------------------------------------------------------------------------------------
	await page.goto(BASE + 'devices/commands');
	await setSelect(page, '#f_device', 'Factory ADMS');
	await page.selectOption('#f_type', 'sync_time');
	await page.click('#queue-save');
	await toast(page);
	await page.waitForTimeout(1200);                      // the page reloads after the save
	await page.waitForLoadState('load');
	check(await rows(page, '#commands-table') >= 1 && /Sync clock/.test(await page.locator('#commands-table tbody').innerText()), 'a command is queued');
	const id = await page.evaluate(() => { const o = window.jQuery('#f_employee option').filter(function () { return /ATT-01/.test(window.jQuery(this).text()); }); return o.length ? o.val() : null; });
	check(!!id, 'found the employee ATT-01 (id ' + id + ')');
	await page.goto(BASE + 'employees/view/' + id);
	await page.click('a[href="#tab-pin"]');
	await page.check('#enrol-form input[name="device_ids[]"] >> nth=0');
	await page.fill('#enrol-form input[name=device_password]', '4321');
	await page.screenshot({ path: path.join(OUT, '05-enrol.png') });
	await page.click('#enrol-save');
	check(/Saved/.test(await toast(page)), 'the employee is enrolled on the device (create_user queued)');
	await page.goto(BASE + 'devices/commands');
	const cmdText = await rows(page, '#commands-table').then(() => page.locator('#commands-table tbody').innerText());
	check(/Add user to device/.test(cmdText) && /PIN 500/.test(cmdText) && !/4321/.test(cmdText), 'create_user is listed with the PIN, the device password is not');
	await page.screenshot({ path: path.join(OUT, '06-commands.png'), fullPage: true });

	// ---- call log --------------------------------------------------------------------------------------------------------
	await page.goto(BASE + 'devices/logs');
	check(await rows(page, '#logs-table') >= 3, 'the call log lists the calls');
	await page.check('#errors-only');
	await page.waitForTimeout(800);
	const errs = await page.locator('#logs-table tbody').innerText();
	check(/401/.test(errs) && !/connection test/.test(errs), 'errors only');
	await page.screenshot({ path: path.join(OUT, '07-call-log.png') });

	// ---- unmatched punches: link PIN 777 -----------------------------------------------------------------------------------
	await page.goto(BASE + 'attendance/unmatched');
	check(await rows(page, '#unmatched-table') >= 1 && /777/.test(await page.locator('#unmatched-table tbody').innerText()), 'the unmatched PIN 777 is listed');
	await page.click('#unmatched-table button.link-pin >> nth=0');
	await page.waitForSelector('#link-modal', { state: 'visible' });
	await page.waitForTimeout(600);                       // the modal fades in
	await page.evaluate(() => { const $s = window.jQuery('#link-employee'); $s.val($s.find('option').filter(function () { return /ATT-07/.test(window.jQuery(this).text()); }).val()).trigger('change'); });
	await page.screenshot({ path: path.join(OUT, '08-link-pin.png') });
	await page.click('#link-save');
	await toast(page);
	await page.waitForLoadState('load');
	await page.waitForTimeout(800);
	const after = await page.locator('#unmatched-table tbody').innerText();
	check(!/\b777\b/.test(after), 'the PIN is linked: 777 left the unmatched list');

	// ---- punches for closed days -------------------------------------------------------------------------------------------
	await page.goto(BASE + 'attendance/closed_punches');
	check(await rows(page, '#closed-table') >= 1 && /ATT-01/.test(await page.locator('#closed-table tbody').innerText()), 'a late punch for a closed day is listed');
	await page.screenshot({ path: path.join(OUT, '09-closed-days.png') });

	// ---- import ------------------------------------------------------------------------------------------------------------
	const day = iso(-1);
	const file = path.join(os.tmpdir(), 'usb-export.dat');
	const sec = String(new Date().getSeconds()).padStart(2, '0');       // another second each run, so the first preview shows new punches
	fs.writeFileSync(file, ['500', '501'].map((p, i) => '      ' + p + '\t' + day + ' 0' + (8 + i) + ':1' + i + ':' + sec + '\t1\t1\t0\t0').join('\n') + '\n      9999\t' + day + ' 09:30:' + sec + '\t1\t1\t0\t0\n');
	await page.goto(BASE + 'attendance/import');
	await setSelect(page, '#f_device', 'Main Gate');
	await page.setInputFiles('#f_file', file);
	await page.click('#import-preview');
	await page.waitForSelector('#preview-box', { state: 'visible', timeout: 8000 });
	const preview = await page.locator('#preview-box').innerText();
	check(/Valid punches\s+3/.test(preview) && /9999/.test(preview), 'the preview shows 3 punches and the unmatched PIN 9999');
	await page.screenshot({ path: path.join(OUT, '10-import-preview.png'), fullPage: true });
	await page.click('#import-commit');
	check(/new/.test(await toast(page)), 'the import is done');
	await setSelect(page, '#f_device', 'Main Gate');      // the form was reset after the import
	await page.setInputFiles('#f_file', file);
	await page.click('#import-preview');
	await page.waitForFunction(() => /Already stored\s+3/.test(document.querySelector('#preview-summary').innerText), null, { timeout: 8000 }).catch(() => {});
	check(/Already stored\s+3/.test(await page.locator('#preview-summary').innerText()) && /New punches\s+0/.test(await page.locator('#preview-summary').innerText()), 'importing the same file again: everything is already stored');
	await ctx.close();

	// ---- no permissions --------------------------------------------------------------------------------------------------------
	const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 860 } });
	const p2 = await ctx2.newPage();
	probing = true;
	await login(p2, 'nopriv', E2E_PASS);
	for (const url of ['devices', 'devices/add', 'devices/keys', 'devices/commands', 'devices/logs', 'attendance/unmatched', 'attendance/closed_punches', 'attendance/import']) {
		const r = await p2.goto(BASE + url);
		check(r.status() === 403, 'no permission: ' + url + ' is HTTP 403 (' + r.status() + ')');
	}
	const device = await p2.request.post(BASE + 'api/v1/pull_attendance', { data: '{"test": true}' });
	check(device.status() === 401, 'the device endpoint without a key is 401');
	await p2.goto(BASE + 'devices');
	await p2.screenshot({ path: path.join(OUT, '11-nopriv-403.png') });
	await ctx2.close();
	probing = false;

	await browser.close();
	check(problems.length === 0, 'no console errors or page errors' + (problems.length ? ': ' + problems.join(' | ') : ''));
	console.log(failures.length ? failures.length + ' check(s) FAILED' : 'all checks passed');
	process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
