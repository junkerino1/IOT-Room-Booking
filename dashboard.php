<?php

if (!isset($conn)) {
    require_once __DIR__ . '/db.php';
}

$bookings = [];

$sql =
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
    . 'GROUP BY '
    . 'b.room_id, '
    . 'b.booking_date, '
    . 'b.student_id '
    . 'ORDER BY b.booking_date DESC, start_time DESC';


$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $bookings = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}



$today = date('Y-m-d');
$nowTime = date('H:i:s');

$stmt = $conn->prepare("
    SELECT sl.*, r.room_number
    FROM sensor_logs sl
    JOIN rooms r ON r.room_id = sl.room_id
    WHERE sl.logged_at = (
        SELECT MAX(sl2.logged_at)
        FROM sensor_logs sl2
        WHERE sl2.room_id = sl.room_id
    )
    ORDER BY r.room_number ASC
");
$stmt->execute();
$sensorData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

?>

<div id="content-admin" class="tab-pane">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50/30">
            <h3 class="font-bold text-lg">All System Bookings</h3>
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
                <tbody id="admin-table-body">
                    <?php foreach ($bookings as $b): ?>
                        <?php
                        $bookingId     = (string)($b['id'] ?? '');
                        $studentName   = (string)($b['student_name'] ?? '');
                        $studentNumber = (string)($b['student_number'] ?? '');
                        $room          = (string)($b['room'] ?? '');
                        $date          = (string)($b['booking_date'] ?? '');
                        $startTime     = (string)($b['start_time'] ?? '');
                        $endTime       = (string)($b['end_time'] ?? '');
                        $status        = (string)($b['status'] ?? '');

                        $statusColor = match ($status) {
                            'Active' => 'text-emerald-600',
                            'Expired'  => 'text-red-400',
                            default    => 'text-slate-400',
                        };

                        $initial = $studentName !== '' ? mb_strtoupper(mb_substr($studentName, 0, 1)) : '?';
                        ?>

                        <tr class="border-b border-slate-50 hover:bg-slate-50/50 transition-colors">
                            <td class="p-4 font-mono text-xs text-slate-400"><?php echo htmlspecialchars($bookingId, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="p-4">
                                <div class="flex items-center gap-2">
                                    <div class="w-8 h-8 bg-slate-100 rounded-full flex items-center justify-center text-xs font-bold text-slate-500">
                                        <?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <div>
                                        <p class="font-medium text-slate-700"><?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?></p>
                                        <p class="text-xs text-slate-400"><?php echo htmlspecialchars($studentNumber, ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="p-4 font-medium text-slate-600"><?php echo htmlspecialchars($room, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="p-4">
                                <p class="text-slate-600">
                                    <?php echo htmlspecialchars(substr($startTime, 0, 5), ENT_QUOTES, 'UTF-8'); ?>
                                    –
                                    <?php echo htmlspecialchars(substr($endTime, 0, 5), ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                                <p class="text-xs text-slate-400"><?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?></p>
                            </td>
                            <td class="p-4">
                                <span class="flex items-center gap-1.5 font-semibold text-xs uppercase tracking-wider <?php echo $statusColor; ?>">
                                    <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                    <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
    <section class="mt-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold text-lg text-slate-800 flex items-center gap-2">
                <i data-lucide="info" class="w-5 h-5 text-indigo-600"></i>
                Real-time Room Conditions
            </h3>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <?php foreach ($sensorData as $row): ?>
                <?php
                $roomNumber = (string)($row['room_number'] ?? '');
                $loggedAt = (string)($row['logged_at'] ?? '');

                $updatedLabel = 'Updated —';
                if ($loggedAt !== '') {
                    $loggedTs = strtotime($loggedAt);
                    if ($loggedTs !== false) {
                        $diff = time() - $loggedTs;
                        if ($diff < 60) {
                            $updatedLabel = 'Updated Just now';
                        } elseif ($diff < 3600) {
                            $mins = (int)floor($diff / 60);
                            $updatedLabel = 'Updated ' . $mins . ' min' . ($mins === 1 ? '' : 's') . ' ago';
                        } else {
                            $hrs = (int)floor($diff / 3600);
                            $updatedLabel = 'Updated ' . $hrs . ' hour' . ($hrs === 1 ? '' : 's') . ' ago';
                        }
                    }
                }

                $temp = $row['temperature'] ?? ($row['temp'] ?? ($row['temp_c'] ?? null));
                $hum = $row['humidity'] ?? ($row['hum'] ?? null);
                $tempText = ($temp === null || $temp === '') ? '—' : (is_numeric($temp) ? number_format((float)$temp, 1) . '°C' : (string)$temp);
                $humText = ($hum === null || $hum === '') ? '—' : (is_numeric($hum) ? ((int)$hum) . '%' : (string)$hum);

                // System status is either Active or Error
                $status = (string)($row['systemstatus'] ?? ($row['system_status'] ?? ''));
                if ($status !== 'Active' && $status !== 'Error') {
                    $status = $status !== '' ? $status : 'Unknown';
                }

                $pillBg = match ($status) {
                    'Active' => 'bg-emerald-100',
                    'Error' => 'bg-red-100',
                    default => 'bg-slate-100',
                };
                $pillText = match ($status) {
                    'Active' => 'text-emerald-700',
                    'Error' => 'text-red-700',
                    default => 'text-slate-500',
                };
                $iconBg = match ($status) {
                    'Active' => 'bg-emerald-50',
                    'Error' => 'bg-red-50',
                    default => 'bg-slate-50',
                };
                $iconText = match ($status) {
                    'Active' => 'text-emerald-600',
                    'Error' => 'text-red-600',
                    default => 'text-slate-600',
                };
                ?>

                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm hover:-translate-y-1 transition-all">
                    <div class="flex justify-between items-start mb-6">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center <?= $iconBg ?> <?= $iconText ?>">
                                <i data-lucide="door-closed" class="w-5 h-5"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-slate-800"><?= htmlspecialchars($roomNumber, ENT_QUOTES, 'UTF-8') ?></h4>
                                <p class="text-[10px] text-slate-400 uppercase font-bold tracking-wider"><?= htmlspecialchars($updatedLabel, ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                        <div class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider <?= $pillBg ?> <?= $pillText ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                            <div class="flex items-center gap-2 text-slate-400 mb-1">
                                <i data-lucide="thermometer" class="w-3.5 h-3.5"></i>
                                <span class="text-[10px] font-bold uppercase tracking-wider">Temp</span>
                            </div>
                            <p class="text-lg font-bold text-slate-700"><?= htmlspecialchars($tempText, ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                            <div class="flex items-center gap-2 text-slate-400 mb-1">
                                <i data-lucide="droplets" class="w-3.5 h-3.5"></i>
                                <span class="text-[10px] font-bold uppercase tracking-wider">Humidity</span>
                            </div>
                            <p class="text-lg font-bold text-slate-700"><?= htmlspecialchars($humText, ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

</div>