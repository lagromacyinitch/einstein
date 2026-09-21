<?php
require_once 'config.php';
header('Content-Type: application/json');

try {
    $db = getDB();
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    // We can also allow GET parameters for filtering
    $action = $_GET['action'] ?? $input['action'] ?? null;

    if ($method === 'GET') {
        if ($action === 'list') {
            $program_name = $_GET['program_name'] ?? null;
            $package_type = $_GET['package_type'] ?? null;
            
            $query = "SELECT * FROM program_packages WHERE 1=1";
            $params = [];
            
            if ($program_name) {
                $query .= " AND program_name = ?";
                $params[] = $program_name;
            }
            if ($package_type) {
                $query .= " AND package_type = ?";
                $params[] = $package_type;
            }
            
            $query .= " ORDER BY program_name, package_name";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $results]);
            exit;
        }
    } 
    elseif ($method === 'POST') {
        // Program-specific types replace the legacy generic label.
        $programKey = strtolower(preg_replace('/\s+/', '', $input['program_name'] ?? ''));
        if (in_array($programKey, ['playschool', 'playschoolprogram'], true)) {
            $input['package_type'] = 'playschool';
        } elseif (in_array($programKey, ['workshop', 'weekendworkshop'], true)) {
            $input['package_type'] = 'workshop';
        }
        if ($action === 'add') {
            $stmt = $db->prepare("INSERT INTO program_packages (program_name, package_name, care_duration, rate, capacity_slots, package_type) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $input['program_name'] ?? '',
                $input['package_name'] ?? '',
                $input['care_duration'] ?? '',
                $input['rate'] ?? '',
                $input['capacity_slots'] ?? '',
                $input['package_type'] ?? 'general'
            ]);
            echo json_encode(['success' => true, 'message' => 'Package added successfully.', 'id' => $db->lastInsertId()]);
            exit;
        } 
        elseif ($action === 'update') {
            $id = $input['id'] ?? null;
            if (!$id) throw new Exception("Package ID is required for update.");
            
            $stmt = $db->prepare("UPDATE program_packages SET program_name=?, package_name=?, care_duration=?, rate=?, capacity_slots=?, package_type=? WHERE id=?");
            $stmt->execute([
                $input['program_name'] ?? '',
                $input['package_name'] ?? '',
                $input['care_duration'] ?? '',
                $input['rate'] ?? '',
                $input['capacity_slots'] ?? '',
                $input['package_type'] ?? 'general',
                $id
            ]);
            echo json_encode(['success' => true, 'message' => 'Package updated successfully.']);
            exit;
        } 
        elseif ($action === 'delete') {
            $id = $input['id'] ?? null;
            if (!$id) throw new Exception("Package ID is required for deletion.");
            
            $stmt = $db->prepare("DELETE FROM program_packages WHERE id=?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Package deleted successfully.']);
            exit;
        }
    }

    throw new Exception("Invalid method or action.");

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
