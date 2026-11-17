const fs = require('fs');
const path = require('path');
const os = require('os');
const assert = require('assert/strict');
const vm = require('vm');
const { chromium } = require('playwright-core');
const html = fs.readFileSync('public/user.html', 'utf8');
for (const match of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)) if (match[1].trim()) new vm.Script(match[1]);
const executablePath = process.env.CHROME_PATH || ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(fs.existsSync);
(async () => {
    const browser = await chromium.launch({ executablePath, headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const schedules = [
            {enrollment_id:1,child_name:'Maryo',program:'Academic Tutorial',tutor_id:1,tutor_name:'Teacher Ana',timeslot:'8:00 AM - 9:00 AM',slot_type:'CB',days:{mon:true,tue:true}},
            {enrollment_id:2,child_name:'Maya',program:'PlaySchool',tutor_id:2,tutor_name:'Teacher Bea',timeslot:'1:00 PM - 2:00 PM',slot_type:'CB',days:{wed:true,fri:true}}
        ];
        const changes = [];
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (url.hostname !== 'einstein.test') return route.fulfill({status:200,body:''});
            if (url.pathname.startsWith('/api/')) {
                let result = {success:true,settings:{},announcements:[],programs:[],name:'Maria',email:'maria@example.test'};
                if (url.pathname.endsWith('check_session.php')) Object.assign(result,{logged_in:true,user_id:10});
                if (url.pathname.endsWith('get_enrollments.php')) result = {success:true,enrollments:[],stats:{active:2,pending:0},schedules,schedule_changes:changes};
                return route.fulfill({json:result});
            }
            const file = url.pathname.startsWith('/user') ? 'public/user.html' : path.join('public', url.pathname);
            if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return route.fulfill({status:404,body:''});
            const contentType = file.endsWith('.html') ? 'text/html' : file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream';
            return route.fulfill({body:fs.readFileSync(file),contentType});
        });
        await page.goto('http://einstein.test/user/schedule');
        await page.evaluate(() => {
            loadPrograms();
            window.enrollmentClicks = [];
            window.handleEnroll = name => window.enrollmentClicks.push(name);
        });
        assert.equal(await page.locator('.program-enroll-btn').count(), 6);
        for (let i = 0; i < 6; i++) {
            const card = page.locator('.program-card').nth(i);
            await card.evaluate(el => el.click());
            await card.locator('.program-enroll-btn').evaluate(el => el.click());
        }
        const clicks = await page.evaluate(() => window.enrollmentClicks);
        assert.equal(clicks.length, 12, 'Button should not bubble into a second enrollment');
        for (let i = 0; i < clicks.length; i += 2) assert.equal(clicks[i], clicks[i + 1]);

        await page.waitForFunction(() => document.getElementById('parent-schedule-body').textContent.includes('Maryo'));
        assert.equal(await page.locator('#user-page-schedule').isVisible(), true, 'Direct Schedule URL works');
        const labels = await page.locator('.drawer-nav-item').allTextContents();
        assert.deepEqual(labels.map(text => text.trim().replace(/\s+\d+$/, '')), ['Programs','Enrollments','Schedule','VIP Section','My Balance','Announcements','My Profile']);
        assert.equal(await page.locator('#schedule-child-filter').count(),0,'Child dropdown removed');
        assert.equal(await page.locator('.schedule-normal').count(),4,'Initial occupied cells are normal');
        assert.equal(await page.locator('.schedule-normal').first().evaluate(el => getComputedStyle(el).backgroundColor),'rgb(220, 238, 255)');
        assert.equal(await page.locator('.schedule-empty').first().evaluate(el => getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)');
        schedules[0].timeslot='10:00 AM - 11:00 AM';
        schedules[0].updated_days={mon:true,tue:true};
        changes.push({id:1,enrollment_id:1,child_name:'Maryo',program:'Academic Tutorial',previous_timeslot:'Monday, Tuesday 8:00 AM - 9:00 AM',new_timeslot:'Monday, Tuesday 10:00 AM - 11:00 AM',changed_at:'2026-11-16 09:00:00'});
        await page.evaluate(() => loadEnrollments(true));
        assert.ok((await page.locator('#parent-schedule-body').textContent()).includes('10:00 AM - 11:00 AM'), 'Silent polling updates the timetable');
        assert.equal(await page.locator('.schedule-updated').count(),2,'Only changed occupied cells are highlighted');
        assert.equal(await page.locator('.schedule-normal').count(),2,'Other child stays blue');
        assert.equal(await page.locator('.schedule-updated').first().evaluate(el => getComputedStyle(el).backgroundColor),'rgb(212, 237, 218)');
        assert.equal(await page.locator('#userNotifDot').textContent(),'1');
        await page.evaluate(() => toggleUserNotif());
        const notice = page.locator('.user-notif-item').first();
        const text = await notice.textContent();
        for (const part of ['Maryo','Academic Tutorial','8:00 AM - 9:00 AM','10:00 AM - 11:00 AM']) assert.ok(text.includes(part));
        await notice.click();
        assert.equal(await page.locator('#userNotifDot').isVisible(), false, 'Opening notice marks it read');
        await page.reload();
        await page.waitForFunction(() => document.getElementById('parent-schedule-body').textContent.includes('Maryo'));
        assert.equal(await page.locator('#userNotifDot').isVisible(),false,'Read state persists after reload');
        assert.equal(await page.locator('.schedule-updated').count(),2,'Updated colors persist after reload');
        await page.screenshot({path:path.join(os.tmpdir(), 'parent-schedule-desktop.png'),fullPage:true});
        await page.setViewportSize({width:390,height:844});
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true,'No page overflow on mobile');
        assert.equal(await page.locator('.parent-schedule-scroll').evaluate(el => el.scrollWidth > el.clientWidth),true,'Weekly table scrolls within its own container');
        await page.screenshot({path:path.join(os.tmpdir(), 'parent-schedule-mobile.png'),fullPage:true});
        assert.deepEqual(errors,[],'No browser JavaScript errors');
        console.log('PASS: sidebar labels/order, direct route, no dropdown, normal/updated cell colors, live refresh, detailed notification/read state, and mobile scrolling.');
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode=1;});
