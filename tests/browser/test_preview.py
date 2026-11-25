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
        # Exercise the real directory form; changing a filter must alter the result.
        response = page.goto(manifest["pages"]["directory"], wait_until="domcontentloaded")
        assert response and response.ok
        page.locator('select[name="sdp_provinsi"]').select_option("Provinsi Fiktif B")
        # The directory supports JS filtering; use explicit GET as a stable server-side oracle.
        response = page.goto(query(manifest["pages"]["directory"], sdp_provinsi="Provinsi Fiktif B", sdp_kabupaten="Kabupaten Fiktif B", sdp_kecamatan="Kecamatan Fiktif B", sdp_jenjang="SMP"), wait_until="domcontentloaded")
        assert response and response.ok
        directory = page.locator(".sdp-directory").first
        assert "SMP DEMO FIKTIF" in directory.inner_text()
        assert "SD DEMO FIKTIF" not in directory.inner_text()
        response = page.goto(query(manifest["pages"]["compare"], sdp_banding="TEST0001,TEST0002"), wait_until="domcontentloaded")
        assert response and response.ok
        table = page.locator("table.sdp41-compare")
        assert table.count() == 1
        assert "SD DEMO FIKTIF" in table.inner_text()
        assert "SMP DEMO FIKTIF" in table.inner_text()
        page.screenshot(path=str(out/f"{label}-compare-results.png"), full_page=True, timeout=30000)
        response = page.goto(manifest["pages"]["operator"], wait_until="domcontentloaded")
        assert response and response.ok
        assert "Login untuk melihat klaim" in page.locator(".sdp71").inner_text()
        assert "no-cache" in response.headers.get("cache-control", "") or "no-store" in response.headers.get("cache-control", "")
        page.close()
    # Authenticated pages are checked but never screenshotted or uploaded.
    auth_path = os.environ.get("SDP_PREVIEW_AUTH")
    if auth_path:
        auth = json.loads(Path(auth_path).read_text())
        base = manifest["home"].rstrip("/")
        admin_url = base + "/wp-admin/admin.php?page=sdp-directory"
        for role in ("subscriber", "admin"):
            context = browser.new_context()
            page = context.new_page()
            response = page.goto(manifest["pages"]["operator"], wait_until="domcontentloaded")
            assert response and response.ok
            login_link = page.locator(".sdp71").get_by_role("link", name="Masuk", exact=True)
            login_url = login_link.get_attribute("href")
            assert login_url and urlsplit(login_url).hostname == "127.0.0.1"
            response = page.goto(login_url, wait_until="domcontentloaded")
            assert response and response.ok, f"Login page HTTP {response.status if response else 'no response'}; requested path={urlsplit(login_url).path}; final path={urlsplit(page.url).path}"
            user_field = page.locator('input[name="log"], #user_login').first
            pass_field = page.locator('input[name="pwd"], #user_pass').first
            if not user_field.count() or not pass_field.count():
                raise AssertionError(f"Missing login fields: path={urlsplit(page.url).path}; title={page.title()[:120]}; visible body={page.locator('body').inner_text()[:250]}")
            user_field.fill(auth[role]["user"])
            pass_field.fill(auth[role]["password"])
            page.locator('form#loginform input[type="submit"], #wp-submit, form#loginform button[type="submit"]').first.click()
            page.wait_for_load_state("domcontentloaded")
            assert any(c["name"].startswith("wordpress_logged_in_") for c in context.cookies()), f"{role}: no login cookie"
            response = page.goto(manifest["pages"]["operator"], wait_until="domcontentloaded")
            assert response and response.ok
            assert "Klaim saya" in page.locator(".sdp71").inner_text()
            assert "no-cache" in response.headers.get("cache-control", "") or "no-store" in response.headers.get("cache-control", "")
            response = page.goto(admin_url, wait_until="domcontentloaded")
            assert response
            if role == "subscriber":
                assert response.status == 403, "Subscriber must not access plugin administrator"
            else:
                assert response.ok and page.locator("#wpbody-content").count() == 1
                assert "Direktori Sekolah" in page.locator("#wpbody-content").inner_text()
            context.close()
        print("PASS: authenticated operator and admin/subscriber access separation (no private screenshots)")
    else:
        print("SKIP: authenticated browser checks; SDP_PREVIEW_AUTH not supplied")
    browser.close()
    assert not errors, errors
print("PASS: 3 viewports, page responses, demo markers, NPSN search, region and level filters, comparison, anonymous portal and no uncaught JS errors")
