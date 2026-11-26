(function(){
var card=document.querySelector('[data-order]');if(!card)return;
var no=card.dataset.order,st=card.dataset.status,exp=card.dataset.expires?new Date(card.dataset.expires):null;
var t=document.getElementById('timer');
function tick(){if(!t||!exp)return;var s=Math.max(0,Math.floor((exp-Date.now())/1000));t.textContent=s?'· expires in '+Math.floor(s/60)+':'+String(s%60).padStart(2,'0'):'· expired';}
setInterval(tick,1000);tick();
var ua=navigator.userAgent,android=/Android/i.test(ua);
document.querySelectorAll('.app-btn').forEach(function(a){if(android&&a.dataset.android)a.href=a.dataset.android;});
document.querySelectorAll('[data-copy]').forEach(function(b){b.addEventListener('click',function(){navigator.clipboard&&navigator.clipboard.writeText(b.dataset.copy);b.textContent='Copied';});});
if(st==='pending'||st==='review'){var n=0;(function poll(){fetch('/pay/'+no+'/status',{headers:{accept:'application/json'}}).then(function(r){return r.json();}).then(function(d){var s=(d.data&&d.data.status)||d.status;if(d.redirect){location.href=d.redirect;return;}if(s&&s!==st){location.reload();return;}setTimeout(poll,++n>40?10000:3000);}).catch(function(){setTimeout(poll,8000);});})();}
})();
