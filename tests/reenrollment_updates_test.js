const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const html=fs.readFileSync('public/user.html','utf8');
for(const m of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi))if(m[1].trim())new vm.Script(m[1]);
function source(name){const a=html.search(new RegExp('        (?:async )?function '+name+'\\('));assert.ok(a>=0);return html.slice(a,html.indexOf('\n        }',a)+10);}
const input={files:[],dataset:{}},message={style:{}},button={style:{}};let sent=[];
const ctx=vm.createContext({Date,FormData,Blob,console:{error(){}},clientProgramSpans:{tutorial:{value:1,unit:'months'}},getProgramKeyFromEnrollment:()=> 'tutorial',allEnrollments:[],document:{getElementById:id=>id==='balance-receipt-file'?input:id==='balance-submit-message'?message:button},isSubmittingReEnroll:false,selectedReEnrollId:1,reEnrollmentDemoPreview:false,balancePaymentMethod:'Walk-in',fetch:async(url,options)=>{sent.push(options.body);return {json:async()=>({success:false,message:'Test response'})}}});
for(const fn of ['getEnrollmentSpanStatus','submitReEnrollmentPayment'])vm.runInContext(source(fn),ctx);
const old={status:'confirmed',program:'Academic Tutorial',child_name:'Test',start_date:'2020-01-01'};
assert.equal(ctx.getEnrollmentSpanStatus(old).isSpanElapsed,true);
ctx.allEnrollments=[{...old,status:'pending'}];let result=ctx.getEnrollmentSpanStatus(old);assert.equal(result.isSpanElapsed,true);assert.equal(result.eligible,false);assert.equal(result.reason,'pending_request');
ctx.allEnrollments=[{...old,status:'pending',notes:JSON.stringify({re_enrollment:{previous_reference:'OLD-REF'}})}];
assert.equal(ctx.getEnrollmentSpanStatus({...old,reference_no:'OLD-REF'}).reason,'pending_request');
assert.equal(ctx.getEnrollmentSpanStatus({...old,reference_no:'NEW-REF',start_date:'2099-01-01'}).reason,'active_span');
ctx.allEnrollments=[];assert.equal(ctx.getEnrollmentSpanStatus({...old,start_date:'2099-01-01'}).isSpanElapsed,false);
(async()=>{await ctx.submitReEnrollmentPayment();assert.equal(sent.length,1);assert.equal(sent[0].get('payment_method'),'Walk-in');assert.equal(sent[0].has('receipt'),false);ctx.balancePaymentMethod='GCash';await ctx.submitReEnrollmentPayment();assert.equal(sent.length,1);input.files=[new Blob(['test'],{type:'image/png'})];input.dataset.receiptValidationState='valid';await ctx.submitReEnrollmentPayment();assert.equal(sent.length,2);assert.ok(sent[1].has('receipt'));console.log('PASS: expiry with pending renewal, active period, Walk-in without receipt, online receipt requirement, script syntax.');})().catch(e=>{console.error(e);process.exitCode=1;});
