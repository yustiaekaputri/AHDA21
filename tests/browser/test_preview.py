"""Actual browser screenshots and initial functional checks against disposable WordPress."""
import json
import os
from pathlib import Path
from urllib.parse import urlencode, urlsplit, parse_qsl, urlunsplit
from playwright.sync_api import sync_playwright

manifest = json.loads(Path(os.environ["SDP_PREVIEW_MANIFEST"]).read_text())
out = Path(os.environ.get("SDP_SCREENSHOTS", "artifacts/screenshots"))
out.mkdir(parents=True, exist_ok=True)
pages = {"home": manifest["home"], **manifest["pages"], "profile": manifest["profiles"]["TEST0001"]}
for url in pages.values():
    p = urlsplit(url)
    assert p.scheme == "http" and p.hostname == "127.0.0.1", "Loopback only"
def query(url, **kwargs):
    p = urlsplit(url)
    return urlunsplit(p._replace(query=urlencode(dict(parse_qsl(p.query)) | kwargs)))

with sync_playwright() as p:
    browser = p.chromium.launch()
    errors = []
    for label, width, height in [("mobile",390,844),("tablet",768,1024),("desktop",1440,900)]:
        page = browser.new_page(viewport={"width":width,"height":height})
        page.on("pageerror", lambda e: errors.append(str(e)))
        for key, url in pages.items():
            response = page.goto(url, wait_until="domcontentloaded", timeout=30000)
            assert response and response.ok, f"{key}: HTTP failure"
            page.locator("body").wait_for()
            assert "DEMO FIKTIF" in page.locator("body").inner_text(), f"{key}: missing demo marker"
            page.screenshot(path=str(out/f"{label}-{key}.png"), full_page=True, timeout=30000)
        response = page.goto(query(manifest["pages"]["directory"], sdp_q="TEST0001"), wait_until="domcontentloaded")
        assert response and response.ok
        directory = page.locator(".sdp-directory").first
        assert "SD DEMO FIKTIF" in directory.inner_text()
        assert "SMP DEMO FIKTIF" not in directory.inner_text()
        page.close()
    browser.close()
    assert not errors, errors
print("PASS: 3 viewports, page responses, demo markers, NPSN search and no uncaught JS errors")
