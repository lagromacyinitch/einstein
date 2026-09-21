// Programs and Rates Management Logic

document.addEventListener('DOMContentLoaded', () => {
    // Load data when on the right page
    if (document.getElementById('page-programs')) {
        loadPrograms();
        loadDynamicTables();
    }
});

async function loadPrograms() {
    try {
        const res = await fetch('api_programs.php?action=list');
        const data = await res.json();
        
        if (data.success) {
            const tbody = document.querySelector('#tbl-programs tbody');
            if (!tbody) return;
            
            if (data.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">No packages found.</td></tr>';
                return;
            }
            
            tbody.innerHTML = data.data.map(p => `
                <tr>
                    <td><strong>${p.program_name}</strong><br><small style="color:#888">${p.package_type}</small></td>
                    <td>${p.package_name}</td>
                    <td>${p.care_duration}</td>
                    <td><span class="amount" style="color:var(--gold)">${p.rate}</span></td>
                    <td>${p.capacity_slots}</td>
                    <td>
                        <button class="btn-ghost" onclick="editProgram(${p.id})" style="padding:4px 8px;font-size:12px;">Edit</button>
                        <button class="btn-ghost" onclick="deleteProgram(${p.id})" style="padding:4px 8px;font-size:12px;color:#c0392b;">Delete</button>
                    </td>
                </tr>
            `).join('');
        }
    } catch (e) {
        console.error("Error loading programs:", e);
    }
}

// Keep a local copy for editing
let allProgramsCache = [];
async function fetchProgramsCache() {
    const res = await fetch('api_programs.php?action=list');
    const data = await res.json();
    if (data.success) allProgramsCache = data.data;
    return allProgramsCache;
}

async function editProgram(id) {
    await fetchProgramsCache();
    const p = allProgramsCache.find(x => x.id == id);
    if (!p) return;
    
    document.getElementById('pm-id').value = p.id;
    document.getElementById('pm-program').value = p.program_name;
    document.getElementById('pm-name').value = p.package_name;
    document.getElementById('pm-duration').value = p.care_duration;
    document.getElementById('pm-rate').value = p.rate;
    document.getElementById('pm-slots').value = p.capacity_slots;
    document.getElementById('pm-type').value = p.package_type;
    
    document.getElementById('pm-title').innerText = 'Edit Package';
    document.getElementById('programModal').style.display = 'flex';
}

async function loadDynamicTables() {
    const progs = await fetchProgramsCache();
    
    // 1. Package Pricing Reference (type: pricing_ref)
    const pricingRefContainer = document.getElementById('dyn-pricing-ref');
    if (pricingRefContainer) {
        const pricingRefs = progs.filter(p => p.package_type === 'pricing_ref');
        let html = '';
        if (pricingRefs.length === 0) {
            html = '<i>No pricing references configured.</i>';
        } else {
            html = pricingRefs.map((p, i) => `
                <div style="background:${i%2==0 ? '#fdf9f1' : '#f5eee6'}; border:1px solid ${i%2==0 ? '#f0e6d2' : '#e8decb'}; border-radius:8px; padding:16px; min-width:200px; position:relative;">
                    <button onclick="editProgram(${p.id})" style="position:absolute; top:4px; right:4px; background:none; border:none; cursor:pointer; color:#8B6914;">✎</button>
                    <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:8px">
                        <strong style="color:#5E3A21">${p.package_name}</strong>
                        <span class="amount" style="font-size:16px; color:${i%2==0 ? 'inherit' : '#a67c00'}">${p.rate}</span>
                    </div>
                    <div style="font-size:12px; color:#8B6914">${p.care_duration}</div>
                </div>
            `).join('');
        }
        html += `<div style="display:flex;align-items:center;padding:16px;"><button class="btn-ghost" onclick="openProgramModal('Academic Tutorial', 'pricing_ref')">+ Add</button></div>`;
        pricingRefContainer.innerHTML = html;
    }
    
    // 2. Available Workshops & Fees (type: workshop)
    const workshopsTbody = document.querySelector('#dyn-workshops tbody');
    if (workshopsTbody) {
        const workshops = progs.filter(p => p.package_type === 'workshop');
        if (workshops.length === 0) {
            workshopsTbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No workshops available.</td></tr>';
        } else {
            workshopsTbody.innerHTML = workshops.map(p => `
                <tr>
                    <td><b>${p.package_name}</b></td>
                    <td>${p.capacity_slots}</td>
                    <td><span class="amount">${p.rate}</span></td>
                    <td>${p.care_duration}</td>
                    <td>
                        <button onclick="editProgram(${p.id})" style="background:none;border:none;color:#8B6914;cursor:pointer;">✎</button>
                    </td>
                </tr>
            `).join('');
        }
        // Add button to header or below table
        const thead = document.querySelector('#dyn-workshops thead tr');
        if (thead && thead.children.length === 4) {
             thead.innerHTML += '<th>Actions <button class="btn-ghost" style="padding:2px 6px;font-size:11px;" onclick="openProgramModal(\'Weekend Workshop\', \'workshop\')">+ Add</button></th>';
        }
    }
    
    // 3. Package Info & Rates (type: general)
    const packagesTbody = document.querySelector('#dyn-packages tbody');
    if (packagesTbody) {
        const packages = progs.filter(p => p.package_type === 'general' && p.program_name === 'Child Care Program');
        if (packages.length === 0) {
            packagesTbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No general packages available.</td></tr>';
        } else {
            packagesTbody.innerHTML = packages.map(p => `
                <tr>
                    <td><b>${p.package_name}</b></td>
                    <td>${p.care_duration}</td>
                    <td><span class="amount">${p.rate}</span></td>
                    <td>${p.capacity_slots}</td>
                    <td>
                        <button onclick="editProgram(${p.id})" style="background:none;border:none;color:#8B6914;cursor:pointer;">✎</button>
                    </td>
                </tr>
            `).join('');
        }
        const thead = document.querySelector('#dyn-packages thead tr');
        if (thead && thead.children.length === 4) {
             thead.innerHTML += '<th>Actions <button class="btn-ghost" style="padding:2px 6px;font-size:11px;" onclick="openProgramModal(\'Child Care Program\', \'general\')">+ Add</button></th>';
        }
    }
    
    // 4. Playschool Info & Rates (type: general, program: Playschool)
    const playschoolTbody = document.querySelector('#dyn-playschool tbody');
    if (playschoolTbody) {
        const packages = progs.filter(p => p.package_type === 'playschool' || (p.package_type === 'general' && /^play\s*school$/i.test(p.program_name)));
        if (packages.length === 0) {
            playschoolTbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No playschool packages available.</td></tr>';
        } else {
            playschoolTbody.innerHTML = packages.map(p => `
                <tr>
                    <td><b>${p.package_name}</b></td>
                    <td>${p.care_duration}</td>
                    <td><span class="amount">${p.rate}</span></td>
                    <td>${p.capacity_slots}</td>
                    <td>
                        <button onclick="editProgram(${p.id})" style="background:none;border:none;color:#8B6914;cursor:pointer;">✎ Edit</button>
                        <button onclick="deleteProgram(${p.id})" style="background:none;border:none;color:#c0392b;cursor:pointer;margin-left:8px;">&times;</button>
                    </td>
                </tr>
            `).join('');
        }
    }
}

// Modify openProgramModal to accept defaults
function openProgramModal(programName = 'Playschool', packageType = 'playschool') {
    document.getElementById('pm-id').value = '';
    document.getElementById('pm-program').value = programName;
    document.getElementById('pm-name').value = '';
    document.getElementById('pm-duration').value = '';
    document.getElementById('pm-rate').value = '';
    document.getElementById('pm-slots').value = '';
    document.getElementById('pm-type').value = packageType;
    
    document.getElementById('pm-title').innerText = 'Add Package';
    document.getElementById('programModal').style.display = 'flex';
}
