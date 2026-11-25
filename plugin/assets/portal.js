/* Progressive enhancement: ordinary GET form remains usable without JS/REST. */
(() => {
  'use strict';
  const keys = ['provinsi','kabupaten','kecamatan','jenjang','status','akreditasi'];
  document.querySelectorAll('.sdp4[data-sdp-endpoint]').forEach(root => {
    const endpoint = root.dataset.sdpEndpoint;
    if (!endpoint || !window.fetch || !window.AbortController) return;
    const form = root.querySelector('form'), output = root.querySelector('.sdp4-output');
    const feedback = root.querySelector('.sdp4-feedback'), suggestions = root.querySelector('.sdp4-suggestions');
    const input = form.elements.namedItem('sdp_q');
    let controller, suggestController, sequence = 0, suggestSequence = 0, timer;
    const field = k => form.elements.namedItem('sdp_' + k);
    function params(page = 1, mode = '') {
      const u = new URL(endpoint, window.location.href);
      u.searchParams.set('context', root.dataset.sdpContext);
      u.searchParams.set('sdp_p', String(page));
      if (mode) u.searchParams.set('mode', mode);
      ['q', ...keys, 'fasilitas', 'jurusan'].forEach(k => u.searchParams.set('sdp_' + k, field(k).value));
      return u;
    }
    function ordinaryUrl(page = 1) {
      const u = new URL(form.action, window.location.href);
      new FormData(form).forEach((v,k) => {if (v) u.searchParams.set(k,v); else u.searchParams.delete(k);});
      if (page > 1) u.searchParams.set('sdp_p',String(page));
      return u.href;
    }
    function hideSuggestions() {
      clearTimeout(timer); ++suggestSequence;
      if (suggestController) suggestController.abort();
      suggestions.replaceChildren(); suggestions.hidden = true;
    }
    function syncOptions(data) {
      keys.forEach(k => {
        const el = field(k);
        if (el.tagName !== 'SELECT') return;
        const text = k === 'kabupaten' && !data.state.provinsi ? 'Pilih provinsi dahulu' : k === 'kecamatan' && !data.state.kabupaten ? 'Pilih kabupaten dahulu' : 'Semua';
        el.replaceChildren(new Option(text,''));
        (data.options[k] || []).forEach(v => el.add(new Option(v,v)));
        el.value = data.state[k] || '';
      });
    }
    async function load(page = 1) {
      hideSuggestions();
      if (controller) controller.abort();
      const own = ++sequence;
      controller = new AbortController();
      output.setAttribute('aria-busy','true'); feedback.textContent = 'Memuat hasil pencarian…';
      try {
        const res = await fetch(params(page), {signal:controller.signal,credentials:'same-origin',headers:{Accept:'application/json'}});
        if (!res.ok) throw Error('REST request failed');
        const data = await res.json();
        if (own !== sequence) return;
        if (typeof data.html !== 'string' || !data.options || !data.state) throw Error('Unexpected response');
        syncOptions(data);
        output.innerHTML = data.html;
        root.querySelectorAll('[data-sdp-level]').forEach(a => {
          const u = new URL(ordinaryUrl(),location.href);
          u.searchParams.set('sdp_jenjang',a.dataset.sdpLevel); a.href = u.href;
        });
        const u = new URL(data.url || ordinaryUrl(page),location.href);
        if (u.origin === location.origin && u.href !== location.href) history.pushState({sdpPortal:true},'',u.href);
        feedback.textContent = 'Hasil pencarian diperbarui.';
      } catch (e) {
        if (own !== sequence || e.name === 'AbortError') return;
        feedback.textContent = 'Pencarian interaktif tidak tersedia. Membuka halaman hasil biasa…';
        window.location.assign(ordinaryUrl(page));
      } finally {
        if (own === sequence) output.removeAttribute('aria-busy');
      }
    }
    form.addEventListener('submit', e => {e.preventDefault(); load();});
    keys.forEach(k => {
      const el = field(k);
      if (el.tagName !== 'SELECT') return;
      el.addEventListener('change', () => {
        const clear = k === 'provinsi' ? ['kabupaten','kecamatan'] : k === 'kabupaten' ? ['kecamatan'] : [];
        clear.forEach(child => {const c=field(child); if(c.tagName==='SELECT') c.replaceChildren(new Option('Semua',''));});
        if(root.dataset.sdpLow!=='1'||k==='provinsi'||k==='kabupaten')load();
      });
    });
    field('fasilitas').addEventListener('change', () => {if(root.dataset.sdpLow!=='1')load();});
    root.addEventListener('click', e => {
      const a = e.target.closest('a[data-sdp-page],a[data-sdp-level],a.sdp4-reset');
      if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;
      e.preventDefault();
      if(a.classList.contains('sdp4-reset')) {input.value='';field('fasilitas').value='';field('jurusan').value='';keys.forEach(k=>{if(field(k).tagName==='SELECT')field(k).value='';});load();}
      else if(a.dataset.sdpLevel) {const el=field('jenjang');if(el.tagName==='SELECT'){if(!Array.from(el.options).some(o=>o.value===a.dataset.sdpLevel))el.add(new Option(a.dataset.sdpLevel,a.dataset.sdpLevel));el.value=a.dataset.sdpLevel;load();}}
      else load(Number(a.dataset.sdpPage)||1);
    });
    input.addEventListener('input', () => {
      hideSuggestions();
      if(root.dataset.sdpLow==='1'||input.value.trim().length<2)return;
      const own = suggestSequence;
      timer=setTimeout(async()=>{
        suggestController=new AbortController();
        try{
          const res=await fetch(params(1,'suggest'),{signal:suggestController.signal,credentials:'same-origin',headers:{Accept:'application/json'}});
          if(!res.ok)return;const data=await res.json();if(own!==suggestSequence)return;
          (data.items||[]).forEach(item=>{
            const url=new URL(item.url,location.href);if(url.origin!==location.origin)return;
            const a=document.createElement('a');a.href=url.href;
            const strong=document.createElement('strong');strong.textContent=item.name;
            const small=document.createElement('small');small.textContent=[item.npsn,item.region].filter(Boolean).join(' · ');
            a.append(strong,small);suggestions.append(a);
          });suggestions.hidden=!suggestions.children.length;
        }catch(e){/* suggestions optional; submit remains functional */}
      },350);
    });
    root.addEventListener('keydown',e=>{if(e.key==='Escape')hideSuggestions();});
    root.addEventListener('focusout',e=>{if(!root.contains(e.relatedTarget))hideSuggestions();});
    window.addEventListener('popstate',()=>window.location.reload());
  });
})();
