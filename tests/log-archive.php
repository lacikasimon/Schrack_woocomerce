<?php
/** Disposable SQLite + private temporary files. Never loads WordPress or live credentials. */
$fixture = sys_get_temp_dir() . '/schrack-archive-test-' . bin2hex(random_bytes(6));
mkdir($fixture . '/web',0700,true);
define('ABSPATH',$fixture . '/web/'); define('ARRAY_A','ARRAY_A'); define('DAY_IN_SECONDS',86400);
$_SERVER['DOCUMENT_ROOT']=ABSPATH;
$GLOBALS['options']=array(); $GLOBALS['fail_checkpoint']=false;
function get_option($name,$default=false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option($name,$value,$autoload=false) {
 if($GLOBALS['fail_checkpoint'] && !isset($value['pending']) && ($value['archived'] ?? 0)>0 && ($value['status'] ?? '')==='running') { $GLOBALS['fail_checkpoint']=false; throw new RuntimeException('Simulated death after deletion.'); }
 $GLOBALS['options'][$name]=$value;
}
function wp_cache_delete($key,$group): void {}
function wp_next_scheduled($hook) { return false; }
function wp_schedule_event($time,$recurrence,$hook): void {}
function wp_schedule_single_event($time,$hook): void {}
function wp_clear_scheduled_hook($hook): void {}
function wp_generate_uuid4(): string { return '12345678-1234-1234-1234-123456789abc'; }
function wp_date($format,$time): string { return date($format,$time); }
function wp_json_encode($value,$flags=0): string { return json_encode($value,$flags); }
class Schrack_Logger { public static function table_name(): string { return 'testshop_logs'; } }
class ArchiveDB {
 public string $options='testshop_options', $last_error=''; public PDO $db;
 public function __construct() { $this->db=new PDO('sqlite::memory:'); $this->db->exec('CREATE TABLE testshop_logs(id INTEGER PRIMARY KEY,created_at TEXT,level TEXT,operation TEXT,sku TEXT,message TEXT,context TEXT)'); }
 public function prepare($sql,...$args): string { $args=is_array($args[0] ?? null)?$args[0]:$args; $i=0; return preg_replace_callback('/%[sd]/',function($m)use(&$i,$args){$v=$args[$i++];return $m[0]==='%d'?(string)(int)$v:$this->db->quote((string)$v);},$sql); }
 public function get_var($sql) { if(str_contains($sql,'GET_LOCK') || str_contains($sql,'RELEASE_LOCK'))return '1';return $this->db->query($sql)->fetchColumn(); }
 public function get_row($sql,$mode): ?array { if(str_starts_with($sql,'SHOW TABLE'))return array('Engine'=>'InnoDB'); $row=$this->db->query($sql)->fetch(PDO::FETCH_ASSOC);return $row?:null; }
 public function get_results($sql,$mode): array { return $this->db->query(str_replace(' FOR UPDATE','',$sql))->fetchAll(PDO::FETCH_ASSOC); }
 public function query($sql) { return $this->db->exec($sql==='START TRANSACTION'?'BEGIN':$sql); }
 public function insert($table,$row) { $q=$this->db->prepare('INSERT INTO '.$table.'('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')'); return $q->execute(array_values($row)); }
}
$wpdb=new ArchiveDB();
require __DIR__.'/../includes/class-schrack-log-archive.php';
$checks=0;
function verify_archive($ok,$message): void { ++$GLOBALS['checks']; if(!$ok)throw new RuntimeException($message); }
$old=date('Y-m-d H:i:s',time()-100*86400);$recent=date('Y-m-d H:i:s',time()-10*86400);
$original=array();
foreach(array(array(1,'debug',$old),array(2,'info',$old),array(3,'warning',$old),array(4,'error',$recent),array(5,'info',$recent),array(6,'warning',date('Y-m-d H:i:s',time()-40*86400))) as [$id,$level,$date]) {
 $row=array('id'=>(string)$id,'created_at'=>$date,'level'=>$level,'operation'=>'fixture','sku'=>null,'message'=>'Șir „exact” 0','context'=>$id===2?'{"zero":0,"line":"a\\nb"}':null);$original[$id]=$row;$wpdb->insert('testshop_logs',$row);
}
$job=new Schrack_Log_Archive();
try {
 $job->start(); $state=get_option(Schrack_Log_Archive::STATE); $dir=$state['directory'];
 verify_archive((fileperms($dir)&0777)===0700 && !str_starts_with($dir,ABSPATH),'Archive is private and outside webroot.');
 $GLOBALS['fail_checkpoint']=true; $job->tick(); $state=get_option(Schrack_Log_Archive::STATE);
 verify_archive($state['status']==='error' && isset($state['pending']),'An interrupted checkpoint retains the verified segment.');
 verify_archive((int)$wpdb->get_var('SELECT COUNT(*) FROM testshop_logs')===3,'Only old rows covered by retention were archived.');
 $job->start();$job->tick();$job->tick();
 verify_archive(Schrack_Log_Archive::status()['status']==='complete' && Schrack_Log_Archive::status()['archived']===3,'Resume completes a pending delete once, even after process death.');
 verify_archive(!isset(Schrack_Log_Archive::status()['directory']),'Status never exposes the private path.');
 foreach(glob($dir.'/*') as $file)verify_archive((fileperms($file)&0777)===0600,'Every segment and checksum has private permissions.');
 $job->start(true);$job->tick();$job->tick();
 verify_archive(Schrack_Log_Archive::status()['restored']===3,'Restore completes through the normal admin operation.');
 $restored=array_column($wpdb->get_results('SELECT * FROM testshop_logs ORDER BY id',ARRAY_A),null,'id');
 verify_archive($restored==$original,'IDs, Unicode, null fields, JSON and timestamps survive an exact round trip.');
 $job->start(true);$job->tick();$job->tick();verify_archive((int)$wpdb->get_var('SELECT COUNT(*) FROM testshop_logs')===6,'Restoring twice does not duplicate rows.');
 $wpdb->db->exec("UPDATE testshop_logs SET message='different' WHERE id=1");$job->start(true);$job->tick();
 verify_archive(Schrack_Log_Archive::status()['status']==='error' && $wpdb->get_var('SELECT message FROM testshop_logs WHERE id=1')==='different','An ID collision stops restoration without overwriting data.');
 $file=glob($dir.'/batch-*.json.gz')[0];file_put_contents($file,'corrupt');$job->start(true);$job->tick();
 verify_archive(Schrack_Log_Archive::status()['status']==='error','A corrupt archive fails before changing database rows.');
 $read=new ReflectionMethod($job,'read');
 try{$read->invoke($job,$dir,'../../unsafe');verify_archive(false,'Traversal must fail.');}catch(RuntimeException $e){verify_archive(true,'Traversal rejected.');}
} finally {
 foreach(glob($fixture.'/schrack-log-archive-*/*')?:array() as $file)unlink($file);
 foreach(glob($fixture.'/schrack-log-archive-*')?:array() as $path)rmdir($path);
 rmdir($fixture.'/web');rmdir($fixture);
}
echo "Log archive: {$checks} checks passed.\n";
