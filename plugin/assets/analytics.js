/* Browser-local selection; never treats favorite/compare IDs as access grants. */
(() => {
  'use strict';
  const valid = v => typeof v === 'string' && /^[A-Z0-9]{8}$/.test(v);
  const keys = {compare:'sdp41_compare',fav:'sdp41_favorites'};
  const limit = {compare:4,fav:20};
  const state = {};
  for (const type of Object.keys(keys)) {
    try { const raw=JSON.parse(localStorage.getItem(keys[type])||'[]');state[type]=Array.isArray(raw)?[...new Set(raw.filter(valid))].slice(0,limit[type]):[]; }
    catch { state[type]=[]; }
  }
  function store(type) {try{localStorage.setItem(keys[type],JSON.stringify(state[type]));}catch{/* in-memory fallback */}}
  function sync(message='') {
    const toolbars=[...document.querySelectorAll('[data-sdp41-toolbar]')];
    for(const bar of toolbars){
      bar.hidden=false; const count=bar.querySelector('[data-sdp41-count]');count.textContent=message||`${state.compare.length}/4 dipilih · ${state.fav.length}/20 favorit`;
      for(const [type,selector] of [['compare','[data-sdp41-go]'],['fav','[data-sdp41-favorites]']]){
        const a=bar.querySelector(selector),url=new URL(bar.dataset.url,location.href);
        if(state[type].length)url.searchParams.set('sdp_banding',state[type].join(','));
        if(type==='fav')url.searchParams.set('sdp_favorit','1');a.href=url.href;
      }
    }
    for(const type of Object.keys(keys))document.querySelectorAll(`[data-sdp41-${type}]`).forEach(b=>{
      b.hidden=!toolbars.length;const active=state[type].includes(b.dataset[type==='compare'?'sdp41Compare':'sdp41Fav']);
      b.setAttribute('aria-pressed',String(active));b.textContent=type==='compare'?(active?'Dipilih ✓':'Bandingkan'):(active?'Favorit ✓':'Favorit');
    });
    document.querySelectorAll('.sdp41-print').forEach(b=>b.hidden=false);
  }
  document.addEventListener('click',e=>{
    const b=e.target.closest('[data-sdp41-compare],[data-sdp41-fav],[data-sdp41-clear],.sdp41-print');if(!b)return;
    if(b.classList.contains('sdp41-print')){window.print();return;}
    if(b.hasAttribute('data-sdp41-clear')){state.compare=[];store('compare');sync();return;}
    const type=b.hasAttribute('data-sdp41-compare')?'compare':'fav',id=b.dataset[type==='compare'?'sdp41Compare':'sdp41Fav'];if(!valid(id))return;
    if(state[type].includes(id))state[type]=state[type].filter(x=>x!==id);
    else if(state[type].length>=limit[type]){sync(`Maksimal ${limit[type]} sekolah. Hapus pilihan terlebih dahulu.`);return;}
    else state[type].push(id);store(type);sync();
  });
  sync();
  // The portal replaces result cards after AJAX; reapply selected states.
  document.querySelectorAll('.sdp4-output').forEach(el=>new MutationObserver(records=>{if(records.some(r=>[...r.addedNodes].some(n=>n.nodeType===1&&n.matches('.sdp4-cards,.sdp4-summary'))))sync();}).observe(el,{childList:true}));
})();
