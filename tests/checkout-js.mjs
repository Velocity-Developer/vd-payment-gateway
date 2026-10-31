/**
 * Tes browser checkout vd-store: halaman checkout tidak boleh memunculkan error JS
 * (regresi "this.isCodPayment is not a function", vd-store 1.5.0 → diperbaiki di 1.5.1).
 * Tiga skenario: COD mati, ongkir mode off, semua produk digital. Tiap skenario memuat
 * halaman checkout (login admin) lalu menekan tombol pesan bila formulir memungkinkan.
 * Dijalankan oleh run-tests.sh (env WP, WP_CLI). Butuh Playwright: env VDPG_PLAYWRIGHT
 * (path ke paket playwright/playwright-core) atau folder node_modules di sebelah folder wordpress.
 * Bila Playwright tidak ada, suite dilewati (dicetak "dilewati", dianggap lulus).
 */
import { execFileSync } from 'child_process';
import { existsSync, readdirSync } from 'fs';
import { homedir } from 'os';
import { dirname, join } from 'path';
import { fileURLToPath, pathToFileURL } from 'url';

const DIR = dirname(fileURLToPath(import.meta.url));
const WP = process.env.WP;
const WP_CLI = (process.env.WP_CLI || 'wp').split(' ');
let pass = 0, fail = 0;
const check = (label, ok, info = '') => { ok ? pass++ : fail++; console.log(`${ok ? '  OK  ' : '  GAGAL '}${label}${info ? ' — ' + info : ''}`); };
const wp = (...a) => execFileSync(WP_CLI[0], [...WP_CLI.slice(1), '--allow-root', 'eval-file', join(DIR, 'checkout-js-setup.php'), ...a], { cwd: WP, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim().split('\n').pop();

async function loadPlaywright() {
    const candidates = [process.env.VDPG_PLAYWRIGHT, join(WP, '..', 'node_modules', 'playwright-core'), join(WP, '..', 'node_modules', 'playwright')].filter(Boolean);
    for (const c of candidates) {
        const pkg = join(c, 'index.mjs');
        if (existsSync(pkg)) return import(pathToFileURL(pkg).href);
        if (existsSync(join(c, 'index.js'))) return import(pathToFileURL(join(c, 'index.js')).href);
    }
    try { return await import('playwright'); } catch { return null; }
}

const pw = await loadPlaywright();
if (!pw) {
    console.log('  (Playwright tidak ditemukan, suite dilewati)');
    console.log('Hasil: 0 lulus, 0 gagal');
    process.exit(0);
}
const chromium = pw.chromium || pw.default?.chromium;
// Browser: VDPG_CHROMIUM, lalu bawaan Playwright, lalu build Chromium mana pun di cache Playwright.
function cachedChromiums() {
    const root = join(homedir(), '.cache', 'ms-playwright');
    if (!existsSync(root)) return [];
    return readdirSync(root).sort().reverse().flatMap(d => [
        join(root, d, 'chrome-headless-shell-linux64', 'chrome-headless-shell'),
        join(root, d, 'chrome-linux64', 'chrome'),
        join(root, d, 'chrome-linux', 'chrome'),
    ]).filter(existsSync);
}
let browser = null;
for (const exe of [process.env.VDPG_CHROMIUM, undefined, ...cachedChromiums()]) {
    if (exe === '' ) continue;
    try { browser = await chromium.launch(exe ? { executablePath: exe } : {}); break; } catch {}
}
if (!browser) {
    console.log('  (Chromium untuk Playwright tidak ditemukan, suite dilewati)');
    console.log('Hasil: 0 lulus, 0 gagal');
    process.exit(0);
}

const scenarios = {
    'cod-off': { submit: false, note: 'COD mati (alamat wajib, jadi cukup muat halaman)' },
    'shipping-off': { submit: true, note: 'ongkir mode off' },
    'all-digital': { submit: true, note: 'semua produk digital, ongkir dinonaktifkan untuk digital' },
};

try {
    for (const [name, sc] of Object.entries(scenarios)) {
        console.log(`== ${name}: ${sc.note}`);
        const setup = JSON.parse(wp('setup', name));
        check(`${name}: keranjang terisi`, setup.cart_status === 200, `HTTP ${setup.cart_status}`);
        const host = new URL(setup.url).hostname;
        const ctx = await browser.newContext();
        await ctx.addCookies(setup.cookies.map(c => ({ ...c, domain: host })));
        const page = await ctx.newPage();
        const errors = [];
        page.on('pageerror', e => errors.push('pageerror: ' + e.message));
        page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

        const methods = page.waitForResponse(r => r.url().includes('settings/payment-methods'), { timeout: 15000 }).catch(() => null);
        await page.goto(setup.url, { waitUntil: 'networkidle' });
        check(`${name}: daftar metode pembayaran dimuat`, !!(await methods));
        await page.waitForTimeout(500);
        const pm = await page.evaluate(() => { const el = document.querySelector('[x-data*="wpStoreCheckout"]'); return el && window.Alpine ? window.Alpine.$data(el).paymentMethod : null; });
        check(`${name}: metode terpilih bukan COD`, pm && pm !== 'cod', `paymentMethod=${pm}`);

        if (sc.submit) {
            // Pengguna login bisa memakai data profil (field tersembunyi); isi lewat state Alpine bila begitu.
            await page.evaluate(() => {
                const d = window.Alpine.$data(document.querySelector('[x-data*="wpStoreCheckout"]'));
                d.name = 'Tes Checkout JS'; d.email = 'checkout-js@example.com'; d.phone = '081234567890';
                d.recomputeAllow();
            });
            const btn = page.locator('button:has-text("Buat Pesanan")').first();
            await btn.waitFor({ state: 'visible', timeout: 10000 });
            await page.waitForFunction(b => !b.disabled, await btn.elementHandle(), { timeout: 10000 }).catch(() => null);
            const posted = page.waitForResponse(r => /wp-store\/v1\/checkout(\?|$)/.test(r.url()) && r.request().method() === 'POST', { timeout: 15000 }).catch(() => null);
            await btn.click();
            const res = await posted;
            check(`${name}: tombol pesan mengirim checkout ke server`, !!res && res.status() < 400, res ? `HTTP ${res.status()}` : 'tidak ada request checkout');
            await page.waitForTimeout(800);
            check(`${name}: pesanan tercipta`, Number(wp('orders')) >= 1);
        }
        const relevant = errors.filter(e => !/favicon|Failed to load resource/i.test(e));
        check(`${name}: tidak ada error JS`, relevant.length === 0, relevant.join(' | ').slice(0, 300));
        check(`${name}: tidak ada "isCodPayment is not a function"`, !errors.some(e => e.includes('isCodPayment')));
        await ctx.close();
        wp('cleanup');
    }
} finally {
    await browser.close();
    try { wp('cleanup'); } catch {}
}
console.log(`Hasil: ${pass} lulus, ${fail} gagal`);
process.exit(fail ? 1 : 0);
