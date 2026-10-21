const fs = require('fs'), vm = require('vm'), assert = require('assert/strict');
const html = fs.readFileSync('public/admin.html','utf8');
function source(name) {
    const start = html.indexOf('    function '+name+'(');
    assert.ok(start >= 0,name);
    return html.slice(start,html.indexOf('\n    }',start)+6);
}
const elements = {};
for (const id of ['vip-member-filter','vip-active-count','vip-pending-count','nb-vip','vip-members-body','pending-vip-body']) elements[id]={value:'active',textContent:'',innerHTML:''};
const ctx = vm.createContext({
    document:{getElementById:id=>elements[id]},
    enrollments:[
        {id:1,user_id:1,program:'VIP Club Membership',status:'confirmed',guardian_name:'Active Parent'},
        {id:2,user_id:2,program:'VIP Club Membership',status:'cancelled',payment_status:'cancelled',guardian_name:'Former Parent'},
        {id:3,user_id:2,program:'Academic Tutorial',status:'confirmed',package_selected:'Regular Package (VIP) – ₱3,135',guardian_name:'Former Parent'},
        {id:4,user_id:4,program:'VIP Club Membership',status:'cancelled',payment_status:'rejected',guardian_name:'Rejected Applicant'},
    ],
    vipAdminData:{members:{1:{active:true},2:{active:false,unsubscribed:true}}},
    getEnrollmentGroupKeyAdmin:e=>e.user_id,
    isStandaloneStudioRentalRecord:()=>false,isStandaloneSummerBlastRegistrationRecord:()=>false,
    escHtml:s=>String(s || ''),fmtDate:()=>'', siteSettings:{vipDiscounts:{madstudio:10}},
});
for (const fn of ['renderVIPPage','isParentConfirmedVip','bmParsePackageFee','manualVipDiscount','manualRateText','manualPackageLabel','applySlotCapacities','updateSlotSaveState','exportVisibleTableCSV']) vm.runInContext(source(fn),ctx);
ctx.renderVIPPage();
assert.ok(elements['vip-members-body'].innerHTML.includes('Active Parent'));
assert.ok(!elements['vip-members-body'].innerHTML.includes('Former Parent'));
elements['vip-member-filter'].value='unsubscribed';ctx.renderVIPPage();
assert.ok(elements['vip-members-body'].innerHTML.includes('Former Parent'));
assert.ok(elements['vip-members-body'].innerHTML.includes('Reactivate VIP'));
assert.ok(!elements['vip-members-body'].innerHTML.includes('Rejected Applicant'));
let opened;
ctx.openParentEnrollmentApproval = (records,context,options) => { opened={context,options}; };
vm.runInContext(source('openVipEnrollmentApproval'),ctx);
ctx.openVipEnrollmentApproval([2],'unsubscribed');
assert.equal(opened.context,'vip-member');
assert.equal(opened.options.membershipStatus,'unsubscribed');
ctx.enrollments.push({id:5,user_id:5,program:'VIP Club Membership',status:'pending'});
ctx.openVipEnrollmentApproval([5]);
assert.equal(opened.context,'vip-pending');
assert.equal(elements['nb-vip'].textContent,1);
elements['vip-member-filter'].value='all';ctx.renderVIPPage();
assert.equal(elements['vip-active-count'].textContent,2);
assert.equal(ctx.isParentConfirmedVip(ctx.enrollments[2]),false);
assert.equal(ctx.bmParsePackageFee(ctx.enrollments[2]),3135);
ctx.vipAdminData.members[2]={active:true};
assert.equal(ctx.bmParsePackageFee(ctx.enrollments[2]),3135,'Reactivation must not reprice historical enrollment');
assert.ok(ctx.manualPackageLabel({name:'Dance',rate:'2000'},'madstudio',false).includes('2,000'));
assert.ok(ctx.manualPackageLabel({name:'Dance',rate:'2000'},'madstudio',true).includes('1,800'));
const input={value:'40',disabled:false,dataset:{savedCapacity:'25'},checkValidity:()=>true};
const button={style:{}};
elements['slot-playschool']=input; elements['slot-save-playschool']=button; elements['slot-available-playschool']={};
ctx.document.activeElement=input;
ctx.document.querySelectorAll=()=>[{dataset:{slot:'playschool'},querySelector:()=>({})}];
ctx.applySlotCapacities({capacities:{playschool:25},filled:{playschool:2},can_edit:true});
assert.equal(input.value,'40','Background refresh preserves capacity being edited');
assert.equal(button.disabled,false);
input.value='0';ctx.updateSlotSaveState('playschool');assert.equal(button.disabled,false);
input.value='bad';ctx.updateSlotSaveState('playschool');assert.equal(button.disabled,true);
let csv;
Object.assign(ctx,{Blob:class {constructor(parts){csv=parts.join('');}},URL:{createObjectURL:()=>'',revokeObjectURL(){}},setTimeout:()=>{}});
ctx.document.body={append(){}};ctx.document.createElement=()=>({click(){},remove(){}});
const headings=['Name','Package','Action'].map((textContent,cellIndex)=>({textContent,cellIndex}));
const row=(name,hidden=false)=>({style:{display:hidden?'none':''},querySelector:()=>null,cells:[{innerText:name},{innerText:'VIP, ₱500'},{innerText:'View'}]});
ctx.exportVisibleTableCSV({querySelectorAll:s=>s==='thead th'?headings:[row('=FORMULA'),row('Hidden',true)]},'test');
assert.ok(csv.includes("'=FORMULA"));assert.ok(csv.includes('"VIP, ₱500"'));assert.ok(!csv.includes('Hidden'));assert.ok(!csv.includes('Action'));
console.log('PASS: VIP filters/reactivation controls, historical prices, new regular rates, capacity typing/refresh, filtered CSV escaping.');
