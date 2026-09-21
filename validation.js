/**
 * Einstein Center - Global Input Validation & Formatting System
 * Strictly enforces input validation rules across all portals and dynamic forms.
 *
 * Rules:
 * 1. Email: Letters, numbers, and symbols allowed
 * 2. Name: Letters only (no numbers); auto-capitalize the first letter of each word
 * 3. Password: Letters, numbers, and symbols allowed
 * 4. Age / Phone / Child's Grade / Prices: Numbers only
 * 5. Schedules: Letters and numbers allowed
 * 6. Facebook / Link: Letters, digits, spaces, periods (.), slashes (/), hyphens (-), underscores (_), colons (:), @ — full URL support
 * 7. Address: Letters and numbers allowed
 * 8. School: Letters and spaces only (alphabets only)
 */

(function () {
    'use strict';

    /**
     * Capitalizes the first letter of each word in a string.
     */
    function autoCapitalizeWords(str) {
        if (!str) return '';
        return str.replace(/(^|\s)([a-z])/g, function (match, space, char) {
            return space + char.toUpperCase();
        });
    }

    /**
     * Determines the validation type for an input element based on data-validate,
     * attributes, IDs, names, classes, or placeholders.
     */
    function getValidationType(el) {
        if (!el) return null;

        // Check explicit data attribute first
        const dataVal = el.getAttribute('data-validate');
        if (dataVal) return dataVal.toLowerCase();

        const type = (el.type || '').toLowerCase();
        const id = (el.id || '').toLowerCase();
        const name = (el.name || '').toLowerCase();
        const cls = (el.className || '').toLowerCase();
        const placeholder = (el.placeholder || '').toLowerCase();

        // Password fields
        if (type === 'password' || id.includes('pass') || name.includes('pass')) {
            return 'password';
        }

        // Email fields
        if (type === 'email' || id.includes('email') || name.includes('email')) {
            return 'email';
        }

        // Facebook fields
        if (id.includes('fb') || id.includes('facebook') || name.includes('fb') || name.includes('facebook') || placeholder.includes('facebook')) {
            return 'facebook';
        }

        // Name fields (Student Name, Guardian Name, Tutor Name, Admin Name, User Name, Full Name)
        if (
            id.includes('gname') || id.includes('cname') || id.includes('guardian') ||
            id.includes('tutor-name') || id.includes('student-name') || id.includes('user-name') ||
            id.includes('fullname') || id.includes('full-name') || name.includes('name') ||
            cls.includes('ec-cname') || placeholder.includes('name')
        ) {
            return 'name';
        }

        // Phone / Contact
        if (
            type === 'tel' || id.includes('phone') || id.includes('contact') || id.includes('tel') ||
            name.includes('phone') || name.includes('contact') || cls.includes('phone') || cls.includes('contact')
        ) {
            return 'number';
        }

        // Age / Grade / Prices / Rate
        if (
            id.includes('age') || id.includes('grade') || id.includes('price') || id.includes('rate') ||
            id.includes('slots') || id.includes('capacity') || id.includes('hours') ||
            cls.includes('ec-age') || cls.includes('ec-grade') || cls.includes('price') || cls.includes('rate') ||
            name.includes('age') || name.includes('grade') || name.includes('price') || name.includes('rate')
        ) {
            return 'number';
        }

        // Schedule / Timeslot
        if (
            id.includes('schedule') || id.includes('timeslot') || id.includes('time') ||
            cls.includes('schedule') || name.includes('schedule') || placeholder.includes('time slot') || placeholder.includes('schedule')
        ) {
            return 'schedule';
        }

        // Address
        if (
            id.includes('address') || id.includes('addr') || name.includes('address') ||
            placeholder.includes('address')
        ) {
            return 'address';
        }

        // Purok / Sitio (numbers only, max 2 digits)
        if (
            dataVal === 'purok' || id.includes('purok') || cls.includes('purok') ||
            name.includes('purok') || placeholder.includes('purok')
        ) {
            return 'purok';
        }

        // School (Alphabets only)
        if (
            dataVal === 'school' || id.includes('school') || cls.includes('ec-school') ||
            name.includes('school') || placeholder.includes('school')
        ) {
            return 'school';
        }

        return null;
    }

    /**
     * Sanitizes and formats input value according to validation rules.
     */
    function sanitizeInput(el, triggerType) {
        if (!el || el.tagName !== 'INPUT' && el.tagName !== 'TEXTAREA') return;

        // Skip hidden, checkbox, radio, file, date
        const type = (el.type || '').toLowerCase();
        if (type === 'hidden' || type === 'checkbox' || type === 'radio' || type === 'file' || type === 'date') {
            return;
        }

        const vType = getValidationType(el);
        if (!vType) return;

        let val = el.value;
        let original = val;

        switch (vType) {
            case 'name':
                // Name: Letters only (no numbers)
                val = val.replace(/[^A-Za-z\s'-]/g, '');
                // Auto-capitalize first letter of each word
                val = autoCapitalizeWords(val);
                break;

            case 'number':
            case 'age':
            case 'phone':
            case 'grade':
            case 'price':
                // Age / Phone / Child's Grade / Prices: Numbers only
                val = val.replace(/[^\d]/g, '');
                break;

            case 'purok':
                // Purok / Sitio: Numbers only, maximum 2 digits
                val = val.replace(/[^\d]/g, '').slice(0, 2);
                break;

            case 'facebook':
            case 'url':
            case 'link':
                // Facebook / Link: allow letters, digits, spaces, and common URL characters
                // Only strip HTML-injection characters: < > " ' ` \
                val = val.replace(/[<>"'`\\]/g, '');
                break;

            case 'school':
                // School: Alphabets only (letters and spaces)
                val = val.replace(/[^A-Za-z\s]/g, '');
                val = autoCapitalizeWords(val);
                break;

            case 'schedule':
                // Schedules: Letters and numbers allowed
                val = val.replace(/[^A-Za-z0-9\s:-]/g, '');
                break;

            case 'address':
                // Address: Letters and numbers allowed
                val = val.replace(/[^A-Za-z0-9\s,.-]/g, '');
                break;

            case 'email':
                // Email: Letters, numbers, and symbols allowed
                // No restrictive char filtering needed
                break;

            case 'password':
                // Password: Letters, numbers, and symbols allowed
                // No restrictive char filtering needed
                break;
        }

        if (val !== original) {
            const start = el.selectionStart;
            const end = el.selectionEnd;
            el.value = val;
            // Restore selection position if focused
            if (document.activeElement === el && start !== null && end !== null) {
                try {
                    el.setSelectionRange(start, end);
                } catch (e) {
                    // Ignore for input types that don't support setSelectionRange
                }
            }
        }
    }

    // Attach event listeners using event delegation on document
    document.addEventListener('input', function (e) {
        sanitizeInput(e.target, 'input');
    }, true);

    document.addEventListener('blur', function (e) {
        sanitizeInput(e.target, 'blur');
    }, true);

    document.addEventListener('paste', function (e) {
        setTimeout(function () {
            sanitizeInput(e.target, 'paste');
        }, 0);
    }, true);

    // Export helpers globally for programmatic use
    window.EinsteinValidation = {
        sanitizeInput: sanitizeInput,
        autoCapitalizeWords: autoCapitalizeWords,
        getValidationType: getValidationType
    };
})();
