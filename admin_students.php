<?php

if (!isset($conn)) {
    require_once __DIR__ . '/bootstrap.php';
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

$defaultFrom = date('Y-m-d', strtotime('-1 month'));
$defaultTo = date('Y-m-d');

$normalizeDate = static function (string $value, string $fallback): string {
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if ($dt && $dt->format('Y-m-d') === $value) {
        return $value;
    }

    return $fallback;
};

$studentFrom = $normalizeDate((string)($_GET['student_from'] ?? $defaultFrom), $defaultFrom);
$studentTo = $normalizeDate((string)($_GET['student_to'] ?? $defaultTo), $defaultTo);

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
    }

    if ($action === 'add_student') {
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
    }
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

$activeStudents = 0;
$suspendedStudents = 0;

foreach ($students as $student) {
    if ((int)($student['is_suspended'] ?? 0) === 1) {
        $suspendedStudents++;
    } else {
        $activeStudents++;
    }
}

?>

<div class="space-y-6">
    <?php if ($noticeText !== ''): ?>
        <div class="rounded-xl border px-4 py-3 text-sm <?php echo $noticeType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'; ?>">
            <?php echo htmlspecialchars($noticeText, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/30">
            <h3 class="font-bold text-lg text-slate-800">Student Overview</h3>
            <p class="text-sm text-slate-500 mt-1">Student account status and booking activity within the selected date range.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 p-6">
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <p class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Total Students</p>
                <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo number_format(count($students)); ?></p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <p class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Active Students</p>
                <p class="text-2xl font-bold text-emerald-700 mt-1"><?php echo number_format($activeStudents); ?></p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <p class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Suspended Students</p>
                <p class="text-2xl font-bold text-red-700 mt-1"><?php echo number_format($suspendedStudents); ?></p>
            </div>
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
                            <?php $isSuspended = (int)($student['is_suspended'] ?? 0) === 1; ?>
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
</div>
