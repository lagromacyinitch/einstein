const assert = require('assert/strict');
const {chromium} = require('playwright-core');
const base = process.env.TEST_BASE_URL || 'http://localhost/EINSTEIN-WEB18/public/';
(async () => {
    const browser = await chromium.launch({executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true});
    try {
        const page = await browser.newPage();
        await page.goto(base, {waitUntil: 'domcontentloaded'});
        await page.waitForFunction(() => typeof loadHomepageRates === 'function');
        await page.evaluate(() => loadHomepageRates());
        const response = await page.request.get(base + 'api/load_rates.php');
        const saved = await response.json();
        const selector = '#tutorial .pricing-card.regular .pricing-price';
        const expected = Number(saved.rates.tutorial[0].rate.replace(/[^0-9.]/g, '')).toLocaleString('en-US');
        assert.ok((await page.locator(selector).textContent()).includes(expected));

        // Stale legacy settings must never overwrite the managed tutorial rate.
        let rate = '4321';
        await page.route('**/api/api_settings.php', route => route.fulfill({json: {success: true, settings: {prices: {reg: 3300}}}}));
        await page.route('**/api/load_rates.php', route => route.fulfill({json: {success: true, rates: {...saved.rates, tutorial: [{name: 'Regular Package renamed', rate}]}}}));
        await page.evaluate(() => loadHomepageRates());
        assert.ok((await page.locator(selector).textContent()).includes('4,321'));
        rate = '4567';
        await page.evaluate(() => window.dispatchEvent(new Event('focus')));
        await page.waitForFunction(sel => document.querySelector(sel).textContent.includes('4,567'), selector);

        // Rendering the server response must also work when storage is unavailable.
        rate = '5678';
        await page.evaluate(() => {
            Storage.prototype.getItem = () => { throw new Error('Storage disabled'); };
            Storage.prototype.setItem = () => { throw new Error('Storage disabled'); };
        });
        await page.evaluate(() => loadHomepageRates());
        assert.ok((await page.locator(selector).textContent()).includes('5,678'));
        console.log('PASS: saved homepage rate, legacy override regression, renamed package, focus refresh, storage unavailable.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
