<?php
ob_start();
require_once __DIR__ . '/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$view = 'availability'; // Default view
$pageTitle = 'Room Availability';
$activePage = '';

// Extract the path (e.g., /booking/new -> booking/new)
$path = app_route_path();

// API endpoints should bypass the normal web routing (login redirects/layout)
$apiEndpoints = ['report-data', 'parse-nfc', 'take-picture', 'ongoing-booking', 'unlock-door', 'check-out'];
if ($path === 'api.php' || in_array($path, $apiEndpoints, true) || (strpos($path, 'api/') === 0)) {
    define('API_REQUEST', true);
    require __DIR__ . '/api.php';
    exit;
}

switch ($path) {
    case 'logout':
    case 'auth/logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: ' . app_url('login'));
        exit;

    case '':
    case 'availability':
        $view = 'availability';
        $pageTitle = 'Room Availability';
        $activePage = 'availability';
        break;

    case 'login':
        $view = 'login';
        $pageTitle = 'Sign In';
        break;

    case 'signup':
        $view = 'signup';
        $pageTitle = 'Student Sign Up';
        break;

    case 'booking/new':
        $view = 'booking';
        $pageTitle = 'Book a Room';
        $activePage = 'book';
        break;

    case 'booking/my-bookings':
        $view = 'booked';
        $pageTitle = 'My Bookings';
        $activePage = 'my-bookings';
        break;

    case 'dashboard':
        $view = 'admin_dashboard';
        $pageTitle = 'Admin Dashboard';
        $activePage = 'admin-dashboard';
        break;

    case 'admin/dashboard':
        $view = 'admin_dashboard';
        $pageTitle = 'Admin Dashboard';
        $activePage = 'admin-dashboard';
        break;

    case 'admin/students':
        $view = 'admin_students';
        $pageTitle = 'Manage Students';
        $activePage = 'admin-students';
        break;

    case 'admin/admins':
        $view = 'admin_admins';
        $pageTitle = 'Manage Admins';
        $activePage = 'admin-admins';
        break;

    default:
        $view = 'availability';
        $pageTitle = 'Room Availability';
        $activePage = 'availability';
        break;
}

if (!isset($_SESSION['role']) && !empty($_SESSION['student_number'])) {
    $_SESSION['role'] = 'student';
}

if (!function_exists('performLogoutAndRedirect')) {
    function performLogoutAndRedirect(string $target): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: ' . app_url($target));
        exit;
    }
}

// Require proper role for each page
if (!defined('API_REQUEST')) {
    $currentRole = (string)($_SESSION['role'] ?? '');
    $publicViews = ['login', 'signup'];
    $studentViews = ['availability', 'booking', 'booked'];
    $adminViews = ['admin_dashboard', 'admin_students', 'admin_admins'];

    if ($currentRole === '' && !in_array($view, $publicViews, true)) {
        header('Location: ' . app_url('login'));
        exit;
    }

    if ($currentRole === 'student' && in_array($view, $publicViews, true)) {
        header('Location: ' . app_url('availability'));
        exit;
    }

    if ($currentRole === 'admin' && in_array($view, $publicViews, true)) {
        header('Location: ' . app_url('dashboard'));
        exit;
    }

    if (in_array($view, $studentViews, true) && $currentRole !== 'student') {
        header('Location: ' . app_url('dashboard'));
        exit;
    }

    if (in_array($view, $adminViews, true) && $currentRole !== 'admin') {
        header('Location: ' . app_url('availability'));
        exit;
    }

    // Enforce suspended flag every request so already-logged-in suspended students are removed.
    if ($currentRole === 'student' && !empty($_SESSION['student_id'])) {
        $studentId = (int)$_SESSION['student_id'];
        $suspendStmt = $conn->prepare('SELECT COALESCE(is_suspended, 0) AS is_suspended FROM students WHERE student_id = ? LIMIT 1');

        if ($suspendStmt) {
            $suspendStmt->bind_param('i', $studentId);
            $suspendStmt->execute();
            $row = $suspendStmt->get_result()->fetch_assoc();
            $suspendStmt->close();

            if ((int)($row['is_suspended'] ?? 0) === 1) {
                performLogoutAndRedirect('login?suspended=1');
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - RoomBook</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        body {
            font-family: 'Inter', sans-serif;
        }

        .sidebar-item.active {
            background-color: #eff6ff;
            color: #2563eb;
            border-right: 4px solid #2563eb;
        }

        .slot-available {
            background-color: #f1f5f9;
            cursor: pointer;
            transition: all 0.2s;
        }

        .slot-available:hover {
            background-color: #dbeafe;
        }

        .slot-booked {
            background-color: #fee2e2;
            color: #ef4444;
            cursor: not-allowed;
        }
    </style>
</head>

<body class="bg-slate-50 text-slate-900">

    <?php if (in_array($view, ['login', 'signup'], true)): ?>
        <?php include __DIR__ . "/$view.php"; ?>
    <?php else: ?>
        <div class="min-h-screen flex flex-col md:flex-row">
            <?php include __DIR__ . '/partials/sidebar.php'; ?>

            <main class="flex-1 flex flex-col h-screen overflow-hidden">
                <header class="h-20 bg-white border-b border-slate-200 flex items-center justify-between px-8 shrink-0">
                    <h2 class="text-xl font-bold text-slate-800"><?php echo $pageTitle; ?></h2>
                    <div class="flex items-center gap-4">
                        <div class="text-right hidden sm:block">
                            <p class="text-sm font-medium"><?php echo date('l, d F Y'); ?></p>
                        </div>
                        <div class="w-10 h-10 bg-slate-100 rounded-full flex items-center justify-center text-slate-500">
                            <i data-lucide="bell" class="w-5 h-5"></i>
                        </div>
                    </div>
                </header>

                <div class="flex-1 overflow-y-auto p-8">
                    <?php include __DIR__ . "/$view.php"; ?>
                </div>
            </main>
        </div>
    <?php endif; ?>

    <script>
        // Initialize icons for all views
        lucide.createIcons();
    </script>
</body>

</html>

<?php
ob_end_flush();
?>