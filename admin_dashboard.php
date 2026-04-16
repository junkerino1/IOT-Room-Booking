<?php

if (!isset($conn)) {
    require_once __DIR__ . '/db.php';
}
if (!function_exists('app_url')) {
    require_once __DIR__ . '/bootstrap.php';
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ' . app_url('availability'));
    exit;
}

$noticeType = '';
$noticeText = '';

$studentFrom = (string)($_GET['student_from'] ?? date('Y-m-d', strtotime('-1 month')));
$studentTo = (string)($_GET['student_to'] ?? date('Y-m-d'));

$normalizeDate = static function (string $value, string $fallback): string {
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if ($dt && $dt->format('Y-m-d') === $value) {
        return $value;
    }

    return $fallback;
};

$studentFrom = $normalizeDate($studentFrom, date('Y-m-d', strtotime('-1 month')));
$studentTo = $normalizeDate($studentTo, date('Y-m-d'));

if ($studentFrom > $studentTo) {
    $tmp = $studentFrom;
    $studentFrom = $studentTo;
    $studentTo = $tmp;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'toggle_student_suspend') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $suspendValue = ((int)($_POST['suspend'] ?? 0) === 1) ? 1 : 0;

        if ($studentId <= 0) {
            $noticeType = 'error';
            $noticeText = 'Invalid student selected.';
        } else {
            $stmt = $conn->prepare('UPDATE students SET is_suspended = ? WHERE student_id = ?');
            if ($stmt) {
                $stmt->bind_param('ii', $suspendValue, $studentId);
                $stmt->execute();
                $stmt->close();
                $noticeType = 'success';
                $noticeText = $suspendValue === 1 ? 'Student suspended successfully.' : 'Student unsuspended successfully.';
            } else {
                $noticeType = 'error';
                $noticeText = 'Unable to update student status.';
            }
        }
    } elseif ($action === 'add_student') {
        $studentNumber = strtoupper(trim((string)($_POST['student_number'] ?? '')));
        $studentPassword = (string)($_POST['student_password'] ?? '');

        if ($studentNumber === '' || $studentPassword === '') {
            $noticeType = 'error';
            $noticeText = 'Please provide student ID and password.';
        } elseif (strlen($studentPassword) < 6) {
            $noticeType = 'error';
            $noticeText = 'Student password must be at least 6 characters.';
        } else {
            $checkStmt = $conn->prepare('SELECT student_id FROM students WHERE student_number = ? LIMIT 1');
            if (!$checkStmt) {
                $noticeType = 'error';
                $noticeText = 'Unable to validate student ID.';
            } else {
                $checkStmt->bind_param('s', $studentNumber);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();

                if ($exists) {
                    $noticeType = 'error';
                    $noticeText = 'Student ID already exists.';
                } else {
                    $displayName = $studentNumber;
                    $passwordHash = app_password_hash($studentPassword);
                    $insertStmt = $conn->prepare('INSERT INTO students (name, student_number, password, is_suspended) VALUES (?, ?, ?, 0)');

                    if ($insertStmt) {
                        $insertStmt->bind_param('sss', $displayName, $studentNumber, $passwordHash);
                        $insertStmt->execute();
                        $insertStmt->close();
                        $noticeType = 'success';
                        $noticeText = 'Student account created successfully.';
                    } else {
                        $noticeType = 'error';
                        $noticeText = 'Unable to create student account.';
                    }
                }
            }
        }
    } elseif ($action === 'add_admin') {
        $adminNumber = strtoupper(trim((string)($_POST['admin_number'] ?? '')));
        $adminName = trim((string)($_POST['admin_name'] ?? ''));
        $adminPassword = (string)($_POST['admin_password'] ?? '');

        if ($adminNumber === '' || $adminName === '' || $adminPassword === '') {
            $noticeType = 'error';
            $noticeText = 'Please complete all add admin fields.';
        } elseif (strlen($adminPassword) < 6) {
            $noticeType = 'error';
            $noticeText = 'Admin password must be at least 6 characters.';
        } else {
            $checkStmt = $conn->prepare('SELECT admin_id FROM admins WHERE admin_number = ? LIMIT 1');
            if (!$checkStmt) {
                $noticeType = 'error';
                $noticeText = 'Unable to validate admin ID.';
            } else {
                $checkStmt->bind_param('s', $adminNumber);
                $checkStmt->execute();
                $existingAdmin = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();

                if ($existingAdmin) {
                    $noticeType = 'error';
                    $noticeText = 'Admin ID already exists.';
                } else {
                    $passwordHash = app_password_hash($adminPassword);
                    $insertStmt = $conn->prepare('INSERT INTO admins (admin_number, name, password, is_active, created_at) VALUES (?, ?, ?, 1, NOW())');
                    if ($insertStmt) {
                        $insertStmt->bind_param('sss', $adminNumber, $adminName, $passwordHash);
                        $insertStmt->execute();
                        $insertStmt->close();
                        $noticeType = 'success';
                        $noticeText = 'Admin created successfully.';
                    } else {
                        $noticeType = 'error';
                        $noticeText = 'Unable to create admin account.';
                    }
                }
            }
        }
    } elseif ($action === 'update_admin') {
        $targetAdminId = (int)($_POST['admin_id'] ?? 0);
        $adminNumber = strtoupper(trim((string)($_POST['admin_number'] ?? '')));
        $adminName = trim((string)($_POST['admin_name'] ?? ''));
        $adminPassword = (string)($_POST['admin_password'] ?? '');
        $adminIsActive = ((int)($_POST['is_active'] ?? 1) === 1) ? 1 : 0;

        if ($targetAdminId <= 0 || $adminNumber === '' || $adminName === '') {
            $noticeType = 'error';
            $noticeText = 'Please provide valid admin details for update.';
        } else {
            $dupStmt = $conn->prepare('SELECT admin_id FROM admins WHERE admin_number = ? AND admin_id != ? LIMIT 1');
            $duplicate = false;
            if ($dupStmt) {
                $dupStmt->bind_param('si', $adminNumber, $targetAdminId);
                $dupStmt->execute();
                $duplicate = (bool)$dupStmt->get_result()->fetch_assoc();
                $dupStmt->close();
            }

            if ($duplicate) {
                $noticeType = 'error';
                $noticeText = 'Another admin already uses this admin ID.';
            } else {
                if ($adminPassword !== '') {
                    if (strlen($adminPassword) < 6) {
                        $noticeType = 'error';
                        $noticeText = 'New password must be at least 6 characters.';
                    } else {
                        $passwordHash = app_password_hash($adminPassword);
                        $updateStmt = $conn->prepare('UPDATE admins SET admin_number = ?, name = ?, password = ?, is_active = ? WHERE admin_id = ?');
                        if ($updateStmt) {
                            $updateStmt->bind_param('sssii', $adminNumber, $adminName, $passwordHash, $adminIsActive, $targetAdminId);
                            $updateStmt->execute();
                            $updateStmt->close();
                            $noticeType = 'success';
                            $noticeText = 'Admin updated successfully.';
                        } else {
                            $noticeType = 'error';
                            $noticeText = 'Unable to update admin record.';
                        }
                    }
                } else {
                    $updateStmt = $conn->prepare('UPDATE admins SET admin_number = ?, name = ?, is_active = ? WHERE admin_id = ?');
                    if ($updateStmt) {
                        $updateStmt->bind_param('ssii', $adminNumber, $adminName, $adminIsActive, $targetAdminId);
                        $updateStmt->execute();
                        $updateStmt->close();
                        $noticeType = 'success';
                        $noticeText = 'Admin updated successfully.';
                    } else {
                        $noticeType = 'error';
                        $noticeText = 'Unable to update admin record.';
                    }
                }

                if ($noticeType === 'success' && (int)($_SESSION['admin_id'] ?? 0) === $targetAdminId) {
                    $_SESSION['admin_number'] = $adminNumber;
                    $_SESSION['admin_name'] = $adminName;
                    $_SESSION['name'] = $adminName;
                }
            }
        }
    } elseif ($action === 'delete_admin') {
        $targetAdminId = (int)($_POST['admin_id'] ?? 0);
        $currentAdminId = (int)($_SESSION['admin_id'] ?? 0);

        if ($targetAdminId <= 0) {
            $noticeType = 'error';
            $noticeText = 'Invalid admin selected.';
        } elseif ($targetAdminId === $currentAdminId) {
            $noticeType = 'error';
            $noticeText = 'You cannot delete your own admin account.';
        } else {
            $countStmt = $conn->prepare('SELECT COUNT(*) AS total_admins FROM admins');
            $adminCount = 0;
            if ($countStmt) {
                $countStmt->execute();
                $countRow = $countStmt->get_result()->fetch_assoc();
                $countStmt->close();
                $adminCount = (int)($countRow['total_admins'] ?? 0);
            }

            if ($adminCount <= 1) {
                $noticeType = 'error';
                $noticeText = 'At least one admin account must remain.';
            } else {
                $deleteStmt = $conn->prepare('DELETE FROM admins WHERE admin_id = ?');
                if ($deleteStmt) {
                    $deleteStmt->bind_param('i', $targetAdminId);
                    $deleteStmt->execute();
                    $deleteStmt->close();
                    $noticeType = 'success';
                    $noticeText = 'Admin deleted successfully.';
                } else {
                    $noticeType = 'error';
                    $noticeText = 'Unable to delete admin account.';
                }
            }
        }
    }
}

$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));

$bookingsThisWeek = 0;
$weeklyStmt = $conn->prepare('SELECT COUNT(*) AS total_count FROM bookings WHERE booking_date BETWEEN ? AND ?');
if ($weeklyStmt) {
    $weeklyStmt->bind_param('ss', $weekStart, $weekEnd);
    $weeklyStmt->execute();
    $weeklyRow = $weeklyStmt->get_result()->fetch_assoc();
    $weeklyStmt->close();
    $bookingsThisWeek = (int)($weeklyRow['total_count'] ?? 0);
}

$avgTemp = null;
$avgHumidity = null;
$sensorSamples = 0;

$sensorAvgStmt = $conn->prepare('SELECT AVG(temperature) AS avg_temp, AVG(humidity) AS avg_humidity FROM sensor_logs');
if ($sensorAvgStmt) {
    $sensorAvgStmt->execute();
    $avgRow = $sensorAvgStmt->get_result()->fetch_assoc();
    $sensorAvgStmt->close();
    if ($avgRow) {
        $avgTemp = is_numeric($avgRow['avg_temp'] ?? null) ? (float)$avgRow['avg_temp'] : null;
        $avgHumidity = is_numeric($avgRow['avg_humidity'] ?? null) ? (float)$avgRow['avg_humidity'] : null;
    }
}

$sensorCountStmt = $conn->prepare('SELECT COUNT(*) AS total_samples FROM sensor_logs');
if ($sensorCountStmt) {
    $sensorCountStmt->execute();
    $sensorCountRow = $sensorCountStmt->get_result()->fetch_assoc();
    $sensorCountStmt->close();
    $sensorSamples = (int)($sensorCountRow['total_samples'] ?? 0);
}

$bookings = [];
$bookingsSql =
    'SELECT '
    . 'MIN(b.booking_id) AS id, '
    . 'b.booking_date AS booking_date, '
    . 'MIN(ts.start_time) AS start_time, '
    . 'ADDTIME(MIN(ts.start_time), SEC_TO_TIME(COUNT(*) * 3600)) AS end_time, '
    . 'r.room_number AS room, '
    . 's.student_number AS student_number, '
    . 'COALESCE(s.name, s.student_number) AS student_name, '
    . 'MIN(b.status) AS status '
    . 'FROM bookings b '
    . 'JOIN rooms r ON r.room_id = b.room_id '
    . 'JOIN timeslots ts ON ts.timeslot_id = b.timeslot_id '
    . 'JOIN students s ON s.student_id = b.student_id '
    . 'GROUP BY b.room_id, b.booking_date, b.student_id '
    . 'ORDER BY b.booking_date DESC, start_time DESC';

$bookingStmt = $conn->prepare($bookingsSql);
if ($bookingStmt) {
    $bookingStmt->execute();
    $bookingResult = $bookingStmt->get_result();
    if ($bookingResult) {
        $bookings = $bookingResult->fetch_all(MYSQLI_ASSOC);
    }
    $bookingStmt->close();
}

$sensorData = [];
$sensorStmt = $conn->prepare(
    'SELECT sl.*, r.room_number '
    . 'FROM sensor_logs sl '
    . 'JOIN rooms r ON r.room_id = sl.room_id '
    . 'WHERE sl.logged_at = ('
    . '    SELECT MAX(sl2.logged_at) '
    . '    FROM sensor_logs sl2 '
    . '    WHERE sl2.room_id = sl.room_id'
    . ') '
    . 'ORDER BY r.room_number ASC'
);
if ($sensorStmt) {
    $sensorStmt->execute();
    $sensorResult = $sensorStmt->get_result();
    if ($sensorResult) {
        $sensorData = $sensorResult->fetch_all(MYSQLI_ASSOC);
    }
    $sensorStmt->close();
}

$students = [];
$studentsSql =
    'SELECT '
    . 's.student_id, '
    . 's.name, '
    . 's.student_number, '
    . 'COALESCE(s.is_suspended, 0) AS is_suspended, '
    . 'COUNT(b.booking_id) AS total_bookings, '
    . 'COALESCE(SUM(CASE WHEN b.booking_date BETWEEN ? AND ? THEN 1 ELSE 0 END), 0) AS range_bookings '
    . 'FROM students s '
    . 'LEFT JOIN bookings b ON b.student_id = s.student_id '
    . 'GROUP BY s.student_id, s.name, s.student_number, s.is_suspended '
    . 'ORDER BY s.student_number ASC';

$studentsStmt = $conn->prepare($studentsSql);
if ($studentsStmt) {
    $studentsStmt->bind_param('ss', $studentFrom, $studentTo);
    $studentsStmt->execute();
    $studentsResult = $studentsStmt->get_result();
    if ($studentsResult) {
        $students = $studentsResult->fetch_all(MYSQLI_ASSOC);
    }
    $studentsStmt->close();
}

$admins = [];
$adminsStmt = $conn->prepare('SELECT admin_id, admin_number, name, COALESCE(is_active, 1) AS is_active, created_at FROM admins ORDER BY admin_id ASC');
if ($adminsStmt) {
    $adminsStmt->execute();
    $adminsResult = $adminsStmt->get_result();
    if ($adminsResult) {
        $admins = $adminsResult->fetch_all(MYSQLI_ASSOC);
    }
    $adminsStmt->close();
}

?>

<div id="content-admin" class="space-y-6">
    <?php if ($noticeText !== ''): ?>
        <div class="rounded-xl border px-4 py-3 text-sm <?php echo $noticeType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'; ?>">
            <?php echo htmlspecialchars($noticeText, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/30">
            <h3 class="font-bold text-lg text-slate-800">Statistics</h3>
            <p class="text-sm text-slate-500 mt-1">Current week booking count and overall environment averages.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 p-6">
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <p class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Bookings This Week</p>
                <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo number_format($bookingsThisWeek); ?></p>
                <p class="text-xs text-slate-500 mt-1"><?php echo htmlspecialchars($weekStart . ' to ' . $weekEnd, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <p class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Average Temperature</p>
                <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo $avgTemp === null ? '—' : number_format($avgTemp, 2) . '°C'; ?></p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <p class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Average Humidity</p>
                <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo $avgHumidity === null ? '—' : number_format($avgHumidity, 2) . '%'; ?></p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <p class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Sensor Log Samples</p>
                <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo number_format($sensorSamples); ?></p>
            </div>
        </div>
    </section>

    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/30">
            <h3 class="font-bold text-lg text-slate-800">Manage Room</h3>
            <p class="text-sm text-slate-500 mt-1">Take picture and open door using the existing IoT action flow.</p>
        </div>

        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            <?php if (empty($sensorData)): ?>
                <div class="col-span-full rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-sm text-slate-500 text-center">
                    No sensor logs yet. Room controls will appear when data is received.
                </div>
            <?php else: ?>
                <?php foreach ($sensorData as $row): ?>
                    <?php
                    $roomNumber = (string)($row['room_number'] ?? 'Unknown Room');
                    $temp = $row['temperature'] ?? null;
                    $hum = $row['humidity'] ?? null;
                    $status = (string)($row['system_status'] ?? 'Unknown');
                    $loggedAt = (string)($row['logged_at'] ?? '');

                    $tempText = ($temp === null || $temp === '') ? '—' : number_format((float)$temp, 1) . '°C';
                    $humText = ($hum === null || $hum === '') ? '—' : number_format((float)$hum, 1) . '%';

                    $statusClass = 'bg-slate-100 text-slate-700';
                    if ($status === 'Active') {
                        $statusClass = 'bg-emerald-100 text-emerald-700';
                    } elseif ($status === 'Error') {
                        $statusClass = 'bg-red-100 text-red-700';
                    }
                    ?>
                    <div class="rounded-2xl border border-slate-200 p-5 bg-white shadow-sm">
                        <div class="flex items-center justify-between mb-4">
                            <h4 class="font-semibold text-slate-800"><?php echo htmlspecialchars($roomNumber, ENT_QUOTES, 'UTF-8'); ?></h4>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full <?php echo $statusClass; ?>"><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>

                        <div class="grid grid-cols-2 gap-3 mb-4">
                            <div class="rounded-xl bg-slate-50 border border-slate-100 px-3 py-2">
                                <p class="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Temp</p>
                                <p class="text-lg font-bold text-slate-700"><?php echo htmlspecialchars($tempText, ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <div class="rounded-xl bg-slate-50 border border-slate-100 px-3 py-2">
                                <p class="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Humidity</p>
                                <p class="text-lg font-bold text-slate-700"><?php echo htmlspecialchars($humText, ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                        </div>

                        <p class="text-xs text-slate-400 mb-4">Latest log: <?php echo htmlspecialchars($loggedAt !== '' ? $loggedAt : 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>

                        <div class="flex gap-2">
                            <button type="button" onclick="handleRoomAction('picture', this, '<?php echo htmlspecialchars($roomNumber, ENT_QUOTES, 'UTF-8'); ?>')" class="flex-1 px-3 py-2 rounded-xl text-xs font-semibold bg-slate-100 text-slate-700 hover:bg-slate-200 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                                Take Picture
                            </button>
                            <button type="button" onclick="handleRoomAction('door', this, '<?php echo htmlspecialchars($roomNumber, ENT_QUOTES, 'UTF-8'); ?>')" class="flex-1 px-3 py-2 rounded-xl text-xs font-semibold bg-emerald-100 text-emerald-700 hover:bg-emerald-200 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                                Open Door
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/30">
            <h3 class="font-bold text-lg text-slate-800">All System Bookings</h3>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="bg-slate-50/50">
                        <th class="p-4 font-semibold text-slate-600 border-b border-slate-100">ID</th>
                        <th class="p-4 font-semibold text-slate-600 border-b border-slate-100">Student</th>
                        <th class="p-4 font-semibold text-slate-600 border-b border-slate-100">Room</th>
                        <th class="p-4 font-semibold text-slate-600 border-b border-slate-100">Time</th>
                        <th class="p-4 font-semibold text-slate-600 border-b border-slate-100">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bookings)): ?>
                        <tr>
                            <td colspan="5" class="p-4 text-slate-500">No booking records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($bookings as $booking): ?>
                            <?php
                            $status = (string)($booking['status'] ?? 'Unknown');
                            $statusColor = 'text-slate-500';
                            if ($status === 'Active') {
                                $statusColor = 'text-emerald-600';
                            } elseif ($status === 'Expired') {
                                $statusColor = 'text-red-500';
                            }
                            ?>
                            <tr class="border-b border-slate-100 hover:bg-slate-50/40">
                                <td class="p-4 font-mono text-xs text-slate-500"><?php echo htmlspecialchars((string)($booking['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-4">
                                    <p class="font-medium text-slate-700"><?php echo htmlspecialchars((string)($booking['student_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                    <p class="text-xs text-slate-500"><?php echo htmlspecialchars((string)($booking['student_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                </td>
                                <td class="p-4 text-slate-700"><?php echo htmlspecialchars((string)($booking['room'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-4">
                                    <p class="text-slate-700">
                                        <?php echo htmlspecialchars(substr((string)($booking['start_time'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8'); ?> -
                                        <?php echo htmlspecialchars(substr((string)($booking['end_time'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                    <p class="text-xs text-slate-500"><?php echo htmlspecialchars((string)($booking['booking_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                </td>
                                <td class="p-4">
                                    <span class="text-xs font-semibold uppercase tracking-wider <?php echo $statusColor; ?>"><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/30">
            <h3 class="font-bold text-lg text-slate-800">Add Student</h3>
            <p class="text-sm text-slate-500 mt-1">Create student accounts using student card ID and password.</p>
        </div>

        <div class="p-6">
            <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <input type="hidden" name="action" value="add_student">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Student ID</label>
                    <input type="text" name="student_number" required class="w-full px-3 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-blue-200" placeholder="e.g. 25WMR09840">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Password</label>
                    <input type="password" name="student_password" required class="w-full px-3 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-blue-200" placeholder="At least 6 characters">
                </div>
                <div class="md:col-span-2 flex items-end">
                    <button type="submit" class="w-full md:w-auto px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold transition-colors">Add Student</button>
                </div>
            </form>
        </div>
    </section>

    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/30 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h3 class="font-bold text-lg text-slate-800">Manage Students</h3>
                <p class="text-sm text-slate-500 mt-1">Suspend student login and view booking totals for all data or a date range.</p>
            </div>
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-1">From</label>
                    <input type="date" name="student_from" value="<?php echo htmlspecialchars($studentFrom, ENT_QUOTES, 'UTF-8'); ?>" class="px-3 py-2 rounded-lg border border-slate-200 text-sm">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-1">To</label>
                    <input type="date" name="student_to" value="<?php echo htmlspecialchars($studentTo, ENT_QUOTES, 'UTF-8'); ?>" class="px-3 py-2 rounded-lg border border-slate-200 text-sm">
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-800 text-white text-sm font-semibold hover:bg-slate-900">Apply</button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="bg-slate-50/50">
                        <th class="p-4 font-semibold text-slate-600 border-b border-slate-100">Student ID</th>
                        <th class="p-4 font-semibold text-slate-600 border-b border-slate-100">Name</th>
                        <th class="p-4 font-semibold text-slate-600 border-b border-slate-100">Total Bookings</th>
                        <th class="p-4 font-semibold text-slate-600 border-b border-slate-100">Range Bookings</th>
                        <th class="p-4 font-semibold text-slate-600 border-b border-slate-100">Status</th>
                        <th class="p-4 font-semibold text-slate-600 border-b border-slate-100">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="6" class="p-4 text-slate-500">No students found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($students as $student): ?>
                            <?php
                            $isSuspended = (int)($student['is_suspended'] ?? 0) === 1;
                            ?>
                            <tr class="border-b border-slate-100 hover:bg-slate-50/40">
                                <td class="p-4 font-mono text-xs"><?php echo htmlspecialchars((string)($student['student_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-4 text-slate-700"><?php echo htmlspecialchars((string)($student['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-4 text-slate-700"><?php echo (int)($student['total_bookings'] ?? 0); ?></td>
                                <td class="p-4 text-slate-700"><?php echo (int)($student['range_bookings'] ?? 0); ?></td>
                                <td class="p-4">
                                    <span class="text-xs font-semibold uppercase tracking-wider <?php echo $isSuspended ? 'text-red-600' : 'text-emerald-600'; ?>">
                                        <?php echo $isSuspended ? 'Suspended' : 'Active'; ?>
                                    </span>
                                </td>
                                <td class="p-4">
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="toggle_student_suspend">
                                        <input type="hidden" name="student_id" value="<?php echo (int)($student['student_id'] ?? 0); ?>">
                                        <input type="hidden" name="suspend" value="<?php echo $isSuspended ? '0' : '1'; ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-semibold <?php echo $isSuspended ? 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200' : 'bg-red-100 text-red-700 hover:bg-red-200'; ?> transition-colors">
                                            <?php echo $isSuspended ? 'Unsuspend' : 'Suspend'; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/30">
            <h3 class="font-bold text-lg text-slate-800">Manage Admins</h3>
            <p class="text-sm text-slate-500 mt-1">Add, update, activate/deactivate, and delete admin accounts.</p>
        </div>

        <div class="p-6 border-b border-slate-100">
            <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <input type="hidden" name="action" value="add_admin">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Admin ID</label>
                    <input type="text" name="admin_number" required class="w-full px-3 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-blue-200" placeholder="e.g. ADM001">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Name</label>
                    <input type="text" name="admin_name" required class="w-full px-3 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-blue-200" placeholder="Admin name">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2">Password</label>
                    <input type="password" name="admin_password" required class="w-full px-3 py-2.5 rounded-xl border border-slate-200 focus:outline-none focus:ring-2 focus:ring-blue-200" placeholder="At least 6 characters">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full md:w-auto px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold transition-colors">Add Admin</button>
                </div>
            </form>
        </div>

        <div class="p-6 space-y-4">
            <?php if (empty($admins)): ?>
                <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-sm text-slate-500 text-center">
                    No admin accounts found.
                </div>
            <?php else: ?>
                <?php foreach ($admins as $admin): ?>
                    <div class="rounded-xl border border-slate-200 p-4">
                        <form method="POST" class="grid grid-cols-1 md:grid-cols-5 gap-3">
                            <input type="hidden" name="action" value="update_admin">
                            <input type="hidden" name="admin_id" value="<?php echo (int)($admin['admin_id'] ?? 0); ?>">

                            <div>
                                <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Admin ID</label>
                                <input type="text" name="admin_number" value="<?php echo htmlspecialchars((string)($admin['admin_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Name</label>
                                <input type="text" name="admin_name" value="<?php echo htmlspecialchars((string)($admin['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-1">New Password</label>
                                <input type="password" name="admin_password" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm" placeholder="Leave blank to keep">
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Status</label>
                                <select name="is_active" class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm">
                                    <option value="1" <?php echo ((int)($admin['is_active'] ?? 1) === 1) ? 'selected' : ''; ?>>Active</option>
                                    <option value="0" <?php echo ((int)($admin['is_active'] ?? 1) === 0) ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="flex items-end">
                                <button type="submit" class="w-full px-3 py-2 rounded-lg bg-slate-800 text-white text-sm font-semibold hover:bg-slate-900">Update</button>
                            </div>
                        </form>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs text-slate-500">Created: <?php echo htmlspecialchars((string)($admin['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                            <form method="POST" onsubmit="return confirm('Delete this admin account?');">
                                <input type="hidden" name="action" value="delete_admin">
                                <input type="hidden" name="admin_id" value="<?php echo (int)($admin['admin_id'] ?? 0); ?>">
                                <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-red-100 text-red-700 hover:bg-red-200 transition-colors">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <script>
        async function handleRoomAction(action, btnElement, roomLabel) {
            let originalContent = '';
            if (btnElement) {
                originalContent = btnElement.innerHTML;
                btnElement.disabled = true;
                btnElement.innerHTML = 'Please wait...';
            }

            try {
                const statusRes = await fetch('https://iot-room.samsam123.name.my/api/status');
                if (statusRes.status !== 200) {
                    showToast('Status request failed (HTTP ' + statusRes.status + ').', 'error');
                    return;
                }

                const statusData = await statusRes.json();
                if (!statusData || statusData.status !== 'online') {
                    showToast('System is not online for ' + roomLabel + '.', 'warning');
                    return;
                }

                if (action === 'door') {
                    const openRes = await fetch('https://iot-room.samsam123.name.my/api/open-door');
                    if (openRes.status === 200) {
                        showToast('Door opened for ' + roomLabel + '.', 'success');
                    } else {
                        showToast('Failed to open door (HTTP ' + openRes.status + ').', 'error');
                    }
                }

                if (action === 'picture') {
                    const pictureRes = await fetch('https://iot-room.samsam123.name.my/api/take-picture');
                    if (pictureRes.status !== 200) {
                        showToast('Failed to capture picture (HTTP ' + pictureRes.status + ').', 'error');
                        return;
                    }

                    const pictureData = await pictureRes.json();
                    if (pictureData && pictureData.data) {
                        showPictureModal('data:image/jpeg;base64,' + pictureData.data);
                        showToast('Picture captured for ' + roomLabel + '.', 'success');
                    } else {
                        showToast('No image data returned for ' + roomLabel + '.', 'warning');
                    }
                }
            } catch (err) {
                console.error(err);
                showToast('Connection error while calling IoT endpoints.', 'error');
            } finally {
                if (btnElement) {
                    btnElement.disabled = false;
                    btnElement.innerHTML = originalContent;
                }
            }
        }

        function showToast(message, type) {
            let container = document.getElementById('toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast-container';
                container.className = 'fixed bottom-4 right-4 z-[120] flex flex-col gap-2';
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            let classes = 'bg-slate-800 text-white border-slate-700';

            if (type === 'success') {
                classes = 'bg-emerald-50 text-emerald-800 border-emerald-200';
            } else if (type === 'error') {
                classes = 'bg-red-50 text-red-800 border-red-200';
            } else if (type === 'warning') {
                classes = 'bg-amber-50 text-amber-800 border-amber-200';
            }

            toast.className = 'px-4 py-3 rounded-xl border shadow-lg text-sm font-medium transition-opacity duration-200 opacity-0 ' + classes;
            toast.textContent = message;
            container.appendChild(toast);

            requestAnimationFrame(function() {
                toast.classList.remove('opacity-0');
            });

            setTimeout(function() {
                toast.classList.add('opacity-0');
                setTimeout(function() {
                    if (toast.parentElement) {
                        toast.remove();
                    }
                }, 220);
            }, 3500);
        }

        function showPictureModal(imgSrc) {
            let modal = document.getElementById('iot-picture-modal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'iot-picture-modal';
                modal.className = 'fixed inset-0 z-[130] bg-slate-900/70 p-4 flex items-center justify-center';
                modal.innerHTML = '<div class="bg-white rounded-2xl shadow-xl max-w-3xl w-full overflow-hidden">'
                    + '<div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">'
                    + '<h4 class="font-semibold text-slate-800">Room Picture</h4>'
                    + '<button type="button" class="text-slate-500 hover:text-slate-700" onclick="document.getElementById(\'iot-picture-modal\').style.display=\'none\'">Close</button>'
                    + '</div>'
                    + '<div class="p-4 bg-slate-50 flex justify-center">'
                    + '<img id="iot-picture-img" src="" alt="Room picture" class="max-h-[70vh] w-auto rounded-lg shadow-sm" />'
                    + '</div>'
                    + '</div>';
                document.body.appendChild(modal);
            }

            const img = document.getElementById('iot-picture-img');
            if (img) {
                img.src = imgSrc;
            }
            modal.style.display = 'flex';
        }
    </script>
</div>
