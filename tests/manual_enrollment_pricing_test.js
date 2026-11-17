const fs = require('fs');
const assert = require('assert/strict');
const { chromium } = require('playwright-core');
const html = fs.readFileSync('public/admin.html', 'utf8');
const executablePath = process.env.CHROME_PATH || ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(fs.existsSync);
(async () => {
  const browser = await chromium.launch({ executablePath, headless: true });
  try {
    const page = await browser.newPage();
    await page.setContent(html.slice(html.indexOf('    <!-- Add Student Modal -->'), html.indexOf('    <!-- Student View Modal -->')));
    await page.addScriptTag({ content: `
      const siteSettings = {
        vipDiscounts: { tutorial: 5, workshop: 5, playschool: 20, summerblast: 10, madstudio: 10 },
        tutorials: [{name:'Regular Package',rate:'₱3,300'}, {name:'Double Package',rate:'₱5,500'}],
        workshops: [{name:'Group Subscription',rate:'₱2,500'}],
        playschool: [{name:'Caterpillar',rate:'₱4,875'}],
        childcareRates: {'tt-nonmember-monthly':'12250','tt-vip-monthly':'10413'},
        summerblast: [{name:'Single Course',rate:'₱3,900'}, {name:'Registration Fee',rate:'₱1,000'}],
        madstudio: [{name:'Ballet Class',rate:'₱2,500'}, {name:'Studio Rental',rate:'₱450/hour'}]
      };
      function ensureRatesDefaults() {}
      function escHtml(value) { return String(value).replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;'); }
      function showToast(message) { throw new Error(message); }
      ${html.slice(html.indexOf('    let manualEnrollmentSaving = false;'), html.indexOf('    async function saveManualEnrollment()'))}
    ` });
    const result = await page.evaluate(() => {
      const card = document.querySelector('.enrollment-child');
      const program = card.querySelector('[data-child-field="program"]');
      const select = card.querySelector('[data-child-field="package"]');
      const total = () => document.getElementById('manual-enrollment-total').textContent;
      const vip = yes => { document.getElementById(yes ? 'manual-vip-yes' : 'manual-vip-no').checked = true; toggleManualVipOption(); };
      const results = [];
      for (const name of ['tutoring','workshop','playschool','childcare','summerblast','madstudio']) {
        program.value = name; select.value = ''; updateStudentPackages(card);
        select.selectedIndex = 1; updateStudentPackages(card);
        const initial = select.value;
        const normal = total();
        for (let i=0;i<3;i++) { vip(true); if (!select.value) throw new Error(name+' lost VIP selection'); vip(false); if (select.value !== initial) throw new Error(name+' lost regular selection'); }
        results.push([name, normal]);
      }
      program.value = 'tutoring'; select.value = ''; updateStudentPackages(card); select.selectedIndex = 1; updateStudentPackages(card);
      const regular = total(); vip(true); const discounted = total();
      card.querySelector('[data-child-field="name"]').value = 'Sample Child';
      addEnrollmentProgram(card.querySelector('.manual-program-actions button'));
      const added = document.querySelectorAll('.enrollment-child')[1];
      added.querySelector('[data-child-field="package"]').selectedIndex = 1; updateStudentPackages(added);
      const multiple = total(); added.querySelector('.manual-remove-child').click(); const removed = total();
      vip(false); program.value = 'summerblast'; select.value = ''; updateStudentPackages(card); select.selectedIndex=1; updateStudentPackages(card);
      card.querySelector('.manual-sb-main').value = 'Basic Read & Write'; card.querySelector('.manual-sb-free').value = 'Math for Elementary';
      card.querySelector('[data-child-field="summer-registration"]').checked=true; updateStudentPackages(card); vip(true);
      const summer = total(); const course = card.querySelector('.manual-sb-main').value;
      program.value='madstudio'; select.value=''; updateStudentPackages(card); select.selectedIndex=1; updateStudentPackages(card); addManualRentalDay(card);
      const hours = card.querySelector('.manual-rental-hours'); hours.value='2'; hours.dispatchEvent(new Event('input',{bubbles:true}));
      const rental = total(); removeManualRentalDay(card,0); const rentalRemoved=total();
      return { results, regular, discounted, multiple, removed, summer, course, rental, rentalRemoved };
    });
    assert.equal(result.regular, '₱3,300.00');
    assert.equal(result.discounted, '₱3,635.00');
    assert.equal(result.multiple, '₱6,010.00');
    assert.equal(result.removed, result.discounted);
    assert.equal(result.summer, '₱5,010.00');
    assert.equal(result.course, 'Basic Read & Write');
    assert.equal(result.rental, '₱3,650.00');
    assert.equal(result.rentalRemoved, '₱2,750.00');
    console.log('PASS: six programs preserve selections across repeated VIP switches; configured rates, membership once, multiple programs/removal, Summer Blast courses/registration, and live rental totals.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
