<?php
// Verify real MySQL, not MariaDB; no production target and no credential output.
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true')exit(1);
$port=(int)getenv('SDP_DB_PORT');
$pass=getenv('SDP_DB_PASSWORD');
if($port<1||$port>65535||$pass!=='disposable-ci-only')exit(1);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=null;
for($i=0;$i<30;$i++){
    try {
        $db=mysqli_init();
        $db->options(MYSQLI_OPT_CONNECT_TIMEOUT,2);
        $db->real_connect('127.0.0.1','sdp_test',$pass,'sdp_test',$port);
        break;
    }catch(mysqli_sql_exception $e){$db=null;sleep(2);}
}
if(!$db){fwrite(STDERR,"MySQL test login not ready\n");exit(1);}
$version=$db->server_info;
if(!preg_match('/^8\.4\./',$version)||stripos($version,'MariaDB')!==false){fwrite(STDERR,"Unexpected database engine/version\n");exit(1);}
if($db->query('SHOW TABLES')->num_rows!==0){fwrite(STDERR,"Nonempty test database\n");exit(1);}
echo "PASS: real MySQL ".$version."; PHP ".PHP_VERSION."; empty disposable database\n";
$db->close();
