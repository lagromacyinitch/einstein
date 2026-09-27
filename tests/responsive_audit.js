#!/usr/bin/env node
/**
 * Responsive audit — loads every public page (and every admin / user-portal
 * section) in headless Chrome at several widths and reports horizontal overflow.
 *
 * APIs are stubbed: check_session.php returns a logged-in admin or user, and
 * every other endpoint returns an empty success payload, so tables render
 * header-only. Real data will make them wider — test on staging too.
 *
 * Checks: 0px horizontal overflow (measured with overflow-x:hidden on html/body
 * switched off, as iOS ignores it), no controls cut off by overflow:hidden
 * boxes, 44×44px tap targets on phones, the admin notifications panel, admin
 * modals at 390px, the homepage menu and the user-portal top bar height.
 *
 * Setup: npm install (uses an installed Chrome or Edge; no browser download).
 *
 * Usage:
 *   npm run test:responsive                        # all pages, all widths
 *   node tests/responsive_audit.js admin user      # only these pages
 *   CHROME_PATH="C:/path/chrome.exe" node tests/responsive_audit.js
 *   PUBLIC_DIR=/other/checkout/public node tests/responsive_audit.js
 *
 * Exits with code 1 when any check fails.
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const PUBLIC_DIR = path.resolve(process.env.PUBLIC_DIR || path.join(__dirname, '..', 'public'));
const WIDTHS = [320, 360, 390, 414, 768, 1024, 1440];
const HEIGHT = 800;

const STATIC_PAGES = [
  'login', 'academic_tutorial', 'childcare_program', 'playschool',
  'summerblast', 'workshop', 'mad', 'collage',
];
const ADMIN_SECTIONS = [
  'dashboard', 'my_schedule', 'my_students', 'my_hours', 'my_profile',
  'students', 'enrollment', 'tutoring', 'workshop', 'playschool', 'childcare',
  'madstudio', 'summerblast', 'pricemanagement', 'balancemonitoring',
  'vipmembers', 'tutors', 'staff', 'schedule', 'announcements',
  'activitylogs', 'settings',
];
const USER_SECTIONS = ['dashboard', 'enrollments', 'vip', 'balance', 'announcements', 'profile'];

// Elements that must offer at least a 44×44px tap area on phones.
const TAP_TARGETS = {
  main: ['#nav-hamburger', '#login-icon-link'],
  user: ['#nav-hamburger-btn', '#userNotifBellBtn', '.nav-home-btn', '#client-account-button',
    '.drawer-close-btn', '#user-notif-panel button[aria-label="Close notifications"]'],
  login: ['#login-back-btn', '#login-signup-link', '#login-forgot-link'],
};

const CHROME_CANDIDATES = [
  process.env.CHROME_PATH,
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
].filter(Boolean);

const MIME = {
  '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript',
  '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon', '.gif': 'image/gif', '.woff2': 'font/woff2',
};

const EMPTY_PAYLOAD = {
  success: true, data: [], enrollments: [], items: [], admins: [], tutors: [],
  sessions: [], schedules: [], announcements: [], logs: [], students: [],
  members: [], rates: {}, settings: {}, spans: [], capacities: {},
};

function apiStub(pathname, pageName) {
  if (pathname.endsWith('/check_session.php')) {
    if (pageName === 'admin') {
      return { logged_in: true, role: 'admin', is_head_admin: true, can_edit_prices: true, email: 'admin@example.com', username: 'admin' };
    }
    if (pageName === 'user') {
      return { logged_in: true, role: 'user', user_id: 1, email: 'parent@example.com', is_vip: false, vip_pending: false };
    }
    return { logged_in: false };
  }
  return EMPTY_PAYLOAD;
}

function startServer() {
  const server = http.createServer((req, res) => {
    const pathname = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    const file = path.join(PUBLIC_DIR, pathname === '/' ? 'main.html' : pathname);
    if (!file.startsWith(PUBLIC_DIR) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
      res.writeHead(404); res.end(); return;
    }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(file).toLowerCase()] || 'application/octet-stream' });
    fs.createReadStream(file).pipe(res);
  });
  return new Promise(resolve => server.listen(0, '127.0.0.1', () => resolve(server)));
}

// Runs in the page. iOS ignores overflow-x:hidden on html/body, so overflow
// is measured with it switched off. Culprits are elements that stick out past
// the viewport and past their parent, and aren't inside a scroll/clip box.
function measureOverflow() {
  const vw = document.documentElement.clientWidth;
  const overflow = Math.max(0, document.documentElement.scrollWidth - vw);
  const culprits = [];
  if (overflow > 0) {
    const clipped = el => {
      for (let a = el.parentElement; a && a !== document.body && a !== document.documentElement; a = a.parentElement) {
        const cs = getComputedStyle(a);
        if (cs.overflowX !== 'visible' || cs.position === 'fixed') return true;
      }
      return getComputedStyle(el).position === 'fixed';
    };
    const describe = el => {
      let s = el.tagName.toLowerCase();
      if (el.id) s += '#' + el.id;
      else if (typeof el.className === 'string' && el.className.trim()) s += '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.');
      return s;
    };
    for (const el of document.body.querySelectorAll('*')) {
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.right <= vw + 1) continue;
      const pr = el.parentElement.getBoundingClientRect();
      if (r.right <= pr.right + 1 && el.parentElement !== document.body) continue;
      if (clipped(el)) continue;
      culprits.push({ el: describe(el), right: Math.round(r.right), width: Math.round(r.width) });
    }
    culprits.sort((a, b) => b.right - a.right);
  }
  return { overflow, culprits: culprits.slice(0, 5) };
}

// Runs in the page. Finds content cut off by an overflow:hidden box (e.g. a
// card header whose controls don't fit) — that never shows up as page overflow.
function measureClipping() {
  const describe = el => {
    let s = el.tagName.toLowerCase();
    if (el.id) s += '#' + el.id;
    else if (typeof el.className === 'string' && el.className.trim()) s += '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.');
    return s;
  };
  const found = [];
  for (const box of document.querySelectorAll('body *')) {
    const cs = getComputedStyle(box);
    if (cs.overflowX !== 'hidden' && cs.overflowX !== 'clip') continue;
    const br = box.getBoundingClientRect();
    if (br.width === 0 || br.height === 0 || box.offsetParent === null) continue;
    if (box.scrollWidth <= box.clientWidth + 1) continue;
    for (const el of box.querySelectorAll('input, select, button, textarea, a, h1, h2, h3, h4, label')) {
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.right <= br.right + 1) continue;
      let inner = false;
      for (let a = el.parentElement; a && a !== box; a = a.parentElement) {
        const ox = getComputedStyle(a).overflowX;
        if (ox !== 'visible') { inner = true; break; }
      }
      if (!inner) { found.push(`${describe(el)} cut ${Math.round(r.right - br.right)}px by ${describe(box)}`); }
    }
  }
  return [...new Set(found)].slice(0, 4);
}

async function openPage(browser, baseUrl, pageName, width) {
  const context = await browser.newContext({ viewport: { width, height: HEIGHT }, deviceScaleFactor: 1 });
  const page = await context.newPage();
  page.on('dialog', d => d.dismiss().catch(() => {}));
  await page.route('**/api/**', route => {
    const pathname = new URL(route.request().url()).pathname;
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(apiStub(pathname, pageName)) });
  });
  // External fonts/CDNs slow the run and don't affect layout checks much.
  await page.route(/^https?:\/\/(?!127\.0\.0\.1)/, route => route.abort());
  await page.goto(`${baseUrl}/${pageName}.html`, { waitUntil: 'load' });
  await page.addStyleTag({ content: 'html,body{overflow-x:visible!important}' });
  await page.waitForTimeout(400);
  return { context, page };
}

const results = [];
function record(page, width, check, ok, detail = '') {
  results.push({ page, width, check, ok, detail });
}

async function auditOverflow(page, label, width) {
  const { overflow, culprits } = await page.evaluate(measureOverflow);
  const detail = overflow ? `${overflow}px too wide — ${culprits.map(c => `${c.el} (right ${c.right})`).join(', ')}` : '';
  record(label, width, 'overflow', overflow === 0, detail);
  const clipped = await page.evaluate(measureClipping);
  record(label, width, 'clipping', clipped.length === 0, clipped.join('; '));
}

async function auditTapTargets(page, pageName, width) {
  for (const selector of TAP_TARGETS[pageName] || []) {
    const size = await page.evaluate(sel => {
      const el = document.querySelector(sel);
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { w: Math.round(r.width), h: Math.round(r.height) };
    }, selector);
    record(`${pageName}:${selector}`, width, 'tap target ≥44×44', !!size && size.w >= 44 && size.h >= 44,
      size ? `${size.w}×${size.h}` : 'missing');
  }
}

async function auditAdmin(page, width) {
  for (const section of ADMIN_SECTIONS) {
    await page.evaluate(s => { window.showPage(s, null); window.scrollTo(0, 0); }, section);
    await page.waitForTimeout(150);
    await auditOverflow(page, `admin:${section}`, width);
  }

  if (width === 390 || width === 768) {
    const panelState = () => page.evaluate(() => {
      const p = document.getElementById('notif-panel');
      const r = p.getBoundingClientRect();
      const visible = getComputedStyle(p).visibility !== 'hidden' && r.left < innerWidth && r.right > 0;
      return { visible, fits: r.left >= -1 && r.right <= innerWidth + 1 };
    });
    const initial = await panelState();
    await page.evaluate(() => window.toggleNotif());
    await page.waitForTimeout(450);
    const opened = await panelState();
    await page.evaluate(() => window.toggleNotif());
    await page.waitForTimeout(450);
    const closed = await panelState();
    const ok = !initial.visible && opened.visible && opened.fits && !closed.visible;
    record('admin:notif-panel', width, 'opens/closes', ok,
      ok ? '' : `initial visible=${initial.visible}, opened visible=${opened.visible} fits=${opened.fits}, closed visible=${closed.visible}`);
  }

  if (width === 390) {
    const ids = await page.$$eval('.modal-overlay[id]', els => els.map(e => e.id));
    for (const id of ids) {
      const res = await page.evaluate(modalId => {
        const overlay = document.getElementById(modalId);
        overlay.classList.add('open');
        overlay.style.display = overlay.style.display === 'none' ? '' : overlay.style.display;
        const modal = overlay.querySelector('.modal') || overlay.firstElementChild;
        const vw = document.documentElement.clientWidth;
        const mr = modal.getBoundingClientRect();
        let worst = null;
        for (const el of modal.querySelectorAll('*')) {
          const r = el.getBoundingClientRect();
          if (r.width === 0) continue;
          let clippedBy = false;
          for (let a = el.parentElement; a && a !== modal; a = a.parentElement) {
            if (getComputedStyle(a).overflowX !== 'visible') { clippedBy = true; break; }
          }
          if (clippedBy) continue;
          const over = Math.round(r.right - mr.right);
          if (over > 1 && (!worst || over > worst.over)) worst = { over, el: el.tagName.toLowerCase() + (el.id ? '#' + el.id : el.className && typeof el.className === 'string' ? '.' + el.className.split(' ')[0] : '') };
        }
        overlay.classList.remove('open');
        return { left: Math.round(mr.left), right: Math.round(mr.right), vw, worst };
      }, id);
      const ok = res.left >= 0 && res.right <= res.vw && !res.worst;
      record(`admin:${id}`, width, 'modal fits', ok,
        ok ? '' : `modal ${res.left}–${res.right} of ${res.vw}px${res.worst ? `; ${res.worst.el} sticks out ${res.worst.over}px` : ''}`);
    }
  }
}

async function auditUser(page, width) {
  for (const section of USER_SECTIONS) {
    await page.evaluate(s => { window.showUserPage(s); window.scrollTo(0, 0); }, section);
    await page.waitForTimeout(150);
    await auditOverflow(page, `user:${section}`, width);
  }
  if (width <= 414) {
    const h = await page.evaluate(() => Math.round(document.querySelector('nav').getBoundingClientRect().height));
    record('user:top-bar', width, 'height ≤ 64px', h <= 64, `${h}px`);
  }
}

async function auditMain(page, width) {
  await auditOverflow(page, 'main', width);
  if (width <= 414) {
    const login = await page.evaluate(() => {
      const a = document.getElementById('login-icon-link');
      if (!a) return null;
      const r = a.getBoundingClientRect();
      return { onScreen: r.left >= 0 && r.right <= innerWidth && r.width > 0, w: Math.round(r.width), h: Math.round(r.height) };
    });
    record('main:login-icon', width, 'visible, ≥44×44', !!login && login.onScreen && login.w >= 44 && login.h >= 44,
      login ? `${login.w}×${login.h}, onScreen=${login.onScreen}` : 'missing');

    const menu = page.locator('nav .nav-hamburger');
    if (await menu.count() === 0 || !(await menu.isVisible())) {
      record('main:menu', width, 'opens/closes', false, 'no visible .nav-hamburger');
    } else {
      const linksVisible = () => page.evaluate(() => {
        const ul = document.getElementById('nav-links');
        const r = ul.getBoundingClientRect();
        return getComputedStyle(ul).display !== 'none' && r.height > 0;
      });
      const before = await linksVisible();
      await menu.click();
      await page.waitForTimeout(300);
      const opened = await linksVisible();
      const expanded = await menu.getAttribute('aria-expanded');
      await page.locator('#nav-links a').first().click({ noWaitAfter: true }).catch(() => {});
      await page.waitForTimeout(300);
      const afterLink = await linksVisible();
      const ok = !before && opened && expanded === 'true' && !afterLink;
      record('main:menu', width, 'opens/closes', ok,
        ok ? '' : `before=${before}, opened=${opened}, aria-expanded=${expanded}, afterLinkTap=${afterLink}`);
    }
  }
}

async function main() {
  const only = process.argv.slice(2);
  const pages = ['admin', 'user', 'main', ...STATIC_PAGES].filter(p => !only.length || only.includes(p));
  const executablePath = CHROME_CANDIDATES.find(p => fs.existsSync(p));
  if (!executablePath) throw new Error('Chrome not found. Set CHROME_PATH.');

  const server = await startServer();
  const baseUrl = `http://127.0.0.1:${server.address().port}`;
  const browser = await chromium.launch({ executablePath, headless: true });
  try {
    for (const pageName of pages) {
      for (const width of WIDTHS) {
        const { context, page } = await openPage(browser, baseUrl, pageName, width);
        try {
          if (pageName === 'admin') await auditAdmin(page, width);
          else if (pageName === 'user') await auditUser(page, width);
          else if (pageName === 'main') await auditMain(page, width);
          else await auditOverflow(page, pageName, width);
          if (width <= 414) await auditTapTargets(page, pageName, width);
        } catch (e) {
          record(pageName, width, 'error', false, e.message.split('\n')[0]);
        }
        await context.close();
      }
    }
  } finally {
    await browser.close();
    server.close();
  }

  const failed = results.filter(r => !r.ok);
  for (const r of failed) console.log(`FAIL ${r.page} @${r.width}px [${r.check}] ${r.detail}`);
  console.log(`\n${results.length - failed.length}/${results.length} checks passed.`);
  process.exitCode = failed.length ? 1 : 0;
}

main().catch(e => { console.error(e); process.exit(1); });
