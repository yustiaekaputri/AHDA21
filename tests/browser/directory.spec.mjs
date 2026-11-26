import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { mkdir, readFile } from 'node:fs/promises';
import { randomBytes } from 'node:crypto';

const directory = '/direktori-sekolah/';
const screenshotDir = `${process.env.SDP_ARTIFACT_DIR ?? 'artifacts'}/screenshots`;

async function login(page, user, password) {
    if (!password) throw new Error('Kredensial uji lokal diperlukan.');
    await page.goto('/wp-login.php');
    await page.locator('#user_login').fill(user);
    await page.locator('#user_pass').fill(password);
    await page.locator('#wp-submit').click();
    await expect(page.locator('#loginform')).toHaveCount(0);
}

for (const [name, width, height] of [['mobile', 390, 844], ['tablet', 768, 1024], ['desktop', 1440, 900]]) {
    test(`pratinjau ${name}`, async ({ page }) => {
        await mkdir(screenshotDir, { recursive: true });
        await page.setViewportSize({ width, height });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('console', message => {
            if (message.type() === 'error') errors.push(message.text());
        });
        page.on('response', response => {
            if (response.status() >= 400) errors.push(`${response.status()} ${response.url()}`);
        });
        for (const [slug, title] of [
            ['', 'beranda'], [directory, 'direktori'],
            ['/bandingkan-sekolah/?sdp_banding=DEMO0001,DEMO0002', 'perbandingan'],
            ['/statistik-pendidikan/', 'statistik'], ['/portal-operator/', 'operator'],
        ]) {
            expect((await page.goto(slug || '/')).ok()).toBeTruthy();
            await expect(page.locator('h1')).toHaveCount(1);
            await expect(page.locator('html')).toHaveAttribute('lang', /^(id|en)(-|$)/);
            expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
            await page.screenshot({ path: `${screenshotDir}/${name}-${title}.png` });
        }
        expect(errors).toEqual([]);
    });
}

test('navigasi, filter bertingkat, pencarian, pagination, profil dan perbandingan', async ({ page }) => {
    await page.goto('/');
    const links = await page.locator('nav[aria-label="Navigasi utama"] a').evaluateAll(items => items.map(item => item.href));
    expect(links.length).toBeGreaterThanOrEqual(5);
    for (const url of links) {
        expect((await page.request.get(url)).ok()).toBeTruthy();
    }
    await page.goto(directory);
    await page.locator('[data-sdp-page="2"]').click();
    await expect(page.locator('.sdp-pagination')).toContainText('Halaman 2');
    await page.goto(directory);
    await page.selectOption('[name="sdp_provinsi"]', 'Jawa Barat');
    await expect(page.locator('[name="sdp_kabupaten"] option[value="Kota Bandung"]')).toHaveCount(1);
    await page.selectOption('[name="sdp_kabupaten"]', 'Kota Bandung');
    await expect(page.locator('[name="sdp_kecamatan"] option[value="Coblong"]')).toHaveCount(1);
    await page.selectOption('[name="sdp_kecamatan"]', 'Coblong');
    await page.selectOption('[name="sdp_jenjang"]', 'SD');
    await expect(page.locator('.sdp4-output')).not.toContainText('Sekolah DEMO FIKTIF 02');
    for (const query of ['Sekolah DEMO FIKTIF 01', 'DEMO0001']) {
        await page.goto(directory);
        await page.locator('[name="sdp_q"]').fill(query);
        await page.getByRole('button', { name: 'Cari sekolah' }).click();
        await expect(page.locator('.sdp4-output')).toContainText('Sekolah DEMO FIKTIF 01');
        await expect(page.locator('.sdp4-output')).not.toContainText('Sekolah DEMO FIKTIF 02');
    }
    await page.getByRole('link', { name: 'Sekolah DEMO FIKTIF 01' }).first().click();
    await expect(page.locator('h1')).toContainText('Sekolah DEMO FIKTIF 01');
    await page.screenshot({ path: `${screenshotDir}/desktop-profil.png` });
    await page.goto('/bandingkan-sekolah/?sdp_banding=DEMO0001,DEMO0002');
    await expect(page.locator('table')).toContainText('Sekolah DEMO FIKTIF 02');
    await page.goto('/statistik-pendidikan/');
    await expect(page.getByRole('heading', { name: /^Statistik pendidikan:/ })).toBeVisible();
    await expect(page.getByRole('heading', { name: /^Perbandingan snapshot periode:/ })).toBeVisible();
});

test('login, registrasi dan panel admin', async ({ page }) => {
    await page.goto('/portal-operator/');
    await expect(page.getByRole('heading', { name: 'Portal operator sekolah' })).toBeVisible();
    await page.goto('/wp-login.php?action=register');
    await expect(page.locator('#registerform')).toBeVisible();
    await page.goto('/wp-admin/');
    await expect(page.locator('#loginform')).toBeVisible();
    await page.screenshot({ path: `${screenshotDir}/desktop-login.png` });
    await login(page, 'sdp-admin', process.env.SDP_ADMIN_PASSWORD);
    await expect(page.locator('#wpbody-content h1')).toBeVisible();
    await page.screenshot({ path: `${screenshotDir}/desktop-admin.png` });
    await page.goto('/wp-admin/admin.php?page=sdp-directory');
    await expect(page.locator('#wpbody-content h1')).toContainText('Sekolah Directory Pro');
    await page.screenshot({ path: `${screenshotDir}/desktop-pengaturan.png` });
});

test('aksesibilitas direktori', async ({ page }, testInfo) => {
    await page.goto(directory);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    await testInfo.attach('axe', { body: JSON.stringify(results.violations, null, 2), contentType: 'application/json' });
    expect(results.violations.map(violation => violation.id)).toEqual([]);
});

test('mode gelap bertahan setelah halaman dimuat ulang', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto(directory);
    await page.getByRole('button', { name: 'Aktifkan mode gelap' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-sdt-mode', 'dark');
    await page.reload();
    await expect(page.getByRole('button', { name: 'Aktifkan mode terang' })).toHaveAttribute('aria-pressed', 'true');
    await page.screenshot({ path: `${screenshotDir}/desktop-mode-gelap.png` });
});

test('operator mengajukan klaim dan admin memoderasi perubahan', async ({ browser }) => {
    const operator = await browser.newPage();
    const admin = await browser.newPage();
    await login(operator, 'sdp-operator', process.env.SDP_OPERATOR_PASSWORD);
    await operator.goto(`${directory}?sdp_q=DEMO0001`);
    await operator.getByRole('link', { name: 'Sekolah DEMO FIKTIF 01' }).first().click();
    await expect(operator.getByRole('button', { name: 'Ajukan klaim' })).toHaveCount(0);
    await operator.goto(`${directory}?sdp_q=FIK00001`);
    await operator.getByRole('link', { name: 'Sekolah DEMO 00' }).first().click();
    const profile = operator.url();
    await operator.locator('input[name="role_note"]').fill('Operator DEMO FIKTIF');
    await operator.locator('input[name="evidence"]').fill('https://example.org/demo');
    await operator.getByRole('button', { name: 'Ajukan klaim' }).click();
    await expect(operator.getByText('Pengajuan sedang ditinjau administrator.')).toBeVisible();

    await login(admin, 'sdp-admin', process.env.SDP_ADMIN_PASSWORD);
    await admin.goto('/wp-admin/admin.php?page=sdp-national');
    const claim = admin.locator('form').filter({ has: admin.locator('input[name="npsn"][value="FIK00001"]') });
    await claim.locator('input[name="verified"]').check();
    await claim.locator('button[value="approved"]').click();
    await expect(admin.getByText('FIK00001 — approved')).toBeVisible();

    await operator.goto('/portal-operator/?sdp_operator_npsn=FIK00001');
    await operator.locator('[name="candidate[field]"]').selectOption('jumlah_siswa');
    await operator.locator('[name="candidate[value]"]').fill('1234');
    await operator.locator('[name="candidate[url]"]').fill('https://example.org/demo');
    await operator.locator('[name="candidate[period]"]').fill('2025/2026');
    await operator.screenshot({ path: `${screenshotDir}/desktop-form-operator.png` });
    await operator.getByRole('button', { name: 'Kirim usulan' }).click();
    await expect(operator.getByRole('cell', { name: 'Menunggu' })).toBeVisible();
    await operator.goto(profile);
    await expect(operator.locator('main')).not.toContainText(/1[.,]?234/);

    await admin.goto('/wp-admin/admin.php?page=sdp-operator');
    const proposal = admin.locator('form').filter({ has: admin.locator('button[value="approve"]') });
    await proposal.locator('input[name="confirm"]').check();
    await proposal.locator('button[value="approve"]').click();
    await expect(admin.getByText('Tidak ada usulan tertunda.')).toBeVisible();
    await operator.goto(profile);
    await expect(operator.locator('main')).toContainText(/1[.,]?234/);
});

test('unggah XLSX, unduh CSV, dan impor draf lewat admin', async ({ page }) => {
    await login(page, 'sdp-admin', process.env.SDP_ADMIN_PASSWORD);
    await page.goto('/wp-admin/admin.php?page=sdp-excel');
    await page.locator('input[name="workbook"]').setInputFiles('tests/integration/import-fixture.xlsx');
    await page.locator('input[name="authorized"]').check();
    const [csv] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('button', { name: 'Konversi dan unduh CSV' }).click(),
    ]);
    const content = await readFile(await csv.path(), 'utf8');
    expect(content).toContain('09876543');
    expect(content).toContain('Sekolah FIKTIF XLSX');

    const npsn = `FIK9${randomBytes(2).toString('hex').toUpperCase()}`;
    const fixture = {
        name: 'fixture.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(content.replace('09876543', npsn)),
    };
    for (const result of ['1 profil baru, 0 diperbarui', '0 profil baru, 1 diperbarui']) {
        await page.goto('/wp-admin/admin.php?page=sdp-import');
        await page.locator('input[name="csv"]').setInputFiles(fixture);
        await page.getByRole('button', { name: 'Impor dan buat artikel' }).click();
        await expect(page.locator('#wpbody-content')).toContainText(result);
    }
    const response = await page.request.get(`/wp-json/sdp/v1/schools/${npsn}`);
    expect(response.status()).toBe(404);
});

test('impor XLSX langsung dan perbarui profil dengan TXT bertab', async ({ page }) => {
    await login(page, 'sdp-admin', process.env.SDP_ADMIN_PASSWORD);
    await page.goto('/wp-admin/admin.php?page=sdp-excel');
    await page.locator('input[name="workbook"]').setInputFiles('tests/integration/import-fixture.xlsx');
    await page.locator('input[name="authorized"]').check();
    await page.getByRole('button', { name: 'Impor sebagai draf' }).click();
    await expect(page.locator('#wpbody-content')).toContainText('1 profil baru, 0 diperbarui');

    const uploadText = async (text) => {
        await page.goto('/wp-admin/admin.php?page=sdp-import');
        await page.locator('input[name="csv"]').setInputFiles({
            name: 'sekolah.txt', mimeType: 'text/plain', buffer: Buffer.from(text),
        });
        await page.getByRole('button', { name: 'Impor dan buat artikel' }).click();
    };
    await uploadText('\ufeffnpsn\tnama\r\n09876543\tSekolah FIKTIF dari TXT\r\n');
    await expect(page.locator('#wpbody-content')).toContainText('0 profil baru, 1 diperbarui');
    await uploadText('09876543\n');
    await expect(page.locator('#wpbody-content')).toContainText('Header harus unik dan memuat npsn,nama.');
    await page.goto('/wp-admin/admin.php?page=sdp-edit&npsn=09876543');
    await expect(page.locator('#sdp-nama')).toHaveValue('Sekolah FIKTIF dari TXT');
    expect((await page.request.get('/wp-json/sdp/v1/schools/09876543')).status()).toBe(404);
});
