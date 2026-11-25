"""End-to-end claim, moderation and access isolation on disposable WordPress only."""
import json
import secrets
import subprocess
from pathlib import Path
from urllib.parse import urlencode, urlsplit
from playwright.sync_api import sync_playwright, expect

ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'artifacts'
URLS=json.loads((OUT/'preview-urls.json').read_text())
HOME='http://127.0.0.1:8080/'
assert URLS['home']==HOME, 'Local test stack required'
checks=[]

def wp(*args):
    # Avoid printing command arguments: user creation includes a random local password.
    result=subprocess.run(['docker','compose','exec','-T','-u','www-data','wordpress','wp',*args],cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    if result.returncode:
        raise RuntimeError('WP-CLI fixture or state query failed (command '+args[0]+', exit '+str(result.returncode)+')')
    return result.stdout.strip()

def state():
    return json.loads(wp('eval',r'''global $wpdb;
        $claims=$wpdb->get_results("SELECT npsn,user_id,status FROM ".SDP_National::table()." ORDER BY npsn",ARRAY_A);
        $proposals=$wpdb->get_results("SELECT id,npsn,user_id,status FROM ".SDP_Operator::table()." ORDER BY id",ARRAY_A);
        $a=SDP_Intelligence::load("ZZQA0001"); $b=SDP_Intelligence::load("ZZQA0002");
        if($wpdb->last_error||is_wp_error($a)||is_wp_error($b)||!is_array($claims)||!is_array($proposals)) WP_CLI::error("Fixture read failed");
        WP_CLI::log(wp_json_encode(["claims"=>$claims,"proposals"=>$proposals,"a"=>$a["visi"]??"","b"=>$b["visi"]??""]));'''))

def record(label,ok=True):
    if not ok: raise AssertionError(label)
    checks.append(label)
    (OUT/'operator-checks.json').write_text(json.dumps(checks,indent=2))

def local(url):
    u=urlsplit(url)
    return u.scheme=='http' and u.netloc=='127.0.0.1:8080'

def goto(page,url):
    assert local(url)
    response=page.goto(url,wait_until='domcontentloaded',timeout=30000)
    assert response and response.ok and local(page.url),'Navigation failed'

def form(page,action,extra=''):
    return page.locator('form:has(input[name="action"][value="'+action+'"])'+extra)

def login(browser,user,password):
    context=browser.new_context()
    page=context.new_page()
    page.route('**/*',lambda route:route.continue_() if local(route.request.url) else route.abort())
    goto(page,HOME+'wp-login.php')
    page.locator('#user_login').fill(user)
    page.locator('#user_pass').fill(password)
    page.locator('#wp-submit').click()
    page.wait_for_url(lambda u:'/wp-login.php' not in u,timeout=15000)
    return context,page

def portal(page,n=None):
    goto(page,URLS['pages']['operator']+('?' + urlencode({'sdp_operator_npsn':n}) if n else ''))

def claim(page,i):
    goto(page,URLS['schools'][i])
    f=form(page,'sdp50_claim')
    f.locator('[name=role_note]').fill('Operator DEMO')
    f.locator('[name=evidence]').fill('https://example.invalid/bukti-demo')
    f.locator('button[type=submit]').click()
    expect(page.locator('body')).to_contain_text('Klaim profil sekolah')

def review_claim(admin,n,decision):
    goto(admin,HOME+'wp-admin/admin.php?page=sdp-national')
    f=form(admin,'sdp50_review',':has(input[name="npsn"][value="'+n+'"])')
    if decision=='approved': f.locator('[name=verified]').check()
    f.locator('button[name=decision][value="'+decision+'"]').click()
    expect(admin.locator('h1')).to_contain_text('Skala Nasional')

def fill_proposal(page,value):
    f=form(page,'sdp71_submit')
    expect(f).to_have_count(1)
    f.locator('[name="candidate[field]"]').select_option('visi')
    f.locator('[name="candidate[value]"]').fill(value)
    f.locator('[name="candidate[url]"]').fill('https://example.invalid/bukti-demo')
    f.locator('[name="candidate[note]"]').fill('Bukti fiktif khusus pengujian')
    return f

def denied_response(response,phrases):
    assert response.status>=400,'Unexpected successful forbidden HTTP request'
    assert any(x in response.text() for x in phrases),'Unexpected denial response'

# Check the test environment independently before creating accounts.
guard=wp('eval',"""if(wp_get_environment_type()!=='local'||home_url('/')!=='http://127.0.0.1:8080/'||(string)get_option('blog_public')!=='0'||!defined('SDP_SAFE_MODE')||!SDP_SAFE_MODE) WP_CLI::error('Not disposable'); WP_CLI::log('LOCAL');""")
assert guard=='LOCAL'
# TEST identifiers are intentionally blocked by the production operator engine.
# Use distinct internal-only fixtures that meet the application's identity format.
# These are not official NPSNs. They never enter the preview snapshot or public artifacts.
fixture=wp('eval',r'''if(!is_wp_error(SDP_Intelligence::load("TEST0001"))) WP_CLI::error("Demo identity guard failed");
    $urls=[]; foreach(["ZZQA0001","ZZQA0002"] as $n){
        $r=SDP_Directory::save(["npsn"=>$n,"nama"=>"UJI INTERNAL BUKAN SEKOLAH NYATA ".$n,"jenjang"=>"SD","status"=>"Negeri","provinsi"=>"Wilayah Uji Internal","kabupaten"=>"Kabupaten Uji Internal","kecamatan"=>"Kecamatan Uji Internal","sumber"=>"Fixture QA lokal bukan data resmi"],true);
        if(is_wp_error($r)||empty($r["id"])||get_post_status($r["id"])!=="publish"||is_wp_error(SDP_Intelligence::load($n))) WP_CLI::error("Internal operator fixture unavailable");
        $urls[]=get_permalink($r["id"]);
    } WP_CLI::log(wp_json_encode($urls,JSON_UNESCAPED_SLASHES));''','--user=sdp_local_admin')
URLS['schools']=json.loads(fixture)
record('Existing TEST identity guard rejects demo profile')
initial=state()
assert initial['claims']==[] and initial['proposals']==[],'Fresh fixture required'
pa,pb=secrets.token_urlsafe(24),secrets.token_urlsafe(24)
ua=int(wp('user','create','sdp_demo_a','a@example.invalid','--role=subscriber','--user_pass='+pa,'--porcelain'))
ub=int(wp('user','create','sdp_demo_b','b@example.invalid','--role=subscriber','--user_pass='+pb,'--porcelain'))
with sync_playwright() as p:
    browser=p.chromium.launch()
    ca,a=login(browser,'sdp_demo_a',pa)
    cb,b=login(browser,'sdp_demo_b',pb)
    cc,admin=login(browser,'sdp_local_admin',(ROOT/'.env.sdp-admin-password').read_text().strip())
    try:
        claim(a,0)
        claim(b,1)
        s=state()
        record('Two claims submitted via actual browser forms',[(x['npsn'],int(x['user_id']),x['status']) for x in s['claims']]==[('ZZQA0001',ua,'pending'),('ZZQA0002',ub,'pending')])
        portal(a,'ZZQA0001')
        expect(form(a,'sdp71_submit')).to_have_count(0)
        record('Pending claim cannot submit proposal')
        review_claim(admin,'ZZQA0001','approved')
        review_claim(admin,'ZZQA0002','approved')
        record('Admin approved both claims through actual forms',all(x['status']=='approved' for x in state()['claims']))
        portal(a,'ZZQA0002')
        expect(form(a,'sdp71_submit')).to_have_count(0)
        expect(a.locator('.sdp71')).not_to_contain_text('ZZQA0002 — approved')
        portal(b,'ZZQA0001')
        expect(form(b,'sdp71_submit')).to_have_count(0)
        expect(b.locator('.sdp71')).not_to_contain_text('ZZQA0001 — approved')
        record('Both subscribers isolated from other school proposal forms and claim history')
        portal(a,'ZZQA0001')
        f=fill_proposal(a,'Visi DEMO A teruji')
        nonce=f.locator('[name=_wpnonce]').input_value()
        before=state()
        attack=ca.request.post(HOME+'wp-admin/admin-post.php',form={'action':'sdp71_submit','npsn':'ZZQA0002','_wpnonce':nonce,'candidate[field]':'visi','candidate[value]':'Perubahan terlarang','candidate[url]':'https://example.invalid/bukti-demo','candidate[period]':'','candidate[note]':''})
        record('Tampered school identity rejected with unchanged database',attack.status==403 and state()==before)
        f.locator('button[type=submit]').click()
        expect(a.locator('.sdp71')).to_contain_text('Menunggu')
        s=state()
        record('Submission is pending, not public mutation',len(s['proposals'])==1 and s['proposals'][0]['status']=='pending' and int(s['proposals'][0]['user_id'])==ua and s['a']==initial['a'] and s['b']==initial['b'])
        pid=str(s['proposals'][0]['id'])
        portal(b,'ZZQA0002')
        expect(b.locator('.sdp71-table')).not_to_contain_text('ZZQA0001')
        denied=cb.request.post(HOME+'wp-admin/admin-post.php',form={'action':'sdp71_review','id':pid,'decision':'approve','confirm':'1'})
        denied_response(denied,['Akses ditolak'])
        record('Other subscriber cannot see proposal or invoke moderator action',state()==s)
        goto(admin,HOME+'wp-admin/admin.php?page=sdp-operator')
        f=form(admin,'sdp71_review',':has(input[name="id"][value="'+pid+'"])')
        f.locator('[name=confirm]').check()
        f.locator('button[value=approve]').click()
        expect(admin.locator('h1')).to_contain_text('Portal Operator')
        s=state()
        record('Moderator approval updates only target school',s['a']=='Visi DEMO A teruji' and s['b']==initial['b'] and s['proposals'][0]['status']=='approved')
        portal(a,'ZZQA0001')
        expect(a.locator('.sdp71-table')).to_contain_text('Disetujui')
        f=fill_proposal(a,'Visi DEMO setelah pencabutan')
        review_claim(admin,'ZZQA0001','revoked')
        f.locator('button[type=submit]').click()
        expect(a.locator('body')).to_contain_text('Klaim aktif dan profil publik diperlukan')
        s=state()
        record('Revoked claim rejects stale form with valid session nonce',len(s['proposals'])==1 and s['a']=='Visi DEMO A teruji' and s['b']==initial['b'] and s['claims'][0]['status']=='revoked')
    finally:
        ca.close()
        cb.close()
        cc.close()
        browser.close()
# Stack is destroyed by CI; never export authenticated screenshots, storage or credentials.
print('Operator E2E:',len(checks),'checks passed')
