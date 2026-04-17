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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_admin') {
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
    }

    if ($action === 'update_admin') {
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
    }

    if ($action === 'delete_admin') {
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

$activeAdmins = 0;
$inactiveAdmins = 0;

foreach ($admins as $admin) {
    if ((int)($admin['is_active'] ?? 1) === 1) {
        $activeAdmins++;
    } else {
        $inactiveAdmins++;
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
            <h3 class="font-bold text-lg text-slate-800">Admin Accounts Overview</h3>
            <p class="text-sm text-slate-500 mt-1">Create, update, activate, or remove administrative accounts.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 p-6">
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <p class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Total Admins</p>
                <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo number_format(count($admins)); ?></p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <p class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Active Admins</p>
                <p class="text-2xl font-bold text-emerald-700 mt-1"><?php echo number_format($activeAdmins); ?></p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <p class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Inactive Admins</p>
                <p class="text-2xl font-bold text-amber-700 mt-1"><?php echo number_format($inactiveAdmins); ?></p>
            </div>
        </div>
    </section>

    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/30">
            <h3 class="font-bold text-lg text-slate-800">Add Admin</h3>
            <p class="text-sm text-slate-500 mt-1">Create a new admin account with a unique Admin ID.</p>
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
</div>
