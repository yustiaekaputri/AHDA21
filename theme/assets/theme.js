(function(){
  'use strict';
  const root=document.documentElement;
  const button=document.querySelector('[data-sdt-mode]');
  if(!button) return;
  let saved=null;
  try{saved=localStorage.getItem('sdtheme-color');}catch(e){}
  const media=window.matchMedia('(prefers-color-scheme: dark)');
  let explicit=saved==='dark'||saved==='light';
  function apply(mode){
    root.dataset.sdtMode=mode;
    button.setAttribute('aria-pressed',String(mode==='dark'));
    const label=button.querySelector('[data-sdt-mode-label]');
    if(label) label.textContent=mode==='dark'?'Terang':'Gelap';
    button.setAttribute('aria-label',mode==='dark'?'Aktifkan mode terang':'Aktifkan mode gelap');
  }
  apply(explicit?saved:(media.matches?'dark':'light'));
  button.hidden=false;
  button.addEventListener('click',function(){
    const next=root.dataset.sdtMode==='dark'?'light':'dark';
    explicit=true;apply(next);
    try{localStorage.setItem('sdtheme-color',next);}catch(e){}
  });
  const change=function(e){if(!explicit)apply(e.matches?'dark':'light');};
  if(media.addEventListener)media.addEventListener('change',change);else if(media.addListener)media.addListener(change);
})();
