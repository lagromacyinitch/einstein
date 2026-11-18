const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const html=fs.readFileSync('public/user.html','utf8');
function source(name){const a=html.indexOf('        function '+name+'(');return html.slice(a,html.indexOf('\n        }',a)+10);}
const target={innerHTML:''};
const ctx=vm.createContext({window:{parentEndDateChanges:[{id:42,child_name:'Tiya',program:'Academic Tutorial',previous_override:'2026-11-20',new_override:'2026-11-25',reason:'Make-up classes <test>',changed_at:'2026-11-18'}]},document:{getElementById:()=>target},allEnrollments:[],loadUserNotificationReadKeys:()=>new Set(),timeAgoUser:()=> 'Just now',escapeHTML:s=>String(s).replaceAll('<','&lt;').replaceAll('>','&gt;')});
ctx.updateUserNotificationBadge=()=>{};
vm.runInContext(source('renderUserNotifications'),ctx);
ctx.renderUserNotifications();
assert.ok(target.innerHTML.includes('November 25, 2026'));
assert.ok(target.innerHTML.includes('November 20, 2026'));
assert.ok(target.innerHTML.includes('Make-up classes &lt;test&gt;'));
assert.ok(target.innerHTML.includes('Tiya'));
assert.equal(ctx.userNotificationItems[0].readKey,'end-date:42');
assert.equal(ctx.userNotificationItems[0].unread,true);
ctx.loadUserNotificationReadKeys=()=>new Set(['end-date:42']);
ctx.renderUserNotifications();
assert.equal(ctx.userNotificationItems[0].unread,false);
console.log('PASS: end-date notice dates, reason escaping, parent/student details and read state.');

