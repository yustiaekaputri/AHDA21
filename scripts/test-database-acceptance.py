"""Disposable MariaDB acceptance: XLSX conversion, resumable CSV, benchmark, restore.
No production data or SQL dumps are published. All schools are labelled fiktif.
"""
import csv
import hashlib
import io
import json
import subprocess
import tempfile
import time
from pathlib import Path
from xml.sax.saxutils import escape
from zipfile import ZipFile

ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'artifacts'
OUT.mkdir(exist_ok=True)
report={'checks':[],'benchmarks':[]}
def save():
    (OUT/'database-checks.json').write_text(json.dumps(report,indent=2))
def check(label,ok):
    if not ok: raise AssertionError(label)
    report['checks'].append(label); save()
def run(args,data=None,timeout=180):
    p=subprocess.run(['docker','compose','exec','-T',*args],cwd=ROOT,input=data,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=timeout)
    if p.returncode: raise RuntimeError('Container command failed: '+args[0]+' (exit '+str(p.returncode)+')')
    return p.stdout
def wp(*args):
    return run(['-u','www-data','wordpress','wp',*args]).decode().strip()
def evaluate(code):
    return json.loads(wp('eval',code,'--user=sdp_local_admin'))
def put(name,data):
    # fixed internal filenames, never user input
    assert name in ('initial.csv','growth.csv','demo.xlsx','formula.xlsx','converted.csv')
    run(['-u','www-data','wordpress','sh','-c','cat > /tmp/sdp-'+name],data)
def csv_bytes(rows):
    out=io.StringIO(newline='')
    w=csv.writer(out);w.writerow(['npsn','nama','jenjang','status','provinsi','kabupaten','kecamatan']);w.writerows(rows)
    return out.getvalue().encode()
def row(i):
    return [f'TEST{i:04d}',f'SD Fiktif QA {i:04d}','SD','Negeri','Provinsi Fiktif','Kabupaten Fiktif','Kecamatan Fiktif']
def xlsx(rows,formula=False):
    # Minimal OOXML workbook with inline strings; exercises the PHP converter itself.
    ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main'
    rel='http://schemas.openxmlformats.org/officeDocument/2006/relationships'
    package='http://schemas.openxmlformats.org/package/2006/relationships'
    buffer=io.BytesIO()
    with ZipFile(buffer,'w') as z:
        z.writestr('[Content_Types].xml','<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>')
        z.writestr('_rels/.rels',f'<Relationships xmlns="{package}"><Relationship Id="rId1" Type="{rel}/officeDocument" Target="xl/workbook.xml"/></Relationships>')
        z.writestr('xl/workbook.xml',f'<workbook xmlns="{ns}" xmlns:r="{rel}"><sheets><sheet name="QA" sheetId="1" r:id="rId1"/></sheets></workbook>')
        z.writestr('xl/_rels/workbook.xml.rels',f'<Relationships xmlns="{package}"><Relationship Id="rId1" Type="{rel}/worksheet" Target="worksheets/sheet1.xml"/></Relationships>')
        rs=[]
        for i,values in enumerate(rows,1):
            cells=[]
            for j,v in enumerate(values):
                ref=f'{chr(65+j)}{i}'
                if formula and i==2 and j==1: cells.append(f'<c r="{ref}"><f>1+1</f><v>2</v></c>')
                else: cells.append(f'<c r="{ref}" t="inlineStr"><is><t>{escape(str(v))}</t></is></c>')
            rs.append(f'<row r="{i}">'+''.join(cells)+'</row>')
        z.writestr('xl/worksheets/sheet1.xml',f'<worksheet xmlns="{ns}"><sheetData>'+''.join(rs)+'</sheetData></worksheet>')
    return buffer.getvalue()
def import_batch(name):
    out=wp('sdp','import','/tmp/sdp-'+name,'--authorized=1','--source=Simulasi QA lokal','--publish','--limit=100','--user=sdp_local_admin')
    return json.loads(out[out.index('{'):out.rindex('}')+1])
def count():
    return evaluate('global $wpdb; $t=SDP_Directory::table(); $n=$wpdb->get_var("SELECT COUNT(*) FROM $t"); if($wpdb->last_error||$n===null) WP_CLI::error("Count failed"); WP_CLI::log(wp_json_encode((int)$n));')
def finish(name,expected,invalid=0):
    last=-1
    for _ in range(25):
        v=import_batch(name)
        if v['done']:
            check(name+' checkpoint totals',v['seen']==expected and v['invalid']==invalid and v['saved']==expected-invalid)
            check(name+' completed checkpoint is idempotent',import_batch(name)==v)
            return
        if v['seen']<=last: raise AssertionError('Checkpoint failed to advance')
        last=v['seen']
    raise AssertionError('Checkpoint iteration limit')
def benchmark(n):
    v=evaluate('''$r=SDP_Benchmark::run(["sdp_provinsi"=>"Provinsi Fiktif"],3,15,true); WP_CLI::log(wp_json_encode($r));''')
    check(f'{n} indexed/public search results',v['cases']['listing']['result_counts']==[n]*3 and v['cases']['filtered']['result_counts']==[n]*3 and all(not x['errors'] for x in v['cases'].values()))
    report['benchmarks'].append({'school_count':n,'report':v});save()

guard=evaluate('''if(wp_get_environment_type()!=="local"||home_url("/")!=="http://127.0.0.1:8080/"||(string)get_option("blog_public")!=="0"||!defined("SDP_SAFE_MODE")||!SDP_SAFE_MODE||!SDP_Resource::enabled()) WP_CLI::error("Not disposable low resource"); WP_CLI::log(wp_json_encode(DB_NAME));''')
check('Fresh local QA database only',guard=='sdp_test' and count()==0)
wp('eval','update_option("sdp_seo_config",array_merge(SDP_SEO::config(),["guard"=>0]),false);','--user=sdp_local_admin')
header=['npsn','nama','jenjang','status','provinsi','kabupaten','kecamatan']
put('demo.xlsx',xlsx([header,row(1)]))
put('formula.xlsx',xlsx([header,row(1)],True))
v=evaluate('''$r=SDP_Excel::convert("/tmp/sdp-demo.xlsx",1); $bad=SDP_Excel::convert("/tmp/sdp-formula.xlsx",1); if(is_wp_error($r)) WP_CLI::error("XLSX conversion failed"); if(file_put_contents("/tmp/sdp-converted.csv",$r)===false) WP_CLI::error("CSV write failed"); WP_CLI::log(wp_json_encode(["formula_rejected"=>is_wp_error($bad),"csv"=>$r]));''')
check('PHP XLSX converter preserves header and text NPSN',list(csv.reader(io.StringIO(v['csv'])))==[header,row(1)])
check('Formula workbook rejected',v['formula_rejected'] and count()==0)
finish('converted.csv',1)
check('Converted XLSX imported exactly once',count()==1)
# First batch includes an existing identity, then one invalid row. No duplicate NPSN.
put('initial.csv',csv_bytes([row(i) for i in range(1,101)]+[row(1),['bad','Invalid','SD','Negeri','P','K','C']]))
start=time.monotonic()
first=import_batch('initial.csv')
check('CSV first batch bounded',not first['done'] and 0<first['seen']<=100)
finish('initial.csv',102,1)
check('100 unique school identities after resume and duplicate',count()==100)
report['import_100_seconds']=round(time.monotonic()-start,3);benchmark(100)
put('growth.csv',csv_bytes([row(i) for i in range(101,1001)]))
start=time.monotonic();finish('growth.csv',900)
check('1000 unique school identities',count()==1000)
report['import_900_seconds']=round(time.monotonic()-start,3);benchmark(1000)
integrity=evaluate('''global $wpdb; $t=SDP_Directory::table(); $bad=$wpdb->get_var("SELECT COUNT(*) FROM $t s LEFT JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE p.ID IS NULL OR p.post_type<>'sdp_school' OR p.post_status<>'publish'"); $duplicate=$wpdb->get_var("SELECT COUNT(*)-COUNT(DISTINCT post_id) FROM $t"); WP_CLI::log(wp_json_encode(["bad"=>(int)$bad,"duplicate"=>(int)$duplicate,"error"=>$wpdb->last_error]));''')
check('No orphan, unpublished or shared post mapping',integrity=={'bad':0,'duplicate':0,'error':''})
# SQL-only restore to an isolated schema in the disposable DB container.
def db(command,data=None):
    return run(['db','sh','-c','export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"; '+command],data,timeout=240)
dump=db('mariadb-dump -uroot --single-transaction --skip-comments sdp_test')
check('Nonempty logical backup',len(dump)>1000)
db('mariadb -uroot -e "CREATE DATABASE sdp_restore_qa"')
db('mariadb -uroot sdp_restore_qa',dump)
options='mariadb-dump -uroot --single-transaction --skip-comments --no-create-info --skip-triggers --skip-extended-insert --order-by-primary '
original=db(options+'sdp_test')
restored=db(options+'sdp_restore_qa')
check('Logical restored data matches source dump byte-for-byte',hashlib.sha256(original).digest()==hashlib.sha256(restored).digest())
report['backup_bytes']=len(dump)
report['limitations']='Synthetic test data only; PHP CLI sequential queries in CI, not HTTP concurrency or VPS performance. SQL restore only, not media or full site.'
save()
print('Database acceptance:',len(report['checks']),'checks passed')
