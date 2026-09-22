<?php
require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders(); header('Content-Type: application/json'); startUserSession();
try {
    if (empty($_SESSION['user_id'])) { http_response_code(401); throw new RuntimeException('Please sign in again.'); }
    $db=getDB(); $id=(int)$_SESSION['user_id'];
    $q=$db->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$id]); $user=$q->fetch(PDO::FETCH_ASSOC);
    if(!$user) throw new RuntimeException('Account not found.');
    if($_SERVER['REQUEST_METHOD']==='GET') {
        $_SESSION['account_csrf'] ??= bin2hex(random_bytes(32));
        if (!array_key_exists('returning_portal_visit', $_SESSION)) {
            $db->exec('CREATE TABLE IF NOT EXISTS client_portal_visits (user_id INT UNSIGNED PRIMARY KEY, first_opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
            $visit=$db->prepare('INSERT IGNORE INTO client_portal_visits (user_id) VALUES (?)');
            $visit->execute([$id]);
            $_SESSION['returning_portal_visit'] = $visit->rowCount() === 0;
        }
        $profile=decryptRow($user,['username','firstname','middlename','lastname','contact_number']);
        $name=trim(implode(' ', array_filter([$profile['firstname'] ?? '', $profile['middlename'] ?? '', $profile['lastname'] ?? ''], static fn($part) => trim($part) !== '')));
        $phone=trim($profile['contact_number'] ?? '');

        // Auto-fill from latest enrollment if name or phone is empty
        if (empty($name) || empty($phone)) {
            try {
                $enrStmt = $db->prepare("SELECT guardian_name, contact FROM enrollments WHERE user_id=? ORDER BY id DESC LIMIT 1");
                $enrStmt->execute([$id]);
                $latestEnr = $enrStmt->fetch(PDO::FETCH_ASSOC);
                if ($latestEnr) {
                    $enrDecrypted = decryptRow($latestEnr, ['guardian_name', 'contact']);
                    if (empty($name) && !empty($enrDecrypted['guardian_name'])) {
                        $name = trim($enrDecrypted['guardian_name']);
                        $db->prepare("UPDATE users SET firstname=? WHERE id=? AND (firstname IS NULL OR firstname='')")
                           ->execute([encryptAES256($name), $id]);
                    }
                    if (empty($phone) && !empty($enrDecrypted['contact'])) {
                        $phone = trim($enrDecrypted['contact']);
                        $db->prepare("UPDATE users SET contact_number=? WHERE id=? AND (contact_number IS NULL OR contact_number='')")
                           ->execute([encryptAES256($phone), $id]);
                    }
                }
            } catch (Exception $ex) {}
        }

        echo json_encode(['success'=>true,'name'=>$name,'phone'=>$phone,'returning'=>(bool)$_SESSION['returning_portal_visit'],'username'=>$profile['username'] ?? '', 'email'=>$_SESSION['email'] ?? '', 'token'=>$_SESSION['account_csrf']]); exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('Method not allowed.');
    $data=json_decode(file_get_contents('php://input'),true);
    if(!is_array($data) || !hash_equals($_SESSION['account_csrf'] ?? '', (string)($data['token'] ?? '')) || empty($_SESSION['account_csrf'])) throw new RuntimeException('Please reopen account settings.');
    rateLimit('account_changes_'.$id, 5, 600);
    if(($data['action'] ?? '')!=='profile' && !password_verify((string)($data['password'] ?? ''),$user['password_hash'])) throw new RuntimeException('Current password is incorrect.');
    if(($data['action'] ?? '')==='delete') {
        if(($data['confirmation'] ?? '')!=='DELETE') throw new RuntimeException('Type DELETE to confirm.');
        $db->beginTransaction();
        $db->prepare('UPDATE enrollments SET user_id=NULL WHERE user_id=?')->execute([$id]);
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        $db->commit(); $_SESSION=[]; session_destroy();
    } elseif(($data['action'] ?? '')==='profile') {
        $fullName=trim(preg_replace('/\s+/u', ' ', (string)($data['name'] ?? '')));
        if($fullName==='' || mb_strlen($fullName)>150) throw new RuntimeException('Enter your full name (up to 150 characters).');
        $profile=decryptRow($user,['firstname','middlename','lastname']);
        $previousName=trim(implode(' ', array_filter([$profile['firstname'] ?? '', $profile['middlename'] ?? '', $profile['lastname'] ?? ''])));
        $first=$user['firstname']; $middle=$user['middlename']; $last=$user['lastname'];
        if($fullName!==$previousName) {
            // Keep the entered full name intact; do not guess surname boundaries.
            $first=encryptAES256($fullName); $middle=null; $last=null;
        }
        $rawPhone=trim((string)($data['phone'] ?? ''));
        if($rawPhone!=='' && !preg_match('/^[0-9+\s\-()]{7,25}$/', $rawPhone)) {
            throw new RuntimeException('Enter a valid phone number (digits, spaces, or dashes).');
        }
        $phoneEnc = $rawPhone !== '' ? encryptAES256($rawPhone) : null;
        $name=trim((string)($data['username'] ?? ''));
        if($name!=='' && !preg_match('/^[a-zA-Z0-9_.-]{3,50}$/',$name)) throw new RuntimeException('Username must be 3–50 letters, numbers, dots, underscores or hyphens.');
        $q=$db->prepare('SELECT id FROM users WHERE username_hash=? AND id<>?'); $q->execute([hashLookup($name),$id]);
        if($q->fetchColumn()) throw new RuntimeException('That username is already used.');
        $db->prepare('UPDATE users SET firstname=?, middlename=?, lastname=?, username=?, username_hash=?, contact_number=? WHERE id=?')->execute([$first,$middle,$last,$name!==''?encryptAES256($name):null,$name!==''?hashLookup($name):null,$phoneEnc,$id]);
    } elseif(($data['action'] ?? '')==='password') {
        $password=(string)($data['new_password'] ?? '');
        if(strlen($password)<8 || strlen($password)>72) throw new RuntimeException('Use a password between 8 and 72 characters.');
        if($password!==($data['confirm_password'] ?? '')) throw new RuntimeException('New passwords do not match.');
        $db->prepare('UPDATE users SET password_hash=?, password_encrypted=NULL WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
    } else throw new RuntimeException('Invalid action.');
    echo json_encode(['success'=>true]);
} catch(Throwable $e) {
    if(isset($db) && $db->inTransaction()) $db->rollBack();
    if(http_response_code()<400) http_response_code(422);
    echo json_encode(['success'=>false,'message'=>$e instanceof PDOException?'Unable to update account. Please try again.':$e->getMessage()]);
}
