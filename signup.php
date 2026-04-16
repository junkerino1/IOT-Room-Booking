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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentNumber = strtoupper(trim((string)($_POST['student_number'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($studentNumber === '' || $password === '' || $confirmPassword === '') {
        $message = 'Please complete all fields.';
    } elseif ($password !== $confirmPassword) {
        $message = 'Password confirmation does not match.';
    } elseif (strlen($password) < 6) {
        $message = 'Password must be at least 6 characters.';
    } else {
        $checkStmt = $conn->prepare('SELECT student_id FROM students WHERE student_number = ? LIMIT 1');

        if (!$checkStmt) {
            $message = 'Unable to process sign up right now.';
        } else {
            $checkStmt->bind_param('s', $studentNumber);
            $checkStmt->execute();
            $exists = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if ($exists) {
                $message = 'This student ID already exists.';
            } else {
                $passwordHash = app_password_hash($password);
                $displayName = $studentNumber;

                $insertStmt = $conn->prepare('INSERT INTO students (name, student_number, password, is_suspended) VALUES (?, ?, ?, 0)');
                if (!$insertStmt) {
                    $message = 'Unable to create account right now.';
                } else {
                    $insertStmt->bind_param('sss', $displayName, $studentNumber, $passwordHash);
                    $insertStmt->execute();

                    if ($insertStmt->affected_rows > 0) {
                        $insertStmt->close();
                        header('Location: ' . app_url('login?registered=1'));
                        exit;
                    }

                    $message = 'Sign up failed. Please try again.';
                    $insertStmt->close();
                }
            }
        }
    }
}

?>

<div class="min-h-screen flex items-center justify-center bg-slate-100 p-4">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-xl p-8">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-600 rounded-2xl mb-4 shadow-lg shadow-blue-200">
                <i data-lucide="user-plus" class="text-white w-8 h-8"></i>
            </div>
            <h1 class="text-2xl font-bold text-slate-900">Student Sign Up</h1>
            <p class="text-slate-500 mt-2">Use your student card ID and set a password</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form action="" method="POST" class="space-y-6">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Student ID</label>
                <input type="text" name="student_number" required class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition-all" placeholder="e.g. 25WMR09840">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                <input type="password" name="password" required class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition-all" placeholder="At least 6 characters">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Confirm Password</label>
                <input type="password" name="confirm_password" required class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:ring-2 focus:ring-blue-500 outline-none transition-all" placeholder="Repeat password">
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-xl shadow-lg shadow-blue-200 transition-all active:scale-[0.98]">
                Create Account
            </button>
        </form>

        <p class="text-center text-sm text-slate-500 mt-6">
            Already have an account?
            <a href="<?php echo htmlspecialchars(app_url('login'), ENT_QUOTES, 'UTF-8'); ?>" class="text-blue-600 hover:text-blue-700 font-semibold">Sign in</a>
        </p>
    </div>
</div>
