const fs = require('fs'), vm = require('vm'), assert = require('assert/strict');
const html = fs.readFileSync('public/admin.html', 'utf8');
function source(name) {
  const start = html.search(new RegExp('    (?:async )?function ' + name + '\\('));
  assert.ok(start >= 0);
  return html.slice(start, html.indexOf('\n    }', start) + 6);
}
(async () => {
  const elements = {'tutor-schedule-timeslot':{value:'7:00 AM - 8:00 AM'}, 'tutor-schedule-slot-type':{value:'CB'}, 'tutor-schedule-save':{disabled:false}};
  const requests = []; let accepted = false, syncs = 0, reloads = 0;
  const context = vm.createContext({document:{getElementById:id=>elements[id]}, showToast(){}, closeModal(){}, renderSubadminSchedule(){}, notifyScheduleChange(){syncs++;}, loadSubadminSchedule:async()=>{reloads++;}, showAppConfirmation:async()=>accepted, fetch:async(url, options)=>{requests.push(JSON.parse(options.body));return {ok:true,json:async()=>({success:true})};}});
  vm.runInContext("let tutorAvailabilityBusy=false; let subadminSchedules=[{id:1,timeslot:'7:00 AM - 8:00 AM',slot_type:'CB',mon:'Available'},{id:2,mon:'Student'}];", context);
  for (const name of ['saveTutorScheduleRow','deleteTutorScheduleRow']) vm.runInContext(source(name),context);
  await context.saveTutorScheduleRow();
  assert.deepEqual(requests[0], {action:'create_mine',timeslot:'7:00 AM - 8:00 AM',slot_type:'CB'});
  assert.equal(elements['tutor-schedule-save'].disabled,false);
  await context.deleteTutorScheduleRow(0);
  assert.equal(requests.length,1,'Cancel does not delete');
  accepted=true;
  await context.deleteTutorScheduleRow(1);
  assert.equal(requests.length,1,'Assigned row cannot submit deletion');
  await context.deleteTutorScheduleRow(0);
  assert.deepEqual(requests[1],{action:'delete_mine',id:'1'});
  assert.equal(syncs,2); assert.equal(reloads,2);
  const modal = html.slice(html.indexOf('<div class="modal-overlay" id="modal-add-tutor-schedule">'),html.indexOf('<!--',html.indexOf('<div class="modal-overlay" id="modal-add-tutor-schedule">')));
  assert.ok(!modal.includes('schedule-tutor-input'));
  assert.ok(modal.includes('modal-header') && modal.includes('modal-footer'));
  assert.ok(!html.includes('</span>N/A</span>'));
  console.log('PASS: tutor modal, create payload, cancellation, assigned-row protection, delete payload, refresh and sync signal.');
})().catch(error=>{console.error(error);process.exitCode=1;});
