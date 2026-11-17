<?php
require __DIR__.'/../public/includes/admin_notifications.php';
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE admin_accounts(id INTEGER,can_edit_prices INTEGER,nav_permissions TEXT,is_active INTEGER);
INSERT INTO admin_accounts VALUES(1,0,'[]',1);
CREATE TABLE admin_notifications(id INTEGER,target_page TEXT);
INSERT INTO admin_notifications VALUES(1,'vipmembers'),(2,'enrollment'),(3,'balancemonitoring'),(4,'activitylogs');");
function visible($db,$key){[$scope,$params]=adminNotificationScope($db,$key);$s=$db->prepare('SELECT n.id FROM admin_notifications n WHERE '.$scope.' ORDER BY n.id');$s->execute($params);return $s->fetchAll(PDO::FETCH_COLUMN);}
function check($actual,$expected){if($actual!==$expected)throw new Exception(json_encode([$actual,$expected]));}
check(visible($db,'sub_admin:1'),[]);
$db->exec("UPDATE admin_accounts SET nav_permissions='[\"students\"]'");
check(visible($db,'sub_admin:1'),[1,2]);
$db->exec("UPDATE admin_accounts SET nav_permissions='[\"finance\"]'");
check(visible($db,'sub_admin:1'),[3]);
$db->exec("UPDATE admin_accounts SET nav_permissions='[]',can_edit_prices=1");
check(visible($db,'sub_admin:1'),[1,2,3]);
$db->exec('UPDATE admin_accounts SET can_edit_prices=0');
check(visible($db,'sub_admin:1'),[]);
try{markAdminNotificationRead($db,'sub_admin:1',1);throw new Exception('Read should be denied');}catch(RuntimeException $e){check($e->getMessage(),'Notification unavailable.');}
$db->exec('UPDATE admin_accounts SET can_edit_prices=1,is_active=0');
check(visible($db,'sub_admin:1'),[]);
check(visible($db,'sub_admin:999'),[]);
check(visible($db,'head_admin:admin'),[1,2,3,4]);
echo "PASS: tutor denied, section permissions, full access, immediate revocation, hidden read protection, disabled/missing accounts, head admin.\n";
