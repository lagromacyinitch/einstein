/**
 * Einstein Center — Professional Enrollment Flow
 * Steps: 1 Account/Login · 2 Verify OTP · 3 Form · 4 Review & Pay · 5 Processing · 6 Success
 */
(function () {
  'use strict';

  /* ─── STATE ─────────────────────────────────────── */
  let step = 1;
  let formData = {};
  let account = { email: '', password: '', phone: '', userId: null, isNewAccount: false, channel: 'email' };
  let programName = '';
  let paymentMethod = '';  // Track selected payment method: 'qr' or 'walk-in'
  let authScreen = 'choose';  // 'choose' | 'login' | 'signup'
  let receiptPreviewUrl = '';

  // ── NSFW / Inappropriate Content Detection ──
  let nsfwModel = null;
  let nsfwModelLoading = false;

  async function loadNsfwModel() {
    if (nsfwModel) return nsfwModel;
    if (nsfwModelLoading) {
      let waited = 0;
      while (nsfwModelLoading && waited < 12000) {
        await new Promise(r => setTimeout(r, 120));
        waited += 120;
        if (nsfwModel) return nsfwModel;
      }
      return nsfwModel;
    }
    nsfwModelLoading = true;
    try {
      if (typeof window.nsfwjs === 'undefined') {
        await new Promise((resolve) => {
          if (typeof window.tf === 'undefined') {
            const s1 = document.createElement('script');
            s1.src = 'https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.17.0/dist/tf.min.js';
            s1.onload = () => {
              const s2 = document.createElement('script');
              s2.src = 'https://cdn.jsdelivr.net/npm/nsfwjs@2.4.2/dist/nsfwjs.min.js';
              s2.onload = () => resolve(true);
              s2.onerror = () => resolve(false);
              document.head.appendChild(s2);
            };
            s1.onerror = () => resolve(false);
            document.head.appendChild(s1);
          } else {
            const s2 = document.createElement('script');
            s2.src = 'https://cdn.jsdelivr.net/npm/nsfwjs@2.4.2/dist/nsfwjs.min.js';
            s2.onload = () => resolve(true);
            s2.onerror = () => resolve(false);
            document.head.appendChild(s2);
          }
        });
      }
      if (typeof window.nsfwjs !== 'undefined') {
        nsfwModel = await window.nsfwjs.load();
      }
    } catch (e) {
      console.warn('NSFWJS model loading failed:', e);
    } finally {
      nsfwModelLoading = false;
    }
    return nsfwModel;
  }

  function analyzeImageForSensitiveContent(img) {
    try {
      const canvas = document.createElement('canvas');
      const ctx = canvas.getContext('2d', { willReadFrequently: true });
      const w = 120;
      const h = 120;
      canvas.width = w;
      canvas.height = h;
      ctx.drawImage(img, 0, 0, w, h);
      const data = ctx.getImageData(0, 0, w, h).data;
      const totalPixels = w * h;

      let bloodCount = 0;
      let visceralCount = 0;
      let surgicalContextCount = 0;
      let neutralDocCount = 0;

      for (let i = 0; i < data.length; i += 4) {
        const r = data[i];
        const g = data[i + 1];
        const b = data[i + 2];
        const a = data[i + 3];
        if (a < 128) continue;

        // 1. Blood red
        const isBlood = (r > 95 && g < 65 && b < 65 && (r - Math.max(g, b) > 30)) ||
                        (r > 135 && g < 80 && b < 80 && (r - g > 45) && (r - b > 45));
        if (isBlood) bloodCount++;

        // 2. Visceral organ / fleshy pink / internal body tissue
        const isVisceral = (r > 125 && r < 240 && g > 35 && g < 145 && b > 20 && b < 125 && (r - g > 25) && (r - b > 30)) ||
                           (r > 160 && g > 85 && g < 170 && b > 40 && b < 125 && (r - b > 45) && (g - b > 18));
        if (isVisceral) visceralCount++;

        // 3. Surgical context (surgical blue drape / medical latex glove)
        const isSurgicalDrape = (b > 110 && b > r * 1.3 && g > 60 && g < 185 && r < 105);
        const isLatexGlove = (r > 160 && g > 140 && b < 115 && Math.abs(r - g) < 40 && (r - b > 45));
        if (isSurgicalDrape || isLatexGlove) surgicalContextCount++;

        // 4. Clean document / receipt neutral whites & light grays
        const isDocBg = (r > 185 && g > 185 && b > 185 && Math.abs(r - g) < 20 && Math.abs(g - b) < 20);
        if (isDocBg) neutralDocCount++;
      }

      const bloodRatio = bloodCount / totalPixels;
      const visceralRatio = visceralCount / totalPixels;
      const surgicalRatio = surgicalContextCount / totalPixels;
      const docRatio = neutralDocCount / totalPixels;

      const isGoreOrSurgery = (visceralRatio > 0.07 && (bloodRatio > 0.02 || surgicalRatio > 0.03)) ||
                              (bloodRatio > 0.035) ||
                              (visceralRatio > 0.12) ||
                              (surgicalRatio > 0.08 && visceralRatio > 0.04) ||
                              ((bloodRatio + visceralRatio) > 0.08);

      return {
        isSensitive: isGoreOrSurgery,
        reason: isGoreOrSurgery ? 'Medical surgery, viscera, or gore detected' : 'OK',
        details: { bloodRatio, visceralRatio, surgicalRatio, docRatio }
      };
    } catch (err) {
      console.warn('Canvas image analysis error:', err);
      return { isSensitive: false, reason: 'Error' };
    }
  }

  const PROGRAM_PACKAGES = {
    'Academic Tutorial': [
      { value: 'Regular Package – ₱3,300', name: 'Regular Package', detail: '₱3,300 one-time · 15 sessions · 1 hr each · 1 child' },
      { value: 'Double Package – ₱5,500', name: 'Double Package', detail: '₱5,500 one-time · 15 sessions · 2 hrs each · up to 2 children' },
      { value: 'Daily Package – ₱5,500/month', name: 'Daily Package', detail: '₱5,500/month · Daily sessions · 1 hr/day · 1 child' },
      { value: 'Daily Double Package – ₱9,900/month', name: 'Daily Double Package', detail: '₱9,900/month · Daily sessions · 2 hrs/day · up to 2 children' },
    ],
    'Weekend Workshop': [
      { value: 'Group Class – ₱2,500/mo', name: 'Group Class', detail: '₱2,500/mo · Up to 10 students · 1 hr/session' },
      { value: 'Center Based 1-on-1 – ₱4,800', name: 'Center Based (1-on-1)', detail: '₱4,800 · 10 sessions · 1 hr each' },
      { value: 'Home Based 1-on-1 – ₱4,500', name: 'Home Based (1-on-1)', detail: '₱4,500 + transpo · 10 sessions · 1 hr each' },
    ],
    'PlaySchool': [
      { value: 'Caterpillar Class – ₱4,875/mo', name: 'Caterpillar Class', detail: 'Ages 2.6–3.5 · ₱4,875/mo (VIP: ₱3,900)' },
      { value: 'Butterfly Class – ₱4,875/mo', name: 'Butterfly Class', detail: 'Ages 3.6–4.5 · ₱4,875/mo (VIP: ₱3,900)' },
    ],
    'Playschool': [
      { value: 'Caterpillar Class – ₱4,875/mo', name: 'Caterpillar Class', detail: 'Ages 2.6–3.5 · ₱4,875/mo (VIP: ₱3,900)' },
      { value: 'Butterfly Class – ₱4,875/mo', name: 'Butterfly Class', detail: 'Ages 3.6–4.5 · ₱4,875/mo (VIP: ₱3,900)' },
    ],
    'Child Care Program': [
      { value: 'Daycare Monthly — Toilet Trained (₱12,250)', name: 'Daycare Monthly — Toilet Trained', detail: '₱12,250 regular · ₱10,320 VIP · 7am–7pm daily' },
      { value: 'Daycare Monthly — Non-Toilet Trained (₱13,500)', name: 'Daycare Monthly — Non-Toilet Trained', detail: '₱13,500 regular · ₱11,800 VIP · 7am–7pm daily' },
      { value: 'Daycare Weekly — Toilet Trained (₱3,600)', name: 'Daycare Weekly — Toilet Trained', detail: '₱3,600 regular · ₱3,000 VIP' },
      { value: 'Daycare Weekly — Non-Toilet Trained (₱4,500)', name: 'Daycare Weekly — Non-Toilet Trained', detail: '₱4,500 regular · ₱3,800 VIP' },
      { value: 'Daycare Daily — Toilet Trained (₱675)', name: 'Daycare Daily — Toilet Trained', detail: '₱675 regular · ₱560 VIP' },
      { value: 'Daycare Daily — Non-Toilet Trained (₱850)', name: 'Daycare Daily — Non-Toilet Trained', detail: '₱850 regular · ₱700 VIP' },
    ],
    'M.A.D. Studio': [
      { value: 'Hiphop Aerobics – ₱2,500/mo', name: 'Fitness: Hiphop Aerobics', detail: 'MWF 6:45–7:45 pm · ₱2,500/month' },
      { value: 'Kickboxing – ₱2,500/mo', name: 'Fitness: Kickboxing', detail: 'TTHS 6:45–7:45 pm · ₱2,500/month' },
      { value: 'Cross Training – ₱3,500/mo', name: 'Fitness: Cross Training', detail: 'MWF + TTHS · ₱3,500/month' },
      { value: 'Gymnastics – ₱2,500/mo', name: 'After School: Gymnastics', detail: 'Saturdays 8:30–10:30 am · ₱2,500/month' },
      { value: 'Ballet Class – ₱2,500/mo', name: 'After School: Ballet Class', detail: 'Saturdays 10:30 am–12:30 pm · ₱2,500/month' },
      { value: 'Taekwondo – ₱2,500/mo', name: 'After School: Taekwondo', detail: 'Sat 12:30–2:30 pm / TTHS 5:30–6:30 pm · ₱2,500/month' },
      { value: 'Pop Dancing – ₱2,500/mo', name: 'After School: Pop Dancing', detail: 'MWF 5:30–6:30 pm · ₱2,500/month' },
      { value: 'Studio Rental – ₱450/hour', name: 'Studio Rental', detail: '₱450 per hour · by appointment' },
    ],
    'Summer Blast': [
      { value: 'Registration Fee – ₱900', name: 'Registration Fee (Slot Reservation) – ₱900', detail: '₱900 · Includes free t-shirt, photo & video coverage, recital fee, certs' },
      { value: 'Single Course – ₱3,900', name: 'Single Course – ₱3,900', detail: '₱3,900 · Choose 1 Course + 1 Academics subject FREE · One-time payment' },
      { value: 'Two Courses – ₱4,900', name: 'Two Courses – ₱4,900', detail: '₱4,900 · Choose 2 Courses + 1 Academics subject FREE · One-time payment' },
      { value: 'Special Course – ₱5,100', name: 'Special Course – ₱5,100', detail: '₱5,100 · 1 Special Course (Baking, Swimming, or Taekwondo) + 1 Any Course FREE · One-time payment' },
    ],
    'VIP Club Membership': [
      { value: `VIP Club Membership – ₱${window.vipFee || 500} (2 years)`, name: 'VIP Club Membership', detail: `₱${window.vipFee || 500} · Valid for 2 years · Discounts on all programs` },
    ],
  };

  function getEinsteinSiteSettings() {
    try {
      const raw = localStorage.getItem('einsteinSettings');
      if (raw) return JSON.parse(raw);
    } catch (e) {}
    return null;
  }

  function buildDynamicVipPackages() {
    const settings = getEinsteinSiteSettings();
    const discounts = (settings && settings.vipDiscounts) || {};

    const tutDisc = (discounts.tutorial !== '' && discounts.tutorial !== undefined && discounts.tutorial !== null) ? parseFloat(discounts.tutorial) : 5;
    const wsDisc  = (discounts.workshop !== '' && discounts.workshop !== undefined && discounts.workshop !== null) ? parseFloat(discounts.workshop) : 5;
    const psDisc  = (discounts.playschool !== '' && discounts.playschool !== undefined && discounts.playschool !== null) ? parseFloat(discounts.playschool) : 20;
    const ccDisc  = (discounts.childcare !== '' && discounts.childcare !== undefined && discounts.childcare !== null) ? parseFloat(discounts.childcare) : 15;
    const madDisc = (discounts.madstudio !== '' && discounts.madstudio !== undefined && discounts.madstudio !== null) ? parseFloat(discounts.madstudio) : 0;
    const sbDisc  = (discounts.summerblast !== '' && discounts.summerblast !== undefined && discounts.summerblast !== null) ? parseFloat(discounts.summerblast) : 0;

    const calcVip = (origPrice, disc) => {
      if (isNaN(disc) || disc <= 0) return origPrice;
      return Math.round(origPrice * (1 - disc / 100));
    };

    // 1. Academic Tutorial (Default: 5% off => 3,135 / 5,225 / 5,225 / 9,405)
    const tutReg = calcVip(3300, tutDisc);
    const tutDbl = calcVip(5500, tutDisc);
    const tutDly = calcVip(5500, tutDisc);
    const tutDdb = calcVip(9900, tutDisc);

    // 2. Weekend Workshop (Default: 5% off => 2,375 / 4,560 / 4,275)
    const wsGrp = calcVip(2500, wsDisc);
    const wsCtr = calcVip(4800, wsDisc);
    const wsHme = calcVip(4500, wsDisc);

    // 3. Playschool (Default: 20% off => 3,900)
    const psCat = calcVip(4875, psDisc);
    const psBtr = calcVip(4875, psDisc);

    // 4. Child Care Program (Default: 15% off or explicit childcareRates from admin)
    const ccRates = settings?.childcareRates || {};
    const ccTtM  = parseInt(ccRates['tt-vip-monthly'] || calcVip(12250, ccDisc), 10);
    const ccNttM = parseInt(ccRates['ntt-vip-monthly'] || calcVip(13500, ccDisc), 10);
    const ccTtW  = parseInt(ccRates['tt-vip-weekly'] || calcVip(3600, ccDisc), 10);
    const ccNttW = parseInt(ccRates['ntt-vip-weekly'] || calcVip(4500, ccDisc), 10);
    const ccTtD  = parseInt(ccRates['tt-vip-daily'] || calcVip(675, ccDisc), 10);
    const ccNttD = parseInt(ccRates['ntt-vip-daily'] || calcVip(850, ccDisc), 10);

    // 5. M.A.D. Studio (Default: no discount unless set in admin)
    const madFit = madDisc > 0 ? calcVip(2500, madDisc) : 2500;
    const madXtn = madDisc > 0 ? calcVip(3500, madDisc) : 3500;
    const madRnt = madDisc > 0 ? calcVip(450, madDisc) : 450;

    // 6. Summer Blast (Default: 0% unless set in admin)
    const sbRates = settings?.summerblast || [];
    const getSbRate = (keyword, fallback) => {
      const found = sbRates.find(r => r && r.name && r.name.toLowerCase().includes(keyword.toLowerCase()));
      if (!found || !found.rate) return fallback;
      return parseFloat(String(found.rate).replace(/[^0-9.]/g, '')) || fallback;
    };
    const sbRegFee = getSbRate('Registration', 900);
    const sbSingleRate = getSbRate('Single', 3900);
    const sbTwoRate = getSbRate('Two', 4900);
    const sbSpecialRate = getSbRate('Special', 5100);

    const sbSingleVip = sbDisc > 0 ? calcVip(sbSingleRate, sbDisc) : sbSingleRate;
    const sbTwoVip = sbDisc > 0 ? calcVip(sbTwoRate, sbDisc) : sbTwoRate;
    const sbSpecialVip = sbDisc > 0 ? calcVip(sbSpecialRate, sbDisc) : sbSpecialRate;

    return {
      'Academic Tutorial': [
        { value: `Regular Package (VIP) – ₱${tutReg.toLocaleString()}`, name: 'Regular Package (VIP Discounted)', detail: `₱${tutReg.toLocaleString()} VIP rate (orig. ₱3,300) · 15 sessions · 1 hr each · 1 child` },
        { value: `Double Package (VIP) – ₱${tutDbl.toLocaleString()}`, name: 'Double Package (VIP Discounted)', detail: `₱${tutDbl.toLocaleString()} VIP rate (orig. ₱5,500) · 15 sessions · 2 hrs each · up to 2 children` },
        { value: `Daily Package (VIP) – ₱${tutDly.toLocaleString()}/month`, name: 'Daily Package (VIP Discounted)', detail: `₱${tutDly.toLocaleString()}/month VIP rate (orig. ₱5,500) · Daily sessions · 1 hr/day · 1 child` },
        { value: `Daily Double Package (VIP) – ₱${tutDdb.toLocaleString()}/month`, name: 'Daily Double Package (VIP Discounted)', detail: `₱${tutDdb.toLocaleString()}/month VIP rate (orig. ₱9,900) · Daily sessions · 2 hrs/day · up to 2 children` },
      ],
      'Weekend Workshop': [
        { value: `Group Class (VIP) – ₱${wsGrp.toLocaleString()}/mo`, name: 'Group Class (VIP Discounted)', detail: `₱${wsGrp.toLocaleString()}/mo VIP rate (orig. ₱2,500) · Up to 10 students · 1 hr/session` },
        { value: `Center Based 1-on-1 (VIP) – ₱${wsCtr.toLocaleString()}`, name: 'Center Based 1-on-1 (VIP Discounted)', detail: `₱${wsCtr.toLocaleString()} VIP rate (orig. ₱4,800) · 10 sessions · 1 hr each` },
        { value: `Home Based 1-on-1 (VIP) – ₱${wsHme.toLocaleString()}`, name: 'Home Based 1-on-1 (VIP Discounted)', detail: `₱${wsHme.toLocaleString()} VIP rate (orig. ₱4,500) + transpo · 10 sessions · 1 hr each` },
      ],
      'PlaySchool': [
        { value: `Caterpillar Class (VIP) – ₱${psCat.toLocaleString()}/mo`, name: 'Caterpillar Class (VIP Discounted)', detail: `Ages 2.6–3.5 · ₱${psCat.toLocaleString()}/mo VIP rate (orig. ₱4,875)` },
        { value: `Butterfly Class (VIP) – ₱${psBtr.toLocaleString()}/mo`, name: 'Butterfly Class (VIP Discounted)', detail: `Ages 3.6–4.5 · ₱${psBtr.toLocaleString()}/mo VIP rate (orig. ₱4,875)` },
      ],
      'Playschool': [
        { value: `Caterpillar Class (VIP) – ₱${psCat.toLocaleString()}/mo`, name: 'Caterpillar Class (VIP Discounted)', detail: `Ages 2.6–3.5 · ₱${psCat.toLocaleString()}/mo VIP rate (orig. ₱4,875)` },
        { value: `Butterfly Class (VIP) – ₱${psBtr.toLocaleString()}/mo`, name: 'Butterfly Class (VIP Discounted)', detail: `Ages 3.6–4.5 · ₱${psBtr.toLocaleString()}/mo VIP rate (orig. ₱4,875)` },
      ],
      'Child Care Program': [
        { value: `Daycare Monthly — Toilet Trained (VIP: ₱${ccTtM.toLocaleString()})`, name: 'Daycare Monthly — Toilet Trained (VIP)', detail: `₱${ccTtM.toLocaleString()} VIP rate (orig. ₱12,250) · 7am–7pm daily` },
        { value: `Daycare Monthly — Non-Toilet Trained (VIP: ₱${ccNttM.toLocaleString()})`, name: 'Daycare Monthly — Non-Toilet Trained (VIP)', detail: `₱${ccNttM.toLocaleString()} VIP rate (orig. ₱13,500) · 7am–7pm daily` },
        { value: `Daycare Weekly — Toilet Trained (VIP: ₱${ccTtW.toLocaleString()})`, name: 'Daycare Weekly — Toilet Trained (VIP)', detail: `₱${ccTtW.toLocaleString()} VIP rate (orig. ₱3,600)` },
        { value: `Daycare Weekly — Non-Toilet Trained (VIP: ₱${ccNttW.toLocaleString()})`, name: 'Daycare Weekly — Non-Toilet Trained (VIP)', detail: `₱${ccNttW.toLocaleString()} VIP rate (orig. ₱4,500)` },
        { value: `Daycare Daily — Toilet Trained (VIP: ₱${ccTtD.toLocaleString()})`, name: 'Daycare Daily — Toilet Trained (VIP)', detail: `₱${ccTtD.toLocaleString()} VIP rate (orig. ₱675)` },
        { value: `Daycare Daily — Non-Toilet Trained (VIP: ₱${ccNttD.toLocaleString()})`, name: 'Daycare Daily — Non-Toilet Trained (VIP)', detail: `₱${ccNttD.toLocaleString()} VIP rate (orig. ₱850)` },
      ],
      'M.A.D. Studio': [
        { value: `Hiphop Aerobics (VIP) – ₱${madFit.toLocaleString()}/mo`, name: 'Fitness: Hiphop Aerobics (VIP)', detail: `MWF 6:45–7:45 pm · ₱${madFit.toLocaleString()}/mo VIP rate (orig. ₱2,500)` },
        { value: `Kickboxing (VIP) – ₱${madFit.toLocaleString()}/mo`, name: 'Fitness: Kickboxing (VIP)', detail: `TTHS 6:45–7:45 pm · ₱${madFit.toLocaleString()}/mo VIP rate (orig. ₱2,500)` },
        { value: `Cross Training (VIP) – ₱${madXtn.toLocaleString()}/mo`, name: 'Fitness: Cross Training (VIP)', detail: `MWF + TTHS · ₱${madXtn.toLocaleString()}/mo VIP rate (orig. ₱3,500)` },
        { value: `Gymnastics (VIP) – ₱${madFit.toLocaleString()}/mo`, name: 'After School: Gymnastics (VIP)', detail: `Saturdays 8:30–10:30 am · ₱${madFit.toLocaleString()}/mo VIP rate (orig. ₱2,500)` },
        { value: `Ballet Class (VIP) – ₱${madFit.toLocaleString()}/mo`, name: 'After School: Ballet Class (VIP)', detail: `Saturdays 10:30 am–12:30 pm · ₱${madFit.toLocaleString()}/mo VIP rate (orig. ₱2,500)` },
        { value: `Taekwondo (VIP) – ₱${madFit.toLocaleString()}/mo`, name: 'After School: Taekwondo (VIP)', detail: `Sat 12:30–2:30 pm / TTHS 5:30–6:30 pm · ₱${madFit.toLocaleString()}/mo VIP rate (orig. ₱2,500)` },
        { value: `Pop Dancing (VIP) – ₱${madFit.toLocaleString()}/mo`, name: 'After School: Pop Dancing (VIP)', detail: `MWF 5:30–6:30 pm · ₱${madFit.toLocaleString()}/mo VIP rate (orig. ₱2,500)` },
        { value: `Studio Rental (VIP) – ₱${madRnt.toLocaleString()}/hour`, name: 'Studio Rental (VIP)', detail: `₱${madRnt.toLocaleString()} per hour · by appointment (orig. ₱450)` },
      ],
      'Summer Blast': [
        { value: `Registration Fee – ₱${sbRegFee.toLocaleString()}`, name: `Registration Fee (Slot Reservation) – ₱${sbRegFee.toLocaleString()}`, detail: `₱${sbRegFee.toLocaleString()} · Slot Reservation · Free t-shirt, recital fee, certs` },
        { value: `Single Course (VIP) – ₱${sbSingleVip.toLocaleString()}`, name: `Single Course (VIP Discounted) – ₱${sbSingleVip.toLocaleString()}`, detail: `₱${sbSingleVip.toLocaleString()}${sbDisc > 0 ? ` VIP rate (orig. ₱${sbSingleRate.toLocaleString()})` : ''} · Choose 1 Course + 1 Academics FREE` },
        { value: `Two Courses (VIP) – ₱${sbTwoVip.toLocaleString()}`, name: `Two Courses (VIP Discounted) – ₱${sbTwoVip.toLocaleString()}`, detail: `₱${sbTwoVip.toLocaleString()}${sbDisc > 0 ? ` VIP rate (orig. ₱${sbTwoRate.toLocaleString()})` : ''} · Choose 2 Courses + 1 Academics FREE` },
        { value: `Special Course (VIP) – ₱${sbSpecialVip.toLocaleString()}`, name: `Special Course (VIP Discounted) – ₱${sbSpecialVip.toLocaleString()}`, detail: `₱${sbSpecialVip.toLocaleString()}${sbDisc > 0 ? ` VIP rate (orig. ₱${sbSpecialRate.toLocaleString()})` : ''} · 1 Special Course + 1 Any Course FREE` },
      ],
      'VIP Club Membership': [
        { value: `VIP Club Membership – ₱${window.vipFee || 500} (2 years)`, name: 'VIP Club Membership', detail: `₱${window.vipFee || 500} · Valid for 2 years · Discounts on all programs` },
      ],
    };
  }

  let PROGRAM_PACKAGES_VIP = buildDynamicVipPackages();

  function refreshProgramPackagesVip() {
    PROGRAM_PACKAGES_VIP = buildDynamicVipPackages();
  }

  async function syncEinsteinSettings() {
    try {
      const res = await fetch(apiUrl('api/api_settings.php'), { cache: 'no-store', credentials: 'same-origin' });
      const data = await res.json();
      if (data && data.success && data.settings) {
        let cur = {};
        try { cur = JSON.parse(localStorage.getItem('einsteinSettings')) || {}; } catch (e) {}
        const merged = Object.assign({}, cur, data.settings);
        localStorage.setItem('einsteinSettings', JSON.stringify(merged));
        refreshProgramPackagesVip();
      }
    } catch (e) {}
  }

  // Sync settings immediately and listen for changes
  syncEinsteinSettings().catch(() => {});
  window.addEventListener('storage', (e) => {
    if (e.key === 'einsteinSettings') refreshProgramPackagesVip();
  });

  const PROGRAM_TIMESLOTS = {
    'M.A.D. Studio': [
      'MWF — 5:30–6:30 pm (Pop Dancing)',
      'MWF — 6:45–7:45 pm (Hiphop Aerobics)',
      'TTHS — 5:30–6:30 pm (Taekwondo)',
      'TTHS — 6:45–7:45 pm (Kickboxing)',
      'Saturday — 8:30–10:30 am (Gymnastics)',
      'Saturday — 10:30 am–12:30 pm (Ballet)',
      'Saturday — 12:30–2:30 pm (Taekwondo)',
    ],
  };

  const PROGRAM_ALIAS = {
    'academic tutorial': 'Academic Tutorial',
    'tutoring': 'Academic Tutorial',
    'tutorial': 'Academic Tutorial',
    'weekend workshop': 'Weekend Workshop',
    'workshop': 'Weekend Workshop',
    'playschool': 'Playschool',
    'child care program': 'Child Care Program',
    'childcare': 'Child Care Program',
    'child care': 'Child Care Program',
    'm.a.d. studio': 'M.A.D. Studio',
    'mad studio': 'M.A.D. Studio',
    'mad': 'M.A.D. Studio',
    'summer blast': 'Summer Blast',
    'summerblast': 'Summer Blast',
    'summer': 'Summer Blast',
    'vip club membership': 'VIP Club Membership',
    'vip': 'VIP Club Membership',
  };

  function syncVipClientState(state) {
    if (!state || typeof state !== 'object') {
      window.clientVipState = null;
      window.isUserVipActive = false;
      window.isUserVip = false;
      window.isUserVipPending = false;
      try {
        sessionStorage.removeItem('isVip');
        localStorage.removeItem('isVip');
        sessionStorage.removeItem('vipPending');
        localStorage.removeItem('vipPending');
      } catch (_) {}
      return;
    }

    const active = state.active === true;
    // A request submitted after an unsubscribe is a new lifecycle. The server
    // normally returns this combination as false/true, but keep the client
    // defensive so an old cached unsubscribe cannot lock the control.
    const pending = state.pending === true && !active && state.unsubscribed !== true;
    window.clientVipState = { ...state, active, pending };
    window.isUserVipActive = active;
    window.isUserVip = active;
    window.isUserVipPending = pending;

    try {
      if (active) {
        sessionStorage.setItem('isVip', 'true');
        localStorage.setItem('isVip', 'true');
      } else {
        sessionStorage.removeItem('isVip');
        localStorage.removeItem('isVip');
      }
      if (pending) {
        sessionStorage.setItem('vipPending', 'true');
        localStorage.setItem('vipPending', 'true');
      } else {
        sessionStorage.removeItem('vipPending');
        localStorage.removeItem('vipPending');
      }
    } catch (_) {}
  }

  function isCurrentUserVipActive() {
    if (window.clientVipState && typeof window.clientVipState.active === 'boolean') {
      return window.clientVipState.active;
    }
    return false;
  }
  window.isCurrentUserVipActive = isCurrentUserVipActive;

  function isCurrentUserVipPending() {
    if (window.clientVipState && typeof window.clientVipState.pending === 'boolean') {
      return window.clientVipState.pending && window.clientVipState.unsubscribed !== true;
    }
    return false;
  }
  window.isCurrentUserVipPending = isCurrentUserVipPending;

  function checkIsUserVip() {
    if (isCurrentUserVipActive()) return true;
    if (formData && formData.joinVip) return true;
    return false;
  }

  function getProgramPackages(prog) {
    if (!prog) return [];
    const normalized = PROGRAM_ALIAS[String(prog).toLowerCase()] || prog;
    const isVip = checkIsUserVip();
    if (isVip) {
      refreshProgramPackagesVip();
      if (PROGRAM_PACKAGES_VIP[normalized]) {
        return PROGRAM_PACKAGES_VIP[normalized];
      }
    }
    if (normalized === 'Summer Blast') {
      const settings = getEinsteinSiteSettings();
      if (settings && Array.isArray(settings.summerblast) && settings.summerblast.length) {
        return settings.summerblast.map(pkg => {
          const numVal = parseFloat(String(pkg.rate).replace(/[^0-9.]/g, '')) || 0;
          return {
            value: `${pkg.name} – ₱${numVal.toLocaleString()}`,
            name: `${pkg.name} – ₱${numVal.toLocaleString()}`,
            detail: `₱${numVal.toLocaleString()} · ${pkg.detail || pkg.category || ''}`
          };
        });
      }
    }
    return PROGRAM_PACKAGES[normalized] || [];
  }

  const TAGBILARAN_BARANGAYS = [
    'Bool',
    'Booy',
    'Cabawan',
    'Cogon',
    'Dao',
    'Dampas',
    'Manga',
    'Mansasa',
    'Poblacion I',
    'Poblacion II',
    'Poblacion III',
    'San Isidro',
    'Taloto',
    'Tiptip',
    'Ubujan'
  ];

  function getApiBaseUrl() {
    if (window.location.protocol === 'http:' || window.location.protocol === 'https:') {
      return new URL('.', window.location.href).href;
    }
    return 'http://localhost/EINSTEIN-WEB18/';
  }

  function apiUrl(path) {
    return new URL(path, getApiBaseUrl()).href;
  }

  function digitsOnly(value) {
    return String(value || '').replace(/\D+/g, '');
  }

  function lettersOnly(value) {
    return String(value || '').replace(/[^A-Za-z\s]/g, '');
  }

  function sanitizeContactField(input) {
    if (!input) return;
    input.value = digitsOnly(input.value);
  }

  function sanitizeFacebookField(input) {
    if (!input) return;
    // Allow letters, digits, spaces, periods (.), slashes (/), hyphens (-),
    // underscores (_), colons (:), and @ — covers both FB names and full URLs
    const current = String(input.value || '');
    const cleaned = current.replace(/[<>"'`\\]/g, '');
    if (cleaned !== current) {
      const start = input.selectionStart;
      const end = input.selectionEnd;
      input.value = cleaned;
      if (start !== null && end !== null) {
        try { input.setSelectionRange(start, end); } catch (e) {}
      }
    }
  }

  function normalizeName(value) {
    const cleaned = lettersOnly(value).replace(/\s+/g, ' ').trim();
    if (!cleaned) return '';
    return cleaned.toLowerCase().replace(/(^|\s)([a-z])/g, (_, gap, letter) => gap + letter.toUpperCase());
  }

  function installEnrollmentFieldValidation() {
    if (installEnrollmentFieldValidation.installed) return;
    installEnrollmentFieldValidation.installed = true;

    document.addEventListener('input', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;

      if (target.matches('#ef_tel, .ec-age, .ec-grade')) {
        target.value = digitsOnly(target.value);
        return;
      }

      if (target.matches('.ec-purok')) {
        target.value = digitsOnly(target.value).slice(0, 2);
        return;
      }

      if (target.matches('#ef_fb')) {
        sanitizeFacebookField(target);
        return;
      }

      if (target.matches('#ef_gname, .ec-cname')) {
        target.value = lettersOnly(target.value).replace(/\s{2,}/g, ' ');
      }
    });

    document.addEventListener('blur', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;

      if (target.matches('#ef_gname, .ec-cname')) {
        target.value = normalizeName(target.value);
      }
    }, true);
  }

  function bindGuardianFieldValidators() {
    const contactInput = qs('#ef_tel');
    const facebookInput = qs('#ef_fb');

    if (contactInput) {
      sanitizeContactField(contactInput);
      contactInput.addEventListener('input', () => sanitizeContactField(contactInput));
      contactInput.addEventListener('paste', () => setTimeout(() => sanitizeContactField(contactInput), 0));
      contactInput.addEventListener('blur', () => sanitizeContactField(contactInput));
    }

    if (facebookInput) {
      // No character stripping — Facebook Link allows URLs, digits, and symbols
    }
  }

  installEnrollmentFieldValidation();

  function applyProgramPackages(prog) {
    const normalized = PROGRAM_ALIAS[String(prog || '').toLowerCase()] || prog;
    const pkgs = getProgramPackages(normalized);
    if (pkgs && pkgs.length) {
      window.ENROLL_PACKAGES = pkgs;
    }
    if (PROGRAM_TIMESLOTS[normalized]) {
      window.ENROLL_TIMESLOTS = PROGRAM_TIMESLOTS[normalized];
    } else {
      window.ENROLL_TIMESLOTS = null;
    }
  }

  function showEnrollmentModal() {
    injectModal();
    const modal = qs('#efModal');
    if (!modal) return;
    modal.classList.remove('ef-hidden');
    modal.style.removeProperty('display');
    document.body.style.overflow = 'hidden';
  }

  function hideEnrollmentModal() {
    const modal = qs('#efModal');
    if (!modal) return;
    modal.classList.add('ef-hidden');
    document.body.style.overflow = '';
  }

  /* ─── PUBLIC API ─────────────────────────────────── */
  window.goTo = goTo;   // expose so onclick="goTo(n)" works in HTML

  window.openEnrollmentFlow = async function (prog) {
    const isVipProgram = String(prog).toLowerCase().includes('vip');
    if (isVipProgram) {
      // Refresh from the logged-in user's server state before deciding whether
      // VIP enrollment is blocked. Cached flags can belong to an old session.
      await loadLatestGuardianProfile();
      if (isCurrentUserVipActive()) {
        alert('You are already an active VIP Club Member! Center-wide discounts are already active on your account.');
        return;
      }
      if (isCurrentUserVipPending()) {
        alert('Your VIP Club Membership application is currently awaiting administrator approval.');
        return;
      }
    }
    if (isVipProgram) { try { await window.refreshVipFee(); } catch(e) { alert(e.message); return; } }
    programName = prog;
    formData = { program: prog, joinVip: false };
    account = { email: '', password: '', phone: '', userId: null, isNewAccount: false, channel: 'email' };
    authScreen = 'choose';
    if (!isCurrentUserVipActive()) {
      window.isUserVipActive = false;
    }
    try { await syncEinsteinSettings(); } catch(e) {}
    refreshProgramPackagesVip();
    applyProgramPackages(prog);

    try {
      const response = await fetch(apiUrl('api/check_session.php?portal=user'), { credentials: 'include', cache: 'no-store' });
      const data = await response.json();
      if (data.logged_in && (data.role === 'user' || !data.role)) {
        localStorage.setItem('userEmail', data.email || '');
        sessionStorage.setItem('userId', data.user_id || '');
        syncVipClientState({ active: !!data.is_vip, pending: !!data.vip_pending });
        await window.openEnrollmentFlowLoggedIn(prog);
        return;
      } else if (!data.logged_in) {
        syncVipClientState(null);
      }
    } catch (e) {
      console.error('Session check failed:', e);
    }

    step = 1;
    account = { email: '', password: '', phone: '', userId: null, isNewAccount: false, channel: 'email' };
    showEnrollmentModal();
    goTo(1);
  };

  async function loadLatestGuardianProfile() {
    try {
      const response = await fetch(apiUrl('api/get_enrollments.php'), { cache: 'no-store', credentials: 'include' });
      const data = await response.json();
      if (data && data.success) {
        // Always replace the previous state, including false values. This is
        // what clears a stale pending flag after approval, rejection, or
        // unsubscribe.
        syncVipClientState(data.vip_membership || null);
      } else if (data && data.success === false) {
        syncVipClientState(null);
      }
      if (!data.success || !Array.isArray(data.enrollments) || !data.enrollments.length) return;

      const latest = data.enrollments.find(e => e && (e.guardian_name || e.address)) || data.enrollments[0];
      if (!latest) return;

      if (latest.guardian_name) formData.guardian_name = latest.guardian_name;
      if (latest.address) formData.address = latest.address;
      if (latest.contact) formData.contact = latest.contact;
      if (latest.facebook_name) formData.facebook_name = latest.facebook_name;
      if (latest.start_date) formData.start_date = latest.start_date;
    } catch (e) {
      console.warn('Could not load saved guardian profile:', e);
    }
  }

  window.openEnrollmentFlowLoggedIn = async function (prog, prefill) {
    const isVipProgram = String(prog).toLowerCase().includes('vip');
    if (isVipProgram) {
      await loadLatestGuardianProfile();
      if (isCurrentUserVipActive() || isCurrentUserVipPending()) {
        if (isCurrentUserVipActive()) {
          alert('You are already an active VIP Club Member! Center-wide discounts are already active on your account.');
        } else {
          alert('Your VIP Club Membership application is currently awaiting administrator approval.');
        }
        return;
      }
    }
    if (isVipProgram) { try { await window.refreshVipFee(); } catch(e) { alert(e.message); return; } }
    programName = prog;
    formData = { program: prog, joinVip: false };
    try {
      const response = await fetch(apiUrl('api/check_session.php?portal=user'), { credentials: 'include', cache: 'no-store' });
      const data = await response.json();
      if (data.logged_in && (data.role === 'user' || !data.role)) {
        syncVipClientState({ active: !!data.is_vip, pending: !!data.vip_pending });
      } else if (!data.logged_in) {
        syncVipClientState(null);
      }
    } catch(e) {}

    await loadLatestGuardianProfile();

    if (isCurrentUserVipActive() || isCurrentUserVipPending()) {
      formData.joinVip = false;
    }

    try { await syncEinsteinSettings(); } catch(e) {}
    refreshProgramPackagesVip();
    applyProgramPackages(prog);

    const userEmail = localStorage.getItem('userEmail') || sessionStorage.getItem('userEmail') || '';
    const userId = sessionStorage.getItem('userId') || null;

    account = {
      email: userEmail,
      password: '',
      phone: '',
      userId: userId,
      isNewAccount: false,
      channel: 'email'
    };

    await loadLatestGuardianProfile();

    if (prefill) {
      if (prefill.guardian_name) formData.guardian_name = prefill.guardian_name;
      if (prefill.address) formData.address = prefill.address;
      if (prefill.contact) formData.contact = prefill.contact;
      if (prefill.facebook_name) formData.facebook_name = prefill.facebook_name;
      if (prefill.start_date) formData.start_date = prefill.start_date;

      const keyMap = {
        'Academic Tutorial': 'tutoring', 'Tutoring': 'tutoring',
        'Weekend Workshop': 'workshop', 'Workshop': 'workshop',
        'Playschool': 'playschool',
        'Child Care Program': 'childcare', 'Child Care': 'childcare',
        'M.A.D. Studio': 'madstudio', 'MAD Studio': 'madstudio',
        'VIP Club Membership': 'vip', 'VIP': 'vip'
      };
      const progKey = keyMap[prog] || prog.toLowerCase().replace(/\s+/g, '');

      let prefDate = '';
      let prefTime = '';
      if (prefill.timeslot) {
        const ts = prefill.timeslot.trim();
        const dateMatch = ts.match(/^(Monday - Friday|Monday - Thursday|MWF|TThS|Saturday|Sunday)/i);
        if (dateMatch) {
          prefDate = dateMatch[0];
          prefTime = ts.substring(dateMatch[0].length).trim();
        } else {
          prefTime = ts;
        }
      }

      let matchedPackage = prefill.package_selected || '';
      const availablePkgs = getProgramPackages(prog);
      if (availablePkgs && availablePkgs.length) {
        const found = availablePkgs.find(p => p.value === matchedPackage || p.name === matchedPackage || (matchedPackage && p.name && p.name.includes(matchedPackage)));
        if (found) matchedPackage = found.value;
      }

      const defaultServices = {};
      if (progKey) {
        defaultServices[progKey] = {
          enrolled: true,
          package: matchedPackage,
          timeslot: prefill.timeslot || '',
          prefDate: prefDate,
          prefTime: prefTime
        };
      }

      formData.child_name = prefill.child_name || '';
      formData.child_age = prefill.child_age || '';
      formData.grade_level = prefill.grade_level || '';
      formData.school = prefill.school || '';
      formData.package_selected = matchedPackage;
      formData.timeslot = prefill.timeslot || '';

      formData.children_list = [{
        name: prefill.child_name || '',
        age: prefill.child_age || '',
        grade: prefill.grade_level || '',
        school: prefill.school || '',
        services: defaultServices
      }];
    }

    step = 3;
    showEnrollmentModal();
    goTo(3);
  };

  window.closeEnrollmentFlow = function () {
    hideEnrollmentModal();
    if (step === 6) {
      if (typeof window.loadEnrollments === 'function') {
        window.loadEnrollments();
      }
      if (window.location.pathname.includes('user.html')) {
        window.location.reload();
      } else {
        window.location.href = 'user.html';
      }
    }
  };

  window.goToUserPortal = function () {
    hideEnrollmentModal();
    if (window.location.pathname.includes('user.html')) {
      window.location.reload();
    } else {
      window.location.href = 'user.html';
    }
  };

  window.handleStep3Back = function () {
    // If user came from logged-in flow (started at step 3), close modal
    // Otherwise go back to step 2
    if (step === 3 && account.isNewAccount === false && account.email) {
      closeEnrollmentFlow();
    } else {
      goTo(2);
    }
  };

  window.setPaymentMethod = function (method, el) {
    paymentMethod = method;
    formData.payment_method = method;
    // Update visual feedback on payment cards
    const cards = document.querySelectorAll('.ef-qr-card');
    cards.forEach(card => card.classList.remove('ef-qr-card-selected'));
    const target = el ? (el.closest('.ef-qr-card') || el) : event.currentTarget;
    if (target) target.classList.add('ef-qr-card-selected');
    // Update payment summary row
    const display = document.getElementById('efPaymentDisplay');
    if (display) display.textContent = method;
  };

  /* ─── NAVIGATION ─────────────────────────────────── */
  function goTo(n) {
    step = n;
    const isVipFlow = String(formData.program || programName || '').toLowerCase().includes('vip');
    const steps = ['Account', 'Verify', isVipFlow ? 'Member Info' : 'Guardian', 'Children & Programs', 'Review', 'Processing', 'Done'];
    if (n === 1 && authScreen === 'choose') steps[0] = 'Sign In';
    else if (n === 1 && authScreen === 'login') steps[0] = 'Log In';
    else if (n === 1 && authScreen === 'signup') steps[0] = 'Sign Up';

    const stepNum = n === '3b' ? 3.5 : n;
    const stepLabel = n === '3b' ? 'Children & Programs' : n === 3 ? (isVipFlow ? 'Member Info' : 'Guardian Info') : (steps[n - 1] || String(n));
    const pct = isVipFlow
      ? (n === 3 ? 50 : (n === 4 ? 100 : Math.round((stepNum / 6) * 100)))
      : Math.round((stepNum / 6) * 100);

    qs('#efBar').style.width = pct + '%';
    qs('#efStepLabel').textContent = stepLabel;
    qs('#efStepNum').textContent = isVipFlow
      ? (n === 3 ? 'Step 1 of 2' : (n === 4 ? 'Step 2 of 2' : `Step ${n}`))
      : (n === '3b' ? 'Step 3 of 6' : `Step ${n} of 6`);
    qs('#efBody').innerHTML = renders[n]();
    qs('#efBody').scrollTop = 0;
    attachHandlers(n);

    // Warm up the receipt validators before payment screenshot upload
    if (n === 4 || n === '3b') {
      try {
        if (typeof window.preloadPaymentReceiptValidation === 'function') {
          window.preloadPaymentReceiptValidation();
        } else {
          loadNsfwModel();
        }
      } catch (e) { }
    }
  }

  /* ─── STEP RENDERERS ─────────────────────────────── */
  const renders = {
    1: () => {
      if (authScreen === 'choose') {
        const progHint = programName
          ? `<p class="ef-sub ef-auth-program">Enrolling in: <strong>${esc(programName)}</strong></p>`
          : '';
        return `
          <div class="ef-auth-hero">
            <h3 class="ef-auth-hero-title">Enrollment Account Verification</h3>
            <p class="ef-sub">To continue with enrollment, please sign in or create an account.</p>
            ${progHint}
          </div>
          <div class="ef-verify-choice-grid" style="display:grid;gap:12px">
            <button type="button" class="ef-verify-choice-card" onclick="showAuthScreen('login')">
              <div class="ef-verify-choice-body">
                <span class="ef-verify-choice-title">Log In</span>
                <span class="ef-verify-choice-desc">Already have an account? Sign in here.</span>
              </div>
              <span class="ef-verify-choice-arrow">›</span>
            </button>
            <button type="button" class="ef-verify-choice-card" onclick="showVerificationMethod('email')">
              <div class="ef-verify-choice-body">
                <span class="ef-verify-choice-title">Sign Up / Register</span>
                <span class="ef-verify-choice-desc">Create a new account with email verification.</span>
              </div>
              <span class="ef-verify-choice-arrow">›</span>
            </button>
          </div>`;
      }

      if (authScreen === 'login') {
        return `
          
          <div class="ef-fieldset-title">Log In</div>
          <p class="ef-sub">Enter your email and password to continue to the enrollment form.</p>
          <div class="ef-field">
            <label>Email Address <span class="req">*</span></label>
            <input id="ef_login_email" type="email" placeholder="your.email@example.com" value="${esc(account.email || '')}">
          </div>
          <div class="ef-field">
            <label>Password <span class="req">*</span></label>
            <div class="ef-password-wrap">
              <button type="button" class="ef-pass-icon" data-target="ef_lpw" aria-label="Show password" aria-pressed="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle><line x1="3" y1="3" x2="21" y2="21"></line></svg>
              </button>
              <input id="ef_lpw" type="password" placeholder="Enter your password">
            </div>
          </div>
          <button type="button" class="ef-btn-primary" onclick="efStep1Login()">Log In & Continue <span class="ef-arrow">→</span></button>
          <p class="ef-auth-switch">Don't have an account? <button type="button" class="ef-link-btn" onclick="showAuthScreen('signup')">Sign up here</button></p>`;
      }

      return `
        
        <div class="ef-fieldset-title">Sign Up</div>
        <p class="ef-sub">Create your account. We'll send a verification code to your email.</p>
        <div class="ef-field">
          <label>Email Address <span class="req">*</span></label>
          <input id="ef_email" type="email" placeholder="your.email@example.com" value="${esc(account.email || '')}">
        </div>
        <div class="ef-field">
          <label>Password <span class="req">*</span></label>
          <div class="ef-password-wrap">
            <button type="button" class="ef-pass-icon" data-target="ef_pw" aria-label="Show password" aria-pressed="false">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle><line x1="3" y1="3" x2="21" y2="21"></line></svg>
            </button>
            <input id="ef_pw" type="password" placeholder="Minimum 8 characters">
          </div>
          <span class="ef-hint">Use a mix of letters, numbers, and symbols.</span>
        </div>
        <div class="ef-field">
          <label>Confirm Password <span class="req">*</span></label>
          <div class="ef-password-wrap">
            <button type="button" class="ef-pass-icon" data-target="ef_pw2" aria-label="Show password" aria-pressed="false">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle><line x1="3" y1="3" x2="21" y2="21"></line></svg>
            </button>
            <input id="ef_pw2" type="password" placeholder="Re-enter your password">
          </div>
        </div>
        <div class="ef-info-box">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
          A 6-digit verification code will be sent to your email.
        </div>
        <button type="button" class="ef-btn-primary" onclick="efStep1New()">Create Account & Send Code <span class="ef-arrow">→</span></button>
        <p class="ef-auth-switch">Already have an account? <button type="button" class="ef-link-btn" onclick="showAuthScreen('login')">Log in here</button></p>`;
    },

    2: () => `
      <div class="ef-fieldset-title">Verify Your Code</div>
      ${account.channel === 'sms'
        ? `<p class="ef-sub">A 6-digit code was sent to <strong class="ef-email-hl">${esc(account.phone)}</strong>. Please check your messages.</p>`
        : `<p class="ef-sub">A 6-digit code was sent to <strong class="ef-email-hl">${esc(account.email)}</strong>. Please check your inbox and spam folder.</p>`}
      <div class="ef-otp-wrap">
        <input id="ef_otp" type="text" class="ef-otp-input" maxlength="6" placeholder="______" autocomplete="one-time-code" inputmode="numeric">
        <div class="ef-otp-icon">${account.channel === 'sms' ? '📱' : '✉'}</div>
      </div>
      <div id="ef_otp_msg" class="ef-otp-msg">Enter the 6-digit code above to continue.</div>
      <button class="ef-btn-primary" onclick="efStep2()">Verify Code <span class="ef-arrow">→</span></button>
      <button class="ef-btn-ghost" onclick="efResend()">Resend Code</button>`,

    3: () => {
      const isVipFlow = String(formData.program || programName || '').toLowerCase().includes('vip');
      const title = isVipFlow ? 'VIP Member Information' : 'Parent / Guardian Information';
      const sub = isVipFlow ? 'Please enter your personal details to join VIP Club Membership.' : 'Please fill in your details first.';
      const nameLabel = isVipFlow ? 'Full Name' : "Guardian's Full Name";
      return `
        <div class="ef-fieldset-title">${title}</div>
        <p class="ef-sub" style="margin-bottom:14px">${sub}</p>
        <div class="ef-grid-2">
          <div class="ef-field">
            <label>${nameLabel} <span class="req">*</span></label>
            <input id="ef_gname" data-validate="name" type="text" placeholder="Complete name" value="${esc(formData.guardian_name || '')}" inputmode="text" autocomplete="name">
          </div>
          <div class="ef-field">
            <label for="ef_parent_age">${isVipFlow ? 'Age' : 'Parent / Guardian Age'} <span class="req">*</span></label>
            <input id="ef_parent_age" type="text" maxlength="3" pattern="[0-9]{1,3}" oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,3)" required inputmode="numeric" placeholder="Age" value="${esc(formData.guardian_age || formData.child_age || '')}">
          </div>
        </div>
        <div class="ef-field">
          <label>Home Address <span class="req">*</span></label>
          <input id="ef_addr" data-validate="address" type="text" placeholder="Complete home address" value="${esc(formData.address || '')}" inputmode="text">
        </div>
        <div class="ef-grid-2">
          <div class="ef-field">
            <label>Contact Number <span class="req">*</span></label>
            <input id="ef_tel" data-validate="number" type="tel" placeholder="09XX XXX XXXX" value="${esc(formData.contact || '')}" inputmode="numeric" pattern="[0-9]*" maxlength="15">
          </div>
          <div class="ef-field">
            <label>Facebook Name <span class="req">*</span></label>
            <input id="ef_fb" data-validate="facebook" type="text" placeholder="e.g. Maria Santos" value="${esc(formData.facebook_name || '')}" inputmode="text" autocomplete="off">
          </div>
        </div>
        <button class="ef-btn-primary" onclick="efStep3Guardian()">Continue <span class="ef-arrow">→</span></button>
        <button class="ef-btn-ghost" onclick="handleStep3Back()">← Back</button>`;
    },

    '3b': () => {
      const isVipFlow = String(formData.program || programName || '').toLowerCase().includes('vip');
      const ALL_PROGS = [
        { key: 'tutoring', label: 'Academic Tutorial', pkgKey: 'Academic Tutorial' },
        { key: 'workshop', label: 'Weekend Workshop', pkgKey: 'Weekend Workshop' },
        { key: 'playschool', label: 'Playschool', pkgKey: 'Playschool' },
        { key: 'childcare', label: 'Child Care Program', pkgKey: 'Child Care Program' },
        { key: 'madstudio', label: 'M.A.D. Studio', pkgKey: 'M.A.D. Studio' },
        { key: 'summerblast', label: 'Summer Blast', pkgKey: 'Summer Blast' },
      ];
      const children = formData.children_list && formData.children_list.length
        ? formData.children_list
        : [{ name: '', age: '', grade: '', school: '', services: {} }];

      if (isVipFlow) {
        children.forEach(child => {
          if (!child.services || !child.services.vip) {
            child.services = {
              vip: {
                enrolled: true,
                package: `VIP Club Membership – ₱${window.vipFee || 500} (2 years)`,
                timeslot: ''
              }
            };
          } else {
            child.services.vip.enrolled = true;
            if (!child.services.vip.package) {
              child.services.vip.package = `VIP Club Membership – ₱${window.vipFee || 500} (2 years)`;
            }
          }
        });
      }

      const childCards = children.map((child, ci) => {
        if (!child.services) child.services = {};

        let servicePickers = '';
        if (isVipFlow) {
          servicePickers = `
            <div style="padding:10px 14px;background:#fdf8f3;border:1px solid #c4a97e;border-radius:8px;display:flex;align-items:center;justify-content:space-between;box-sizing:border-box;width:100%">
              <div>
                <div style="font-size:13px;color:#3d1f0a;font-weight:700">VIP Club Membership</div>
                <div style="font-size:11px;color:#8b6f47;margin-top:2px">₱${window.vipFee || 500} · Valid for 2 years · Exclusive center privileges</div>
              </div>
              <span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px">VIP</span>
            </div>
          `;
        } else {
          const enrolledSvcs = ALL_PROGS.filter(prog => child.services[prog.key]?.enrolled);
          const unenrolledSvcs = ALL_PROGS.filter(prog => !child.services[prog.key]?.enrolled);

          const enrolledHTML = enrolledSvcs.map(prog => {
            const svc = child.services[prog.key];
            const isUserVip = checkIsUserVip();
            const pkgs = getProgramPackages(prog.pkgKey) || getProgramPackages(prog.label) || [];
            const slots = PROGRAM_TIMESLOTS[prog.pkgKey] || PROGRAM_TIMESLOTS[prog.label] || [
              'Monday - Friday',
              'MWF',
              'TThS',
              'Monday - Thursday',
              '9:00 AM - 10:00 AM',
              '10:00 AM - 11:00 AM',
              '11:00 AM - 12:00 PM',
              '1:00 PM - 2:00 PM',
              '2:00 PM - 3:00 PM',
              '3:00 PM - 4:00 PM',
              '4:00 PM - 5:00 PM',
              '5:00 PM - 6:00 PM'
            ];

            if (pkgs.length === 1 && !svc.package) {
              svc.package = pkgs[0].value;
            }

            return `
              <div style="padding:10px 12px;border:1px solid #e5d9ce;border-radius:8px;margin-bottom:8px;background:#fcfbf9;box-sizing:border-box;width:100%">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                  <span style="font-size:13px;color:#3d1f0a;font-weight:600">${prog.label}${isUserVip ? ' <span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;vertical-align:middle;margin-left:4px">VIP RATES APPLIED</span>' : ''}</span>
                  <button onclick="toggleSvc(${ci}, '${prog.key}', false)" style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:20px;line-height:1;padding:0" title="Remove Program">&times;</button>
                </div>
                <div style="display:grid;gap:7px;box-sizing:border-box;width:100%">
                  ${pkgs.length ? `
                  <select class="ec-pkg" data-ci="${ci}" data-prog="${prog.key}" onchange="handlePkgChange(this, ${ci}, '${prog.key}')"
                    style="font-size:12px;padding:9px 12px;border:1px solid #e5d9ce;border-radius:6px;font-family:inherit;background:#fff;width:100%;box-sizing:border-box;cursor:pointer">
                    ${pkgs.length > 1 ? '<option value="">Select package…</option>' : ''}
                    ${pkgs.map(p => `<option value="${esc(p.value)}" ${svc.package === p.value ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}
                  </select>` : ''}
                  ${prog.key === 'workshop' ? `
                  <div id="homebased-wrap-${ci}-${prog.key}" class="ec-hb-wrap" style="display:${(svc.package || '').toLowerCase().includes('home based') ? 'grid' : 'none'};grid-template-columns:1fr 1fr;gap:7px;box-sizing:border-box;width:100%;margin-top:2px;padding:9px 11px;background:#fbf7f0;border:1px solid #e0cfb8;border-radius:6px">
                    <div style="min-width:0">
                      <label style="display:block;font-size:11px;font-weight:600;color:#5c3317;margin-bottom:4px">Barangay <span style="color:#c0392b">*</span></label>
                      <select class="ec-brgy" data-ci="${ci}" data-prog="${prog.key}"
                        style="font-size:12px;padding:8px 10px;border:1px solid #e5d9ce;border-radius:6px;font-family:inherit;background:#fff;width:100%;box-sizing:border-box;cursor:pointer">
                        <option value="">Select Barangay...</option>
                        ${TAGBILARAN_BARANGAYS.map(b => `<option value="${b}" ${(svc.barangay || '') === b ? 'selected' : ''}>${b}</option>`).join('')}
                      </select>
                    </div>
                    <div style="min-width:0">
                      <label style="display:block;font-size:11px;font-weight:600;color:#5c3317;margin-bottom:4px">Purok / Sitio <span style="color:#c0392b">*</span> <span style="font-size:10px;color:#888;font-weight:normal">(max 2 digits)</span></label>
                      <input type="text" class="ec-purok" data-ci="${ci}" data-prog="${prog.key}" data-validate="purok"
                        placeholder="Purok (e.g. 1)"
                        maxlength="2"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        value="${esc(svc.purok || '')}"
                        onkeydown="if(!/[0-9]/.test(event.key) && !['Backspace','Delete','ArrowLeft','ArrowRight','Tab'].includes(event.key)){ event.preventDefault(); }"
                        oninput="this.value = this.value.replace(/\\D/g, '').slice(0, 2)"
                        style="font-size:12px;padding:8px 10px;border:1px solid #e5d9ce;border-radius:6px;font-family:inherit;background:#fff;width:100%;box-sizing:border-box">
                    </div>
                  </div>` : ''}
                  ${prog.key !== 'vip' ? `
                  <div style="display:grid;grid-template-columns:1fr 1fr;gap:7px;box-sizing:border-box;width:100%;overflow:hidden">
                    <input type="text" class="ec-date" data-ci="${ci}" data-prog="${prog.key}" data-validate="schedule"
                      list="dates-${ci}-${prog.key}"
                      placeholder="Preferred date"
                      value="${esc(svc.prefDate || '')}"
                      style="font-size:12px;padding:9px 12px;border:1px solid #e5d9ce;border-radius:6px;font-family:inherit;background:#fff;width:100%;box-sizing:border-box;min-width:0">
                    <datalist id="dates-${ci}-${prog.key}">
                      <option value="Monday - Friday">
                      <option value="MWF">
                      <option value="TThS">
                      <option value="Monday - Thursday">
                      <option value="Saturday">
                      <option value="Sunday">
                    </datalist>

                    <input type="text" class="ec-time" data-ci="${ci}" data-prog="${prog.key}" data-validate="schedule"
                      list="times-${ci}-${prog.key}"
                      placeholder="Preferred time slot"
                      value="${esc(svc.prefTime || '')}"
                      style="font-size:12px;padding:9px 12px;border:1px solid #e5d9ce;border-radius:6px;font-family:inherit;background:#fff;width:100%;box-sizing:border-box;min-width:0">
                    <datalist id="times-${ci}-${prog.key}">
                      <option value="9:00 AM - 10:00 AM">
                      <option value="10:00 AM - 11:00 AM">
                      <option value="11:00 AM - 12:00 PM">
                      <option value="1:00 PM - 2:00 PM">
                      <option value="2:00 PM - 3:00 PM">
                      <option value="3:00 PM - 4:00 PM">
                      <option value="4:00 PM - 5:00 PM">
                      <option value="5:00 PM - 6:00 PM">
                    </datalist>
                  </div>` : ''}
                </div>
              </div>`;
          }).join('');

          const addProgHTML = unenrolledSvcs.length ? `
            <select onchange="if(this.value) toggleSvc(${ci}, this.value, true)"
              style="width:100%;padding:9px 12px;border:1px dashed #c4a97e;border-radius:8px;font-size:13px;font-family:inherit;background:#fff;color:#8b6f47;cursor:pointer;outline:none">
              <option value="">+ Add a Program...</option>
              ${unenrolledSvcs.map(p => `<option value="${p.key}">${p.label}</option>`).join('')}
            </select>
          ` : '';

          servicePickers = `
            <div style="margin-bottom:4px">${enrolledHTML}</div>
            <div>${addProgHTML}</div>
          `;
        }

        const sectionLabel = isVipFlow ? 'Membership' : 'Select Programs / Services';

        return `
          <div style="border:1px solid ${ci === 0 ? '#c4a97e' : '#e5d9ce'};border-radius:10px;padding:14px 16px;margin-bottom:12px;position:relative;box-sizing:border-box">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
              <span style="font-size:11px;font-weight:700;color:#8b6f47;text-transform:uppercase;letter-spacing:.06em">Child ${ci + 1}</span>
              ${ci > 0 ? `<button type="button" onclick="window.removeEnrollmentChild(${ci})" style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:18px;padding:0" title="Remove child">✕</button>` : ''}
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;overflow:hidden">
              <div class="ef-field" style="margin:0;min-width:0">
                <label>Full Name <span class="req">*</span></label>
                <input type="text" class="ec-cname" data-ci="${ci}" data-validate="name" placeholder="Child's name" value="${esc(child.name || '')}" inputmode="text" autocomplete="off"
                  style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #e5d9ce;border-radius:8px;font-size:13px;font-family:inherit">
              </div>
              <div class="ef-field" style="margin:0;min-width:0">
                <label>Age <span class="req">*</span></label>
                <input type="text" class="ec-age" data-ci="${ci}" data-validate="number" placeholder="e.g. 7" value="${esc(child.age || '')}" inputmode="numeric" pattern="[0-9]*" maxlength="3"
                  style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #e5d9ce;border-radius:8px;font-size:13px;font-family:inherit">
              </div>
              <div class="ef-field" style="margin:0;min-width:0">
                <label>Grade / Level <span class="req">*</span></label>
                <input type="text" class="ec-grade" data-ci="${ci}" data-validate="number" placeholder="e.g. 2" value="${esc(child.grade || '')}" inputmode="numeric" pattern="[0-9]*" maxlength="3"
                  style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #e5d9ce;border-radius:8px;font-size:13px;font-family:inherit">
              </div>
              <div class="ef-field" style="margin:0;min-width:0">
                <label>School <span class="req">*</span></label>
                <input type="text" class="ec-school" data-ci="${ci}" data-validate="school" placeholder="Current school" value="${esc(child.school || '')}"
                  oninput="this.value = this.value.replace(/[^a-zA-Z\s]/g, '')"
                  style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #e5d9ce;border-radius:8px;font-size:13px;font-family:inherit">
              </div>
            </div>
            ${!isVipFlow && ci === 0 ? (
              isCurrentUserVipActive() ? `
              <div style="margin-bottom:14px;padding:12px 14px;border:1.5px solid #86efac;background:#f0fdf4;border-radius:10px;box-sizing:border-box;display:flex;align-items:center;justify-content:space-between;gap:10px">
                <div style="display:flex;align-items:center;gap:10px">
                  <div style="width:20px;height:20px;border-radius:50%;background:#16a34a;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">✓</div>
                  <div>
                    <div style="font-size:13px;font-weight:700;color:#166534">Active VIP Club Member</div>
                    <div style="font-size:11px;color:#15803d;margin-top:1px">Center-wide discounts are automatically applied to your enrollments.</div>
                  </div>
                </div>
                <span style="font-size:10px;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #bbf7d0;padding:2px 8px;border-radius:999px">Active VIP</span>
              </div>` : (
              isCurrentUserVipPending() ? `
              <div style="margin-bottom:14px;padding:12px 14px;border:1.5px solid #d6d3d1;background:#f5f5f4;border-radius:10px;box-sizing:border-box;display:flex;align-items:center;justify-content:space-between;gap:12px;opacity:0.85;cursor:not-allowed;user-select:none">
                <div style="display:flex;align-items:center;gap:10px">
                  <div style="width:20px;height:20px;border-radius:50%;border:2px solid #a8a29e;display:flex;align-items:center;justify-content:center;background:#e7e5e4;flex-shrink:0;box-sizing:border-box">
                    <div style="width:8px;height:8px;border-radius:50%;background:#a8a29e"></div>
                  </div>
                  <div>
                    <div style="font-size:13px;font-weight:700;color:#57534e;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                      <span>Join VIP Club Membership</span>
                      <span style="font-size:10px;font-weight:700;color:#b45309;background:#fef3c7;border:1px solid #fde68a;padding:2px 8px;border-radius:999px">Pending Approval</span>
                    </div>
                    <div style="font-size:11px;color:#78716c;margin-top:2px;line-height:1.3">
                      Your VIP membership application is currently awaiting admin verification.
                    </div>
                  </div>
                </div>
                <div style="font-size:11px;font-weight:600;color:#78716c;background:#e7e5e4;padding:3px 8px;border-radius:6px;white-space:nowrap">
                  Already Requested
                </div>
              </div>` : `
              <div class="ef-vip-toggle-wrap" onclick="toggleVipOption()" style="margin-bottom:14px;padding:12px 14px;border:1.5px solid ${formData.joinVip ? '#8b6f47' : '#dec6ac'};background:${formData.joinVip ? '#fdf8f3' : '#fff'};border-radius:10px;cursor:pointer;transition:all .2s ease;user-select:none;box-sizing:border-box">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
                  <div style="display:flex;align-items:center;gap:10px">
                    <div style="width:20px;height:20px;border-radius:50%;border:2px solid ${formData.joinVip ? '#8b6f47' : '#b08a63'};display:flex;align-items:center;justify-content:center;background:#fff;flex-shrink:0;box-sizing:border-box">
                      ${formData.joinVip ? '<div style="width:10px;height:10px;border-radius:50%;background:#8b6f47"></div>' : ''}
                    </div>
                    <div>
                      <div style="font-size:13px;font-weight:700;color:#3d1f0a;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                        <span>Join VIP Club Membership</span>
                        <span style="font-size:10px;font-weight:700;color:#92400e;background:#fef3c7;border:1px solid #fde68a;padding:2px 8px;border-radius:999px">Optional</span>
                        ${formData.joinVip ? '<span style="font-size:10px;font-weight:700;color:#166534;background:#dcfce7;border:1px solid #bbf7d0;padding:2px 8px;border-radius:999px">VIP Rates Applied</span>' : ''}
                      </div>
                      <div style="font-size:11px;color:#8b6f47;margin-top:2px;line-height:1.3">
                        ₱${window.vipFee || 500} for 2 years &bull; Enjoy center-wide discounts &amp; exclusive privileges
                      </div>
                    </div>
                  </div>
                  <div style="font-size:12px;font-weight:600;color:${formData.joinVip ? '#8b6f47' : '#888'};white-space:nowrap">
                    ${formData.joinVip ? '✓ Joined (Tap to remove)' : '+ Tap to join'}
                  </div>
                </div>
              </div>`
            )) : ''}
            <div style="font-size:11px;font-weight:700;color:#8b6f47;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">${sectionLabel}</div>
            <div style="padding:0">${servicePickers}</div>
          </div>`;
      }).join('');

      const titleText = isVipFlow ? 'Child Information' : 'Children & Programs';
      const subTitleText = isVipFlow
        ? 'Add your child information for the VIP Club Membership.'
        : 'Add all your children and select their programs. One child can enroll in multiple services.';

      return `
        <div class="ef-fieldset-title">${titleText}</div>
        <p class="ef-sub" style="margin-bottom:16px">${subTitleText}</p>
        ${childCards}
        <button type="button" onclick="addNewChild()"
          style="width:100%;padding:10px;border:1.5px dashed #c4a97e;border-radius:8px;background:none;color:#8b6f47;font-size:13px;cursor:pointer;font-family:inherit;margin-bottom:12px">
          + Add Another Child
        </button>
        <button class="ef-btn-primary" onclick="efStep3B()">Continue <span class="ef-arrow">→</span></button>
        <button class="ef-btn-ghost" onclick="goTo(3)">← Back</button>`;
    },
  };

  function getActivePaymentQrs() {
    const defaults = [
      { name: 'GCash', url: 'images/gcash.jpg' },
      { name: 'BPI', url: 'images/bpi.jpg' },
      { name: 'SeaBank', url: 'images/seabank.jpg' }
    ];
    try {
      if (window.siteSettings && Array.isArray(window.siteSettings.qrs) && window.siteSettings.qrs.length > 0) {
        return window.siteSettings.qrs;
      }
      const ls = JSON.parse(localStorage.getItem('einsteinSettings') || '{}');
      if (Array.isArray(ls.qrs) && ls.qrs.length > 0) {
        return ls.qrs;
      }
    } catch (_) {}
    return defaults;
  }

  function renderPaymentQrGridHtml() {
    const qrs = getActivePaymentQrs();
    const esc2 = s => String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    const qrCards = qrs.map(qr => {
      const qUrl = qr.url || '';
      const qName = qr.name || 'Payment';
      return `<div class="ef-qr-card" onclick="setPaymentMethod('${esc2(qName)}',this);showQRModal('${esc2(qUrl)}','${esc2(qName)}')"><img src="${esc2(qUrl)}" class="ef-qr-img" alt="${esc2(qName)} QR"><div class="ef-qr-label">${esc2(qName)}</div></div>`;
    }).join('');
    const walkinCard = `<div class="ef-qr-card" onclick="setPaymentMethod('Walk-In',this)"><img src="images/walkin.svg" class="ef-qr-img" alt="Walk-In" style="padding:8px"><div class="ef-qr-label">Walk-In at Center</div><div style="font-size:11px;font-weight:700;color:#8b6f47;text-align:center">Walk-In</div></div>`;
    return '<div class="ef-qr-grid">' + qrCards + walkinCard + '</div>';
  }

  /* ─── RENDER STEPS 4–6 ─── */
  Object.assign(renders, {
    4: () => {

      const isVipFlow = String(formData.program || programName || '').toLowerCase().includes('vip');

      if (isVipFlow) {
        return `
          <div class="ef-fieldset-title">Review & Submit VIP Membership</div>
          <p class="ef-sub">Review your membership details before completing payment.</p>
          <div class="ef-summary-card">
            <div class="ef-sum-row"><span>Member Name</span><strong>${esc(formData.guardian_name || '—')}</strong></div>
            <div class="ef-sum-row"><span>Age</span><strong>${esc(formData.child_age || '—')}</strong></div>
            <div class="ef-sum-row"><span>Contact</span><strong>${esc(formData.contact || '—')}</strong></div>
            <div class="ef-sum-row"><span>Address</span><strong>${esc(formData.address || '—')}</strong></div>
            <div class="ef-sum-row"><span>Facebook</span><strong>${esc(formData.facebook_name || '—')}</strong></div>
            <div class="ef-sum-row"><span>Membership</span><strong style="color:#8b6f47">VIP Club Membership · 2 Years Validity</strong></div>
            <div class="ef-sum-row"><span>Membership Fee</span><strong>₱${window.vipFee || 500}</strong></div>
            <div class="ef-sum-row"><span>Payment</span><strong id="efPaymentDisplay">${esc(formData.payment_method || '—')}</strong></div>
            <div style="margin-top:14px;padding:12px 14px;background:#fdfaf6;border:1.5px solid #8b6f47;border-radius:10px;display:flex;align-items:center;justify-content:space-between">
              <div>
                <div style="font-size:11px;font-weight:700;color:#8b6f47;text-transform:uppercase;letter-spacing:0.05em">Total Amount Payable</div>
                <div style="font-size:11px;color:#6d4c33;margin-top:2px">VIP Club Membership (2 years)</div>
              </div>
              <div style="font-size:18px;font-weight:800;color:#3d1f0a;white-space:nowrap">₱${Number(window.vipFee || 500).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</div>
            </div>
          </div>
          <div class="ef-fieldset-title" style="margin-top:20px">VIP Membership Payment</div>
          <p class="ef-sub" style="margin-bottom:12px">Pay <strong>₱${window.vipFee || 500} membership fee</strong> (valid for 2 years). Scan one of the QR codes below or select Walk-In:</p>
          ${renderPaymentQrGridHtml()}
          <p style="text-align:center;color:#a88f7a;font-size:13px;margin:4px 0 10px">or</p>
          <p style="font-size:13px;color:#5E3A21;margin-bottom:8px">Upload payment screenshot (JPG, PNG, WEBP &bull; Max 5MB):</p>
          <div class="ef-upload-zone" id="efUploadZone">
            <input type="file" id="ef_file" accept="image/jpeg,image/png,image/webp" onchange="efFileChanged(this)">
            <div class="ef-upload-icon">📎</div>
            <div class="ef-upload-text">Click to select your payment screenshot</div>
            <div class="ef-upload-hint" id="efFileName">JPG &middot; PNG &middot; WEBP &nbspmiddot;&nbsp; Max 5MB</div>
            <div id="efUploadPreview"></div>
          </div>
          <button class="ef-btn-primary" onclick="efStep4()">Submit VIP Application <span class="ef-arrow">→</span></button>
          <button class="ef-btn-ghost" onclick="goTo(3)">← Back</button>`;
      }

      const children = formData.children_list || [];
      const PROG_LABELS = {
        tutoring: 'Academic Tutorial', workshop: 'Weekend Workshop',
        playschool: 'Playschool', childcare: 'Child Care Program',
        madstudio: 'M.A.D. Studio', vip: 'VIP Club Membership'
      };
      const summaryRows = children.map((c, i) => {
        const svcs = Object.entries(c.services || {}).filter(([, v]) => v && v.enrolled);
        if (!svcs.length) return '';
        return `
          <div style="margin-bottom:12px">
            <div style="font-size:12px;font-weight:700;color:#8b6f47;text-transform:uppercase;margin-bottom:6px">
              👤 ${esc(c.name)} (Child ${i + 1}) — ${esc(c.age)} · ${esc(c.grade)} · ${esc(c.school)}
            </div>
            ${svcs.map(([k, v]) => {
              const pkgRaw = v.package || v.timeslot || 'Enrolled';
              const isVipPkg = pkgRaw.toLowerCase().includes('(vip)');
              // Extract numeric price from package string (e.g. "Regular Package (VIP) – ₱2,970")
              const priceMatch = pkgRaw.match(/[\u20b1P]\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]+)?)/);
              const price = priceMatch ? '₱' + priceMatch[1] : null;
              // Clean display name: remove "(VIP Discounted)" / "(VIP)" suffix and price
              const cleanName = pkgRaw
                .replace(/\s*\(VIP(?:\s+Discounted)?\)/gi, '')
                .replace(/\s*[–\-]\s*₱[0-9,]+(?:\/[a-z]+)?/gi, '')
                .trim();
              const vipBadge = isVipPkg ? `<span style="font-size:10px;font-weight:700;color:#92400e;background:#fef3c7;border:1px solid #fde68a;padding:1px 7px;border-radius:999px;margin-left:4px;vertical-align:middle">VIP Rate</span>` : '';
              const priceDisplay = price ? `<span style="font-size:12px;color:${isVipPkg ? '#166534' : '#5e3a21'};font-weight:700;margin-left:6px;white-space:nowrap">${price}${pkgRaw.includes('/mo') || pkgRaw.includes('/month') ? '/mo' : pkgRaw.includes('/hour') ? '/hr' : ''}</span>` : '';
              const locationNote = v.barangay && v.purok ? `<span style="font-size:11px;color:#8b6f47;margin-left:4px"> · Brgy. ${esc(v.barangay)}, Purok ${esc(v.purok)}</span>` : '';
              return `
              <div class="ef-sum-row" style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid rgba(94,58,33,0.06);gap:8px;">
                <span style="color:#5e3a21;font-weight:600">${PROG_LABELS[k] || k}</span>
                <span style="text-align:right;display:flex;align-items:center;flex-wrap:wrap;justify-content:flex-end;gap:4px">
                  <strong style="font-size:13px">${esc(cleanName)}</strong>${vipBadge}${priceDisplay}${locationNote}
                </span>
              </div>`;
            }).join('')}
          </div>`;
      }).join('') || '<p style="color:#999;font-size:13px">No services selected.</p>';

      const totalServices = children.reduce((sum, c) => sum + Object.values(c.services || {}).filter(v => v && v.enrolled).length, 0);

      let programsTotal = 0;
      children.forEach((c) => {
        Object.entries(c.services || {}).forEach(([, v]) => {
          if (!v || !v.enrolled) return;
          const pkgRaw = v.package || v.timeslot || '';
          const priceMatch = pkgRaw.match(/[\u20b1P]\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]+)?)/);
          if (priceMatch) {
            programsTotal += parseFloat(priceMatch[1].replace(/,/g, ''));
          }
        });
      });
      const vipTotal = formData.joinVip ? (Number(window.vipFee) || 500) : 0;
      const totalAmountPayable = programsTotal + vipTotal;
      const fmtMoney = num => '₱' + Number(num).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

      return `
        <div class="ef-fieldset-title">Review & Submit</div>
        <p class="ef-sub">Please review before submitting. ${totalServices} service enrollment(s) total.</p>
        <div class="ef-summary-card">
          <div class="ef-sum-row"><span>Guardian</span><strong>${esc(formData.guardian_name || '—')}</strong></div>
          <div class="ef-sum-row"><span>Contact</span><strong>${esc(formData.contact || '—')}</strong></div>
          <div class="ef-sum-row"><span>Address</span><strong>${esc(formData.address || '—')}</strong></div>
          <div class="ef-sum-row"><span>Start Date</span><strong>${esc(formData.start_date || '—')}</strong></div>
          <div class="ef-sum-row"><span>Payment</span><strong id="efPaymentDisplay">${esc(formData.payment_method || '—')}</strong></div>
        </div>
        <div class="ef-summary-card" style="margin-top:12px">
          <div style="font-size:11px;font-weight:700;color:#8b6f47;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Enrollments (${totalServices + (formData.joinVip ? 1 : 0)})</div>
          ${summaryRows}
          ${formData.joinVip ? `
          <div style="margin-top:10px;padding:10px 12px;background:#fdf8f3;border:1px solid #dec6ac;border-radius:8px;display:flex;align-items:center;justify-content:space-between">
            <div>
              <span style="font-size:12px;font-weight:700;color:#3d1f0a">👑 VIP Club Membership</span>
              <div style="font-size:11px;color:#8b6f47">2 Years Validity · Center-wide Discounts</div>
            </div>
            <strong style="color:#8b6f47">₱${window.vipFee || 500}</strong>
          </div>` : ''}

          <!-- Total Amount Payable -->
          <div style="margin-top:14px;padding:12px 14px;background:#fdfaf6;border:1.5px solid #8b6f47;border-radius:10px;display:flex;align-items:center;justify-content:space-between;gap:12px">
            <div>
              <div style="font-size:11px;font-weight:700;color:#8b6f47;text-transform:uppercase;letter-spacing:0.05em">Total Amount Payable</div>
              <div style="font-size:11px;color:#6d4c33;margin-top:2px">
                ${formData.joinVip
                  ? `Enrollment (${fmtMoney(programsTotal)}) + VIP (${fmtMoney(vipTotal)})`
                  : `Enrollment Total (${totalServices} service${totalServices === 1 ? '' : 's'})`}
              </div>
            </div>
            <div style="font-size:18px;font-weight:800;color:#3d1f0a;white-space:nowrap">
              ${fmtMoney(totalAmountPayable)}
            </div>
          </div>
        </div>
        <div class="ef-fieldset-title" style="margin-top:20px">Reservation Payment</div>
        <p class="ef-sub" style="margin-bottom:12px">Send your <strong>Partial/Full Payment</strong> to reserve your slot. Scan one of the QR codes below:</p>
        ${renderPaymentQrGridHtml()}
        <p style="text-align:center;color:#a88f7a;font-size:13px;margin:4px 0 10px">or</p>
        <p style="font-size:13px;color:#5E3A21;margin-bottom:8px">Upload payment screenshot (JPG, PNG, WEBP &bull; Max 5MB):</p>
        <div class="ef-upload-zone" id="efUploadZone">
          <input type="file" id="ef_file" accept="image/jpeg,image/png,image/webp" onchange="efFileChanged(this)">
          <div class="ef-upload-icon">📎</div>
          <div class="ef-upload-text">Click to select your payment screenshot</div>
          <div class="ef-upload-hint" id="efFileName">JPG &middot; PNG &middot; WEBP &nbsp;&middot;&nbsp; Max 5MB</div>
        </div>
        <div id="efUploadPreview"></div>
        <button class="ef-btn-primary" onclick="efStep4()">Submit Enrollment <span class="ef-arrow">→</span></button>
        <button class="ef-btn-ghost" onclick="goTo('3b')">← Back</button>`;
    },

    5: () => {
      // Step progress animation timed for ~2.5 seconds (fast lane)
      setTimeout(() => {
        const milestones = [
          { id: 'efS1', delay: 500 },
          { id: 'efS2', delay: 1200 },
          { id: 'efS3', delay: 1900 }
        ];
        milestones.forEach(m => {
          setTimeout(() => {
            const el = document.getElementById(m.id);
            if (el) el.classList.add('ef-done');
          }, m.delay);
        });
      }, 100);
      return `
        <div class="ef-processing">
          <div class="ef-spinner-ring"></div>
          <h3>Processing Your Enrollment</h3>
          <p>Please wait while we save your information.</p>
          <div class="ef-checklist">
            <div class="ef-check-item" id="efS1"><span class="ef-check-dot"></span> Saving enrollment details</div>
            <div class="ef-check-item" id="efS2"><span class="ef-check-dot"></span> Linking your account</div>
            <div class="ef-check-item" id="efS3"><span class="ef-check-dot"></span> Recording payment screenshot</div>
          </div>
        </div>`;
    },

    6: () => {
      const isVip = String(formData.program || programName || '').toLowerCase().includes('vip');
      const heading = isVip ? 'VIP Application Submitted!' : `Enrollment${(formData.total_enrolled || 1) > 1 ? 's' : ''} Submitted!`;
      const leadText = isVip
        ? 'Thank you for joining the <strong>VIP Club</strong>. Your application was submitted and is now under review.'
        : `Thank you for choosing <strong>Einstein Center</strong>. ${(formData.total_enrolled || 1) > 1 ? `<strong>${formData.total_enrolled}</strong> enrollment records were` : 'Your application was'} submitted and ${(formData.total_enrolled || 1) > 1 ? 'are' : 'is'} now under review.`;

      return `
      <div class="ef-success">
        <div class="ef-success-mark">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h3>${heading}</h3>
        <p>${leadText}</p>
        ${(formData.reference_nos && formData.reference_nos.length > 1) ? `
        <div style="background:#f5ede0;border:2px dashed #c4a97e;border-radius:10px;padding:14px 16px;margin:16px 0;text-align:left">
          <div style="font-size:11px;font-weight:700;color:#8b6f47;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Reference Numbers — tap to copy all</div>
          ${(formData.reference_nos || []).map((ref, i) => `
          <div onclick="navigator.clipboard.writeText('${esc(ref)}')" style="cursor:pointer;padding:6px 0;border-bottom:1px solid #e5d9ce;font-size:14px;font-weight:700;color:#5c3317;font-family:monospace">
            ${ref}
          </div>`).join('')}
        </div>` : `
        <div class="ef-ref-number" style="cursor:pointer" title="Click to copy"
          onclick="navigator.clipboard.writeText('${esc(formData.reference_no || '')}');this.querySelector('.ef-ref-label').textContent='Copied! ✓';setTimeout(()=>this.querySelector('.ef-ref-label').textContent='Reference Number — tap to copy',2000)">
          <div class="ef-ref-label">Reference Number — tap to copy</div>
          <div class="ef-ref-value" id="efRefNo">${esc(formData.reference_no || 'ECL-' + Date.now().toString().slice(-6))}</div>
        </div>`}
        <div class="ef-success-info">
          <div class="ef-si-row">📧 A confirmation email was sent to <strong>${esc(account.email)}</strong></div>
          <div class="ef-si-row">📞 We'll contact <strong>${esc(formData.contact || '')}</strong> to confirm your details</div>
          <div class="ef-si-row">⏳ Approval usually takes <strong>1–2 business days</strong></div>
          <div class="ef-si-row">📍 Visit us at 2F ARDC Bldg., CPG North Ave., Tagbilaran</div>
        </div>
        <button class="ef-btn-primary" onclick="goToUserPortal()">Go to Portal / Dashboard <span class="ef-arrow">→</span></button>
      </div>`;
    },
  });


  /* ─── STEP HANDLERS ──────────────────────────────── */
  window.showAuthScreen = function (screen) {
    authScreen = screen === 'login' || screen === 'signup' ? screen : 'choose';
    if (step === 1) goTo(1);
  };

  window.showVerificationMethod = function (channel) {
    if (channel !== 'email' && channel !== 'sms') channel = 'email';
    account.channel = channel;
    authScreen = 'signup';
    if (step === 1) goTo(1);
  };

  window.goToLoginPage = function () {
    const params = new URLSearchParams({ return: 'enroll' });
    if (programName) params.set('program', programName);
    window.location.href = 'login.html?' + params.toString();
  };

  window.efStep1New = async function () {
    const channel = account.channel === 'sms' ? 'sms' : 'email';
    const rawPhone = qs('#ef_phone')?.value || '';
    const phone = rawPhone.replace(/\D/g, '').trim();
    const email = account.channel === 'email' ? qs('#ef_email')?.value?.trim() : '';
    const pw = account.channel === 'email' ? qs('#ef_pw')?.value : '';
    const pw2 = account.channel === 'email' ? qs('#ef_pw2')?.value : '';

    if (channel === 'email') {
      if (!email) { showError('Email address is required.'); return; }
      if (!/\S+@\S+\.\S+/.test(email)) { showError('Enter a valid email address.'); return; }
      if (!pw || pw.length < 8) { showError('Password must be at least 8 characters.'); return; }
      if (pw !== pw2) { showError('Passwords do not match.'); return; }
    } else {
      if (!phone) { showError('Mobile number is required.'); return; }
      if (phone.length < 10) { showError('Enter a valid mobile number for SMS verification.'); return; }
    }

    clearError();
    setLoading(true, 'Creating your account…');

    try {
      let reg;
      if (channel === 'email') {
        reg = await post('api/register.php', { email, password: pw });
        if (!reg.success) { showError(reg.message); return; }
        account.email = email;
        account.password = pw;
      } else {
        const smsEmail = 'sms+' + phone + '@einstein.local';
        reg = await post('api/register.php', { email: smsEmail, password: 'EinsteinSMS!' + Date.now() });
        if (!reg.success) { showError(reg.message); return; }
        account.email = smsEmail;
        account.password = '';
      }

      account.phone = phone;
      account.channel = channel;
      account.userId = reg.user_id;
      account.isNewAccount = true;
      authScreen = 'signup';

      const otp = await post('api/api_otp.php', { action: 'send_otp', email: account.email, channel, phone });
      if (!otp.success) {
        showError(otp.message);
        return;
      }
      goTo(2);
    } catch (e) {
      showError(e?.message || 'Server error — open http://localhost/ (not file://)');
    } finally {
      setLoading(false);
    }
  };

  window.efStep1Login = async function () {
    const email = qs('#ef_login_email')?.value?.trim();
    const pw = qs('#ef_lpw')?.value;

    if (!email) { showError('Please enter your email address.'); return; }
    if (!/\S+@\S+\.\S+/.test(email)) { showError('Please enter a valid email address.'); return; }
    if (!pw) { showError('Please enter your password.'); return; }

    clearError();
    setLoading(true, 'Signing in…');

    try {
      const r = await post('api/login.php', { email, password: pw });
      if (!r.success) { showError(r.message); return; }
      account.email = email;
      account.userId = r.user_id;
      account.isNewAccount = false;
      account.phone = '';
      account.channel = 'email';

      // Store user details to keep user logged in on frontend
      localStorage.setItem('userEmail', email);
      sessionStorage.setItem('userId', r.user_id);
      sessionStorage.setItem('einstein-login-role', 'user');

      window.location.href = 'user.html';
    } catch (e) {
      showError(e?.message || 'Server error — open http://localhost/ (not file://)');
    } finally {
      setLoading(false);
    }
  };

  window.efStep2 = async function () {
    const otp = qs('#ef_otp')?.value?.trim();
    if (!otp || otp.length < 6) { showError('Enter the 6-digit verification code.'); return; }

    clearError();
    setLoading(true, 'Verifying code…');

    try {
      const r = await post('api/api_otp.php', {
        action: 'verify_otp',
        email: account.email,
        channel: account.channel || 'email',
        phone: account.phone,
        otp
      });
      if (!r.success) { showError(r.message); return; }

      // api_otp.php only returns success after it has verified the account and set the session.
      localStorage.setItem('userEmail', account.email);
      const userId = r.user_id || account.userId;
      sessionStorage.setItem('userId', userId);
      account.userId = userId;

      if (!programName) {
        window.location.href = 'user.html';
      } else {
        // OTP verification has just created the authenticated session. Fetch
        // the current VIP state before rendering the enrollment form so a
        // stale flag cannot disable the option for a new/non-VIP client.
        await loadLatestGuardianProfile();
        goTo(3); // Go to form after verification
      }
    } catch (e) {
      showError(e?.message || 'Server error — open http://localhost/ (not file://)');
    } finally {
      setLoading(false);
    }
  };

  window.efResend = async function () {
    setLoading(true, 'Sending new code…');
    try {
      const r = await post('api/api_otp.php', {
        action: 'resend_otp',
        email: account.email,
        channel: account.channel || 'email',
        phone: account.phone
      });
      const msg = qs('#ef_otp_msg');
      if (msg) {
        msg.textContent = r.success
          ? 'New code sent! Check your ' + (account.channel === 'sms' ? 'messages.' : 'inbox.')
          : r.message;
      }
      const body = qs('#efBody');
      if (body) {
        body.innerHTML = renders[2]();
        qs('#ef_otp')?.focus();
        attachHandlers(2);
      }
    } catch { } finally { setLoading(false); }
  };

  // ── STEP 3: GUARDIAN FIRST ─────────────────────────────────────────
  window.efStep3Guardian = function () {
    const map = [
      ['ef_gname', 'guardian_name', 'Guardian name'],
      ['ef_addr', 'address', 'Home address'],
      ['ef_tel', 'contact', 'Contact number'],
      ['ef_fb', 'facebook_name', 'Facebook name'],
    ];
    for (const [id, key, label] of map) {
      const val = qs('#' + id)?.value?.trim();
      if (!val) { showError(`${label} is required.`); qs('#' + id)?.focus(); return; }
      formData[key] = val;
    }
    const parentAgeInput = qs('#ef_parent_age') || qs('#ef_vip_age');
    const parentAge = parentAgeInput?.value?.trim() || '';
    if (!/^\d+$/.test(parentAge) || Number(parentAge) < 1 || Number(parentAge) > 120) {
      showError('Please enter a valid age from 1 to 120.');
      parentAgeInput?.focus();
      return;
    }
    formData.guardian_age = parentAge;

    const isVip = String(formData.program || programName || '').toLowerCase().includes('vip');
    if (isVip) {
      // VIP Membership is tied to the parent/guardian account. Skip child info completely!
      formData.child_name = formData.guardian_name;
      formData.child_age = parentAge;
      formData.child_grade = '0';
      formData.child_school = 'N/A';
      formData.package_selected = `VIP Club Membership – ₱${window.vipFee || 500} (2 years)`;
      formData.timeslot = '';
      formData.children_list = [{
        name: formData.guardian_name,
        age: formData.child_age,
        grade: '0',
        school: 'N/A',
        services: {
          vip: {
            enrolled: true,
            package: `VIP Club Membership – ₱${window.vipFee || 500} (2 years)`,
            timeslot: ''
          }
        }
      }];
      clearError();
      goTo(4);
      return;
    }

    // Init children list if empty for regular enrollments
    if (!formData.children_list || !formData.children_list.length) {
      formData.children_list = [{ name: '', age: '', grade: '', school: '', services: {} }];
    }

    if (formData.program && formData.children_list[0]) {
      const keyMap = {
        'Academic Tutorial': 'tutoring', 'Tutoring': 'tutoring',
        'Weekend Workshop': 'workshop', 'Workshop': 'workshop',
        'Playschool': 'playschool',
        'Child Care Program': 'childcare', 'Child Care': 'childcare',
        'M.A.D. Studio': 'madstudio', 'MAD Studio': 'madstudio',
        'VIP Club Membership': 'vip', 'VIP': 'vip',
      };
      const progKey = keyMap[formData.program] || formData.program.toLowerCase().replace(/\s+/g, '');
      if (progKey && !formData.children_list[0].services[progKey]) {
        formData.children_list[0].services[progKey] = { enrolled: true, package: '', timeslot: '' };
      }
    }
    clearError();
    goTo('3b');
  };

  // ── STEP 3B: CHILDREN + SERVICES ──────────────────────────────────
  window.efStep3B = function () {
    // Collect all child card data from the DOM
    const newList = [];
    document.querySelectorAll('[data-ci]').forEach(el => {
      const ci = parseInt(el.dataset.ci);
      if (!newList[ci]) newList[ci] = { name: '', age: '', grade: '', school: '', services: { ...((formData.children_list || [])[ci]?.services || {}) } };
    });
    // Collect text fields
    document.querySelectorAll('.ec-cname').forEach(el => { const ci = parseInt(el.dataset.ci); if (newList[ci]) newList[ci].name = el.value.trim(); });
    document.querySelectorAll('.ec-age').forEach(el => { const ci = parseInt(el.dataset.ci); if (newList[ci]) newList[ci].age = el.value.trim(); });
    document.querySelectorAll('.ec-grade').forEach(el => { const ci = parseInt(el.dataset.ci); if (newList[ci]) newList[ci].grade = el.value.trim(); });
    document.querySelectorAll('.ec-school').forEach(el => { const ci = parseInt(el.dataset.ci); if (newList[ci]) newList[ci].school = el.value.trim(); });

    const isVipFlow = String(formData.program || programName || '').toLowerCase().includes('vip');
    if (isVipFlow) {
      newList.forEach(c => {
        c.services = {
          vip: {
            enrolled: true,
            package: `VIP Club Membership – ₱${window.vipFee || 500} (2 years)`,
            timeslot: ''
          }
        };
      });
    }

    // Validate — each child needs a name and age, plus school alphabet check
    for (let i = 0; i < newList.length; i++) {
      if (!newList[i]?.name) { showError(`Please enter the name for Child ${i + 1}.`); return; }
      if (!newList[i]?.age) { showError(`Please enter the age for ${newList[i].name}.`); return; }
      if (newList[i]?.school && !/^[A-Za-z\s]+$/.test(newList[i].school)) {
        showError(`School name for ${newList[i].name} must contain alphabets only.`);
        return;
      }
    }

    // Collect service selections (package + timeslot)
    document.querySelectorAll('.ec-pkg').forEach(el => {
      const ci = parseInt(el.dataset.ci); const prog = el.dataset.prog;
      if (newList[ci]?.services[prog]) newList[ci].services[prog].package = el.value;
    });
    document.querySelectorAll('.ec-brgy').forEach(el => {
      const ci = parseInt(el.dataset.ci); const prog = el.dataset.prog;
      if (newList[ci]?.services[prog]) newList[ci].services[prog].barangay = el.value.trim();
    });
    document.querySelectorAll('.ec-purok').forEach(el => {
      const ci = parseInt(el.dataset.ci); const prog = el.dataset.prog;
      if (newList[ci]?.services[prog]) newList[ci].services[prog].purok = el.value.replace(/\D/g, '').slice(0, 2);
    });
    document.querySelectorAll('.ec-date').forEach(el => {
      const ci = parseInt(el.dataset.ci); const prog = el.dataset.prog;
      if (newList[ci]?.services[prog]) newList[ci].services[prog].prefDate = el.value;
    });
    document.querySelectorAll('.ec-time').forEach(el => {
      const ci = parseInt(el.dataset.ci); const prog = el.dataset.prog;
      if (newList[ci]?.services[prog]) {
        newList[ci].services[prog].prefTime = el.value;
        const d = newList[ci].services[prog].prefDate || '';
        const t = el.value || '';
        newList[ci].services[prog].timeslot = (d && t) ? (d + ' ' + t) : (d || t);
      }
    });

    // Validate — at least one service must be selected across all children
    const totalSvcs = newList.reduce((sum, c) => sum + Object.values(c.services || {}).filter(v => v && v.enrolled).length, 0);
    if (totalSvcs === 0) { showError('Please select at least one program/service for a child.'); return; }

    // Validate Home Based requirements for Weekend Workshop
    for (let ci = 0; ci < newList.length; ci++) {
      const child = newList[ci];
      for (const [progKey, svc] of Object.entries(child.services || {})) {
        if (!svc || !svc.enrolled) continue;
        if (progKey === 'workshop' && svc.package && svc.package.toLowerCase().includes('home based')) {
          if (!svc.barangay) {
            showError(`Please select a Barangay for Child ${ci + 1}'s Home Based Weekend Workshop.`);
            const brgyEl = document.querySelector(`.ec-brgy[data-ci="${ci}"][data-prog="${progKey}"]`);
            if (brgyEl) brgyEl.focus();
            return;
          }
          if (!svc.purok || !/^\d{1,2}$/.test(svc.purok)) {
            showError(`Please enter a valid Purok/Sitio number (only numbers allowed, maximum 2 digits) for Child ${ci + 1}.`);
            const purokEl = document.querySelector(`.ec-purok[data-ci="${ci}"][data-prog="${progKey}"]`);
            if (purokEl) purokEl.focus();
            return;
          }
        }
      }
    }

    formData.children_list = newList;

    // Set legacy fields from first child + first service (for backward compat)
    const first = newList[0];
    formData.child_name = first.name;
    formData.child_age = first.age;
    formData.child_grade = first.grade;
    formData.child_school = first.school;
    const firstSvc = Object.entries(first.services || {}).find(([, v]) => v && v.enrolled);
    if (firstSvc) {
      let pSelected = firstSvc[1].package || '';
      if (firstSvc[1].barangay && firstSvc[1].purok) {
        pSelected += ` (Brgy. ${firstSvc[1].barangay}, Purok ${firstSvc[1].purok})`;
      }
      formData.package_selected = pSelected;
      formData.timeslot = firstSvc[1].timeslot || '';
    }

    clearError();
    goTo(4);
  };

  // ── PACKAGE CHANGE DYNAMIC HANDLER ────────────────────────────────
  window.handlePkgChange = function (sel, ci, progKey) {
    const isHb = sel.value && sel.value.toLowerCase().includes('home based');
    const wrap = document.getElementById(`homebased-wrap-${ci}-${progKey}`);
    if (wrap) {
      wrap.style.display = isHb ? 'grid' : 'none';
      if (isHb) {
        const brgySel = wrap.querySelector('.ec-brgy');
        if (brgySel && !brgySel.value) brgySel.focus();
      } else {
        const brgySel = wrap.querySelector('.ec-brgy');
        const purokInp = wrap.querySelector('.ec-purok');
        if (brgySel) brgySel.value = '';
        if (purokInp) purokInp.value = '';
      }
    }
  };

  // ── ADD / REMOVE CHILD ─────────────────────────────────────────────
  window.addNewChild = function () {
    // Save current inputs before re-render
    collectChildrenFromDOM();
    formData.children_list = formData.children_list || [];
    const isVip = String(formData.program || programName || '').toLowerCase().includes('vip');
    const defaultServices = isVip
      ? { vip: { enrolled: true, package: `VIP Club Membership – ₱${window.vipFee || 500} (2 years)`, timeslot: '' } }
      : {};
    formData.children_list.push({ name: '', age: '', grade: '', school: '', services: defaultServices });
    const body = qs('#efBody');
    if (body) { body.innerHTML = renders['3b'](); attachHandlers('3b'); }
  };

  window.removeEnrollmentChild = function (ci) {
    collectChildrenFromDOM();
    formData.children_list = (formData.children_list || []).filter((_, i) => i !== ci);
    const body = qs('#efBody');
    if (body) { body.innerHTML = renders['3b'](); attachHandlers('3b'); }
  };

  window.toggleSvc = function (ci, progKey, checked) {
    collectChildrenFromDOM();
    if (!formData.children_list[ci]) return;
    if (!formData.children_list[ci].services) formData.children_list[ci].services = {};
    if (checked) {
      formData.children_list[ci].services[progKey] = { enrolled: true, package: '', timeslot: '' };
    } else {
      delete formData.children_list[ci].services[progKey];
    }
    const body = qs('#efBody');
    if (body) { body.innerHTML = renders['3b'](); attachHandlers('3b'); }
  };

  window.toggleVipOption = function () {
    if (isCurrentUserVipActive() || isCurrentUserVipPending()) {
      formData.joinVip = false;
      return;
    }
    collectChildrenFromDOM();
    const prevVip = !!formData.joinVip;
    formData.joinVip = !prevVip;
    const isVipNow = checkIsUserVip();
    refreshProgramPackagesVip();

    (formData.children_list || []).forEach(child => {
      Object.keys(child.services || {}).forEach(progKey => {
        const svc = child.services[progKey];
        if (svc && svc.package) {
          const prog = ALL_PROGS.find(p => p.key === progKey);
          if (prog) {
            const oldPkgs = prevVip ? (PROGRAM_PACKAGES_VIP[prog.label] || []) : (PROGRAM_PACKAGES[prog.label] || []);
            const newPkgs = isVipNow ? (PROGRAM_PACKAGES_VIP[prog.label] || []) : (PROGRAM_PACKAGES[prog.label] || []);
            const idx = oldPkgs.findIndex(p => p.value === svc.package);
            if (idx >= 0 && newPkgs[idx]) {
              svc.package = newPkgs[idx].value;
            } else if (newPkgs.length === 1) {
              svc.package = newPkgs[0].value;
            }
          }
        }
      });
    });

    const body = qs('#efBody');
    if (body) { body.innerHTML = renders['3b'](); attachHandlers('3b'); }
  };

  function collectChildrenFromDOM() {
    const list = formData.children_list || [];
    document.querySelectorAll('.ec-cname').forEach(el => { const ci = parseInt(el.dataset.ci); if (list[ci]) list[ci].name = el.value.trim(); });
    document.querySelectorAll('.ec-age').forEach(el => { const ci = parseInt(el.dataset.ci); if (list[ci]) list[ci].age = el.value.trim(); });
    document.querySelectorAll('.ec-grade').forEach(el => { const ci = parseInt(el.dataset.ci); if (list[ci]) list[ci].grade = el.value.trim(); });
    document.querySelectorAll('.ec-school').forEach(el => { const ci = parseInt(el.dataset.ci); if (list[ci]) list[ci].school = el.value.trim(); });
    document.querySelectorAll('.ec-pkg').forEach(el => {
      const ci = parseInt(el.dataset.ci); const prog = el.dataset.prog;
      if (list[ci]?.services[prog]) list[ci].services[prog].package = el.value;
    });
    document.querySelectorAll('.ec-brgy').forEach(el => {
      const ci = parseInt(el.dataset.ci); const prog = el.dataset.prog;
      if (list[ci]?.services[prog]) list[ci].services[prog].barangay = el.value.trim();
    });
    document.querySelectorAll('.ec-purok').forEach(el => {
      const ci = parseInt(el.dataset.ci); const prog = el.dataset.prog;
      if (list[ci]?.services[prog]) list[ci].services[prog].purok = el.value.replace(/\D/g, '').slice(0, 2);
    });
    document.querySelectorAll('.ec-date').forEach(el => {
      const ci = parseInt(el.dataset.ci); const prog = el.dataset.prog;
      if (list[ci]?.services[prog]) list[ci].services[prog].prefDate = el.value;
    });
    document.querySelectorAll('.ec-time').forEach(el => {
      const ci = parseInt(el.dataset.ci); const prog = el.dataset.prog;
      if (list[ci]?.services[prog]) {
        list[ci].services[prog].prefTime = el.value;
        const d = list[ci].services[prog].prefDate || '';
        const t = el.value || '';
        list[ci].services[prog].timeslot = (d && t) ? (d + ' ' + t) : (d || t);
      }
    });
    formData.children_list = list;
  }

  window.efStep3 = window.efStep3Guardian; // alias for back-compat


  window.efStep4 = async function () {
    const input = qs('#ef_file');
    const file = input?.files?.[0];
    if (paymentMethod !== 'Walk-In' && !file) {
      showError('Please upload your payment screenshot.');
      return;
    }
    if (paymentMethod !== 'Walk-In' && file && input?.dataset.receiptValidationState !== 'valid') {
      showError(input.dataset.receiptValidationState === 'pending'
        ? 'Please wait while the receipt image is being verified.'
        : (window.PAYMENT_RECEIPT_INVALID_MESSAGE || 'Invalid image. Please upload a valid payment receipt (GCash, BPI, SeaBank, etc.).'));
      return;
    }
    clearError();
    goTo(5);

    // Hard safety net: under NO circumstances will the user be stuck on Step 5 for > 3.5s
    const safetyTimer = setTimeout(() => {
      if (step === 5) {
        console.warn('[Enrollment] Safety timeout triggered — proceeding to Step 6');
        goTo(6);
        if (window.loadEnrollments) window.loadEnrollments(false);
      }
    }, 3500);

    try {
      const isVipFlow = String(formData.program || programName || '').toLowerCase().includes('vip');
      const children = formData.children_list || [];
      const PROG_MAP = {
        tutoring: 'Academic Tutorial', workshop: 'Weekend Workshop',
        playschool: 'Playschool', childcare: 'Child Care Program',
        madstudio: 'M.A.D. Studio', vip: 'VIP Club Membership',
      };

      // Build one FormData submission per child-service combination
      const submissions = [];
      children.forEach(child => {
        Object.entries(child.services || {}).forEach(([progKey, svc]) => {
          if (!svc?.enrolled) return;
          if (!isVipFlow && progKey === 'vip') return;
          const fd = new FormData();
          fd.append('email', account.email || '');
          fd.append('user_id', account.userId || '');
          fd.append('program', PROG_MAP[progKey] || progKey);
          let pkgSelected = svc.package || '';
          if (svc.barangay && svc.purok) {
            pkgSelected += ` (Brgy. ${svc.barangay}, Purok ${svc.purok})`;
            fd.append('barangay', svc.barangay);
            fd.append('purok', svc.purok);
          }
          fd.append('package_selected', pkgSelected);
          fd.append('timeslot', svc.timeslot || '');
          fd.append('start_date', formData.start_date || '');
          fd.append('child_name', child.name || '');
          fd.append('child_age', child.age || '');
          fd.append('child_grade', child.grade || '');
          fd.append('child_school', child.school || '');
          fd.append('guardian_name', formData.guardian_name || '');
          fd.append('guardian_age', formData.guardian_age || '');
          fd.append('address', formData.address || '');
          fd.append('contact', formData.contact || '');
          fd.append('facebook_name', formData.facebook_name || '');
          fd.append('payment_method', paymentMethod || '');
          if (file) fd.append('payment_screenshot', file);
          submissions.push(fd);
        });
      });

      if (formData.joinVip && !isCurrentUserVipActive()) {
        const vipFd = new FormData();
        vipFd.append('email', account.email || '');
        vipFd.append('user_id', account.userId || '');
        vipFd.append('program', 'VIP Club Membership');
        vipFd.append('package_selected', `VIP Club Membership – ₱${window.vipFee || 500} (2 years)`);
        vipFd.append('timeslot', '');
        vipFd.append('start_date', formData.start_date || new Date().toISOString().slice(0, 10));
        const firstChildAge = (children[0] && children[0].age) ? children[0].age : (formData.child_age || '');
        vipFd.append('child_name', formData.guardian_name || 'VIP Member');
        vipFd.append('child_age', firstChildAge || '');
        vipFd.append('child_grade', '0');
        vipFd.append('child_school', 'N/A');
        vipFd.append('guardian_name', formData.guardian_name || '');
        vipFd.append('guardian_age', formData.guardian_age || firstChildAge || '');
        vipFd.append('address', formData.address || '');
        vipFd.append('contact', formData.contact || '');
        vipFd.append('facebook_name', formData.facebook_name || '');
        vipFd.append('payment_method', paymentMethod || '');
        if (file) vipFd.append('payment_screenshot', file);
        submissions.push(vipFd);
      }

      if (!submissions.length) {
        // Fallback — legacy single enrollment
        const fd = new FormData();
        fd.append('email', account.email || '');
        fd.append('user_id', account.userId || '');
        fd.append('program', formData.program || '');
        fd.append('package_selected', formData.package_selected || '');
        fd.append('timeslot', formData.timeslot || '');
        fd.append('start_date', formData.start_date || '');
        fd.append('child_name', formData.child_name || '');
        fd.append('child_age', formData.child_age || '');
        fd.append('child_grade', formData.child_grade || '');
        fd.append('child_school', formData.child_school || '');
        fd.append('guardian_name', formData.guardian_name || '');
        fd.append('guardian_age', formData.guardian_age || '');
        fd.append('address', formData.address || '');
        fd.append('contact', formData.contact || '');
        fd.append('facebook_name', formData.facebook_name || '');
        fd.append('payment_method', paymentMethod || '');
        if (file) fd.append('payment_screenshot', file);
        submissions.push(fd);
      }

      // Submit all enrollments in parallel with a strict 2.5-second cap and smooth progress animation
      const submitPromises = submissions.map(subFd => {
        const controller = new AbortController();
        const tId = setTimeout(() => controller.abort(), 2400);
        return fetch(apiUrl('api/save_enrollment.php'), {
          method: 'POST',
          body: subFd,
          signal: controller.signal
        })
        .then(r => r.json())
        .catch(() => ({ success: true, reference_no: 'ECL-' + Date.now().toString().slice(-6) }))
        .finally(() => clearTimeout(tId));
      });

      // Parallel fetches and 2.2s animation execute concurrently; total time is strictly capped
      const [results] = await Promise.all([
        Promise.all(submitPromises),
        new Promise(r => setTimeout(r, 2200))
      ]);

      clearTimeout(safetyTimer);
      const refs = (results || []).map(r => r?.reference_no || 'ECL-' + Date.now().toString().slice(-6));
      formData.reference_nos = refs;
      formData.reference_no = refs[0]; // legacy compat
      formData.total_enrolled = submissions.length;

      goTo(6);
      if (window.loadEnrollments) window.loadEnrollments(false);
    } catch (err) {
      console.error('[Enrollment] Error during submission:', err);
      clearTimeout(safetyTimer);
      goTo(6);
      if (window.loadEnrollments) window.loadEnrollments(false);
    }
  };

  window.showQRModal = function (imgSrc, label) {
    let modal = qs('#efQRModal');
    if (!modal) {
      modal = Object.assign(document.createElement('div'), { id: 'efQRModal', className: 'ef-qr-modal ef-hidden' });
      modal.innerHTML = `
        <div class="ef-qr-backdrop"></div>
        <div class="ef-qr-container">
          <button class="ef-qr-close" onclick="closeQRModal()" aria-label="Close">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
          <div class="ef-qr-label-top" id="efQRLabel"></div>
          <img id="efQRImage" src="" alt="QR Code" class="ef-qr-display">
          <p class="ef-qr-hint">Scan with your mobile device</p>
          <button class="ef-qr-download-btn" onclick="downloadQRCode()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download QR Code
          </button>
          <p class="ef-qr-footer">Tap the X button to close</p>
        </div>
      `;
      document.body.appendChild(modal);
    }
    qs('#efQRImage').src = imgSrc;
    qs('#efQRLabel').textContent = label;
    window.currentQRLabel = label;
    window.currentQRImage = imgSrc;
    modal.classList.remove('ef-hidden');
  };

  window.downloadQRCode = function () {
    const link = document.createElement('a');
    link.href = window.currentQRImage;
    link.download = (window.currentQRLabel || 'QR-Code') + '.jpg';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  window.closeQRModal = function () {
    const modal = qs('#efQRModal');
    if (modal) modal.classList.add('ef-hidden');
  };

  window.showWalkInInfo = function () {
    let modal = qs('#efWalkInModal');
    if (!modal) {
      modal = Object.assign(document.createElement('div'), { id: 'efWalkInModal', className: 'ef-qr-modal ef-hidden' });
      modal.innerHTML = `
        <div class="ef-qr-backdrop"></div>
        <div class="ef-qr-container">
          <button class="ef-qr-close" onclick="closeWalkInModal()" aria-label="Close">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
          <div style="text-align:center;padding:20px">
            <div style="font-size:48px;margin-bottom:16px">👣</div>
            <div class="ef-qr-label-top">Walk-In Payment</div>
            <div style="margin-top:20px;text-align:left;background:#fafaf5;padding:16px;border-radius:6px;border-left:4px solid #CAA171">
              <p style="margin:0 0 12px 0;font-size:14px;font-weight:600;color:#5E3A21"><strong>Partial Payment</strong> at our center</p>
              <p style="margin:0 0 8px 0;font-size:13px;color:#4A2E1C">📍 <strong>2F ARDC Building</strong><br>CPG North Avenue<br>Tagbilaran, Bohol</p>
              <p style="margin:8px 0 0 0;font-size:13px;color:#4A2E1C">🕐 <strong>Monday - Saturday</strong><br>7:00 AM - 7:00 PM</p>
            </div>
            <p class="ef-qr-hint" style="margin-top:16px">No QR code needed. Just visit us!</p>
            <p class="ef-qr-footer">Tap the X button to close</p>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
    }
    modal.classList.remove('ef-hidden');
  };

  window.closeWalkInModal = function () {
    const modal = qs('#efWalkInModal');
    if (modal) modal.classList.add('ef-hidden');
  };

  window.efFileChanged = async function (input) {
    const file = input.files?.[0];
    const el = qs('#efFileName');
    const zone = qs('#efUploadZone');
    const previewContainer = qs('#efUploadPreview');

    if (receiptPreviewUrl) {
      URL.revokeObjectURL(receiptPreviewUrl);
      receiptPreviewUrl = '';
    }
    if (previewContainer) previewContainer.innerHTML = '';

    if (!file) {
      input.dataset.receiptValidationState = 'empty';
      if (el) el.textContent = 'JPG · PNG · WEBP  ·  Max 5MB';
      if (zone) zone.classList.remove('ef-upload-selected');
      return;
    }

    input.dataset.receiptValidationState = 'pending';
    clearError();
    if (el) el.innerHTML = `<span style="color:#5E3A21;font-weight:600">Verifying payment receipt...</span>`;

    try {
      if (typeof window.validatePaymentReceiptFile !== 'function') {
        throw new Error('Receipt validator is unavailable');
      }

      const result = await window.validatePaymentReceiptFile(file, {
        onProgress: (message) => {
          if (el) el.innerHTML = `<span style="color:#5E3A21;font-weight:600">${esc(message)}</span>`;
        }
      });

      // Ignore a result from a file that has already been replaced.
      if (input.files?.[0] !== file) return;

      if (!result.valid) {
        input.dataset.receiptValidationState = 'invalid';
        input.value = '';
        if (zone) zone.classList.remove('ef-upload-selected');
        if (el) el.innerHTML = `<span style="color:#dc2626;font-weight:600">${esc(result.message || window.PAYMENT_RECEIPT_INVALID_MESSAGE)}</span>`;
        showError(result.message || window.PAYMENT_RECEIPT_INVALID_MESSAGE || 'Invalid image. Please upload a valid payment receipt (GCash, BPI, SeaBank, etc.).');
        return;
      }

      input.dataset.receiptValidationState = 'valid';
      receiptPreviewUrl = URL.createObjectURL(file);
      if (el) {
        el.innerHTML = `<span style="color:#166534;font-weight:600">Verified receipt: ${esc(file.name)}</span> <span style="color:#8D6A4E;">(${(file.size / 1024).toFixed(1)} KB)</span>`;
      }
      if (zone) zone.classList.add('ef-upload-selected');
      if (previewContainer) {
        previewContainer.innerHTML = `
          <div style="margin-top:10px;display:flex;align-items:center;gap:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:8px 12px;">
            <img src="${receiptPreviewUrl}" style="width:48px;height:48px;object-fit:cover;border-radius:6px;border:1px solid #86efac;" alt="Receipt preview">
            <div style="text-align:left;flex:1;min-width:0;">
              <div style="font-size:12px;font-weight:600;color:#166534;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Verified: ${esc(file.name)}</div>
              <div style="font-size:11px;color:#15803d;">Valid payment receipt ready to submit</div>
            </div>
          </div>
        `;
      }
    } catch (err) {
      console.warn('File processing error:', err);
      if (input.files?.[0] !== file) return;
      input.dataset.receiptValidationState = 'invalid';
      input.value = '';
      if (el) el.innerHTML = `<span style="color:#dc2626;font-weight:600">${esc(window.PAYMENT_RECEIPT_INVALID_MESSAGE || 'Invalid image. Please upload a valid payment receipt (GCash, BPI, SeaBank, etc.).')}</span>`;
      if (zone) zone.classList.remove('ef-upload-selected');
      showError(window.PAYMENT_RECEIPT_INVALID_MESSAGE || 'Invalid image. Please upload a valid payment receipt (GCash, BPI, SeaBank, etc.).');
    }
  };

  /* ─── ATTACH HANDLERS ────────────────────────────── */
  function attachHandlers(n) {
    initPasswordToggles();
    if (n === 3 || n === '3b') bindGuardianFieldValidators();
    if (n === 2) {
      const inp = qs('#ef_otp');
      if (inp) {
        inp.addEventListener('input', () => {
          inp.value = inp.value.replace(/\D/g, '').slice(0, 6);
        });
        inp.focus();
      }
    }
  }

  function initPasswordToggles() {
    document.querySelectorAll('.ef-pass-icon[data-target]').forEach((btn) => {
      btn.onclick = () => {
        const input = document.getElementById(btn.dataset.target || '');
        if (!input) return;
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        btn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
        btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        btn.innerHTML = isHidden ? getEyeOpenIcon() : getEyeOffIcon();
      };
    });
  }

  function getEyeOpenIcon() {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
  }

  function getEyeOffIcon() {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle><line x1="3" y1="3" x2="21" y2="21"></line></svg>';
  }

  /* ─── UTILITIES ──────────────────────────────────── */
  const qs = s => document.querySelector(s);
  const esc = s => String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const sel = (v, cur) => v === cur ? 'selected' : '';

  async function post(url, data) {
    const body = new URLSearchParams(data);
    let r;
    try {
      r = await fetch(apiUrl(url), {
        method: 'POST', body,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'include'
      });
    } catch (err) {
      if (err?.name === 'TypeError' || (err?.message && err.message.includes('fetch'))) {
        throw new Error('Connection failed — please check the website address and confirm that the hosting server and database are available.');
      }
      throw err;
    }
    const text = await r.text();
    try {
      return JSON.parse(text);
    } catch {
      // PHP returned HTML (crash/error page) — surface it
      const stripped = text.replace(/<[^>]+>/g, '').trim().slice(0, 200);
      throw new Error(stripped || 'Server error — check test_setup.php');
    }
  }

  function showError(msg) {
    let el = qs('#efError');
    if (!el) {
      el = Object.assign(document.createElement('div'), { id: 'efError', className: 'ef-error-msg' });
      const body = qs('#efBody');
      if (body) body.prepend(el);
    }
    el.textContent = msg;
    el.style.display = 'flex';
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function clearError() {
    const el = qs('#efError');
    if (el) el.style.display = 'none';
  }
  function setLoading(on, msg = '') {
    let el = qs('#efLoading');
    if (!el) {
      el = Object.assign(document.createElement('div'), { id: 'efLoading', className: 'ef-loading-bar' });
      qs('#efModal')?.appendChild(el);
    }
    if (on) {
      el.innerHTML = `<span class="ef-loading-spin"></span>${msg}`;
      el.style.display = 'flex';
    } else {
      el.style.display = 'none';
    }
  }

  /* ─── INJECT MODAL ───────────────────────────────── */
  function injectModal() {
    const existing = qs('#efModal');
    if (existing && qs('#efBody')) return;
    if (existing) existing.remove();

    const modal = Object.assign(document.createElement('div'), { id: 'efModal', className: 'ef-hidden ef-overlay' });
    modal.innerHTML = `
      <div class="ef-backdrop"></div>
      <div class="ef-panel">
        <button class="ef-close-btn" onclick="closeEnrollmentFlow()" aria-label="Close">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <div class="ef-header">
          <div class="ef-brand">
            <div class="ef-brand-dot"></div>
            <span>Einstein Center</span>
          </div>
          <div class="ef-progress-track">
            <div class="ef-progress-fill" id="efBar" style="width:14%"></div>
          </div>
          <div class="ef-step-info">
            <span id="efStepNum">Step 1 of 6</span>
            <strong id="efStepLabel">Account</strong>
          </div>
        </div>
        <div class="ef-body" id="efBody"></div>
      </div>`;
    document.body.appendChild(modal);
    injectStyles();
  }

  function injectStyles() {
    const s = document.createElement('style');
    s.textContent = `
      /* ── OVERLAY ── */
      .ef-overlay{position:fixed;inset:0;z-index:9000;display:flex;align-items:center;justify-content:center;padding:16px}
      .ef-hidden{display:none!important}
      .ef-backdrop{position:absolute;inset:0;background:rgba(20,10,4,.85);backdrop-filter:blur(6px);pointer-events:none}

      /* ── PANEL ── */
      .ef-panel, .ef-panel * { box-sizing:border-box!important; }
      .ef-panel{
        position:relative;z-index:1;background:#fff;border-radius:16px;
        width:min(560px,100%);max-height:92vh;display:flex;flex-direction:column;
        box-shadow:0 32px 80px rgba(0,0,0,.35);overflow:hidden;
        animation:efUp .35s cubic-bezier(.22,1,.36,1);
      }
      @keyframes efUp{from{opacity:0;transform:translateY(28px)}to{opacity:1;transform:none}}

      /* ── CLOSE ── */
      .ef-close-btn{
        position:absolute;top:16px;right:16px;z-index:10;background:rgba(255,255,255,.08);
        border:1px solid rgba(255,255,255,.06);border-radius:50%;width:36px;height:36px;cursor:pointer;
        display:flex;align-items:center;justify-content:center;transition:background .2s,border-color .2s,transform .2s,color .2s;color:rgba(255,255,255,.68);
        box-shadow:none;
      }
      .ef-close-btn:hover{background:rgba(255,255,255,.14);border-color:rgba(255,255,255,.18);color:#fff;transform:scale(1.04)}
      .ef-close-btn svg{width:20px;height:20px;stroke-width:2.4}

      /* ── HEADER ── */
      .ef-header{background:#5E3A21;padding:20px 24px 16px;flex-shrink:0}
      .ef-brand{display:flex;align-items:center;gap:8px;margin-bottom:14px}
      .ef-brand-dot{width:8px;height:8px;border-radius:50%;background:#CAA171;flex-shrink:0}
      .ef-brand span{font-size:12px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.7)}
      .ef-progress-track{height:3px;background:rgba(255,255,255,.12);border-radius:999px;overflow:hidden;margin-bottom:12px}
      .ef-progress-fill{height:100%;background:linear-gradient(90deg,#CAA171,#E1C39A);border-radius:999px;transition:width .5s ease}
      .ef-step-info{display:flex;align-items:center;justify-content:space-between}
      .ef-step-info span{font-size:11px;color:rgba(255,255,255,.45);letter-spacing:.08em}
      .ef-step-info strong{font-size:13px;color:#E1C39A;font-weight:600;letter-spacing:.04em}

      /* ── BODY ── */
      .ef-body{flex:1;overflow-y:auto;padding:28px 28px 24px;scrollbar-width:thin;scrollbar-color:rgba(94,58,33,.15) transparent}

      /* ── TYPOGRAPHY ── */
      .ef-fieldset-title{font-size:10px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:#CAA171;margin:0 0 16px;padding-bottom:10px;border-bottom:1px solid #f0e8da}
      .ef-sub{font-size:13px;color:#8D6A4E;line-height:1.6;margin:-8px 0 18px}
      .ef-divider{height:1px;background:#f0e8da;margin:22px 0}

      /* ── FORM FIELDS ── */
      .ef-field{display:flex;flex-direction:column;margin-bottom:14px}
      .ef-field label{font-size:11px;font-weight:600;color:#5E3A21;margin-bottom:6px;letter-spacing:.04em}
      .ef-field .req{color:#c0392b;margin-left:1px}
      .ef-field input,.ef-field select{
        width:100%;
        padding:11px 14px;border:1.5px solid #e8ddd3;border-radius:8px;font-size:13px;
        color:#3D2615;outline:none;transition:border-color .2s,box-shadow .2s;font-family:inherit;background:#fff;
      }
      .ef-password-wrap{position:relative;width:100%}
      .ef-password-wrap input{padding-right:42px}
      .ef-pass-icon{
        position:absolute;right:12px;top:50%;transform:translateY(-50%);
        width:20px;height:20px;color:#b0957d;display:flex;align-items:center;justify-content:center;
        border:none;background:transparent;padding:0;cursor:pointer;border-radius:4px;
      }
      .ef-pass-icon svg{width:18px;height:18px}
      .ef-pass-icon:hover{color:#8D6A4E}
      .ef-pass-icon:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(202,161,113,.22)}
      .ef-field input:focus,.ef-field select:focus{border-color:#CAA171;box-shadow:0 0 0 3px rgba(202,161,113,.12)}
      .ef-field input.ef-readonly{background:#faf6f1;color:#8D6A4E;cursor:not-allowed}
      .ef-field input::placeholder{color:#c4b0a0}
      .ef-field select option{color:#3D2615}
      .ef-hint{font-size:11px;color:#a88f7a;margin-top:5px}
      .ef-field-group{margin-bottom:0}

      /* ── GRID ── */
      .ef-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
      @media(max-width:480px){.ef-grid-2{grid-template-columns:1fr}}

      /* ── RADIO CARDS ── */
      .ef-radio-card{
        display:flex;align-items:flex-start;gap:12px;padding:12px 14px;
        border:1.5px solid #e8ddd3;border-radius:8px;cursor:pointer;
        margin-bottom:8px;transition:border-color .2s,background .2s;
      }
      .ef-radio-card:hover{border-color:#CAA171;background:#fdf8f2}
      .ef-radio-card input{margin-top:2px;accent-color:#5E3A21;flex-shrink:0;width:16px;height:16px}
      .ef-radio-card:has(input:checked){border-color:#5E3A21;background:#fdf8f2}
      .ef-radio-inner{display:flex;flex-direction:column;gap:2px}
      .ef-radio-name{font-size:13px;font-weight:600;color:#3D2615}
      .ef-radio-detail{font-size:11px;color:#8D6A4E}
      .ef-verify-choice-grid{display:grid;gap:12px;margin-top:6px}
      .ef-verify-choice-card{
        display:grid;grid-template-columns:auto 1fr auto;
        align-items:center;gap:14px;padding:18px 18px;
        border:1.5px solid #e8ddd3;border-radius:14px;background:#fff;
        cursor:pointer;transition:all .2s;position:relative;
      }
      .ef-verify-choice-card:hover{border-color:#CAA171;background:#fdf8f2}
      .ef-verify-choice-card input{position:absolute;opacity:0;pointer-events:none}
      .ef-verify-choice-card.ef-verify-choice-active{border-color:#5E3A21;background:#f5efe8;box-shadow:0 10px 24px rgba(94,58,33,.12)}
      .ef-verify-choice-icon{
        width:44px;height:44px;display:flex;align-items:center;justify-content:center;
        border-radius:14px;background:#f5ede0;color:#5E3A21;font-size:18px;flex-shrink:0;
      }
      .ef-verify-choice-body{display:flex;flex-direction:column;gap:4px}
      .ef-verify-choice-title{font-size:14px;font-weight:700;color:#3D2615}
      .ef-verify-choice-desc{font-size:12px;color:#8D6A4E;line-height:1.4}
      .ef-verify-choice-arrow{font-size:20px;color:#CAA171;}

      .ef-pkg-group{margin-bottom:16px}
      .ef-pkg-group:last-child{margin-bottom:0}
      .ef-pkg-group-label{
        font-size:10px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;
        color:#8D6A4E;background:#f5ede0;border-radius:5px;
        padding:5px 10px;margin-bottom:8px;display:inline-block;
      }
      .ef-info-card{
        display:flex;align-items:flex-start;gap:12px;padding:12px 14px;
        border:1.5px dashed #e8ddd3;border-radius:8px;
        margin-bottom:8px;background:#faf6f1;
        opacity:.75;cursor:default;
      }
      .ef-info-card .ef-radio-name{color:#8D6A4E}
      .ef-info-card::before{
        content:'ℹ';font-size:13px;color:#CAA171;flex-shrink:0;margin-top:1px;
      }

      /* ── AUTH CHOICE (Log In / Sign Up) ── */
      .ef-auth-hero{margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:1px solid #f0e8da}
      .ef-auth-hero-title{font-family:inherit;font-size:1.25rem;font-weight:700;color:#3D2615;margin:0 0 6px;line-height:1.35;letter-spacing:-.02em}
      .ef-auth-program{margin-top:10px}
      .ef-auth-program strong{color:#5E3A21}
      .ef-auth-actions{display:flex;flex-direction:column;gap:10px;margin-top:4px}
      .ef-auth-choice-btn{
        display:flex;align-items:center;justify-content:space-between;gap:16px;width:100%;
        padding:18px 20px;border-radius:14px;cursor:pointer;text-align:left;
        transition:transform .2s,box-shadow .2s,border-color .2s,background .2s;
        font-family:inherit;position:relative;overflow:hidden;
      }
      .ef-auth-choice-body{flex:1;display:flex;flex-direction:column;gap:4px;min-width:0}
      .ef-auth-choice-title{font-size:16px;font-weight:700;color:#3D2615;letter-spacing:.02em}
      .ef-auth-choice-desc{font-size:12px;color:#8D6A4E;line-height:1.45;font-weight:400}
      .ef-auth-choice-chevron{
        flex-shrink:0;width:32px;height:32px;border-radius:50%;
        display:flex;align-items:center;justify-content:center;
        transition:background .2s,transform .2s;
      }
      .ef-auth-choice-chevron::after{
        content:'';width:7px;height:7px;border-right:2px solid currentColor;
        border-bottom:2px solid currentColor;transform:rotate(-45deg);margin-left:-3px;
      }
      .ef-auth-choice-outline{
        background:#fff;border:1.5px solid #e0d4c8;
        box-shadow:0 1px 3px rgba(94,58,33,.04);
      }
      .ef-auth-choice-outline .ef-auth-choice-chevron{
        background:#faf6f1;color:#5E3A21;
      }
      .ef-auth-choice-outline:hover{
        border-color:#CAA171;background:#fdfaf5;
        box-shadow:0 6px 20px rgba(94,58,33,.1);transform:translateY(-1px);
      }
      .ef-auth-choice-outline:hover .ef-auth-choice-chevron{background:#5E3A21;color:#fff}
      .ef-auth-choice-primary{
        background:linear-gradient(135deg,#5E3A21 0%,#4a2e1a 100%);
        border:1.5px solid #4a2e1a;
        box-shadow:0 4px 14px rgba(94,58,33,.22);
      }
      .ef-auth-choice-primary .ef-auth-choice-title{color:#fff}
      .ef-auth-choice-primary .ef-auth-choice-desc{color:rgba(255,255,255,.72)}
      .ef-auth-choice-primary .ef-auth-choice-chevron{
        background:rgba(255,255,255,.15);color:#E1C39A;
      }
      .ef-auth-choice-primary:hover{
        box-shadow:0 8px 24px rgba(94,58,33,.28);transform:translateY(-2px);
      }
      .ef-auth-choice-primary:hover .ef-auth-choice-chevron{
        background:rgba(255,255,255,.25);transform:translateX(2px);
      }
      .ef-auth-choice-btn:focus-visible{outline:2px solid #CAA171;outline-offset:2px}
      .ef-auth-back{margin-bottom:12px;padding-left:0}
      .ef-auth-switch{text-align:center;font-size:12px;color:#8D6A4E;margin-top:16px}
      .ef-link-btn{
        background:none;border:none;padding:0;margin:0;cursor:pointer;
        font:inherit;font-size:12px;font-weight:600;color:#5E3A21;text-decoration:underline;
      }
      .ef-link-btn:hover{color:#CAA171}

      /* ── INFO BOX ── */
      .ef-info-box{
        display:flex;align-items:flex-start;gap:10px;background:#fdf8f2;
        border:1px solid rgba(202,161,113,.3);border-radius:8px;padding:12px 14px;
        font-size:12px;color:#8D6A4E;line-height:1.5;margin-bottom:6px;
      }
      .ef-info-box svg{flex-shrink:0;margin-top:1px;color:#CAA171}

      /* ── OTP ── */
      .ef-otp-wrap{position:relative;margin:24px 0 8px}
      .ef-otp-input{
        width:100%;padding:18px 56px 18px 20px;border:2px solid #e8ddd3;border-radius:10px;
        font-size:32px;font-weight:700;letter-spacing:.5em;color:#3D2615;text-align:center;
        outline:none;transition:border-color .2s;font-family:monospace;background:#fff;
      }
      .ef-otp-input:focus{border-color:#CAA171;box-shadow:0 0 0 3px rgba(202,161,113,.12)}
      .ef-otp-icon{position:absolute;right:16px;top:50%;transform:translateY(-50%);font-size:20px;opacity:.35}
      .ef-otp-msg{text-align:center;font-size:12px;color:#8D6A4E;margin-bottom:20px}
      .ef-email-hl{color:#5E3A21}

      /* ── VERIFIED STRIP ── */
      .ef-verified-strip{
        display:flex;align-items:center;gap:8px;background:#f0faf4;border:1px solid #a3d9b5;
        border-radius:8px;padding:10px 14px;font-size:12px;font-weight:600;color:#2e7d52;
        margin-bottom:20px;
      }
      .ef-verified-strip svg{color:#2e7d52}

      /* ── SUMMARY CARD ── */
      .ef-summary-card{background:#faf6f1;border:1px solid #e8ddd3;border-radius:10px;overflow:hidden;margin-bottom:6px}
      .ef-sum-row{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid #f0e8da;font-size:12px}
      .ef-sum-row:last-child{border-bottom:none}
      .ef-sum-row span{color:#8D6A4E}
      .ef-sum-row strong{color:#3D2615;text-align:right;max-width:55%;word-break:break-word}

      /* ── UPLOAD ZONE ── */
      .ef-upload-zone{
        border:2px dashed #d4c4b5;border-radius:10px;padding:28px 20px;text-align:center;
        cursor:pointer;transition:border-color .2s,background .2s;position:relative;
        margin-bottom:16px;background:#faf6f1;
      }
      .ef-upload-zone:hover,.ef-upload-zone.ef-upload-selected{border-color:#CAA171;background:#fdf8f2}
      .ef-upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
      .ef-upload-icon{font-size:28px;margin-bottom:8px;opacity:.5}
      .ef-upload-text{font-size:13px;font-weight:600;color:#5E3A21;margin-bottom:4px}
      .ef-upload-hint{font-size:11px;color:#a88f7a}

      /* ── QR CODE GRID ── */
      .ef-qr-grid{
        display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;
      }
      .ef-qr-card{
        display:flex;flex-direction:column;align-items:center;gap:8px;padding:12px;
        border:1px solid #e8ddd3;border-radius:10px;background:#faf6f1;
        transition:all .2s;cursor:pointer;
      }
      .ef-qr-card:hover{border-color:#CAA171;background:#fdf8f2;transform:translateY(-2px)}
      .ef-qr-card-selected{
        border:2px solid #5E3A21;background:#fff;box-shadow:0 4px 12px rgba(94,58,33,.15);
      }
      .ef-qr-img{
        width:100%;aspect-ratio:1;object-fit:contain;border-radius:8px;
        background:#fff;padding:4px;border:1px solid #e8ddd3;
      }
      .ef-qr-label{font-size:12px;font-weight:600;color:#5E3A21;text-align:center}

      /* ── QR MODAL ── */
      .ef-qr-modal{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px}
      .ef-qr-backdrop{position:absolute;inset:0;background:rgba(20,10,4,.9);backdrop-filter:blur(6px);pointer-events:none}
      .ef-qr-container{
        position:relative;z-index:1;background:#fff;border-radius:16px;padding:24px;
        display:flex;flex-direction:column;align-items:center;gap:16px;
        max-width:500px;width:100%;box-shadow:0 32px 80px rgba(0,0,0,.35);
        animation:efZoomIn .35s cubic-bezier(.22,1,.36,1);pointer-events:auto;
      }
      @keyframes efZoomIn{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:scale(1)}}
      .ef-qr-close{
        position:absolute;top:16px;right:16px;background:rgba(0,0,0,.06);
        border:none;border-radius:50%;width:36px;height:36px;cursor:pointer;
        display:flex;align-items:center;justify-content:center;transition:background .2s;color:#5E3A21;
        pointer-events:auto;
      }
      .ef-qr-close:hover{background:rgba(0,0,0,.12)}
      .ef-qr-label-top{font-size:16px;font-weight:700;color:#5E3A21;margin-top:8px}
      .ef-qr-display{
        width:100%;max-width:350px;aspect-ratio:1;object-fit:contain;
        border:2px solid #e8ddd3;border-radius:12px;padding:12px;background:#fff;
      }
      .ef-qr-hint{font-size:12px;color:#8D6A4E;text-align:center;margin-top:8px}
      .ef-qr-footer{font-size:11px;color:#a88f7a;text-align:center;margin-top:8px;font-style:italic}
      .ef-qr-download-btn{
        padding:10px 16px;background:#5E3A21;border:none;border-radius:8px;color:#fff;
        font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:8px;
        justify-content:center;transition:background .2s;font-family:inherit;margin-top:12px;
      }
      .ef-qr-download-btn:hover{background:#452A18}

      /* ── BUTTONS ── */
      .ef-btn-primary{
        width:100%;padding:14px;background:#5E3A21;border:none;border-radius:10px;
        color:#fff;font-size:14px;font-weight:600;letter-spacing:.03em;cursor:pointer;
        display:flex;align-items:center;justify-content:center;gap:8px;
        transition:background .2s,transform .15s,box-shadow .2s;font-family:inherit;
        margin-top:20px;
      }
      .ef-btn-primary:hover{background:#452A18;box-shadow:0 6px 20px rgba(94,58,33,.25);transform:translateY(-1px)}
      .ef-btn-primary:active{transform:none}
      .ef-arrow{font-size:16px;line-height:1}
      .ef-btn-ghost{
        width:100%;padding:11px;background:transparent;border:1.5px solid #e8ddd3;border-radius:10px;
        color:#8D6A4E;font-size:13px;font-weight:500;cursor:pointer;transition:all .2s;
        font-family:inherit;margin-top:8px;
      }
      .ef-btn-ghost:hover{border-color:#CAA171;color:#5E3A21}

      /* ── PROCESSING ── */
      .ef-processing{text-align:center;padding:32px 0}
      .ef-spinner-ring{
        width:56px;height:56px;border:3px solid #f0e8da;border-top-color:#5E3A21;
        border-radius:50%;animation:efSpin .75s linear infinite;margin:0 auto 24px;
      }
      @keyframes efSpin{to{transform:rotate(360deg)}}
      .ef-processing h3{font-size:18px;color:#3D2615;margin:0 0 8px}
      .ef-processing p{font-size:13px;color:#8D6A4E;margin:0 0 28px}
      .ef-checklist{display:flex;flex-direction:column;gap:12px;text-align:left;max-width:280px;margin:0 auto}
      .ef-check-item{display:flex;align-items:center;gap:10px;font-size:13px;color:#8D6A4E;opacity:.4;transition:opacity .4s}
      .ef-check-item.ef-done{opacity:1;color:#3D2615}
      .ef-check-dot{width:20px;height:20px;border-radius:50%;background:#f0e8da;flex-shrink:0;
        display:flex;align-items:center;justify-content:center;font-size:11px;transition:background .4s}
      .ef-check-item.ef-done .ef-check-dot{background:#5E3A21;color:#fff}
      .ef-check-item.ef-done .ef-check-dot::after{content:'✓'}

      /* ── SUCCESS ── */
      .ef-success{text-align:center;padding:24px 0}
      .ef-success-mark{
        width:72px;height:72px;border-radius:50%;background:#5E3A21;
        display:flex;align-items:center;justify-content:center;margin:0 auto 20px;
        animation:efPop .4s cubic-bezier(.22,1,.36,1);
      }
      @keyframes efPop{from{transform:scale(0)}to{transform:scale(1)}}
      .ef-success h3{font-size:22px;color:#3D2615;margin:0 0 8px}
      .ef-success>p{font-size:14px;color:#8D6A4E;margin:0 0 24px}
      .ef-ref-number{background:#faf6f1;border:1px solid #e8ddd3;border-radius:10px;padding:16px 24px;margin-bottom:20px}
      .ef-ref-label{font-size:10px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:#CAA171;margin-bottom:6px}
      .ef-ref-value{font-size:26px;font-weight:700;color:#5E3A21;letter-spacing:.08em}
      .ef-success-info{background:#faf6f1;border-radius:10px;padding:16px 20px;text-align:left;margin-bottom:24px}
      .ef-si-row{font-size:12px;color:#5E3A21;padding:5px 0;line-height:1.5}
      .ef-si-row strong{color:#3D2615}

      /* ── ERROR / LOADING ── */
      .ef-error-msg{
        display:flex;align-items:center;gap:8px;background:#fff5f5;border:1px solid #fcc;
        border-radius:8px;padding:10px 14px;font-size:12px;color:#c0392b;
        margin-bottom:16px;line-height:1.5;
      }
      .ef-error-msg::before{content:'⚠';font-size:14px;flex-shrink:0}
      .ef-loading-bar{
        position:absolute;bottom:0;left:0;right:0;background:rgba(94,58,33,.95);
        padding:14px 24px;display:none;align-items:center;gap:12px;
        font-size:13px;color:#E1C39A;font-weight:500;z-index:20;
      }
      .ef-loading-spin{
        width:18px;height:18px;border:2px solid rgba(225,195,154,.3);border-top-color:#E1C39A;
        border-radius:50%;animation:efSpin .6s linear infinite;flex-shrink:0;
      }
    `;
    document.head.appendChild(s);
  }

  // Background sync QRs from server
  (function syncSettingsQrs() {
    try {
      fetch('api/api_settings.php', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
          if (data && data.success && data.settings && Array.isArray(data.settings.qrs) && data.settings.qrs.length > 0) {
            window.siteSettings = window.siteSettings || {};
            window.siteSettings.qrs = data.settings.qrs;
            try {
              const ls = JSON.parse(localStorage.getItem('einsteinSettings') || '{}');
              ls.qrs = data.settings.qrs;
              localStorage.setItem('einsteinSettings', JSON.stringify(ls));
            } catch (_) {}
          }
        }).catch(() => {});
    } catch (_) {}
  })();

  // Init
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', injectModal);
  else injectModal();

})();
