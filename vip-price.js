window.vipFee = 500;
window.refreshVipFee = async function() {
 const response=await fetch('vip_price.php',{cache:'no-store',credentials:'same-origin'});const data=await response.json();
 if(!response.ok || !data.success) throw Error(data.message || 'Unable to load VIP fee.');
 window.vipFee=Number(data.price);
 document.querySelectorAll('[data-vip-fee]').forEach(el=>el.textContent='₱'+window.vipFee.toLocaleString('en-PH'));
 return window.vipFee;
};
window.refreshVipFee().catch(()=>{});
