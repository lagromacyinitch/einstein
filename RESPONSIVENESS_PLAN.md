# Responsiveness Plan

Plan for fixing layout problems on phones, tablets and small laptops. Line numbers are as of commit `fc6b846`; search for the selector if they have drifted.

## Status: complete

Phases 1–4 and the automated check in Phase 5 are done. `npm run test:responsive` passes 593 of 593 checks (432 of 593 before the fixes). The three manual checks in Phase 5 (real data on staging, a real Android phone, a real iPhone) haven't been run.

Changes beyond the plan:

- **Admin:** `.two-col` now collapses to one column at ≤768px. Card headers wrap on mobile, which also overrides the inline input widths.
- **Homepage:** the VIP card image fits small screens. The M.A.D. rate rows use a `.mad-rate-row` class, and the homepage-content script selects them by that class.
- **User portal:** the balance stat cards stack at ≤768px, not below 400px, because they were cut off at 600px too. The drawer shadow is hidden while the drawer is closed.

Not changed: some homepage labels are still 11.2–11.5px, and `user.html` keeps the browser's default 8px body margin.

The sections below are the original plan and audit, as of `fc6b846`.

## Current state (before the fixes)

Audit: every page, all 23 admin sections and all 6 user-portal sections, loaded in headless Chrome at 320, 360, 390, 414, 768, 1024 and 1440px. APIs were stubbed with logged-in sessions and empty data, so tables were header-only. Real rows will make them wider.

| Pages | Phone (320–414) | Tablet (768) | Laptop (1024) | 1440 |
|---|---|---|---|---|
| login, academic_tutorial, childcare_program, playschool, summerblast, workshop, mad, collage | OK | OK | OK | OK |
| main.html (homepage) | 190–360px too wide | OK | OK | OK |
| admin.html: 17 of 23 sections | up to 730px too wide | program pages and schedules too wide | same sections too wide | OK |
| user.html | top bar wraps to 3 rows; Balance tab too wide at ≤360px | OK | OK | OK |

### Root causes

- **Admin notifications panel is stuck open at ≤768px.** The mobile rule in `css/admin.css` (`.notif-panel { right: 0 }`, around line 1202) overrides the hidden position (`right: -340px`). The panel covers the admin screen on every phone and tablet, and toggling `.open` doesn't hide it.
- **Admin `#main` can't shrink.** `body` is a flex container and `#main` is `flex: 1` without `min-width: 0`, so it grows to fit the widest table.
- **Admin tables have no scroll box above 768px.** `.card-body { overflow-x: auto }` only exists inside the 768px media query.
- **Homepage has no mobile menu.** Nav links are hidden below 768px, but `main.html` has no `.nav-hamburger` button (the CSS for it exists). The Login icon is also pushed off-screen.
- **Homepage grids don't collapse.** `.classes-grid` is `repeat(2, minmax(320px, 1fr))` at every width. An inline two-column grid (`main.html` line 535) never collapses. `.contact-layout` and `.childcare-layout` columns stretch to fit their content.
- **User portal top bar stacks vertically.** The mobile rule in `user.html` (around line 397) sets `nav` and `.nav-right` to `flex-direction: column`, which makes the bar about 190px tall.

## Phase 1: Admin panel

**File:** `public/css/admin.css`, plus inline styles in `public/admin.html`

- [x] **Notifications panel.** Replace the `.notif-panel` rules in the 768px query with a transform-based hide:
  ```css
  @media (max-width: 768px) {
    .notif-panel { width: 100vw; right: 0; transform: translateX(100%); visibility: hidden; }
    .notif-panel.open { transform: none; visibility: visible; }
  }
  ```
  Apply the same approach to the desktop rule (around line 887). `right: -340px` there can widen the page too.
- [x] **`#main`.** Add `min-width: 0` to `#main` (around line 263).
- [x] **Table scrolling.** Move `.card-body { overflow-x: auto }` from the 768px query to the base `.card-body` rule (around line 487). Keep `table { min-width: 600px }` mobile-only.
- [x] **Grid children.** Add:
  ```css
  .two-col > *, .three-col > *, .stats-grid > *, .vip-section-columns > * { min-width: 0; }
  ```
- [x] **Inline fixed widths.** In `admin.html`, change fixed `width: 180px`-style inline widths on filters and search inputs (e.g. `#student-archive-filter`, `#student-program-filter`, `.search-input`) to `max-width` with `width: 100%`, or confirm the `.filter-bar` mobile rule overrides them.
- [x] **Modals.** Open the enrollment, archive and receipt modals at 390px and confirm the forms and footers fit.

**Done when:** every admin section has 0px horizontal overflow from 320 to 1440px, and the notifications panel opens and closes correctly at 390 and 768px.

## Phase 2: Homepage

**Files:** `public/main.html`, `public/css/main.css`

- [x] **Hamburger button.** Add to the `<nav>` in `main.html`:
  ```html
  <button class="nav-hamburger" aria-label="Menu" aria-expanded="false"><span></span><span></span><span></span></button>
  ```
  Add a click handler that toggles `.nav-open` on `#nav-links`, updates `aria-expanded`, and closes the menu when a link is tapped.
- [x] **Merge the mobile nav rules.** The 768px query at line 80 comes before the base `nav ul` rule (line 149), and a second 768px query (line 1121) hides the links again. Combine them into one mobile block placed after the base rules, so `nav ul.nav-open { display: flex }` wins.
- [x] **`.classes-grid`.** Switch to `grid-template-columns: 1fr` below 768px.
- [x] **Inline grid at `main.html` line 535.** Move the inline style into a class that collapses to one column on mobile.
- [x] **`.contact-layout` and `.childcare-layout`.** Use `grid-template-columns: minmax(0, 1fr)` on mobile.
- [x] **`.rates-table`.** Wrap it in a container with `overflow-x: auto`, or reduce the cell padding at ≤480px.
- [x] **Login icon.** Give `#login-icon-link` a tap area of at least 44×44px (padding is enough).

**Done when:** 0px overflow at 320–414px, the menu opens and closes, and Login is visible on screen at 320px.

## Phase 3: User portal

**File:** `public/user.html` (inline `<style>`)

- [x] **Top bar.** In the 768px query, keep `nav` and `.nav-right` as a single row (`flex-direction: row`):
  - Shrink or hide the brand text below 480px.
  - Show "View Website" as an icon only, or move it into the existing drawer (`openUserDrawer`).
- [x] **Top offset.** Once the bar is one row, match the page's top padding to the new height so no content sits under it.
- [x] **Balance tab.**
  - Let the filter chips and `#balanceCountPill` wrap.
  - Stack the stat cards' label, value and note vertically below 400px.
- [x] **Tap targets.** Increase `#client-account-button` and the drawer/panel close buttons from 30×30px to 44×44px.

**Done when:** the top bar is at most 64px tall at 390px, and there's 0px overflow on every tab at 320px.

## Phase 4: Clean-up

- [x] **`login.html`.** Increase the tap areas of `#login-back-btn` (currently 24px) and `#login-signup-link` (currently 14px tall) to 44px.
- [x] **Homepage small text.** Review the 24 text elements under 11px and raise labels to at least 12px.
- [x] **Breakpoints.** The CSS uses 13 different breakpoint values. Standardise on 480, 768 and 1024px in any CSS touched in Phases 1–3.

## Phase 5: Verification

- [x] **Automated check.** Add the audit script as `tests/responsive_audit.js`, with `playwright-core` as a dev dependency. Run it after each phase.
- [ ] **Real data.** Test on staging with real data: long names, many enrollments and receipts.
- [ ] **Android.** Check on a real phone in Chrome.
- [ ] **iPhone.** Check on a real phone in Safari. iOS ignores `overflow-x: hidden` on `html`/`body`, so any overflow left over will scroll sideways there.

## Order

1. **Phase 1.** The stuck notifications panel makes the admin panel unusable on phones and tablets.
2. **Phase 2.** The homepage is the public face of the site, and phone visitors currently have no menu.
3. **Phase 3.**
4. **Phases 4–5.**

Each phase can ship as its own commit or PR.
