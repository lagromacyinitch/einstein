const fs = require('fs');
const assert = require('assert/strict');
const { chromium } = require('playwright-core');
const base = process.env.TEST_BASE_URL || 'http://localhost/EINSTEIN-WEB18/public/';

(async () => {
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        const response = await page.request.get(base + 'api/api_homepage_content.php');
        const saved = await response.json();
        assert.equal(saved.success, true);
        await page.goto(base + 'workshop?v=content-test', { waitUntil: 'domcontentloaded' });
        const activities = page.locator('[data-homepage-list="workshop.activities"]');
        await page.waitForFunction(() => typeof window.loadProgramContent === 'function');
        await page.evaluate(() => window.loadProgramContent());
        assert.deepEqual(await activities.locator('.program-tag').allTextContents(), saved.content['workshop.activities']);

        // Read-only API fixtures: never change the user's saved settings.
        const content = { ...saved.content,
            'workshop.activities': ['Sketching', '<img src=x onerror=alert(1)>'],
            'tutorial.regular.features': ['Updated tutorial inclusion'],
            'playschool.caterpillar.morning': 'Morning: 8:00 AM - 10:00 AM',
            'playschool.butterfly.morning': 'Morning: 10:00 AM - 12:00 PM',
            'childcare.inclusions': ['Updated care inclusion'],
            'mad.rates.ballet.schedule': 'Saturday 2:00 PM - 3:00 PM',
            'summer.offering.arts.items': ['Sketching for summer'],
        };
        await page.route('**/api/api_homepage_content.php', route => route.fulfill({ json: { success: true, content } }));
        await page.evaluate(() => window.loadProgramContent());
        assert.deepEqual(await activities.locator('.program-tag').allTextContents(), content['workshop.activities']);
        assert.equal(await activities.locator('img').count(), 0);
        content['workshop.activities'] = [];
        await page.evaluate(() => window.loadProgramContent());
        assert.equal(await activities.locator('.program-tag').count(), 0);
        await page.locator('[data-tab="rates"]').click();
        assert.equal(await page.locator('#tab-rates').evaluate(el => el.classList.contains('active')), true);

        const checks = [
            ['academic_tutorial', '[data-homepage-list="tutorial.regular.features"]', 'Updated tutorial inclusion'],
            ['playschool', '[data-program-school-schedules]', 'Morning: 8:00 AM - 10:00 AM'],
            ['childcare_program', '[data-homepage-list="childcare.inclusions"]', 'Updated care inclusion'],
            ['mad', '[data-homepage-text="mad.rates.ballet.schedule"]', 'Saturday 2:00 PM - 3:00 PM'],
            ['summerblast', '[data-homepage-list="summer.offering.arts.items"]', 'Sketching for summer'],
        ];
        for (const [name, selector, expected] of checks) {
            await page.goto(base + name + '?v=content-test', { waitUntil: 'domcontentloaded' });
            await page.waitForFunction(() => typeof window.loadProgramContent === 'function');
            await page.evaluate(() => window.loadProgramContent());
            assert.ok((await page.locator(selector).textContent()).includes(expected), name);
            assert.equal(await page.locator('.enroll-btn').count(), 1);
            if (name === 'playschool') assert.ok((await page.locator(selector).textContent()).includes(content['playschool.butterfly.morning']));
            // Parse each page's embedded scripts as an additional syntax check.
            const html = fs.readFileSync('public/' + name + '.html', 'utf8');
            for (const match of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)) new Function(match[1]);
        }
        await page.unroute('**/api/api_homepage_content.php');
        await page.route('**/api/api_homepage_content.php', route => route.fulfill({ status: 503, body: '{}' }));
        await page.goto(base + 'workshop?v=fallback-test', { waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => typeof window.loadProgramContent === 'function');
        await page.evaluate(() => window.loadProgramContent());
        assert.ok((await activities.textContent()).includes('Drum'));
        console.log('PASS: saved workshop activities, additions/removals, safe text, tabs, all six content bindings, separate class schedules, API failure fallback.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
