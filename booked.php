<?php

if (!isset($conn)) {
    require_once __DIR__ . '/bootstrap.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$noticeType = '';
$noticeText = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'checkout_booking') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $studentId = (int)($_SESSION['student_id'] ?? 0);

        if ($bookingId <= 0 || $studentId <= 0) {
            $noticeType = 'error';
            $noticeText = 'Invalid booking selected for checkout.';
        } else {
            $bookingLookup = $conn->prepare('SELECT booking_id, room_id, booking_date, checked_out_at FROM bookings WHERE booking_id = ? AND student_id = ? LIMIT 1');

            if (!$bookingLookup) {
                $noticeType = 'error';
                $noticeText = 'Unable to verify your booking.';
            } else {
                $bookingLookup->bind_param('ii', $bookingId, $studentId);
                $bookingLookup->execute();
                $bookingRow = $bookingLookup->get_result()->fetch_assoc();
                $bookingLookup->close();

                if (!$bookingRow) {
                    $noticeType = 'error';
                    $noticeText = 'Booking not found for your account.';
                } elseif (!empty($bookingRow['checked_out_at'])) {
                    $noticeType = 'error';
                    $noticeText = 'This booking has already been checked out.';
                } else {
                    $roomId = (int)($bookingRow['room_id'] ?? 0);
                    $bookingDate = (string)($bookingRow['booking_date'] ?? '');

                    $checkoutStmt = $conn->prepare(
                        'UPDATE bookings '
                        . 'SET checked_out_at = NOW(), status = \'Expired\' '
                        . 'WHERE student_id = ? AND room_id = ? AND booking_date = ? AND checked_out_at IS NULL'
                    );

                    if (!$checkoutStmt) {
                        $noticeType = 'error';
                        $noticeText = 'Unable to complete checkout right now.';
                    } else {
                        $checkoutStmt->bind_param('iis', $studentId, $roomId, $bookingDate);
                        $checkoutStmt->execute();
                        $affectedRows = $checkoutStmt->affected_rows;
                        $checkoutStmt->close();

                        if ($affectedRows > 0) {
                            $noticeType = 'success';
                            $noticeText = 'Checkout complete. You can no longer enter this room for this booking.';
                        } else {
                            $noticeType = 'error';
                            $noticeText = 'Checkout was not applied. The booking may already be closed.';
                        }
                    }
                }
            }
        }
    }
}

$expireStmt = $conn->prepare(
    'UPDATE bookings b '
    . 'JOIN timeslots t ON b.timeslot_id = t.timeslot_id '
    . 'SET b.status = \'Expired\' '
    . 'WHERE b.status != \'Expired\' '
    . 'AND TIMESTAMPADD(SECOND, 7200, TIMESTAMP(b.booking_date, t.start_time)) < NOW()'
);
if ($expireStmt) {
    $expireStmt->execute();
    $expireStmt->close();
}

$myBookings = [];
$bookingStmt = $conn->prepare(
    'SELECT '
    . 'MIN(b.booking_id) AS booking_id, '
    . 'b.room_id, '
    . 'r.room_number, '
    . 'b.booking_date, '
    . 'MIN(t.start_time) AS start_time, '
    . 'ADDTIME(MIN(t.start_time), SEC_TO_TIME(COUNT(*) * 3600)) AS end_time, '
    . 'MIN(b.status) AS status, '
    . 'MAX(b.checked_out_at) AS checked_out_at '
    . 'FROM bookings b '
    . 'JOIN rooms r ON b.room_id = r.room_id '
    . 'JOIN timeslots t ON b.timeslot_id = t.timeslot_id '
    . 'WHERE b.student_id = ? '
    . 'GROUP BY b.room_id, b.booking_date, b.student_id '
    . 'ORDER BY b.booking_date ASC, start_time ASC'
);

if ($bookingStmt) {
    $bookingStmt->bind_param('i', $_SESSION['student_id']);
    $bookingStmt->execute();
    $result = $bookingStmt->get_result();
    if ($result) {
        $myBookings = $result->fetch_all(MYSQLI_ASSOC);
    }
    $bookingStmt->close();
}

$nowTs = time();

?>

<div class="space-y-6">
    <?php if ($noticeText !== ''): ?>
        <div class="rounded-xl border px-4 py-3 text-sm <?php echo $noticeType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'; ?>">
            <?php echo htmlspecialchars($noticeText, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <?php if (empty($myBookings)): ?>
            <div class="col-span-full flex flex-col items-center justify-center py-20 text-slate-400 bg-white rounded-2xl border border-slate-200">
                <i data-lucide="calendar-x" class="w-16 h-16 mb-4 opacity-20"></i>
                <p class="text-lg">You have no bookings yet</p>
            </div>
        <?php else: ?>
            <?php foreach ($myBookings as $b): ?>
                <?php
                $status = (string)($b['status'] ?? 'Expired');
                $checkedOutAt = (string)($b['checked_out_at'] ?? '');
                $isCheckedOut = $checkedOutAt !== '';

                $startTs = strtotime((string)($b['booking_date'] ?? '') . ' ' . (string)($b['start_time'] ?? '00:00:00'));
                $endTs = strtotime((string)($b['booking_date'] ?? '') . ' ' . (string)($b['end_time'] ?? '00:00:00'));

                $hasStarted = ($startTs !== false) && ($nowTs >= $startTs);
                $withinCheckoutWindow = ($endTs !== false) ? ($nowTs <= ($endTs + 7200)) : true;
                $canCheckout = ($status === 'Active') && !$isCheckedOut && $hasStarted && $withinCheckoutWindow;

                $badgeText = $isCheckedOut ? 'Checked Out' : $status;
                $badgeClass = 'bg-red-100 text-red-400';

                if ($isCheckedOut) {
                    $badgeClass = 'bg-amber-100 text-amber-700';
                } elseif ($status === 'Active') {
                    $badgeClass = 'bg-emerald-100 text-emerald-700';
                }
                ?>
                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md transition-all">
                    <div class="flex justify-between items-start mb-4">
                        <div class="w-12 h-12 bg-blue-50 rounded-xl flex items-center justify-center text-blue-600">
                            <i data-lucide="door-closed" class="w-6 h-6"></i>
                        </div>
                        <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase <?php echo $badgeClass; ?>">
                            <?php echo htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                    <h4 class="text-lg font-bold text-slate-800 mb-1"><?php echo htmlspecialchars((string)($b['room_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h4>
                    <div class="space-y-2 text-sm text-slate-500">
                        <div class="flex items-center gap-2">
                            <i data-lucide="clock" class="w-4 h-4"></i>
                        <span><?php echo htmlspecialchars((string)($b['start_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string)($b['end_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <i data-lucide="calendar" class="w-4 h-4"></i>
                        <span><?php echo htmlspecialchars((string)($b['booking_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <?php if ($isCheckedOut): ?>
                            <div class="flex items-center gap-2 text-amber-700">
                                <i data-lucide="check-circle-2" class="w-4 h-4"></i>
                                <span>Checked out at <?php echo htmlspecialchars($checkedOutAt, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-5">
                        <?php if ($canCheckout): ?>
                            <form method="POST" onsubmit="return confirm('Checkout now? After checkout, room access is blocked for this booking.');">
                                <input type="hidden" name="action" value="checkout_booking">
                                <input type="hidden" name="booking_id" value="<?php echo (int)($b['booking_id'] ?? 0); ?>">
                                <button type="submit" class="w-full px-4 py-2.5 rounded-xl text-sm font-semibold bg-amber-100 text-amber-800 hover:bg-amber-200 transition-colors">
                                    Checkout This Booking
                                </button>
                            </form>
                        <?php elseif ($isCheckedOut): ?>
                            <p class="text-xs text-amber-700 font-medium">Checkout has been completed for this booking.</p>
                        <?php elseif ($status === 'Active' && !$hasStarted): ?>
                            <p class="text-xs text-slate-500">Checkout will be available once your booking starts.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
