"""Capture and verify disposable WordPress UI. No public or authenticated screenshots."""
import json
from pathlib import Path
from urllib.parse import urlsplit, urlencode
from time import perf_counter
from playwright.sync_api import sync_playwright, expect
root=Path(__file__).resolve().parents[1]
urls=json.loads((root/'artifacts/preview-urls.json').read_text())
cases={'home':urls['home'],**urls['pages'],'profil':urls['schools'][0]}
cases['perbandingan']+='?'+urlencode({'sdp_banding':'TEST0001,TEST0002'})
out=root/'artifacts/screenshots'
out.mkdir(parents=True,exist_ok=True)
results=[]
checks=[]
def local(url):
    p=urlsplit(url)
    return p.scheme=='http' and p.netloc=='127.0.0.1:8080'
def check(condition, label):
    if not condition: raise AssertionError(label)
    checks.append(label)
def goto(page,url):
    if not local(url): raise RuntimeError('Unexpected URL')
    start=perf_counter()
    response=page.goto(url,wait_until='domcontentloaded',timeout=30000)
    if not response or not response.ok or not local(page.url): raise RuntimeError('Page navigation failed')
    return response,round((perf_counter()-start)*1000,1)
def restrict(page):
    page.route('**/*',lambda route: route.continue_() if local(route.request.url) else route.abort())
with sync_playwright() as p:
    browser=p.chromium.launch()
    for device,w,h in [('mobile',390,844),('tablet',768,1024),('desktop',1440,900)]:
        page=browser.new_page(viewport={'width':w,'height':h})
        errors=[]
        page.on('pageerror',lambda err: errors.append(str(err)))
        restrict(page)
        for name,url in cases.items():
            errors.clear()
            response,elapsed=goto(page,url)
            body=page.locator('body').inner_text()
            check(bool(body.strip()) and 'critical error on this website' not in body.lower(),f'{device}/{name}: content')
            if name in ('home','direktori'):
                expect(page.locator('.sdp4-card')).to_have_count(3)
            elif name=='perbandingan':
                expect(page.locator('.sdp41-compare')).to_be_visible()
                expect(page.locator('.sdp41-compare thead th')).to_have_count(3)
                expect(page.locator('.sdp41-compare')).to_contain_text('TEST0001')
                expect(page.locator('.sdp41-compare')).to_contain_text('TEST0002')
            elif name=='statistik':
                expect(page.locator('.sdp80')).to_be_visible()
                expect(page.locator('.sdp80')).to_contain_text('3 fiktif, 0 tidak valid')
            elif name=='operator':
                expect(page.locator('.sdp71')).to_contain_text('Login')
                check('no-store' in response.headers.get('cache-control','').lower() or 'no-cache' in response.headers.get('cache-control','').lower(),f'{device}: operator no-cache')
            elif name=='profil':
                expect(page.locator('body')).to_contain_text('TEST0001')
                check('noindex' in (page.locator('meta[name="robots"]').get_attribute('content') or ''),f'{device}: demo noindex')
            path=out/f'{device}-{name}.png'
            page.screenshot(path=str(path),full_page=True)
            results.append({'device':device,'page':name,'status':response.status,'file':path.name,'js_errors':list(errors),'domcontentloaded_ms':elapsed,'html_bytes':len(response.body())})
        page.close()
    # No-JavaScript fallback verifies server-side GET independently of REST.
    page=browser.new_page(java_script_enabled=False)
    restrict(page)
    for label,query in [('NPSN',{'sdp_q':'TEST0002'}),('name',{'sdp_q':'SMP Fiktif DEMO 2'}),('region and level',{'sdp_provinsi':'Provinsi Fiktif','sdp_kabupaten':'Kabupaten Fiktif','sdp_kecamatan':'Kecamatan Fiktif','sdp_jenjang':'SMP'})]:
        goto(page,urls['home']+'?'+urlencode(query))
        expect(page.locator('.sdp4-card')).to_have_count(1)
        expect(page.locator('.sdp4-card')).to_contain_text('TEST0002')
        checks.append('GET search: '+label)
    page.close()
    # Exercise actual interactive controls, not just page navigation.
    page=browser.new_page()
    restrict(page)
    goto(page,urls['home'])
    page.locator('select[name="sdp_provinsi"]').select_option('Provinsi Fiktif')
    expect(page.locator('select[name="sdp_kabupaten"] option[value="Kabupaten Fiktif"]')).to_have_count(1)
    page.locator('select[name="sdp_kabupaten"]').select_option('Kabupaten Fiktif')
    expect(page.locator('select[name="sdp_kecamatan"] option[value="Kecamatan Fiktif"]')).to_have_count(1)
    page.locator('select[name="sdp_kecamatan"]').select_option('Kecamatan Fiktif')
    page.locator('input[name="sdp_q"]').fill('TEST0002')
    page.locator('.sdp4-form button[type="submit"]').click()
    expect(page.locator('.sdp4-card')).to_have_count(1)
    expect(page.locator('.sdp4-card')).to_contain_text('TEST0002')
    expect(page.locator('.sdp4-feedback')).to_contain_text('Hasil pencarian diperbarui')
    checks.append('Interactive search and cascading facets via REST')
    page.close()
    # Admin credential is random and local. Do not persist cookies, screenshots, or traces.
    page=browser.new_page()
    restrict(page)
    goto(page,urls['home']+'wp-login.php')
    page.locator('#user_login').fill('sdp_local_admin')
    page.locator('#user_pass').fill((root/'.env.sdp-admin-password').read_text().strip())
    page.locator('#wp-submit').click()
    expect(page.locator('#wpadminbar')).to_be_visible()
    goto(page,urls['home']+'wp-admin/admin.php?page=sdp-directory')
    expect(page.locator('h1')).to_contain_text('Sekolah Directory Pro')
    checks.append('Authenticated admin dashboard')
    goto(page,urls['pages']['operator'])
    expect(page.locator('.sdp71')).to_contain_text('Klaim saya')
    checks.append('Authenticated operator portal renders')
    page.close()
    browser.close()
(root/'artifacts/preview-results.json').write_text(json.dumps(results,indent=2))
(root/'artifacts/browser-checks.json').write_text(json.dumps(checks,indent=2))
if any(r['js_errors'] for r in results): raise SystemExit('JavaScript errors; inspect preview-results.json')
