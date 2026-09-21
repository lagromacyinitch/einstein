<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/config.php';
setSecurityHeaders();
require_once __DIR__.'/admin_auth.php';
require_once __DIR__.'/tutor_profile_helpers.php';
try {
    $accountId=(int)($_SESSION['sub_admin_id'] ?? 0);
    if (!$accountId || !empty($_SESSION['is_head_admin'])) throw new Exception('A tutor account is required.');
    $db=getDB();
    ensureTutorProfileSchema($db);
    $q=$db->prepare('SELECT * FROM admin_accounts WHERE id=? AND is_active=1');
    $q->execute([$accountId]);
    $account=$q->fetch(PDO::FETCH_ASSOC);
    if (!$account) throw new Exception('Account is unavailable.');
    $id=linkTutorAccount($db,$account);
    $_SESSION['tutor_id']=$id;
    $_SESSION['tutor_profile_csrf'] ??= bin2hex(random_bytes(32));
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $body=json_decode(file_get_contents('php://input'),true) ?? [];
        if (!hash_equals($_SESSION['tutor_profile_csrf'],(string)($body['csrf_token'] ?? ''))) throw new Exception('Please reload your profile and try again.');
        $action = $body['action'] ?? 'profile';
        if ($action === 'password') {
            $currentPass = (string)($body['current_password'] ?? '');
            $newPass = (string)($body['new_password'] ?? '');
            $confirmPass = (string)($body['confirm_password'] ?? '');
            if ($currentPass === '') throw new Exception('Please enter your current password.');
            if (!password_verify($currentPass, $account['password_hash'])) throw new Exception('Current password is incorrect.');
            if (strlen($newPass) < 6 || strlen($newPass) > 72) throw new Exception('New password must be between 6 and 72 characters.');
            if ($newPass !== $confirmPass) throw new Exception('New passwords do not match.');

            $newHash = password_hash($newPass, PASSWORD_BCRYPT);
            $db->prepare('UPDATE admin_accounts SET password_hash=?, updated_at=NOW() WHERE id=?')->execute([$newHash, $accountId]);
            echo json_encode(['success'=>true, 'message'=>'Password changed successfully.']);
            exit;
        }
        $name=trim($body['full_name'] ?? '');
        $email=strtolower(trim($body['email'] ?? ''));
        $phone=trim($body['phone'] ?? '');
        if ($name==='' || mb_strlen($name)>120) throw new Exception('Enter your full name (up to 120 characters).');
        if ($phone!=='' && !preg_match('/^[0-9+() -]{7,15}$/',$phone)) throw new Exception('Enter a valid contact number (7 to 15 characters).');
        validateTutorEmail($db,$email,$accountId);
        $db->beginTransaction();
        $db->prepare('UPDATE admin_accounts SET display_name=?,email=?,updated_at=NOW() WHERE id=?')->execute([$name,$email,$accountId]);
        $db->prepare('UPDATE tutors SET full_name=?,email=?,phone=?,updated_at=NOW() WHERE id=?')->execute([$name,$email,$phone ?: null,$id]);
        $db->commit();
        $_SESSION['display_name']=$name;
        $_SESSION['email']=$email;
    } elseif ($_SERVER['REQUEST_METHOD']!=='GET') throw new Exception('Unsupported request.');
    $q=$db->prepare('SELECT full_name,email,phone,teaching_specialization FROM tutors WHERE id=?');
    $q->execute([$id]);
    echo json_encode(['success'=>true,'profile'=>$q->fetch(PDO::FETCH_ASSOC),'csrf_token'=>$_SESSION['tutor_profile_csrf']]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
