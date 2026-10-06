(function () {
    'use strict';

    const OVERLAY_ID = 'logout-confirm-overlay';
    let pendingResolve = null;
    let previouslyFocused = null;
    let previousBodyOverflow = '';

    function ensureModal() {
        let overlay = document.getElementById(OVERLAY_ID);
        if (overlay) return overlay;

        const style = document.createElement('style');
        style.id = 'logout-confirm-styles';
        style.textContent = `
            .logout-confirm-overlay {
                position: fixed;
                inset: 0;
                z-index: 10050;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                box-sizing: border-box;
                background: rgba(25, 12, 5, .62);
                opacity: 0;
                visibility: hidden;
                transition: opacity .18s ease, visibility .18s ease;
            }
            .logout-confirm-overlay.is-open {
                opacity: 1;
                visibility: visible;
            }
            .logout-confirm-dialog {
                position: relative;
                width: min(100%, 360px);
                box-sizing: border-box;
                padding: 22px 22px 20px;
                border: 1px solid rgba(166, 119, 72, .3);
                border-radius: 18px;
                background: #fffaf5;
                color: #3d1f0a;
                box-shadow: 0 22px 70px rgba(25, 12, 5, .3);
                text-align: center;
                transform: translateY(8px) scale(.98);
                transition: transform .18s ease;
                outline: none;
            }
            .logout-confirm-overlay.is-open .logout-confirm-dialog {
                transform: translateY(0) scale(1);
            }
            .logout-confirm-close {
                position: absolute;
                top: 10px;
                right: 12px;
                width: 38px;
                height: 38px;
                border: 0;
                border-radius: 50%;
                background: transparent;
                color: #8b6f47;
                font-size: 25px;
                line-height: 1;
                cursor: pointer;
            }
            .logout-confirm-close:hover,
            .logout-confirm-close:focus-visible {
                background: #f1e4d7;
                outline: none;
            }
            .logout-confirm-title {
                margin: 0;
                color: #5e3a21;
                font-family: 'Playfair Display', Georgia, serif;
                font-size: 1.2rem;
                line-height: 1.25;
            }
            .logout-confirm-message {
                margin: 8px 0 18px;
                color: #765b45;
                font-size: .88rem;
                line-height: 1.5;
            }
            .logout-confirm-actions {
                display: flex;
                justify-content: center;
                gap: 10px;
            }
            .logout-confirm-actions button {
                min-width: 94px;
                min-height: 38px;
                padding: 7px 14px;
                border-radius: 9px;
                font: inherit;
                font-size: .82rem;
                font-weight: 700;
                cursor: pointer;
            }
            .logout-confirm-cancel {
                border: 1px solid #caa984;
                background: #fff;
                color: #6d4c33;
            }
            .logout-confirm-cancel:hover,
            .logout-confirm-cancel:focus-visible {
                background: #f8efe7;
                outline: 2px solid rgba(139, 111, 71, .35);
                outline-offset: 2px;
            }
            .logout-confirm-submit {
                border: 1px solid #6d3f1e;
                background: #6d3f1e;
                color: #fff;
            }
            .logout-confirm-submit:hover,
            .logout-confirm-submit:focus-visible {
                background: #512d16;
                outline: 2px solid rgba(109, 63, 30, .3);
                outline-offset: 2px;
            }
            @media (max-width: 420px) {
                .logout-confirm-dialog { padding: 20px 18px 18px; }
                .logout-confirm-actions { flex-direction: column-reverse; }
                .logout-confirm-actions button { width: 100%; }
            }
        `;
        document.head.appendChild(style);

        overlay = document.createElement('div');
        overlay.id = OVERLAY_ID;
        overlay.className = 'logout-confirm-overlay';
        overlay.hidden = true;
        overlay.innerHTML = `
            <div class="logout-confirm-dialog" role="dialog" aria-modal="true"
                 aria-labelledby="logout-confirm-title" aria-describedby="logout-confirm-message" tabindex="-1">
                <button type="button" class="logout-confirm-close" data-logout-cancel aria-label="Close logout confirmation">&times;</button>
                <h2 class="logout-confirm-title" id="logout-confirm-title">Logout Confirmation</h2>
                <p class="logout-confirm-message" id="logout-confirm-message">Are you sure you want to log out?</p>
                <div class="logout-confirm-actions">
                    <button type="button" class="logout-confirm-cancel" data-logout-cancel>Cancel</button>
                    <button type="button" class="logout-confirm-submit" data-logout-confirm>Log out</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        overlay.addEventListener('click', function (event) {
            const target = event.target;
            if (!(target instanceof Element)) return;
            if (target === overlay || target.closest('[data-logout-cancel]')) {
                finish(false);
            } else if (target.closest('[data-logout-confirm]')) {
                finish(true);
            }
        });

        return overlay;
    }

    function finish(result) {
        const overlay = document.getElementById(OVERLAY_ID);
        if (!overlay || !pendingResolve) return;

        const resolve = pendingResolve;
        pendingResolve = null;
        overlay.classList.remove('is-open');
        overlay.hidden = true;
        document.body.style.overflow = previousBodyOverflow;
        resolve(result);

        if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
            previouslyFocused.focus({ preventScroll: true });
        }
        previouslyFocused = null;
    }

    document.addEventListener('keydown', function (event) {
        const overlay = document.getElementById(OVERLAY_ID);
        if (!overlay || overlay.hidden || !pendingResolve) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            finish(false);
            return;
        }

        if (event.key !== 'Tab') return;
        const focusables = Array.from(overlay.querySelectorAll('button:not([disabled])'));
        if (!focusables.length) return;
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    window.showLogoutConfirmation = function () {
        const overlay = ensureModal();
        if (pendingResolve) finish(false);

        previouslyFocused = document.activeElement;
        previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        overlay.hidden = false;
        requestAnimationFrame(function () {
            overlay.classList.add('is-open');
            overlay.querySelector('.logout-confirm-cancel')?.focus();
        });

        return new Promise(function (resolve) {
            pendingResolve = resolve;
        });
    };
})();
