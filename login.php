<?php

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_number = trim($_POST['student_number'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($student_number === '' || $password === '') {
        $message = 'Please enter your student number and password.';
    } else {
        $stmt = $conn->prepare('SELECT * FROM students WHERE student_number = ? LIMIT 1');
        $stmt->bind_param('s', $student_number);
        $stmt->execute();
        $stmt->bind_result($db_student_id, $db_student_name, $db_student_number, $db_password);

        if ($stmt->fetch()) {
            $isValid = ((string)$db_password === $password);

            if ($isValid) {
                session_regenerate_id(true);
                $_SESSION['student_number'] = $db_student_number;
                $_SESSION['student_id'] = $db_student_id;
                $_SESSION['name'] = $db_student_name;

                header('Location: /availability');
                exit;
            }
        }

        $message = 'Invalid student ID or password.';
        $stmt->close();
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
            <p class="text-slate-500 mt-2">Sign in to your student account</p>
        </div>

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
    </div>
</div>