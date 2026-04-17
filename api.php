<?php

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

$validApiKey = 'fba35b6f5adac99d4c2bffbc8f87e2da6faf4b0e3613aeb9dc786ea63de1e05d';
$requestApiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

if ($requestApiKey !== $validApiKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$path   = app_route_path();
if (strpos($path, 'index.php/') === 0) {
    $path = substr($path, strlen('index.php/'));
}
if (strpos($path, 'api.php/') === 0) {
    $path = substr($path, strlen('api.php/'));
}
if ($path === 'api.php') {
    $path = '';
}
if (strpos($path, 'api/') === 0) {
    $path = substr($path, 4);
}
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

match (true) {
    $path === 'report-data' && $method === 'POST' => handleReportData($conn, $body),
    $path === 'parse-nfc'   && $method === 'POST' => handleParseNfc($conn, $body),
    $path === 'take-picture' && $method === 'POST' => handleTakePicture($conn, $body),
    $path === 'ongoing-booking' && ($method === 'POST' || $method === 'GET') => handleOngoingBooking($conn, $body),
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
        sub.end_time,
        sub.checked_out_at
    FROM (
        SELECT
            b.student_id,
            b.room_id,
            b.booking_date,
            MIN(ts.start_time) AS start_time,
            MAX(ts.end_time)   AS end_time,
            MAX(b.checked_out_at) AS checked_out_at
        FROM bookings b
        JOIN timeslots ts ON ts.timeslot_id = b.timeslot_id
        WHERE b.room_id      = ?
          AND b.booking_date = ?
        GROUP BY b.student_id, b.room_id, b.booking_date
    ) sub
    JOIN students s ON s.student_id = sub.student_id
    WHERE ? BETWEEN sub.start_time AND sub.end_time
            AND s.student_number = ?
            AND COALESCE(s.is_suspended, 0) = 0");
    $stmt->bind_param('isss', $roomId, $today, $nowTime, $studentNumber);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    if (!$booking) {
        echo json_encode([
            'status' => 402,
            'is_valid' => false,
            'message'  => 'No active booking found for this student in this room right now.',
            'student_id' => $studentNumber
        ]);
        return;
    }

    if (!empty($booking['checked_out_at'])) {
        echo json_encode([
            'status' => 403,
            'is_valid' => false,
            'message' => 'Booking already checked out. Room access is no longer allowed.',
            'student_id' => $studentNumber,
        ]);
        return;
    }

    // Check In Student if the checked_in_at is null
    $checkInStmt = $conn->prepare("
        UPDATE bookings b
        JOIN students s ON s.student_id = b.student_id
        SET b.checked_in_at = NOW()
        WHERE b.room_id = ? AND b.booking_date = ? AND s.student_number = ?
            AND b.checked_in_at IS NULL
            AND b.checked_out_at IS NULL
    ");
    $checkInStmt->bind_param('iss', $roomId, $today, $studentNumber);
    $checkInStmt->execute();


    echo json_encode([
        'status' => 200,
        'is_valid' => true,
        'student_name' => $booking['student_name'] ?? ' ',
        'student_number' => $booking['student_number'] ?? ' ',
        'message'  => 'Access granted. Booking from ' . substr($booking['start_time'], 0, 5) . ' to ' . substr($booking['end_time'], 0, 5) . '.',
        'booking' => $booking
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


// POST/GET /ongoing-booking
// Body or query: { "room_id": 1 }
function handleOngoingBooking(mysqli $conn, array $body): void
{
    $roomId = (int)($body['room_id'] ?? ($_GET['room_id'] ?? 0));

    if ($roomId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'room_id is required']);
        return;
    }

    $today = date('Y-m-d');
    $nowTime = date('H:i:s');

    $stmt = $conn->prepare("SELECT
        b.room_id,
        r.room_number,
        s.student_id,
        s.student_number,
        COALESCE(s.name, s.student_number) AS student_name,
        b.booking_date,
        MIN(ts.start_time) AS start_time,
        MAX(ts.end_time) AS end_time
    FROM bookings b
    JOIN rooms r ON r.room_id = b.room_id
    JOIN students s ON s.student_id = b.student_id
    JOIN timeslots ts ON ts.timeslot_id = b.timeslot_id
    WHERE b.room_id = ?
      AND b.booking_date = ?
            AND b.checked_out_at IS NULL
    GROUP BY b.room_id, r.room_number, s.student_id, s.student_number, s.name, b.booking_date
    HAVING ? BETWEEN MIN(ts.start_time) AND MAX(ts.end_time)
    ORDER BY MIN(ts.start_time) ASC
    LIMIT 1");

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to prepare query']);
        return;
    }

    $stmt->bind_param('iss', $roomId, $today, $nowTime);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        echo json_encode([
            'success' => true,
            'has_ongoing_booking' => false,
            'room_id' => $roomId,
            'checked_at' => date('Y-m-d H:i:s'),
            'message' => 'No ongoing booking for this room right now.',
        ]);
        return;
    }

    $endTime = (string)($booking['end_time'] ?? '');
    $endTimestamp = strtotime($today . ' ' . $endTime);
    $minutesLeft = 0;

    if ($endTimestamp !== false) {
        $secondsLeft = max(0, $endTimestamp - time());
        $minutesLeft = (int)ceil($secondsLeft / 60);
    }

    echo json_encode([
        'success' => true,
        'has_ongoing_booking' => true,
        'room_id' => $roomId,
        'checked_at' => date('Y-m-d H:i:s'),
        'message' => 'Ongoing booking found.',
        'booking' => [
            'room_id' => (int)$booking['room_id'],
            'room_number' => (string)($booking['room_number'] ?? ''),
            'student_id' => (int)$booking['student_id'],
            'student_number' => (string)($booking['student_number'] ?? ''),
            'student_name' => (string)($booking['student_name'] ?? ''),
            'booking_date' => (string)($booking['booking_date'] ?? ''),
            'start_time' => (string)($booking['start_time'] ?? ''),
            'end_time' => $endTime,
            'minutes_left' => $minutesLeft,
        ],
    ]);
}


