const fs=require('fs'), path=require('path'), os=require('os'), assert=require('assert/strict');
const {chromium}=require('playwright-core');
const html=fs.readFileSync('public/admin.html','utf8');
function source(name){const start=html.search(new RegExp('    (?:async )?function '+name+'\\('));return html.slice(start,html.indexOf('\n    }',start)+6);}
(async()=>{
 const executablePath=process.env.CHROME_PATH || ['C:/Program Files/Google/Chrome/Application/chrome.exe','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(fs.existsSync);
 const browser=await chromium.launch({executablePath,headless:true});
 try {
  const page=await browser.newPage({viewport:{width:1900,height:1000}});
  const start=html.indexOf('<div class="activity-log-columns">');
  const end=html.indexOf('<!--',html.indexOf('id="session-activity-next"',start));
  await page.setContent(`<style>${fs.readFileSync('public/css/admin.css','utf8')} body{padding:24px;} .activity-log-columns{max-width:1550px;margin:auto;}</style>${html.slice(start,end)}`);
  await page.addScriptTag({content:`let isHeadAdminUser=true,sessionActivityPage=1,sessionActivityRequest=0;
   function escHtml(v){return String(v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');}
   let fixture={success:true,total:1,sessions:[{session_date:'2026-11-17',created_at:'2026-11-17 14:45:10',tutor_name:'Chov',child_name:'Tiya',program:'Academic Tutorial',duration_hours:1,notes:'Reading practice',status:'Logged'}]};
   async function fetch(url){window.lastRequest=url;return {ok:true,json:async()=>fixture};}
   ${source('alogFormatDate')} ${source('loadSessionActivity')}`});
  await page.evaluate(()=>loadSessionActivity());
  assert.ok((await page.locator('#session-activity-body').textContent()).includes('Tiya'));
  assert.ok((await page.locator('#session-activity-body').textContent()).includes('Logged'));
  const desktop=await page.locator('.activity-log-columns').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length);
  assert.equal(desktop,2);
  await page.fill('#session-activity-date','2026-11-17');
  await page.evaluate(()=>loadSessionActivity(true));
  assert.ok((await page.evaluate(()=>window.lastRequest)).includes('date=2026-11-17'));
  await page.screenshot({path:path.join(os.tmpdir(),'activity-session-desktop.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});
  assert.equal(await page.locator('.activity-log-columns').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length),1);
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
  await page.evaluate(()=>{fixture={success:true,total:0,sessions:[]};return loadSessionActivity(true);});
  assert.ok((await page.locator('#session-activity-body').textContent()).includes('No session logs'));
  console.log('PASS: two desktop tables, mobile stacking/no overflow, Logged records, date filter and empty state.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
