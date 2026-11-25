import chromium from '@sparticuz/chromium';
import { chromium as playwright } from 'playwright-core';
import { execFileSync } from 'node:child_process';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import { brotliDecompressSync } from 'node:zlib';
import { createRequire } from 'node:module';

const dir = path.dirname(fileURLToPath(import.meta.url));
const saasRoot = path.resolve(dir, '../../DGV7.0-SAAS');
const fixture = JSON.parse(
  execFileSync(process.execPath, [path.join(dir, 'run-test.mjs'), 'tests/meridian_render_fixture.php'], {
    encoding: 'utf8',
    maxBuffer: 8 * 1024 * 1024,
  })
);

const mimeTypes = {
  '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.webp': 'image/webp',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.html': 'text/html; charset=utf-8',
};

const routes = {
  '/': fixture.personal,
  '/yellow': fixture.personal_yellow,
  '/Business.php': fixture.business,
  '/ReferAndEarn.php': fixture.refer,
  '/Services.php': fixture.services,
  '/Contact.php': fixture.contact,
};

const server = http.createServer((req, res) => {
  const urlPath = (req.url || '/').split('?')[0];
  if (Object.prototype.hasOwnProperty.call(routes, urlPath)) {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(routes[urlPath]);
    return;
  }
  const safePath = path.normalize(urlPath).replace(/^(\.\.(\/|\\|$))+/, '');
  const filePath = path.join(saasRoot, safePath);
  if (filePath.startsWith(saasRoot) && fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
    const ext = path.extname(filePath).toLowerCase();
    res.writeHead(200, { 'Content-Type': mimeTypes[ext] || 'application/octet-stream' });
    fs.createReadStream(filePath).pipe(res);
    return;
  }
  res.writeHead(404);
  res.end('Not found');
});

await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const { port } = server.address();
const baseUrl = `http://127.0.0.1:${port}`;

const require = createRequire(import.meta.url);
const libraries = fs.mkdtempSync(path.join(os.tmpdir(), 'meridian-libs-'));
const archive = path.resolve(path.dirname(require.resolve('@sparticuz/chromium')), '../../bin/al2023.tar.br');
fs.writeFileSync(path.join(libraries, 'libs.tar'), brotliDecompressSync(fs.readFileSync(archive)));
execFileSync('tar', ['-xf', path.join(libraries, 'libs.tar'), '-C', libraries]);

const browser = await playwright.launch({
  args: chromium.args,
  executablePath: await chromium.executablePath(),
  headless: true,
  env: { ...process.env, LD_LIBRARY_PATH: path.join(libraries, 'lib') + ':' + (process.env.LD_LIBRARY_PATH || '') },
});

let checks = 0;
try {
  const page = await browser.newPage();
  const pageErrors = [];
  page.on('pageerror', (err) => pageErrors.push(err.message));

  for (const route of ['/', '/yellow', '/Business.php', '/ReferAndEarn.php', '/Services.php', '/Contact.php']) {
    for (const width of [1440, 375]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(baseUrl + route, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(150);

      const metrics = await page.evaluate(() => {
        const overflow = document.documentElement.scrollWidth > window.innerWidth + 1;
        const rgb = (s) => (s.match(/[\d.]+/g) || []).map(Number);
        const blend = (a, b) => {
          const alpha = a.length > 3 ? a[3] : 1;
          return [0, 1, 2].map((i) => a[i] * alpha + b[i] * (1 - alpha));
        };
        const bgOf = (el) => {
          if (!el) return [5, 5, 7];
          const c = rgb(getComputedStyle(el).backgroundColor);
          return blend(c.length ? c : [0, 0, 0, 0], bgOf(el.parentElement));
        };
        const lum = (a) => {
          const l = a.map((c) => {
            c /= 255;
            return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
          });
          return 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2];
        };
        const contrast = (a, b) => {
          const x = lum(a);
          const y = lum(b);
          return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
        };

        const primaries = [...document.querySelectorAll('.btn.primary, .btn-dark')];
        const lowContrastButtons = primaries
          .map((el) => {
            const st = getComputedStyle(el);
            const bg = bgOf(el);
            const fg = blend([...rgb(st.color).slice(0, 3), Number(st.opacity)], bg);
            return { text: el.textContent.trim(), ratio: contrast(fg, bg) };
          })
          .filter((r) => r.ratio < 4.5);

        return { overflow, lowContrastButtons, checkedButtons: primaries.length };
      });

      assert.equal(metrics.overflow, false, `${route} at ${width}px overflows horizontally`);
      assert.deepEqual(metrics.lowContrastButtons, [], `${route} at ${width}px low contrast buttons: ${JSON.stringify(metrics.lowContrastButtons)}`);
      checks += 2 + metrics.checkedButtons;
    }
  }

  // Interactive JS checks on Personal landing page
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(baseUrl + '/', { waitUntil: 'domcontentloaded' });
  await page.click('#flipBtn');
  assert.equal(await page.locator('#card3d').evaluate((el) => el.classList.contains('is-flipped')), true, 'card flip toggles .is-flipped');
  const beforeJar = await page.locator('#jarAmt').textContent();
  await page.click('#vaultBtn');
  const afterJar = await page.locator('#jarAmt').textContent();
  assert.notEqual(beforeJar, afterJar, 'savings jar updates amount on Add ₦5,000');
  checks += 2;

  // Interactive chapter switch on Business Dark page
  await page.goto(baseUrl + '/Business.php', { waitUntil: 'domcontentloaded' });
  await page.locator('#bstory .bs-rail button').nth(2).click();
  const thirdActive = await page.locator('#bstory .bs-ch').nth(2).evaluate((el) => el.classList.contains('is-active'));
  assert.equal(thirdActive, true, 'business chapter rail activates clicked chapter');
  checks += 1;

  // Mobile sheet menu check
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto(baseUrl + '/', { waitUntil: 'domcontentloaded' });
  await page.click('#menuBtn');
  const sheetOpen = await page.locator('#sheet').evaluate((el) => !el.hidden);
  assert.equal(sheetOpen, true, 'mobile menuBtn opens #sheet');
  checks += 1;

  assert.deepEqual(pageErrors, [], `JS pageerrors detected: ${JSON.stringify(pageErrors)}`);
  console.log(`${checks} Meridian-1 Chromium browser checks passed (zero JS errors, zero overflow, contrast & interactions verified).`);
} finally {
  await browser.close();
  server.close();
  fs.rmSync(libraries, { recursive: true, force: true });
}
