<?php
require __DIR__ . '/../public/includes/vip_membership.php';
class VipTestResult {
 public function __construct(public $value) {}
 function fetchColumn() {return $this->value;}
 function execute($args) {}
 function fetchAll($mode) {return $this->value;}
}
class VipTestDB {
 public $years=2; public $rows=[]; public $unsub=false;
 function exec($sql) {}
 function query($sql) {return new VipTestResult($this->years);}
 function prepare($sql) {return new VipTestResult(str_contains($sql,'FROM enrollments') ? $this->rows : $this->unsub);}
}
function verifyVip($ok) {if(!$ok) throw new Exception('VIP lifecycle test failed');}
$db=new VipTestDB();
$db->rows=[['id'=>1,'created_at'=>date('Y-m-d H:i:s')]];
$s=vipState($db,1);verifyVip($s['active'] && $s['years']===2);
$db->unsub=date('Y-m-d H:i:s');$s=vipState($db,1);verifyVip(!$s['active'] && $s['unsubscribed']);
$db->unsub=false;$db->rows=[['id'=>1,'created_at'=>date('Y-m-d H:i:s',strtotime('-3 years'))]];verifyVip(!vipState($db,1)['active']);
$db->years=4;verifyVip(vipState($db,1)['active']);
$db->years=2;$db->rows=[['id'=>1,'created_at'=>date('Y-m-d H:i:s',strtotime('-23 months'))],['id'=>2,'created_at'=>date('Y-m-d H:i:s')]];
verifyVip(vipState($db,1)['days_left']>730);
echo "PASS: default duration, unsubscribe, expiry, duration changes, early renewal extends existing term.\n";
