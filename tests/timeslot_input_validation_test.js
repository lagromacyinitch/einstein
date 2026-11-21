const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const context = vm.createContext({window:{}, document:{addEventListener(){}}, setTimeout:fn=>fn()});
vm.runInContext(fs.readFileSync('public/js/validation.js','utf8'),context);
const validation = context.window.EinsteinValidation;
function field(id, value, rule=null) {
  return {id,value,type:'text',tagName:'INPUT',getAttribute:()=>rule};
}
for (const id of ['schedule-timeslot','tutor-schedule-timeslot']) {
  for (const rule of [null,'schedule']) {
    for (const trigger of ['input','paste','blur']) {
      const value = '7:00 AM - 8:00 PM; 7.00 a.m. to 8.00 p.m. \u2013 \u2014';
      const input = field(id,value,rule);
      validation.sanitizeInput(input,trigger);
      assert.equal(input.value,value,`${id}: ${trigger} preserves time punctuation and letters`);
    }
  }
}
for (const id of ['available-slots','tutor-phone','tutor-rate']) {
  const input = field(id,'12abc:;. -');
  validation.sanitizeInput(input,'input');
  assert.equal(input.value,'12',`${id} remains numeric`);
}
console.log('PASS: timeslot typing, paste and blur preserve punctuation; slot counts, phone and rate remain numeric.');
