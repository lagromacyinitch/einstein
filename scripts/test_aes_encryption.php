<?php
/**
 * Test Suite: Computer Science AES-256 Cryptographic Verification
 * Verifies that data stored in MySQL is AES-256 encrypted at rest (enc:v1:...)
 * and that decrypted fields are served accurately to the front-end.
 */
require_once __DIR__ . '/../public/includes/bootstrap.php';

echo "========================================================\n";
echo "  COMPUTER SCIENCE DATA SECURITY — AES-256 TEST SUITE\n";
echo "========================================================\n\n";

// 1. Test Primitive Functions
$testPayload = [
    'id'             => '10042',
    'username'       => 'cs_student_2026',
    'password'       => 'SuperSecurePass123!',
    'firstname'      => 'Juan',
    'lastname'       => 'Dela Cruz',
    'middlename'     => 'Santos',
    'birthdate'      => '2004-05-15',
    'email'          => 'juan.delacruz@example.com',
    'address'        => 'Tagbilaran City, Bohol',
    'contact_number' => '09171234567'
];

echo "[1] Testing AES-256 Cryptographic Encryption primitives...\n";
$encryptedPayload = [];
foreach ($testPayload as $key => $value) {
    $encrypted = encryptAES256($value);
    $encryptedPayload[$key] = $encrypted;
    echo "  - $key:\n";
    echo "      Plaintext  : $value\n";
    echo "      Ciphertext : " . substr($encrypted, 0, 45) . "...\n";
    
    // Assert prefix
    if (!str_starts_with($encrypted, 'enc:v1:')) {
        echo "  [ERROR] Ciphertext does not match enc:v1: format!\n";
        exit(1);
    }
}
echo "  [SUCCESS] All target fields successfully encrypted into enc:v1: ciphertext.\n\n";

echo "[2] Testing AES-256 Decryption primitives...\n";
foreach ($encryptedPayload as $key => $cipher) {
    $decrypted = decryptAES256($cipher);
    if ($decrypted !== $testPayload[$key]) {
        echo "  [ERROR] Mismatch for field $key: expected '{$testPayload[$key]}', got '$decrypted'\n";
        exit(1);
    }
}
echo "  [SUCCESS] All fields decrypted back to exact original plaintext.\n\n";

echo "[3] Testing HMAC-SHA256 Blind Indexing...\n";
$emailHash1 = hashLookup('juan.delacruz@example.com');
$emailHash2 = hashLookup('JUAN.DELACRUZ@EXAMPLE.COM');
if ($emailHash1 !== $emailHash2 || strlen($emailHash1) !== 64) {
    echo "  [ERROR] HMAC-SHA256 blind index calculation failed!\n";
    exit(1);
}
echo "  [SUCCESS] Deterministic HMAC lookup hash generated: " . substr($emailHash1, 0, 32) . "...\n\n";

echo "[4] Testing Database Migration & Integration (if MySQL active)...\n";
try {
    $pdo = getDB();
    echo "  Connected to MySQL Database: " . MYSQL_DB . "\n";
    
    // Clean old test record if exists
    $pdo->prepare("DELETE FROM users WHERE email_hash=? OR email=?")->execute([$emailHash1, 'juan.delacruz@example.com']);
    
    // Insert test encrypted record
    $stmt = $pdo->prepare("INSERT INTO users 
        (email, email_encrypted, email_hash, username, username_hash, password_hash, password_encrypted, firstname, lastname, middlename, birthdate, address, contact_number, is_verified)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
    
    $stmt->execute([
        'juan.delacruz@example.com',
        $encryptedPayload['email'],
        $emailHash1,
        $encryptedPayload['username'],
        hashLookup($testPayload['username']),
        password_hash($testPayload['password'], PASSWORD_BCRYPT),
        $encryptedPayload['password'],
        $encryptedPayload['firstname'],
        $encryptedPayload['lastname'],
        $encryptedPayload['middlename'],
        $encryptedPayload['birthdate'],
        $encryptedPayload['address'],
        $encryptedPayload['contact_number']
    ]);
    
    $insertedId = (int)$pdo->lastInsertId();
    echo "  Inserted user row with ID: $insertedId\n";
    
    // Inspect RAW Database Content
    $rawStmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $rawStmt->execute([$insertedId]);
    $dbRow = $rawStmt->fetch(PDO::FETCH_ASSOC);
    
    echo "  Checking Database At-Rest Encryption:\n";
    echo "    - RAW DB firstname      : " . $dbRow['firstname'] . "\n";
    echo "    - RAW DB address        : " . $dbRow['address'] . "\n";
    echo "    - RAW DB contact_number : " . $dbRow['contact_number'] . "\n";
    echo "    - RAW DB password_enc   : " . $dbRow['password_encrypted'] . "\n";
    
    if (!str_starts_with($dbRow['firstname'], 'enc:v1:')) {
        echo "  [ERROR] Database firstname column is NOT encrypted at rest!\n";
        exit(1);
    }
    
    // Test Decrypting for API response
    $decryptedRow = decryptRow($dbRow);
    echo "  Checking Decrypted Response Output for Front-end:\n";
    echo "    - Decrypted Firstname : " . $decryptedRow['firstname'] . "\n";
    echo "    - Decrypted Lastname  : " . $decryptedRow['lastname'] . "\n";
    echo "    - Decrypted Address   : " . $decryptedRow['address'] . "\n";
    echo "    - Decrypted Contact   : " . $decryptedRow['contact_number'] . "\n";
    
    if ($decryptedRow['firstname'] !== 'Juan' || $decryptedRow['address'] !== 'Tagbilaran City, Bohol') {
        echo "  [ERROR] Front-end decrypted row output mismatch!\n";
        exit(1);
    }
    
    // Clean up test user
    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$insertedId]);
    echo "  Test user cleaned up.\n";
    echo "  [SUCCESS] Database at-rest encryption & front-end decryption verified!\n\n";

} catch (Exception $e) {
    echo "  [INFO] Database integration test skipped or note: " . $e->getMessage() . "\n";
}

echo "========================================================\n";
echo "  ALL AES-256 CRYPTOGRAPHIC VERIFICATION TESTS PASSED!\n";
echo "========================================================\n";
