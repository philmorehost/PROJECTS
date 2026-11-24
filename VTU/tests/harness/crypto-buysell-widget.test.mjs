process.on('uncaughtException', error => { console.error('::error::' + String(error.stack || error).replace(/[\r\n]+/g, ' ')); process.exit(1); });
import chromium from '@sparticuz/chromium';
import { chromium as playwright } from 'playwright-core';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import { brotliDecompressSync } from 'node:zlib';
import { createRequire } from 'node:module';

const dir = path.dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const libraries = fs.mkdtempSync(path.join(os.tmpdir(), 'cbs-widget-libs-'));
const archive = path.resolve(path.dirname(require.resolve('@sparticuz/chromium')), '../../bin/al2023.tar.br');
fs.writeFileSync(path.join(libraries, 'libs.tar'), brotliDecompressSync(fs.readFileSync(archive)));
execFileSync('tar', ['-xf', path.join(libraries, 'libs.tar'), '-C', libraries]);

let browser;
const rendered = JSON.parse(execFileSync(process.execPath, [path.join(dir, 'run-test.mjs'), 'tests/crypto_buysell_widget_render.php'], { encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 }));

try {
    const saasWidget = fs.readFileSync(path.resolve(dir, '../../DGV7.0-SAAS/func/bc-crypto-buysell-widget.php'), 'utf8');
    const nonSaasWidget = fs.readFileSync(path.resolve(dir, '../../DGV7.0-NON-SAAS/func/bc-crypto-buysell-widget.php'), 'utf8');
    assert.equal(saasWidget, nonSaasWidget, 'SAAS and NON-SAAS widget parity');

    browser = await playwright.launch({
        args: chromium.args,
        executablePath: await chromium.executablePath(),
        headless: true,
        env: { ...process.env, LD_LIBRARY_PATH: path.join(libraries, 'lib') + ':' + (process.env.LD_LIBRARY_PATH || '') }
    });

    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));

    let mode = 'held';
    await page.route('**/*', route => {
        const req = route.request();
        const url = new URL(req.url());
        if (url.pathname === '/web/api/crypto-buysell.php' && req.method() === 'POST') {
            assert.equal(req.headers()['x-csrf-token'], 'widget-test-csrf');
            const body = JSON.parse(req.postData() || '{}');
            if (mode === 'held') {
                return route.fulfill({
                    status: 503,
                    contentType: 'application/json',
                    body: JSON.stringify({ status: 'error', code: 'CRYPTO_BUYSELL_HOLD', message: 'Crypto Buy/Sell remains held pending custody/provider verification and operator activation.' })
                });
            }
            if (body.action === 'quote') {
                return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'ok', quote: { quote_id: 42, total_fiat_minor: 10000000 } }) });
            }
            if (body.action === 'create_sell') {
                return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'ok', order: { order_id: 77, deposit_address: 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL' } }) });
            }
            return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'ok', order: { order_id: 78, state: 'never_attempted' } }) });
        }
        return route.fulfill({ status: 200, contentType: 'text/html', body: rendered.html });
    });

    for (const width of [320, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('https://vtu.test/web/CryptoHub.php');
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `no horizontal overflow at ${width}px`);
        assert.match(await page.locator('#bccbs-bal').textContent(), /250,000\.00/);
        console.log(`PASS Crypto Buy/Sell widget layout at ${width}px`);
    }

    // Toggle Buy -> Sell -> Buy and NGN -> USDT
    await page.getByRole('tab', { name: 'Sell crypto' }).click();
    assert.match(await page.locator('#bccbs-amount-label').textContent(), /NGN/);
    await page.locator('#bccbs-unit').click();
    assert.equal(await page.locator('#bccbs-unit').textContent(), 'USDT');

    // Held mode returns 503 CRYPTO_BUYSELL_HOLD cleanly
    await page.locator('#bccbs-amount').fill('50');
    await page.locator('#bccbs-quote-btn').click();
    await page.waitForSelector('#bccbs-error:not([hidden])');
    assert.match(await page.locator('#bccbs-error').textContent(), /held/i);

    // Simulated enabled mode walks through quote -> 2:00 hold -> inline address check -> Sell deposit
    mode = 'enabled';
    await page.getByRole('tab', { name: 'Buy crypto' }).click();
    await page.locator('#bccbs-asset').selectOption('USDT_TRON');
    await page.locator('#bccbs-amount').fill('60');
    await page.locator('#bccbs-quote-btn').click();
    await page.waitForSelector('[data-step="confirm"]:not([hidden])');
    await page.locator('#bccbs-addr').fill('bad-address');
    await page.locator('#bccbs-confirm-btn').click();
    assert.match(await page.locator('#bccbs-error').textContent(), /TRON/);

    await page.getByRole('tab', { name: 'Sell crypto' }).click();
    await page.locator('#bccbs-amount').fill('50');
    await page.locator('#bccbs-quote-btn').click();
    await page.waitForSelector('[data-step="confirm"]:not([hidden])');
    await page.locator('#bccbs-confirm-btn').click();
    await page.waitForSelector('[data-step="deposit"]:not([hidden])');
    assert.equal(await page.locator('#bccbs-d-addr').textContent(), 'TNPeeaaFB7K9cmo4uQpcU32zGK8G1NYqeL');

    assert.deepEqual(errors, []);
    console.log('PASS Crypto Buy/Sell widget browser interaction, CSRF, 503 hold & Sell deposit flow');
} finally {
    if (browser) await browser.close();
    fs.rmSync(libraries, { recursive: true, force: true });
}
