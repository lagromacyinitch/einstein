const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const html = fs.readFileSync('public/admin.html', 'utf8');
for (const match of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)) {
    if (match[1].trim()) new vm.Script(match[1]);
}
new vm.Script(fs.readFileSync('public/js/logout-modal.js', 'utf8'));
function source(name) {
    const start = html.indexOf('    async function ' + name + '(');
    assert.ok(start >= 0, name);
    const end = html.indexOf('\n    }', start) + '\n    }'.length;
    return html.slice(start, end);
}
(async () => {
    let requests = 0;
    const ctx = vm.createContext({
        showAppConfirmation: async () => false,
        fetch: async () => { requests++; return {json: async () => ({success:true})}; },
        showToast() {}, loadAnnouncements: async () => {},
    });
    for (const name of ['approveEnrollment','rejectEnrollment','approveParentGroup','rejectParentGroup','deleteAnnouncement']) {
        vm.runInContext(source(name),ctx);
        await ctx[name](name.includes('Group') ? [1,2] : 1);
    }
    assert.equal(requests,0,'Cancel must not send mutations');
    vm.runInContext(source('setStudentArchived'),ctx);
    ctx.enrollments=[{id:1,child_name:'Sherl',program:'Academic Tutorial'}];
    ctx.isHeadAdminUser=true;
    await ctx.setStudentArchived(1,true,{disabled:false});
    await ctx.setStudentArchived(1,false,{disabled:false});
    assert.equal(requests,0,'Cancelling archive or restore must not change student records');
    let resolve;
    ctx.showAppConfirmation = () => new Promise(r => {resolve=r;});
    const deletion = ctx.deleteAnnouncement(1);
    assert.equal(requests,0,'Wait for explicit confirmation');
    resolve(true);
    await deletion;
    assert.equal(requests,1,'Confirm sends one request');
    let focused = false, notice;
    const input = {value:'',style:{},focus(){focused=true;}};
    Object.assign(ctx, {
        _currentParentGroupIds:null, _currentApprovalContext:'', _currentApprovalRecords:[],
        document:{getElementById(id){return id === 'modal-verified-payment-amount' ? input : null;}},
        showNoticeModal: opts => { notice=opts; return new Promise(r=>{resolve=r;}); },
    });
    vm.runInContext(source('approveFromModal'),ctx);
    const validation = ctx.approveFromModal();
    assert.equal(notice.title,'Payment Amount Required');
    assert.equal(focused,false,'Wait until notice closes before focusing amount');
    resolve(true);
    await validation;
    assert.equal(focused,true);
    assert.equal(requests,1,'Invalid amount must not submit');
    vm.runInContext(source('rejectFromModal'),ctx);
    input.value = '500';
    ctx.currentApprovalStudent = {id:1};
    let closed = 0;
    ctx.closeModal = () => { closed++; };
    for (const group of [false,true]) {
        ctx._currentParentGroupIds = group ? [1,2] : [];
        for (const action of ['approve','reject']) {
            const handler = action + 'FromModal';
            const operation = action + (group ? 'ParentGroup' : 'Enrollment');
            for (const result of [undefined,true]) {
                ctx[operation] = () => new Promise(r => { resolve=r; });
                const before = closed;
                const pending = ctx[handler]();
                assert.equal(closed,before,'Review stays open behind confirmation');
                resolve(result);
                await pending;
                assert.equal(closed,before + (result ? 1 : 0),'Only success closes the review; cancel/failure preserves it');
            }
        }
    }
    console.log('PASS: JavaScript syntax, cancel prevents mutations, confirmation waits, payment validation and focus.');
})().catch(error=>{console.error(error);process.exitCode=1;});
