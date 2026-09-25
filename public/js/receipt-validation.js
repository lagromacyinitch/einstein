/**
 * Client-side payment receipt validation.
 *
 * A receipt must contain recognizable payment text (provider/transaction
 * details, amount, and a status or reference). Image classifiers are used as
 * an additional block for sensitive, people, animal, and nature imagery.
 * This is intentionally fail-closed: an image that cannot be inspected is not
 * allowed to reach the upload request.
 */
(function () {
  'use strict';

  const MAX_FILE_SIZE = 5 * 1024 * 1024;
  const INVALID_MESSAGE = 'Invalid image. Please upload a valid payment receipt (GCash, BPI, SeaBank, etc.).';
  const SCRIPT_URLS = {
    tf: 'https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.17.0/dist/tf.min.js',
    nsfw: 'https://cdn.jsdelivr.net/npm/nsfwjs@2.4.2/dist/nsfwjs.min.js',
    mobilenet: 'https://cdn.jsdelivr.net/npm/@tensorflow-models/mobilenet@2.1.1/dist/mobilenet.min.js',
    tesseract: 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js'
  };

  const PAYMENT_PROVIDERS = [
    /\bg\s*c\s*a\s*s\s*h\b/i, /\bbpi\b/i, /\bsea\s*bank\b/i, /\bmaya\b/i,
    /\bpaymaya\b/i, /\bbdo\b/i, /\bmetrobank\b/i, /\bunionbank\b/i,
    /\blandbank\b/i, /\bpnb\b/i, /\brcbc\b/i, /\bsecurity\s*bank\b/i,
    /\beast\s*west\b/i, /\bcimb\b/i, /\btonik\b/i, /\bgotyme\b/i,
    /\bgrab\s*pay\b/i, /\bshopee\s*pay\b/i, /\bpalawan\s*pay\b/i,
    /\binsta\s*pay\b/i, /\bpesonet\b/i, /\bqr\s*ph\b/i,
    /\bbank\s+of\s+the\s+philippines\b/i
  ];

  const PAYMENT_TERMS = /\b(payment|paid|transfer|transaction|recipient|sender|merchant|bank|wallet|cash\s*(?:in|out)|deposit|withdrawal)\b/i;
  const STATUS_TERMS = /\b(success(?:ful)?|completed|approved|confirmed|sent|received|posted)\b/i;
  const REFERENCE_TERMS = /\b(reference|ref(?:erence)?|transaction|trace|confirmation|receipt)\s*(?:no\.?|number|id|code)?\s*[:#-]?\s*[a-z0-9][a-z0-9 -]{3,}/i;
  const CURRENCY_AMOUNT = /(?:\u20b1|php|p\.?\s*)\s*[0-9][0-9,]*(?:\.[0-9]{1,2})?|[0-9][0-9,]*\.[0-9]{2}\s*(?:php|\u20b1|pesos?)/i;
  const LABELED_AMOUNT = /\b(amount|total|sent|paid|payment|transfer|received|cash\s*(?:in|out))\b[\s\S]{0,55}(?:\u20b1|php|p\.?\s*)?\s*[0-9][0-9,]*(?:\.[0-9]{1,2})?/i;
  const NUMERIC_EVIDENCE = /\b[0-9]{2,}(?:[.,][0-9]{2})?\b/;
  const RECEIPT_CONTEXT = /\b(amount|total|ref\.?\s*no|sent\s+via|receipt|confirmation|payment|transfer|transaction|successful|completed|approved|received|paid)\b/i;

  const BLOCKED_VISUAL_LABELS = [
    /\b(person|people|man|woman|boy|girl|child|baby|face|portrait|bridegroom|groom)\b/i,
    /\b(dog|cat|puppy|kitten|horse|pony|cow|bull|pig|sheep|goat|lion|tiger|bear|wolf|fox|monkey|elephant|giraffe|zebra|bird|eagle|owl|fish|shark|snake|lizard|turtle|frog|rabbit|hamster|poodle|retriever|tabby)\b/i,
    /\b(mountain|valley|lakeside|seashore|beach|forest|tree|flower|volcano|coral\s+reef|alp|cliff|promontory|meadow|desert|waterfall|sunset|rainbow|sky|park)\b/i
  ];

  let scriptPromises = {};
  let nsfwModelPromise = null;
  let mobileNetModelPromise = null;
  let ocrWorkerPromise = null;
  let ocrQueue = Promise.resolve();
  let ocrProgressHandler = null;

  function loadScript(key, url, globalName) {
    if (window[globalName]) return Promise.resolve(window[globalName]);
    if (scriptPromises[key]) return scriptPromises[key];

    scriptPromises[key] = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = url;
      script.async = true;
      script.onload = () => window[globalName] ? resolve(window[globalName]) : reject(new Error(`${globalName} did not load`));
      script.onerror = () => reject(new Error(`Unable to load ${key}`));
      document.head.appendChild(script);
    });
    return scriptPromises[key];
  }

  async function loadTensorFlow() {
    if (window.tf) return window.tf;
    return loadScript('tf', SCRIPT_URLS.tf, 'tf');
  }

  async function loadNsfwModel() {
    if (nsfwModelPromise) return nsfwModelPromise;
    nsfwModelPromise = (async () => {
      await loadTensorFlow();
      const nsfw = window.nsfwjs || await loadScript('nsfw', SCRIPT_URLS.nsfw, 'nsfwjs');
      return nsfw.load();
    })().catch((error) => {
      nsfwModelPromise = null;
      throw error;
    });
    return nsfwModelPromise;
  }

  async function loadMobileNetModel() {
    if (mobileNetModelPromise) return mobileNetModelPromise;
    mobileNetModelPromise = (async () => {
      await loadTensorFlow();
      const mobilenet = window.mobilenet || await loadScript('mobilenet', SCRIPT_URLS.mobilenet, 'mobilenet');
      return mobilenet.load({ version: 2, alpha: 1.0 });
    })().catch((error) => {
      mobileNetModelPromise = null;
      throw error;
    });
    return mobileNetModelPromise;
  }

  async function getOcrWorker() {
    if (ocrWorkerPromise) return ocrWorkerPromise;
    ocrWorkerPromise = (async () => {
      const tesseract = window.Tesseract || await loadScript('tesseract', SCRIPT_URLS.tesseract, 'Tesseract');
      return tesseract.createWorker('eng', 1, {
        logger: (info) => {
          if (typeof ocrProgressHandler === 'function') ocrProgressHandler(info);
        }
      });
    })().catch((error) => {
      ocrWorkerPromise = null;
      throw error;
    });
    return ocrWorkerPromise;
  }

  function normalizeText(text) {
    return String(text || '')
      .replace(/[|]/g, 'I')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function inspectReceiptText(text) {
    const normalized = normalizeText(text);
    const provider = PAYMENT_PROVIDERS.find((pattern) => pattern.test(normalized));
    const hasPaymentTerms = PAYMENT_TERMS.test(normalized);
    const hasStatus = STATUS_TERMS.test(normalized);
    const hasReference = REFERENCE_TERMS.test(normalized);
    const hasAmount = CURRENCY_AMOUNT.test(normalized) || LABELED_AMOUNT.test(normalized);
    const hasNumericEvidence = NUMERIC_EVIDENCE.test(normalized);
    const hasReceiptContext = RECEIPT_CONTEXT.test(normalized);
    // OCR can miss a provider name or the reference label on a real GCash/
    // bank screenshot. Amount/number evidence plus any receipt context is
    // enough; the visual safety checks below still reject unrelated images.
    const valid = normalized.length >= 6 && hasNumericEvidence &&
      (hasAmount || provider || hasPaymentTerms || hasStatus || hasReference || hasReceiptContext);

    return {
      valid,
      text: normalized,
      provider: provider ? provider.toString() : '',
      hasAmount,
      hasStatus,
      hasReference,
      hasPaymentTerms,
      hasNumericEvidence,
      hasReceiptContext
    };
  }

  function getBlockedVisualPrediction(predictions) {
    return (predictions || [])
      .filter((prediction) => Number(prediction.probability || 0) >= 0.32)
      .find((prediction) => BLOCKED_VISUAL_LABELS.some((pattern) => pattern.test(prediction.className || '')));
  }

  function getSensitivePrediction(predictions) {
    const scores = Object.fromEntries((predictions || []).map((prediction) => [prediction.className, Number(prediction.probability || 0)]));
    const porn = scores.Porn || 0;
    const hentai = scores.Hentai || 0;
    const sexy = scores.Sexy || 0;
    return porn >= 0.40 || hentai >= 0.40 || porn + hentai >= 0.45 || sexy >= 0.70;
  }

  function hasSensitivePixels(image) {
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { willReadFrequently: true });
    if (!context) return false;
    canvas.width = 96;
    canvas.height = 96;
    context.drawImage(image, 0, 0, canvas.width, canvas.height);
    const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
    let blood = 0;
    let visceral = 0;
    let surgical = 0;

    for (let index = 0; index < pixels.length; index += 4) {
      const red = pixels[index];
      const green = pixels[index + 1];
      const blue = pixels[index + 2];
      const isBlood = (red > 95 && green < 65 && blue < 65 && red - Math.max(green, blue) > 30) ||
        (red > 135 && green < 80 && blue < 80 && red - green > 45 && red - blue > 45);
      const isVisceral = (red > 125 && red < 240 && green > 35 && green < 145 && blue > 20 && blue < 125 && red - green > 25 && red - blue > 30) ||
        (red > 160 && green > 85 && green < 170 && blue > 40 && blue < 125 && red - blue > 45 && green - blue > 18);
      const isSurgicalDrape = blue > 110 && blue > red * 1.3 && green > 60 && green < 185 && red < 105;
      const isLatexGlove = red > 160 && green > 140 && blue < 115 && Math.abs(red - green) < 40 && red - blue > 45;
      if (isBlood) blood++;
      if (isVisceral) visceral++;
      if (isSurgicalDrape || isLatexGlove) surgical++;
    }

    const total = canvas.width * canvas.height;
    const bloodRatio = blood / total;
    const visceralRatio = visceral / total;
    const surgicalRatio = surgical / total;
    return bloodRatio > 0.035 || visceralRatio > 0.12 ||
      (visceralRatio > 0.07 && (bloodRatio > 0.02 || surgicalRatio > 0.03)) ||
      (surgicalRatio > 0.08 && visceralRatio > 0.04);
  }

  function decodeImage(file) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const image = new Image();
      image.onload = () => {
        URL.revokeObjectURL(url);
        resolve(image);
      };
      image.onerror = () => {
        URL.revokeObjectURL(url);
        reject(new Error('Image could not be decoded'));
      };
      image.src = url;
    });
  }

  function createOcrImage(image) {
    const maxSide = 2200;
    const scale = Math.min(1, maxSide / Math.max(image.naturalWidth || image.width, image.naturalHeight || image.height));
    const width = Math.max(1, Math.round((image.naturalWidth || image.width) * scale));
    const height = Math.max(1, Math.round((image.naturalHeight || image.height) * scale));
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d', { alpha: false });
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, width, height);
    context.drawImage(image, 0, 0, width, height);
    return canvas;
  }

  async function recognizeReceiptText(image, onProgress) {
    const run = async () => {
      ocrProgressHandler = onProgress;
      try {
        const worker = await getOcrWorker();
        const result = await worker.recognize(createOcrImage(image));
        return result && result.data ? result.data.text || '' : '';
      } finally {
        ocrProgressHandler = null;
      }
    };

    const result = ocrQueue.then(run, run);
    ocrQueue = result.catch(() => '');
    return result;
  }

  function validateBasicFile(file) {
    if (!file || !(file instanceof File)) return 'Please choose an image file.';
    if (!/^image\/(jpeg|png|webp)$/i.test(file.type || '')) return 'Please upload a JPG, PNG, or WEBP image.';
    if (file.size > MAX_FILE_SIZE) return 'File is too large. Maximum size is 5MB.';
    return '';
  }

  async function validatePaymentReceiptFile(file, options = {}) {
    const basicError = validateBasicFile(file);
    if (basicError) return { valid: false, message: basicError, reason: 'file' };

    const progress = typeof options.onProgress === 'function' ? options.onProgress : () => {};
    progress('Loading receipt security checks...');

    try {
      const image = await decodeImage(file);
      const width = image.naturalWidth || image.width;
      const height = image.naturalHeight || image.height;
      if (width < 180 || height < 120) {
        return { valid: false, message: INVALID_MESSAGE, reason: 'image-too-small' };
      }
      if (hasSensitivePixels(image)) {
        return { valid: false, message: INVALID_MESSAGE, reason: 'sensitive-pixels' };
      }

      progress('Checking image content...');
      const [ocrResult, nsfwResult, mobileNetResult] = await Promise.allSettled([
        recognizeReceiptText(image, (info) => {
          if (info && info.status === 'recognizing text') {
            progress(`Reading receipt text... ${Math.round(Number(info.progress || 0) * 100)}%`);
          }
        }),
        loadNsfwModel().then((model) => model.classify(image)),
        loadMobileNetModel().then((model) => model.classify(image, 10))
      ]);

      // OCR is mandatory: a clear receipt should contain payment details.
      if (ocrResult.status !== 'fulfilled') throw ocrResult.reason;
      const receiptText = inspectReceiptText(ocrResult.value);
      if (!receiptText.valid) {
        return { valid: false, message: INVALID_MESSAGE, reason: 'missing-payment-details', ocr: receiptText };
      }

      // Classifier downloads can be blocked by a browser extension or a
      // temporary CDN failure. OCR remains mandatory, while each classifier
      // rejects content when it is available.
      if (nsfwResult.status === 'fulfilled' && getSensitivePrediction(nsfwResult.value)) {
        return { valid: false, message: INVALID_MESSAGE, reason: 'sensitive-content' };
      }

      if (mobileNetResult.status === 'fulfilled') {
        const blocked = getBlockedVisualPrediction(mobileNetResult.value);
        if (blocked) {
          return { valid: false, message: INVALID_MESSAGE, reason: 'irrelevant-content', label: blocked.className };
        }
      }

      return { valid: true, message: '', reason: 'payment-receipt', ocr: receiptText };
    } catch (error) {
      console.warn('[Receipt validation] Unable to inspect image:', error);
      return { valid: false, message: INVALID_MESSAGE, reason: 'inspection-failed' };
    }
  }

  window.PAYMENT_RECEIPT_INVALID_MESSAGE = INVALID_MESSAGE;
  window.validatePaymentReceiptFile = validatePaymentReceiptFile;
  window.preloadPaymentReceiptValidation = function () {
    // Start downloads before the user selects a file, without blocking the UI.
    return Promise.allSettled([loadNsfwModel(), loadMobileNetModel(), getOcrWorker()]);
  };

  window.addEventListener('beforeunload', () => {
    if (ocrWorkerPromise) {
      ocrWorkerPromise.then((worker) => worker.terminate()).catch(() => {});
    }
  });
})();
