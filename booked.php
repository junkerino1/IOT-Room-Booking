<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$stmt = $conn->prepare("
    UPDATE bookings b
    JOIN timeslots t ON b.timeslot_id = t.timeslot_id
    SET b.status = 'Expired'
    WHERE b.status != 'Expired'
    AND TIMESTAMPADD(SECOND, 7200, TIMESTAMP(b.booking_date, t.start_time)) < NOW()
");
$stmt->execute();

$stmt = $conn->prepare("
    SELECT 
        r.room_number,
        b.booking_date,

        MIN(t.start_time) AS start_time,

        ADDTIME(
            MIN(t.start_time), 
            SEC_TO_TIME(COUNT(*) * 3600)
        ) AS end_time,

        MIN(b.status) AS status

    FROM bookings b
    JOIN rooms r ON b.room_id = r.room_id
    JOIN timeslots t ON b.timeslot_id = t.timeslot_id

    WHERE b.student_id = ?

    GROUP BY 
        b.room_id,
        b.booking_date,
        b.student_id

    ORDER BY b.booking_date ASC, start_time ASC
");
$stmt->bind_param('i', $_SESSION['student_id']);
$stmt->execute();
$myBookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <?php if (empty($myBookings)): ?>
        <div class="col-span-full flex flex-col items-center justify-center py-20 text-slate-400">
            <i data-lucide="calendar-x" class="w-16 h-16 mb-4 opacity-20"></i>
            <p class="text-lg">You have no bookings yet</p>
        </div>
    <?php else: ?>
        <?php foreach ($myBookings as $b): ?>
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md transition-all">
                <div class="flex justify-between items-start mb-4">
                    <div class="w-12 h-12 bg-blue-50 rounded-xl flex items-center justify-center text-blue-600">
                        <i data-lucide="door-closed" class="w-6 h-6"></i>
                    </div>
                    <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase <?= $b['status'] === 'Active' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-400' ?>">
                        <?= $b['status'] ?>
                    </span>
                </div>
                <h4 class="text-lg font-bold text-slate-800 mb-1"><?= $b['room_number'] ?></h4>
                <div class="space-y-2 text-sm text-slate-500">
                    <div class="flex items-center gap-2">
                        <i data-lucide="clock" class="w-4 h-4"></i>
                        <span><?= $b['start_time'] ?> (<?= $b['end_time'] ?>)</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <i data-lucide="calendar" class="w-4 h-4"></i>
                        <span><?= $b['booking_date'] ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>