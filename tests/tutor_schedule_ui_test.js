const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const html = fs.readFileSync('public/admin.html','utf8');
function source(name) {
    const pattern = new RegExp('    (?:async )?function ' + name + '\\(');
    const start = html.search(pattern);
    assert.ok(start >= 0, name);
    return html.slice(start, html.indexOf('\n    }', start) + 6);
}
(async () => {
    let accepted = false, requests = [], dialog, reloads=0;
    const body = {innerHTML:''};
    const context = vm.createContext({
        document:{getElementById:()=>body},
        escHtml: value => String(value), formatTimeslotDigits: value => value,
        showTutorAvailabilityMenu: async (anchor,available) => {dialog={confirmText:available ? 'Mark Available' : 'Unmark Available'};return accepted;},
        showToast(){}, loadSubadminSchedule:async()=>{reloads++;},
        fetch:async(url,options)=>{requests.push(JSON.parse(options.body));return {ok:true,json:async()=>({success:true})};}
    });
    vm.runInContext(`let tutorAvailabilityBusy=false; let subadminSchedules=[{id:10,timeslot:'8:00 AM - 9:00 AM',slot_type:'CB',mon:'Tiya',tue:'Available',wed:'James',updated_days:{mon:true}}];`,context);
    for(const name of ['subadminCellBg','subadminCellColor','renderSubadminSchedule','toggleTutorAvailability']) vm.runInContext(source(name),context);
    context.renderSubadminSchedule();
    assert.ok(body.innerHTML.includes('#D4EDDA'),'Updated classes are green');
    assert.ok(body.innerHTML.includes('#DCEEFF'),'Normal classes are blue');
    assert.ok(body.innerHTML.includes('Mark available: thu'),'Empty slot is clickable');
    assert.ok(body.innerHTML.includes('Unmark available: tue'),'Available slot can be unmarked');
    assert.ok(!body.innerHTML.includes("toggleTutorAvailability(0, 'mon')"),'Booked slots are not clickable');
    await context.toggleTutorAvailability(0,'thu');
    assert.equal(dialog.confirmText,'Mark Available');
    assert.equal(requests.length,0,'Cancel sends no mutation');
    accepted=true;
    await context.toggleTutorAvailability(0,'thu');
    assert.deepEqual(requests[0],{action:'set_availability',id:'10',day:'thu',available:true});
    await context.toggleTutorAvailability(0,'tue');
    assert.equal(dialog.confirmText,'Unmark Available');
    assert.equal(requests[1].available,false);
    assert.equal(reloads,2,'Refresh after each save');
    await context.toggleTutorAvailability(0,'mon');
    assert.equal(requests.length,2,'Booked slot cannot submit');
    console.log('PASS: tutor cell colors, mark/unmark popup, cancel, request payloads, reload and booked-cell protection.');
})().catch(error=>{console.error(error);process.exitCode=1;});
