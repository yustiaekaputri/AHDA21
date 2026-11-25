"""Capture anonymous pages from disposable local WordPress, not mockups."""
import json
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
urls=json.loads((root/'artifacts/preview-urls.json').read_text())
cases={'home':urls['home'],**urls['pages'],'profil':urls['schools'][0]}
out=root/'artifacts/screenshots'
out.mkdir(parents=True,exist_ok=True)
results=[]
def local(url):
    p=urlsplit(url)
    return p.scheme=='http' and p.netloc=='127.0.0.1:8080'
with sync_playwright() as p:
    browser=p.chromium.launch()
    for device,w,h in [('mobile',390,844),('tablet',768,1024),('desktop',1440,900)]:
        page=browser.new_page(viewport={'width':w,'height':h})
        errors=[]
        page.on('pageerror',lambda err: errors.append(str(err)))
        page.route('**/*',lambda route: route.continue_() if local(route.request.url) else route.abort())
        for name,url in cases.items():
            if not local(url): raise RuntimeError('Unexpected URL')
            errors.clear()
            response=page.goto(url,wait_until='domcontentloaded',timeout=30000)
            if not response or not response.ok or not local(page.url): raise RuntimeError(f'Failed page {name}')
            body=page.locator('body').inner_text()
            if not body.strip() or 'critical error on this website' in body.lower(): raise RuntimeError(f'Invalid page {name}')
            path=out/f'{device}-{name}.png'
            page.screenshot(path=str(path),full_page=True)
            results.append({'device':device,'page':name,'status':response.status,'file':path.name,'js_errors':list(errors)})
        page.close()
    browser.close()
(root/'artifacts/preview-results.json').write_text(json.dumps(results,indent=2))
if any(r['js_errors'] for r in results): raise SystemExit('JavaScript errors; inspect preview-results.json')
