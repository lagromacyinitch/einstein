const fs = require('fs'), vm = require('vm'), assert = require('assert/strict');
const html = fs.readFileSync('public/user.html', 'utf8');
function source(name) {
 const start = html.indexOf('        function ' + name + '(');
 return html.slice(start, html.indexOf('\n        }', start) + 10);
}
const ctx = vm.createContext({getEnrollmentSpanStatus: e => ({isSpanElapsed: e.expired})});
vm.runInContext(source('userEnrollmentMatchesStatus'), ctx);
const records = [
 {status:'confirmed',expired:false},
 {status:'confirmed',expired:true},
 {status:'pending',expired:true},
 {status:'cancelled',expired:false}
];
for (const [filter, expected] of [['confirmed',[0]],['inactive',[1]],['pending',[2]],['all',[0,1,2,3]]]) {
 assert.deepEqual(records.flatMap((e,i)=>ctx.userEnrollmentMatchesStatus(e,filter)?[i]:[]),expected);
}
// Exercise repeated clicks through the actual event handler and rendering filter.
let rendered = [];
const childSelect = {value:''};
const buttons = ['confirmed','inactive','pending'].map(status => ({
 dataset:{status}, classList:{toggle(name, active) { this.active = active; }}
}));
ctx.allEnrollments = records.map((e,i)=>({...e,id:i,child_name:i===1?'Tiya':'Aww'}));
ctx.enrollmentStatusFilter = 'all';
ctx.document = {getElementById:()=>childSelect,querySelectorAll:()=>buttons};
ctx.renderEnrollments = list => { rendered = list.map(e=>e.id); };
vm.runInContext(source('applyEnrollmentFilters'), ctx);
vm.runInContext(source('filterEnrollments'), ctx);
for (const [status,id] of [['confirmed',0],['inactive',1],['pending',2]]) {
 for (let click=0;click<3;click++) {
  ctx.filterEnrollments(status);
  assert.deepEqual(rendered,[id],`${status} must stay selected after repeated clicks`);
  assert.equal(buttons.filter(b=>b.classList.active).length,1);
 }
}
childSelect.value='Tiya';
ctx.applyEnrollmentFilters();
assert.deepEqual(rendered,[], 'Changing child must preserve pending filter');
ctx.filterEnrollments('inactive');
assert.deepEqual(rendered,[1]);
childSelect.value='';
ctx.applyEnrollmentFilters();
assert.deepEqual(rendered,[1], 'All children must preserve inactive filter');
assert.ok(html.includes('onchange="applyEnrollmentFilters()"'));
for (const file of ['public/user.html','public/admin.html']) {
 const text=fs.readFileSync(file,'utf8');
 assert.ok(text.includes('<span style="color:#b91c1c;">NOTE: Updated schedules turn light blue after 3 days.</span>'));
 for (const match of text.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)) {
  if (match[1].trim()) new vm.Script(match[1]);
 }
}
console.log('PASS: active, inactive and pending filters are exclusive; red notes and inline script syntax checked.');
