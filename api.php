
<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
date_default_timezone_set('Asia/Manila');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include 'db.php';

$action = $_GET['action'] ?? '';

function get_request_data() {
    $input = json_decode(file_get_contents('php://input'), true);
    return !empty($input) ? $input : $_POST;
}

$timestamp = date('Y-m-d H:i:s');

// 1. GET ALL INVENTORY & TRANSACTIONS
if ($action === 'get_all') {
    $inventoryResult = $conn->query("SELECT * FROM inventory ORDER BY id ASC");
    $inventory = $inventoryResult ? $inventoryResult->fetch_all(MYSQLI_ASSOC) : [];

    $txResult = $conn->query("SELECT * FROM transactions ORDER BY id DESC");
    $transactions = $txResult ? $txResult->fetch_all(MYSQLI_ASSOC) : [];

    echo json_encode([
        'inventory' => $inventory,
        'transactions' => $transactions
    ]);
    exit;
}

// 2. SAVE OR UPDATE ITEM
if ($action === 'save_item') {
    $data = get_request_data();
    $id = trim($data['id'] ?? '');
    $name = trim($data['name'] ?? '');
    $beginning = isset($data['beginning']) ? (int)$data['beginning'] : 0;

    if (empty($id) || empty($name)) {
        echo json_encode(['success' => false, 'message' => 'ID and Name are required']);
        exit;
    }

    $check = $conn->prepare("SELECT delivery, issued FROM inventory WHERE id = ?");
    $check->bind_param("s", $id);
    $check->execute();
    $res = $check->get_result();

    if ($row = $res->fetch_assoc()) {
        $delivery = (int)$row['delivery'];
        $issued = (int)$row['issued'];
        $ending = $beginning + $delivery - $issued;
        $stmt = $conn->prepare("UPDATE inventory SET name = ?, beginning = ?, ending = ? WHERE id = ?");
        $stmt->bind_param("siis", $name, $beginning, $ending, $id);
    } else {
        $ending = $beginning;
        $stmt = $conn->prepare("INSERT INTO inventory (id, name, beginning, delivery, issued, ending) VALUES (?, ?, ?, 0, 0, ?)");
        $stmt->bind_param("ssii", $id, $name, $beginning, $ending);
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    exit;
}

// 3. ADD DELIVERY STOCKS
if ($action === 'add_delivery') {
    $data = get_request_data();
    $id = trim($data['itemId'] ?? '');
    $qty = isset($data['qty']) ? (int)$data['qty'] : 0;
    $receivedBy = trim($data['receivedBy'] ?? 'Unknown');

    $stmt = $conn->prepare("SELECT * FROM inventory WHERE id = ?");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();

    if ($item && $qty > 0) {
        $newDelivery = (int)$item['delivery'] + $qty;
        $newEnding = (int)$item['beginning'] + $newDelivery - (int)$item['issued'];

        $update = $conn->prepare("UPDATE inventory SET delivery = ?, ending = ? WHERE id = ?");
        $update->bind_param("iis", $newDelivery, $newEnding, $id);
        $update->execute();

        $timestamp = date('Y-m-d H:i:s');
        $log = $conn->prepare("INSERT INTO transactions (timestamp, type, itemId, itemName, qty, issuer) VALUES (?, 'delivery', ?, ?, ?, ?)");
        $log->bind_param("sssis", $timestamp, $id, $item['name'], $qty, $receivedBy);
        $log->execute();

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid item or quantity']);
    }
    exit;
}

// 4. ISSUE STOCK (Deduct stock & log transaction)
if ($action === 'issue_stock') {
    $data = get_request_data();
    $id = trim($data['itemId'] ?? '');
    $qty = isset($data['qty']) ? (int)$data['qty'] : 0;
    $issuer = trim($data['issuer'] ?? 'Unknown');

    $stmt = $conn->prepare("SELECT * FROM inventory WHERE id = ?");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();

    if ($item && (int)$item['ending'] >= $qty && $qty > 0) {
        $newIssued = (int)$item['issued'] + $qty;
        $newEnding = (int)$item['beginning'] + (int)$item['delivery'] - $newIssued;

        $update = $conn->prepare("UPDATE inventory SET issued = ?, ending = ? WHERE id = ?");
        $update->bind_param("iis", $newIssued, $newEnding, $id);
        $update->execute();

        $timestamp = date('Y-m-d H:i:s');
        $log = $conn->prepare("INSERT INTO transactions (timestamp, type, itemId, itemName, qty, issuer) VALUES (?, 'issue', ?, ?, ?, ?)");
        $log->bind_param("sssis", $timestamp, $id, $item['name'], $qty, $issuer);
        $log->execute();

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Insufficient stock or invalid quantity']);
    }
    exit;
}

// 5. DELETE ITEM
if ($action === 'delete_item') {
    $id = $_GET['id'] ?? '';
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Missing item ID']);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
    $stmt->bind_param("s", $id);

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt->error]);
    }
    exit;
}

// 6. CLEAR TRANSACTIONS LOG
if ($action === 'clear_transactions') {
    if ($conn->query("TRUNCATE TABLE transactions") === true) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
    exit;
}

// 7. START NEW MONTH (Carry over ending stock, reset delivery & issued)
if ($action === 'start_new_month') {
    $conn->begin_transaction();
    try {
        $conn->query("UPDATE inventory SET beginning = ending, delivery = 0, issued = 0");
        $conn->query("TRUNCATE TABLE transactions");
        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 8. UNDO TRANSACTION (Handles both Issue & Delivery reversals)
if ($action === 'undo_transaction') {
    $data = get_request_data();
    $txId = $data['transactionId'] ?? null;

    if (!$txId) {
        echo json_encode(['success' => false, 'message' => 'Transaction ID is required']);
        exit;
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ?");
        $stmt->bind_param("i", $txId);
        $stmt->execute();
        $tx = $stmt->get_result()->fetch_assoc();

        if (!$tx) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Transaction not found']);
            exit;
        }

        $itemId = $tx['itemId'];
        $qty = (int)$tx['qty'];
        $type = $tx['type'] ?? 'issue';

        if ($type === 'delivery') {
            $updateStmt = $conn->prepare("UPDATE inventory SET delivery = delivery - ?, ending = ending - ? WHERE id = ?");
        } else {
            $updateStmt = $conn->prepare("UPDATE inventory SET issued = issued - ?, ending = ending + ? WHERE id = ?");
        }
        $updateStmt->bind_param("iis", $qty, $qty, $itemId);
        $updateStmt->execute();

        $delStmt = $conn->prepare("DELETE FROM transactions WHERE id = ?");
        $delStmt->bind_param("i", $txId);
        $delStmt->execute();

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action specified']);