const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const html=fs.readFileSync('public/admin.html','utf8');
function source(name){const a=html.indexOf('    function '+name+'(');assert.ok(a>=0);return html.slice(a,html.indexOf('\n    }',a)+6);}
const ctx=vm.createContext({isHeadAdminUser:true,escHtml:x=>String(x??''),isParentConfirmedVip:()=>false,programDateCells:()=>'<td>Start</td><td>End</td>',programEnrollmentBadge:()=>'<span>Active</span>',isStandaloneSummerBlastRegistrationRecord:e=>!!e.registration,getStudioRentalAdminData:()=>({total:500}),formatStudioRentalDatesAdmin:()=> 'Rental date'});
for(const n of ['programRowAttributes','studentRecordActions','buildProgramRow'])vm.runInContext(source(n),ctx);
for(const [prog,id,label] of [['workshop','workshop','Student Name'],['playschool','playschool','Toddler Name'],['childcare','childcare','Child Name'],['madstudio','mad','Student Name'],['summerblast','summerblast','Student Name'],['studio_rental','mad','Student Name']]){
 const table=html.split('<table id="'+id+'-table">')[1].split('</table>')[0];
 const headers=[...table.split('</thead>')[0].matchAll(/<th\b[^>]*>(.*?)<\/th>/g)].map(m=>m[1]);
 assert.deepEqual(headers,['#',label,'Age','Package',...(prog==='workshop'?['Type']:[]),'VIP','Status','Timeslot','Start Date','End Date','Actions']);
 const e={id:51,status:'confirmed',child_name:'Test Child',child_age:8,package_selected:'Regular (VIP)',session_type:'Group',timeslot:'Monday',guardian_name:'Hidden Guardian',contact:'Hidden Contact'};
 const row=ctx.buildProgramRow(prog,e,1);assert.equal((row.match(/<td\b/g)||[]).length,headers.length);assert.ok(row.includes('student-vip-column'));assert.ok(row.includes('student-view-btn'));assert.ok(row.includes('student-withdraw-btn'));assert.ok(!row.includes('Hidden'));assert.ok(!row.includes('disabled title'));
 e.package_selected='Regular';assert.ok(ctx.buildProgramRow(prog,e,1).includes('class="student-vip-column">\u2014</td>'));
}
const pending=ctx.buildProgramRow('summerblast',{id:52,status:'pending',registration:true},1);assert.equal((pending.match(/<td\b/g)||[]).length,10);assert.ok(pending.includes('openSummerBlastRegistrationReview(52)'));assert.ok(pending.includes('disabled title'));
for(const n of ['academic_tutorial','workshop','playschool','childcare_program','mad','summerblast']){const s=fs.readFileSync('public/'+n+'.html','utf8');assert.equal((s.match(/<button[^>]*class="enroll-btn"/g)||[]).length,1);assert.ok(/onclick="openEnrollmentFlow\('[^']+'\)"/.test(s));}
console.log('PASS: all program column orders, row alignment, VIP/dash, colored actions, rental and registration handling, six restored enrollment buttons.');
