// Program page tabs. The open tab is kept in the URL hash (/workshop#rates), so a tab
// can be linked to directly and Back/Forward move between tabs. The first tab is the
// default and has no hash.
(function () {
    const buttons = Array.from(document.querySelectorAll('.tab-btn[data-tab]'));
    if (!buttons.length) return;
    const defaultTab = buttons[0].dataset.tab;

    // Unknown hashes fall back to the first tab.
    function showTab(tabId) {
        const btn = buttons.find(b => b.dataset.tab === tabId) || buttons[0];
        buttons.forEach(b => b.classList.toggle('active', b === btn));
        document.querySelectorAll('.tab-content').forEach(el => {
            el.classList.toggle('active', el.id === 'tab-' + btn.dataset.tab);
        });
    }

    function tabFromUrl() {
        return location.hash.slice(1) || defaultTab;
    }

    buttons.forEach(btn => {
        btn.addEventListener('click', () => {
            const tabId = btn.dataset.tab;
            if (tabId !== tabFromUrl()) {
                history.pushState(null, '', tabId === defaultTab ? location.pathname + location.search : '#' + tabId);
            }
            showTab(tabId);
        });
    });

    // Back/Forward, and hashes typed into the address bar.
    window.addEventListener('popstate', () => showTab(tabFromUrl()));

    showTab(tabFromUrl());
})();
