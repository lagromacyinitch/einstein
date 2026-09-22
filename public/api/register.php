<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/bootstrap.php';
setSecurityHeaders();
try { rateLimit('register_' . md5($_SERVER['REMOTE_ADDR'] ?? ''), 5, 600); } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }

function digitsOnly(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
}

function normalizeName(string $value, string $fieldLabel): string {
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '') return '';
    if (!preg_match('/^[A-Za-z\s]+$/', $value)) {
        throw new Exception($fieldLabel . ' may only contain letters and spaces.');
    }
    return ucwords(strtolower($value));
}

try {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $post     = array_merge($_POST, $input);

    $email          = strtolower(trim($post['email'] ?? ''));
    $password       = trim($post['password'] ?? '');
    $username       = trim($post['username'] ?? '');
    $firstname      = normalizeName($post['firstname'] ?? '', 'First name');
    $lastname       = normalizeName($post['lastname'] ?? '', 'Last name');
    $middlename     = normalizeName($post['middlename'] ?? '', 'Middle name');
    $birthdate      = trim($post['birthdate'] ?? '');
    $address        = trim($post['address'] ?? '');
    $contactNumber  = digitsOnly(trim($post['contact_number'] ?? $post['contact'] ?? ''));

    // ── Validate ─────────────────────────────────────
    if (!$email)    throw new Exception('Email is required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        throw new Exception('Invalid email address.');
    if (strlen($password) < 8)
        throw new Exception('Password must be at least 8 characters.');

    $db = getDB();

    // ── Cryptographic Pre-Processing (AES-256 & HMAC Blind Indexing) ──
    $emailHash        = hashLookup($email);
    $usernameHash     = $username ? hashLookup($username) : null;
    $emailEncrypted    = encryptAES256($email);
    $usernameEncrypted = $username ? encryptAES256($username) : null;
    $passwordEncrypted = encryptAES256($password); // AES-256 encrypted vault copy
    $passwordHash      = password_hash($password, PASSWORD_BCRYPT);
    $firstnameEnc      = encryptAES256($firstname);
    $lastnameEnc       = encryptAES256($lastname);
    $middlenameEnc     = encryptAES256($middlename);
    $birthdateEnc      = encryptAES256($birthdate);
    $addressEnc        = encryptAES256($address);
    $contactEnc        = encryptAES256($contactNumber);

    // Check if email already registered via lookup hash or plaintext email
    $stmt = $db->prepare("SELECT id, is_verified FROM users WHERE email_hash=? OR email=?");
    $stmt->execute([$emailHash, $email]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Allow re-registration if not yet verified (e.g., abandoned sign-up)
        if ($existing['is_verified']) {
            throw new Exception('This email is already registered. Please log in instead.');
        }
        // Update password & profile for unverified account
        $db->prepare("UPDATE users SET email_encrypted=?, email_hash=?, username=?, username_hash=?, password_hash=?, password_encrypted=?, firstname=?, lastname=?, middlename=?, birthdate=?, address=?, contact_number=? WHERE id=?")
           ->execute([
               $emailEncrypted, $emailHash, $usernameEncrypted, $usernameHash,
               $passwordHash, $passwordEncrypted, $firstnameEnc, $lastnameEnc,
               $middlenameEnc, $birthdateEnc, $addressEnc, $contactEnc, $existing['id']
           ]);
        $userId = (int) $existing['id'];
    } else {
        // Create new user with AES-256 encrypted fields at rest
        $stmtInsert = $db->prepare("INSERT INTO users 
            (email, email_encrypted, email_hash, username, username_hash, password_hash, password_encrypted, firstname, lastname, middlename, birthdate, address, contact_number)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmtInsert->execute([
            $email, $emailEncrypted, $emailHash, $usernameEncrypted, $usernameHash,
            $passwordHash, $passwordEncrypted, $firstnameEnc, $lastnameEnc,
            $middlenameEnc, $birthdateEnc, $addressEnc, $contactEnc
        ]);
        $userId = (int) $db->lastInsertId();
    }

    ob_clean();
    echo json_encode([
        'success'  => true,
        'message'  => 'Account created successfully with AES-256 encrypted storage. Please verify your email.',
        'user_id'  => $userId,
        'email'    => $email,
    ]);

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
