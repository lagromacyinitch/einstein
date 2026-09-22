<?php
require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders(); header('Content-Type: application/json');
try {
$db=getDB();
if($_SERVER['REQUEST_METHOD']==='POST') {
 require_once __DIR__ . '/../includes/admin_auth.php';
 if(($_SESSION['role'] ?? '')!=='admin') throw new Exception('Admin access required.');
 $data=json_decode(file_get_contents('php://input'),true); $value=$data['price'] ?? '';
 if(!is_numeric($value) || !is_finite((float)$value) || $value<=0 || $value>1000000) throw new Exception('Enter a valid positive price.');
 $db->beginTransaction();
 $q=$db->prepare("SELECT id FROM program_packages WHERE program_name='VIP Club Membership' AND package_name='VIP Club Membership' FOR UPDATE");$q->execute();$id=$q->fetchColumn();
 if($id) $db->prepare('UPDATE program_packages SET rate=? WHERE id=?')->execute([(string)round($value,2),$id]);
 else $db->prepare("INSERT INTO program_packages (program_name,package_name,rate,package_type) VALUES ('VIP Club Membership','VIP Club Membership',?,'vip')")->execute([(string)round($value,2)]);
 $db->commit();
}
$q=$db->query("SELECT rate FROM program_packages WHERE program_name='VIP Club Membership' AND package_name='VIP Club Membership' ORDER BY id DESC LIMIT 1");$rate=$q->fetchColumn();
$price=$rate===false?500:(float)preg_replace('/[^0-9.]/','',$rate);
echo json_encode(['success'=>true,'price'=>$price]);
} catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();http_response_code(422);echo json_encode(['success'=>false,'message'=>$e instanceof PDOException?'Unable to save VIP price.':$e->getMessage()]);}
