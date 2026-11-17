// Program details share the editable copy used by the homepage.
// Numeric prices continue to come from each page's existing rates API.
(function () {
    function setText(element, value) {
        element.textContent = String(value);
        element.style.whiteSpace = 'pre-line';
    }

    function applyProgramContent(content) {
        document.querySelectorAll('[data-homepage-text]').forEach(element => {
            const value = content[element.dataset.homepageText];
            if (typeof value === 'string') setText(element, value);
        });
        document.querySelectorAll('[data-homepage-list]').forEach(element => {
            const items = content[element.dataset.homepageList];
            if (!Array.isArray(items)) return;
            const tags = element.dataset.homepageListTemplate === 'program-tag';
            const children = items.filter(item => typeof item === 'string' && item.trim()).map(item => {
                const child = document.createElement(tags ? 'span' : 'li');
                if (tags) child.className = 'program-tag';
                child.textContent = item.trim();
                return child;
            });
            element.replaceChildren(...children);
        });

        const schedules = document.querySelector('[data-program-school-schedules]');
        if (schedules) {
            const fragment = document.createDocumentFragment();
            for (const name of ['caterpillar', 'butterfly']) {
                const prefix = 'playschool.' + name;
                const heading = document.createElement('h4');
                heading.textContent = content[prefix + '.title'] || name;
                fragment.append(heading);
                for (const key of ['schedule_heading', 'morning', 'afternoon']) {
                    const value = content[prefix + '.' + key];
                    if (typeof value !== 'string') continue;
                    const line = document.createElement('p');
                    setText(line, value);
                    fragment.append(line);
                }
            }
            if (typeof content['playschool.caterpillar.morning'] === 'string') schedules.replaceChildren(fragment);
        }

        const dates = document.querySelector('[data-program-summer-dates]');
        if (dates && typeof content['summer.important_dates.title'] === 'string') {
            const heading = document.createElement('h5');
            heading.textContent = content['summer.important_dates.title'];
            const lines = [heading];
            for (const key of ['classes_start', 'no_classes', 'rehearsal', 'recital']) {
                const prefix = 'summer.important_dates.' + key;
                const line = document.createElement('p');
                const label = document.createElement('strong');
                label.textContent = content[prefix + '_label'] || '';
                line.append(label, ' ' + (content[prefix + '_value'] || ''));
                lines.push(line);
            }
            dates.replaceChildren(...lines);
        }
    }

    window.applyProgramContent = applyProgramContent;
    window.loadProgramContent = async function () {
        try {
            const response = await fetch('api/api_homepage_content.php', {credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok) return;
            const data = await response.json();
            if (data.success && data.content) applyProgramContent(data.content);
        } catch (_) {
            // Keep the page's readable fallback copy when the API is unavailable.
        }
    };
    window.loadProgramContent();
    // Reload saved edits when returning from the settings tab or browser history.
    window.addEventListener('pageshow', event => { if (event.persisted) window.loadProgramContent(); });
    window.addEventListener('focus', () => window.loadProgramContent());
})();
