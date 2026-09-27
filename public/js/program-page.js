// Program pages (/workshop, /playschool, ...): tab deep links and the close button.
(function () {
    // Tabs. The open tab is kept in the URL hash (/workshop#rates), so a tab can be linked
    // to directly. The first tab is the default and has no hash. Switching tabs replaces the
    // history entry instead of adding one, so Back leaves the page instead of stepping
    // through tabs.
    const buttons = Array.from(document.querySelectorAll('.tab-btn[data-tab]'));
    if (buttons.length) {
        const defaultTab = buttons[0].dataset.tab;

        // Unknown hashes fall back to the first tab.
        const showTab = tabId => {
            const btn = buttons.find(b => b.dataset.tab === tabId) || buttons[0];
            buttons.forEach(b => b.classList.toggle('active', b === btn));
            document.querySelectorAll('.tab-content').forEach(el => {
                el.classList.toggle('active', el.id === 'tab-' + btn.dataset.tab);
            });
        };
        const tabFromUrl = () => location.hash.slice(1) || defaultTab;

        buttons.forEach(btn => {
            btn.addEventListener('click', () => {
                const tabId = btn.dataset.tab;
                history.replaceState(history.state, '', tabId === defaultTab ? location.pathname + location.search : '#' + tabId);
                showTab(tabId);
            });
        });

        // Hashes typed into the address bar.
        window.addEventListener('hashchange', () => showTab(tabFromUrl()));

        showTab(tabFromUrl());
    }

    // Close (X). Coming from the homepage, go back to it, which returns to where it was
    // scrolled. Otherwise (opened from a link or a new tab) the href opens /programs.
    const HOME_ROOT = new URL('./', document.baseURI).pathname;
    function cameFromHomepage() {
        if (history.length < 2 || !document.referrer) return false;
        const ref = new URL(document.referrer);
        if (ref.origin !== location.origin || !ref.pathname.startsWith(HOME_ROOT)) return false;
        return /^(|main(\.html)?|programs(\/[a-z]+)?|membership)\/?$/.test(ref.pathname.slice(HOME_ROOT.length));
    }

    const closeBtn = document.querySelector('.close-btn');
    if (closeBtn) {
        closeBtn.addEventListener('click', e => {
            if (!cameFromHomepage()) return;
            e.preventDefault();
            history.back();
        });
    }
})();
