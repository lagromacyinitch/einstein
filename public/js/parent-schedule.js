function renderParentSchedule() {
    const body = document.getElementById('parent-schedule-body');
    if (!body) return;
    const rows = window.parentSchedules || [];
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="8" style="padding:32px;color:#8D6A4E">No assigned classes yet. Your children’s schedules will appear here once assigned by the center.</td></tr>';
        return;
    }
    body.innerHTML = rows.map(row => `<tr>
            <td class="schedule-time">${escapeHTML(row.timeslot)}</td>
            ${['mon','tue','wed','thu','fri','sat','sun'].map(day => `<td class="${row.days?.[day] ? (row.updated_days?.[day] ? 'schedule-updated' : 'schedule-normal') : 'schedule-empty'}"${row.days?.[day] ? ` title="${row.updated_days?.[day] ? 'Updated Schedule' : 'Normal Schedule'}"` : ''}>${row.days?.[day] ? `<strong>${escapeHTML(row.child_name)}</strong><small>${escapeHTML(row.program)} · <span class="schedule-type" title="${row.slot_type === 'HB' ? 'Home Based' : 'Center Based'}">${row.slot_type === 'HB' ? 'HB' : 'CB'}</span></small><small>Tutor: ${escapeHTML(row.tutor_name || 'To be assigned')}</small>` : ''}</td>`).join('')}
        </tr>`).join('');
}
