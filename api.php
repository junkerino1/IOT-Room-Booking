<?php

date_default_timezone_set('Asia/Kuala_Lumpur');

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$validApiKey = 'fba35b6f5adac99d4c2bffbc8f87e2da6faf4b0e3613aeb9dc786ea63de1e05d';
$requestApiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

if ($requestApiKey !== $validApiKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$path   = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if (strpos($path, 'api/') === 0) {
    $path = substr($path, 4);
}
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

match (true) {
    $path === 'report-data' && $method === 'POST' => handleReportData($conn, $body),
    $path === 'parse-nfc'   && $method === 'POST' => handleParseNfc($conn, $body),
    $path === 'take-picture' && $method === 'POST' => handleTakePicture($conn, $body),
    default => (function () {
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
    })()
};

// POST /report-data

function handleReportData(mysqli $conn, array $body): void
{
    $roomId = isset($body['room_id']) ? (int)$body['room_id'] : null;
    $temperature = isset($body['temperature']) ? (float)$body['temperature'] : null;
    $humidity = isset($body['humidity']) ? (float)$body['humidity'] : null;
    $distance = isset($body['distance']) ? (float)$body['distance'] : null;
    $systemStatus = isset($body['system_status'])  ? (string)$body['system_status'] : 'unknown';
    

    $stmt = $conn->prepare("
        INSERT INTO sensor_logs (room_id, temperature, humidity, distance, system_status, logged_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param('iddds', $roomId, $temperature, $humidity, $distance, $systemStatus);
    $stmt->execute();

    echo json_encode([
        'success'   => true,
        'logged_at' => date('Y-m-d H:i:s'),
    ]);
}

// POST /parse-nfc
function handleParseNfc(mysqli $conn, array $body): void
{
    $nfcRaw = trim((string)($body['nfc_raw_data'] ?? ''));
    $studentNumber = explode('|', $nfcRaw)[1] ?? '';
    $roomId        = (int)($body['room_id'] ?? 0);
    $today         = date('Y-m-d');
    $nowTime       = date('H:i:s');

    if ($studentNumber === '' || $roomId === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'student_number and room_id are required']);
        return;
    }

    // Find booking for this room + today + within time window
    // Merge adjacent timeslots booked by the same student
    $stmt = $conn->prepare("SELECT
        s.student_number,
        s.name AS student_name,
        sub.start_time,
        sub.end_time
    FROM (
        SELECT
            b.student_id,
            b.room_id,
            b.booking_date,
            MIN(ts.start_time) AS start_time,
            MAX(ts.end_time)   AS end_time
        FROM bookings b
        JOIN timeslots ts ON ts.timeslot_id = b.timeslot_id
        WHERE b.room_id      = ?
          AND b.booking_date = ?
        GROUP BY b.student_id, b.room_id, b.booking_date
    ) sub
    JOIN students s ON s.student_id = sub.student_id
    WHERE ? BETWEEN sub.start_time AND sub.end_time
      AND s.student_number = ?");
    $stmt->bind_param('isss', $roomId, $today, $nowTime, $studentNumber);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    if (!$booking) {
        echo json_encode([
            'status' => 402,
            'is_valid' => false,
            'message'  => 'No active booking found for this student in this room right now.',
        ]);
        return;
    }

    echo json_encode([
        'status' => 200,
        'is_valid' => true,
        'student_name' => $booking['student_name'] ?? ' ',
        'student_number' => $booking['student_number'] ?? ' ',
        'message'  => 'Access granted. Booking from ' . substr($booking['start_time'], 0, 5) . ' to ' . substr($booking['end_time'], 0, 5) . '.',
    ]);
}


// ─── Client: Take picture from webcam ────────────────────────────────────
// POST /take-picture
// Body: { "room_id": 1 }
function handleTakePicture(mysqli $conn, array $body): void
{
    $roomId = (int)($body['room_id'] ?? 0);

    if ($roomId === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'room_id is required']);
        return;
    }

    // Signal Pi to capture — you can store a pending command in DB
    // and have Pi poll it, or use a direct Pi endpoint here
    $stmt = $conn->prepare("
        INSERT INTO pi_commands (room_id, command, status, created_at)
        VALUES (?, 'take_picture', 'pending', NOW())
    ");
    $stmt->bind_param('i', $roomId);
    $stmt->execute();

    echo json_encode([
        'success'    => true,
        'command_id' => $conn->insert_id,
        'message'    => 'Picture command queued',
    ]);
}


