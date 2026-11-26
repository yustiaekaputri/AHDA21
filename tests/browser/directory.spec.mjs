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

test('cari NPSN dari katalog lokal dan simpan hanya setelah ditinjau', async ({ page }) => {
    const npsn = '0' + String(randomBytes(4).readUInt32BE() % 10000000).padStart(7, '0');
    const catalogPage = '/wp-admin/admin.php?page=sdp-npsn-catalog';
    await login(page, 'sdp-admin', process.env.SDP_ADMIN_PASSWORD);
    await page.goto(catalogPage);
    await page.getByLabel('CSV katalog').setInputFiles({
        name: 'katalog-fiktif.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(`npsn,nama,jenjang\n${npsn},Sekolah FIKTIF Katalog,SD\n`),
    });
    await page.getByLabel('Nama sumber').fill('Sumber FIKTIF');
    await page.getByLabel('Periode data').fill('2025/2026');
    await page.getByLabel('Tanggal data').fill('2025-09-01');
    await page.getByLabel('Saya berhak menggunakan dataset ini.').check();
    await page.getByRole('button', { name: 'Simpan katalog' }).click();
    await expect(page.getByText('Rekaman katalog: 1')).toBeVisible();

    await page.getByLabel('NPSN', { exact: true }).fill(npsn);
    await page.getByRole('button', { name: 'Cari NPSN' }).click();
    await expect(page.getByText('Sekolah FIKTIF Katalog', { exact: true })).toBeVisible();
    await expect(page.getByText('Sumber FIKTIF — 2025/2026', { exact: true })).toBeVisible();
    await expect(page.getByText('2025-09-01', { exact: true })).toBeVisible();
    const previewUrl = page.url();

    const stale = await page.context().newPage();
    await stale.goto(previewUrl);
    await stale.getByRole('link', { name: 'Isi profil secara manual' }).click();
    await expect(stale.locator('#sdp-nama')).toHaveValue('');
    await expect(stale.getByRole('link', { name: 'Buka asisten narasi AI' })).toHaveCount(0);
    await stale.goto(previewUrl);

    await page.getByRole('button', { name: 'Simpan draf dari katalog' }).click();
    await expect(page.locator('#sdp-npsn')).toHaveValue(npsn);
    await expect(page.locator('#sdp-nama')).toHaveValue('Sekolah FIKTIF Katalog');
    expect((await page.request.get(`/wp-json/sdp/v1/schools/${npsn}`)).status()).toBe(404);

    await page.goto(previewUrl);
    await expect(page.getByText('NPSN ini sudah memiliki profil.', { exact: false })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Simpan draf dari katalog' })).toHaveCount(0);
    await page.getByRole('link', { name: 'Buka profil tersimpan' }).click();
    await expect(page.locator('#sdp-nama')).toHaveValue('Sekolah FIKTIF Katalog');
    await page.getByRole('link', { name: 'Buka asisten narasi AI' }).click();
    await expect(page).toHaveURL(`${new URL(page.url()).origin}/wp-admin/admin.php?page=sdp-seo&npsn=${npsn}#sdp-narasi-ai`);
    await expect(page.getByLabel('NPSN', { exact: true })).toHaveValue(npsn);
    await expect(page.getByText('Konfigurasikan SDP_CLAUDE_API_KEY atau ANTHROPIC_API_KEY di server.')).toBeVisible();
    await expect(page.getByRole('checkbox', { name: /Saya berhak memproses data ini/ })).not.toBeChecked();
    await page.getByLabel('ID model untuk pemeriksaan Claude').fill('model tidak valid');
    await page.getByRole('button', { name: 'Periksa model Claude' }).click();
    await expect(page.getByText('ID model Claude tidak valid.')).toBeVisible();
    await expect(page.getByLabel('NPSN', { exact: true })).toHaveValue(npsn);
    await page.screenshot({ path: `${screenshotDir}/desktop-narasi-ai-dari-profil.png` });

    await stale.getByRole('button', { name: 'Simpan draf dari katalog' }).click();
    await expect(stale.getByText('NPSN sudah memiliki profil.', { exact: false })).toBeVisible();
    await stale.close();

    await page.goto(`${catalogPage}&npsn=FIK00000`);
    await expect(page.getByText('NPSN tidak ditemukan di katalog lokal.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Isi profil secara manual' })).toBeVisible();
});

test('katalog menerima 689 sekolah dengan tautan sumber dan tanggal yang belum diketahui', async ({ page }) => {
    await login(page, 'sdp-admin', process.env.SDP_ADMIN_PASSWORD);
    const catalogPage = '/wp-admin/admin.php?page=sdp-npsn-catalog';
    const upload = async (count) => {
        await page.goto(catalogPage);
        const records = Array.from({ length: count }, (_, index) => {
            const npsn = `FIK${String(index).padStart(5, '0')}`;
            return `${npsn},Sekolah FIKTIF Katalog ${npsn},https://example.org/${npsn}`;
        });
        await page.getByLabel('CSV katalog').setInputFiles({
            name: 'katalog-fiktif.csv',
            mimeType: 'text/csv',
            buffer: Buffer.from(['npsn,nama,sumber_url', ...records].join('\n')),
        });
        await page.getByLabel('Nama sumber').fill('Sumber FIKTIF');
        await page.getByLabel('Saya berhak menggunakan dataset ini.').check();
        await page.getByRole('button', { name: 'Simpan katalog' }).click();
    };
    await upload(689);
    await expect(page.getByText('Rekaman katalog: 689')).toBeVisible();
    await upload(1001);
    await expect(page.getByText(/melebihi 1000 baris data/)).toBeVisible();
    await page.goto(catalogPage);
    await expect(page.getByText('Rekaman katalog: 689')).toBeVisible();
    await page.getByLabel('NPSN', { exact: true }).fill('FIK00688');
    await page.getByRole('button', { name: 'Cari NPSN' }).click();
    await expect(page.getByText('https://example.org/FIK00688', { exact: true })).toBeVisible();
    await expect(page.locator('dt').filter({ hasText: /^Tanggal data$/ })).toHaveCount(0);
});

test('daftar TXT dari katalog membuat draf baru dan melewati NPSN berulang', async ({ page }) => {
    await login(page, 'sdp-admin', process.env.SDP_ADMIN_PASSWORD);
    const catalogPage = '/wp-admin/admin.php?page=sdp-npsn-catalog';
    const first = `01${randomBytes(3).toString('hex').toUpperCase()}`;
    const missing = `02${randomBytes(3).toString('hex').toUpperCase()}`;
    await page.goto(catalogPage);
    await page.getByLabel('CSV katalog').setInputFiles({
        name: 'katalog-fiktif.csv', mimeType: 'text/csv',
        buffer: Buffer.from(`npsn,nama\n${first},Sekolah FIKTIF Daftar TXT\n`),
    });
    await page.getByLabel('Nama sumber').fill('Sumber FIKTIF');
    await page.getByLabel('Saya berhak menggunakan dataset ini.').check();
    await page.getByRole('button', { name: 'Simpan katalog' }).click();
    await expect(page.getByText('Rekaman katalog: 1')).toBeVisible();

    await page.goto('/wp-admin/admin.php?page=sdp-import');
    await page.getByRole('link', { name: 'Impor daftar NPSN dari katalog' }).click();
    const upload = async (text) => {
        await page.getByLabel('Daftar NPSN (TXT)').setInputFiles({
            name: 'daftar.txt', mimeType: 'text/plain', buffer: Buffer.from(text),
        });
        await page.getByRole('button', { name: 'Buat draf dari daftar NPSN' }).click();
    };
    await upload(`${first}\nkeliru\n`);
    await expect(page.getByText(/NPSN tidak valid pada baris 2/)).toBeVisible();
    await upload(`${first}\n`.repeat(51));
    await expect(page.getByText(/Daftar NPSN melebihi 50 baris data/)).toBeVisible();
    const list = `\ufeffnpsn\r\n${first.toLowerCase()}\r\n\r\n${first}\r\n${missing}\r\n`;
    await upload(list);
    await expect(page.getByText(/1 draf baru, 0 profil sudah ada, 1 baris berulang, 1 NPSN di luar katalog, 0 gagal/)).toBeVisible();
    await expect(page.getByText(`${missing} tidak ditemukan di katalog.`)).toBeVisible();
    await upload(list);
    await expect(page.getByText(/0 draf baru, 1 profil sudah ada, 1 baris berulang/)).toBeVisible();

    await page.goto(`/wp-admin/admin.php?page=sdp-edit&npsn=${first}`);
    await expect(page.locator('#sdp-npsn')).toHaveValue(first);
    await expect(page.locator('#sdp-nama')).toHaveValue('Sekolah FIKTIF Daftar TXT');
    expect((await page.request.get(`/wp-json/sdp/v1/schools/${first}`)).status()).toBe(404);
});
