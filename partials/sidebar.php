<?php
if (!function_exists('app_url')) {
    require_once __DIR__ . '/../bootstrap.php';
}

$role = (string)($_SESSION['role'] ?? '');
$isAdmin = $role === 'admin';
$displayName = $isAdmin
    ? (string)($_SESSION['admin_name'] ?? ($_SESSION['name'] ?? 'Admin'))
    : (string)($_SESSION['student_name'] ?? ($_SESSION['name'] ?? 'Guest'));
$displayId = $isAdmin
    ? (string)($_SESSION['admin_number'] ?? '')
    : (string)($_SESSION['student_number'] ?? '');
?>

<aside class="w-full md:w-72 bg-white border-r border-slate-200 flex flex-col">
    <div class="p-6 border-b border-slate-100">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center shadow-lg shadow-blue-100">
                <i data-lucide="layout-dashboard" class="text-white w-6 h-6"></i>
            </div>
            <span class="font-bold text-xl tracking-tight">IOT</span>
        </div>
    </div>

    <nav class="flex-1 py-6">
        <div class="px-4 mb-4">
            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider px-2"><?php echo $isAdmin ? 'Admin Panel' : 'Main Menu'; ?></p>
        </div>
        <div id="nav-items" class="space-y-1">
            <?php if ($isAdmin): ?>
                <a href="<?= htmlspecialchars(app_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="sidebar-item w-full flex items-center gap-3 px-6 py-3 text-slate-600 hover:bg-slate-50 transition-all <?= ($activePage == 'admin-dashboard') ? 'active' : '' ?>">
                    <i data-lucide="shield-check" class="w-5 h-5"></i>
                    <span class="font-medium">Dashboard</span>
                </a>

                <a href="<?= htmlspecialchars(app_url('admin/students'), ENT_QUOTES, 'UTF-8') ?>" class="sidebar-item w-full flex items-center gap-3 px-6 py-3 text-slate-600 hover:bg-slate-50 transition-all <?= ($activePage == 'admin-students') ? 'active' : '' ?>">
                    <i data-lucide="users" class="w-5 h-5"></i>
                    <span class="font-medium">Manage Students</span>
                </a>

                <a href="<?= htmlspecialchars(app_url('admin/admins'), ENT_QUOTES, 'UTF-8') ?>" class="sidebar-item w-full flex items-center gap-3 px-6 py-3 text-slate-600 hover:bg-slate-50 transition-all <?= ($activePage == 'admin-admins') ? 'active' : '' ?>">
                    <i data-lucide="user-cog" class="w-5 h-5"></i>
                    <span class="font-medium">Manage Admins</span>
                </a>
            <?php else: ?>
                <a href="<?= htmlspecialchars(app_url('availability'), ENT_QUOTES, 'UTF-8') ?>" class="sidebar-item w-full flex items-center gap-3 px-6 py-3 text-slate-600 hover:bg-slate-50 transition-all <?= ($activePage == 'availability') ? 'active' : '' ?>">
                    <i data-lucide="calendar-days" class="w-5 h-5"></i>
                    <span class="font-medium">Availability</span>
                </a>

                <a href="<?= htmlspecialchars(app_url('booking/new'), ENT_QUOTES, 'UTF-8') ?>" class="sidebar-item w-full flex items-center gap-3 px-6 py-3 text-slate-600 hover:bg-slate-50 transition-all <?= ($activePage == 'book') ? 'active' : '' ?>">
                    <i data-lucide="plus-circle" class="w-5 h-5"></i>
                    <span class="font-medium">Book a Room</span>
                </a>

                <a href="<?= htmlspecialchars(app_url('booking/my-bookings'), ENT_QUOTES, 'UTF-8') ?>" class="sidebar-item w-full flex items-center gap-3 px-6 py-3 text-slate-600 hover:bg-slate-50 transition-all <?= ($activePage == 'my-bookings') ? 'active' : '' ?>">
                    <i data-lucide="bookmark-check" class="w-5 h-5"></i>
                    <span class="font-medium">My Bookings</span>
                </a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="p-6 border-t border-slate-100 bg-slate-50/50">
        <div class="flex items-center gap-3">
            <i data-lucide="user"></i>
            <div class="flex-1 overflow-hidden">
                <p class="font-semibold text-sm truncate"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></p>
                <p class="text-xs text-slate-500 truncate"><?= htmlspecialchars($displayId, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <a href="<?= htmlspecialchars(app_url('auth/logout'), ENT_QUOTES, 'UTF-8') ?>" class="text-slate-400 hover:text-red-500 transition-colors">
                <i data-lucide="log-out" class="w-5 h-5"></i>
            </a>
        </div>
    </div>
</aside>