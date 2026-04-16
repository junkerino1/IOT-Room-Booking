<?php

require_once __DIR__ . '/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = (string)($_SESSION['role'] ?? '');
if ($role === 'admin') {
    header('Location: ' . app_url('dashboard'));
    exit;
}
if ($role === 'student') {
    header('Location: ' . app_url('availability'));
    exit;
}

$message = '';
$successMessage = '';

if (($_GET['registered'] ?? '') === '1') {
    $successMessage = 'Sign up successful. You can now sign in.';
}

if (($_GET['suspended'] ?? '') === '1') {
    $message = 'Your student account is suspended. Please contact an admin.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginId = strtoupper(trim((string)($_POST['student_number'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    if ($loginId === '' || $password === '') {
        $message = 'Please enter your ID and password.';
    } else {
        $authenticated = false;

        $adminStmt = $conn->prepare('SELECT admin_id, name, admin_number, password, COALESCE(is_active, 1) AS is_active FROM admins WHERE admin_number = ? LIMIT 1');
        if ($adminStmt) {
            $adminStmt->bind_param('s', $loginId);
            $adminStmt->execute();
            $adminStmt->bind_result($dbAdminId, $dbAdminName, $dbAdminNumber, $dbAdminPassword, $dbAdminActive);

            if ($adminStmt->fetch()) {
                if ((int)$dbAdminActive !== 1) {
                    $message = 'This admin account is inactive.';
                } elseif (app_password_matches($password, (string)$dbAdminPassword)) {
                    session_regenerate_id(true);
                    $_SESSION = [];
                    $_SESSION['role'] = 'admin';
                    $_SESSION['admin_id'] = (int)$dbAdminId;
                    $_SESSION['admin_name'] = (string)$dbAdminName;
                    $_SESSION['admin_number'] = (string)$dbAdminNumber;
                    $_SESSION['name'] = (string)$dbAdminName;

                    header('Location: ' . app_url('dashboard'));
                    exit;
                } else {
                    $message = 'Invalid ID or password.';
                }

                $authenticated = true;
            }

            $adminStmt->close();
        }

        if (!$authenticated) {
            $studentStmt = $conn->prepare('SELECT student_id, name, student_number, password, COALESCE(is_suspended, 0) AS is_suspended FROM students WHERE student_number = ? LIMIT 1');

            if ($studentStmt) {
                $studentStmt->bind_param('s', $loginId);
                $studentStmt->execute();
                $studentStmt->bind_result($dbStudentId, $dbStudentName, $dbStudentNumber, $dbStudentPassword, $dbStudentSuspended);

                if ($studentStmt->fetch()) {
                    if ((int)$dbStudentSuspended === 1) {
                        $message = 'Your student account is suspended. Please contact an admin.';
                    } elseif (app_password_matches($password, (string)$dbStudentPassword)) {
                        session_regenerate_id(true);
                        $_SESSION = [];
                        $_SESSION['role'] = 'student';
                        $_SESSION['student_number'] = (string)$dbStudentNumber;
                        $_SESSION['student_id'] = (int)$dbStudentId;
                        $_SESSION['student_name'] = (string)$dbStudentName;
                        $_SESSION['name'] = (string)$dbStudentName;

                        header('Location: ' . app_url('availability'));
                        exit;
                    } else {
                        $message = 'Invalid ID or password.';
                    }

                    $authenticated = true;
                }

                $studentStmt->close();
            }
        }

        if (!$authenticated && $message === '') {
            $message = 'Invalid ID or password.';
        }
    }
}

?>

<div class="min-h-screen flex items-center justify-center bg-slate-100 p-4">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-xl p-8">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-600 rounded-2xl mb-4 shadow-lg shadow-blue-200">
                <i data-lucide="door-open" class="text-white w-8 h-8"></i>
            </div>
            <h1 class="text-2xl font-bold text-slate-900">Room Booking System</h1>
            <p class="text-slate-500 mt-2">Sign in as student or admin</p>
        </div>

        <?php if (!empty($successMessage)): ?>
            <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($message)): ?>
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form action="" method="POST" class="space-y-6">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Student ID / Admin ID</label>
                <input type="text" name="student_number" required class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition-all" placeholder="e.g. 230001">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                <input type="password" name="password" required class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition-all" placeholder="••••••••">
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-xl shadow-lg shadow-blue-200 transition-all active:scale-[0.98]">
                Sign In
            </button>
        </form>

        <p class="text-center text-sm text-slate-500 mt-6">
            New student?
            <a href="<?php echo htmlspecialchars(app_url('signup'), ENT_QUOTES, 'UTF-8'); ?>" class="text-blue-600 hover:text-blue-700 font-semibold">Create account</a>
        </p>
    </div>
</div>