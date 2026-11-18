const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const html=fs.readFileSync('public/user.html','utf8');
function source(n){const a=html.indexOf('        function '+n+'(');return html.slice(a,html.indexOf('\n        }',a)+10);}
const target={innerHTML:''};const ctx=vm.createContext({allEnrollments:[],document:{getElementById:()=>target},escapeHTML:x=>String(x??''),displayUserProgramName:x=>x,getEnrollmentSpanStatus:()=>({isSpanElapsed:false,eligible:false,reason:'active_span'})});
for(const n of ['userReEnrollmentInfo','visibleUserEnrollments','renderEnrollments'])vm.runInContext(source(n),ctx);
const old={id:1,reference_no:'OLD',program:'Tutorial',child_name:'Aww',status:'confirmed',created_at:'2026-10-21'};
const fresh={...old,id:2,reference_no:'NEW',status:'pending',created_at:'2026-11-18',notes:JSON.stringify({re_enrollment:{previous_reference:'OLD'}})};
const separate={...old,id:3,reference_no:'OTHER',child_name:'Tiya'};
ctx.allEnrollments=[fresh,separate,old];
assert.equal(ctx.visibleUserEnrollments(ctx.allEnrollments).length,2);
ctx.renderEnrollments([fresh,old]);assert.equal((target.innerHTML.match(/class="enrollment-card"/g)||[]).length,1);assert.ok(target.innerHTML.includes('Re-enrollment Pending'));assert.ok(target.innerHTML.includes('Date Re-enrolled'));assert.ok(target.innerHTML.includes('November 18, 2026'));assert.ok(!target.innerHTML.includes('Pending Review'));
assert.equal(ctx.visibleUserEnrollments([old]).length,0,'Status filters cannot bring the replaced card back');
fresh.status='cancelled';assert.equal(ctx.visibleUserEnrollments([old]).length,1,'Rejected request restores original');
fresh.status='confirmed';assert.equal(ctx.visibleUserEnrollments([old]).length,0,'Approved renewal still replaces original');
ctx.renderEnrollments([{...fresh,status:'pending',notes:''}]);assert.ok(target.innerHTML.includes('Pending Review'));assert.ok(target.innerHTML.includes('Date Enrolled'));
console.log('PASS: one renewal card, correct pending label/date, first enrollment label, filters, approval/rejection, separate students preserved.');
