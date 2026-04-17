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

$defaultSensorFrom = date('Y-m-d', strtotime('-7 days'));
$defaultSensorTo = date('Y-m-d');

$normalizeDate = static function (string $value, string $fallback): string {
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if ($dt && $dt->format('Y-m-d') === $value) {
        return $value;
    }

    return $fallback;
};

$sensorFrom = $normalizeDate((string)($_GET['sensor_from'] ?? $defaultSensorFrom), $defaultSensorFrom);
$sensorTo = $normalizeDate((string)($_GET['sensor_to'] ?? $defaultSensorTo), $defaultSensorTo);

if ($sensorFrom > $sensorTo) {
    $tmp = $sensorFrom;
    $sensorFrom = $sensorTo;
    $sensorTo = $tmp;
}

$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime('sunday this week'));

$bookingsThisWeek = 0;
$weeklyStmt = $conn->prepare('SELECT COUNT(*) AS total_count FROM bookings WHERE booking_date BETWEEN ? AND ?');
if ($weeklyStmt) {
    $weeklyStmt->bind_param('ss', $weekStart, $weekEnd);
    $weeklyStmt->execute();
    $weeklyRow = $weeklyStmt->get_result()->fetch_assoc();
    $weeklyStmt->close();
    $bookingsThisWeek = (int)($weeklyRow['total_count'] ?? 0);
}

$avgTemp = null;
$avgHumidity = null;
$sensorSamples = 0;

$sensorAvgStmt = $conn->prepare('SELECT AVG(temperature) AS avg_temp, AVG(humidity) AS avg_humidity FROM sensor_logs');
if ($sensorAvgStmt) {
    $sensorAvgStmt->execute();
    $avgRow = $sensorAvgStmt->get_result()->fetch_assoc();
    $sensorAvgStmt->close();
    if ($avgRow) {
        $avgTemp = is_numeric($avgRow['avg_temp'] ?? null) ? (float)$avgRow['avg_temp'] : null;
        $avgHumidity = is_numeric($avgRow['avg_humidity'] ?? null) ? (float)$avgRow['avg_humidity'] : null;
    }
}

$sensorCountStmt = $conn->prepare('SELECT COUNT(*) AS total_samples FROM sensor_logs');
if ($sensorCountStmt) {
    $sensorCountStmt->execute();
    $sensorCountRow = $sensorCountStmt->get_result()->fetch_assoc();
    $sensorCountStmt->close();
    $sensorSamples = (int)($sensorCountRow['total_samples'] ?? 0);
}

$bookings = [];
$bookingsSql =
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
    . 'GROUP BY b.room_id, b.booking_date, b.student_id '
    . 'ORDER BY b.booking_date DESC, start_time DESC';

$bookingStmt = $conn->prepare($bookingsSql);
if ($bookingStmt) {
    $bookingStmt->execute();
    $bookingResult = $bookingStmt->get_result();
    if ($bookingResult) {
        $bookings = $bookingResult->fetch_all(MYSQLI_ASSOC);
    }
    $bookingStmt->close();
}

$sensorData = [];
$sensorStmt = $conn->prepare(
    'SELECT sl.*, r.room_number '
    . 'FROM sensor_logs sl '
    . 'JOIN rooms r ON r.room_id = sl.room_id '
    . 'WHERE sl.logged_at = ('
    . '    SELECT MAX(sl2.logged_at) '
    . '    FROM sensor_logs sl2 '
    . '    WHERE sl2.room_id = sl.room_id'
    . ') '
    . 'ORDER BY r.room_number ASC'
);
if ($sensorStmt) {
    $sensorStmt->execute();
    $sensorResult = $sensorStmt->get_result();
    if ($sensorResult) {
        $sensorData = $sensorResult->fetch_all(MYSQLI_ASSOC);
    }
    $sensorStmt->close();
}

$roomHistorySeries = [];
$rangeSampleCount = 0;
$rangeStart = $sensorFrom . ' 00:00:00';
$rangeEnd = $sensorTo . ' 23:59:59';

$roomsStmt = $conn->prepare('SELECT room_id, room_number FROM rooms ORDER BY room_number ASC');
if ($roomsStmt) {
    $roomsStmt->execute();
    $roomsResult = $roomsStmt->get_result();
    $rooms = $roomsResult ? $roomsResult->fetch_all(MYSQLI_ASSOC) : [];

    $historyStmt = $conn->prepare(
        'SELECT temperature, humidity, distance, logged_at '
        . 'FROM sensor_logs '
        . 'WHERE room_id = ? AND logged_at BETWEEN ? AND ? '
        . 'ORDER BY logged_at ASC'
    );

    if ($historyStmt) {
        foreach ($rooms as $roomRow) {
            $roomId = (int)($roomRow['room_id'] ?? 0);
            if ($roomId <= 0) {
                continue;
            }

            $historyStmt->bind_param('iss', $roomId, $rangeStart, $rangeEnd);
            $historyStmt->execute();
            $historyResult = $historyStmt->get_result();
            $historyRows = $historyResult ? $historyResult->fetch_all(MYSQLI_ASSOC) : [];

            $labels = [];
            $temperatures = [];
            $humidities = [];
            $distances = [];

            foreach ($historyRows as $historyRow) {
                $labels[] = (string)($historyRow['logged_at'] ?? '');
                $temperatures[] = is_numeric($historyRow['temperature'] ?? null)
                    ? round((float)$historyRow['temperature'], 2)
                    : null;
                $humidities[] = is_numeric($historyRow['humidity'] ?? null)
                    ? round((float)$historyRow['humidity'], 2)
                    : null;
                $distances[] = is_numeric($historyRow['distance'] ?? null)
                    ? round((float)$historyRow['distance'], 2)
                    : null;
            }

            $rangeSampleCount += count($labels);

            $roomHistorySeries[] = [
                'room_id' => $roomId,
                'room_number' => (string)($roomRow['room_number'] ?? ('Room ' . $roomId)),
                'labels' => $labels,
                'temperature' => $temperatures,
                'humidity' => $humidities,
                'distance' => $distances,
            ];
        }

        $historyStmt->close();
    }

    $roomsStmt->close();
}

$roomHistoryJson = json_encode($roomHistorySeries, JSON_UNESCAPED_SLASHES);
if ($roomHistoryJson === false) {
    $roomHistoryJson = '[]';
}

?>

<div class="space-y-6">
    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/30 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <h3 class="font-bold text-lg text-slate-800">Admin Management</h3>
                <p class="text-sm text-slate-500 mt-1">Manage student access and admin accounts from dedicated pages.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="<?php echo htmlspecialchars(app_url('admin/students'), ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition-colors">
                    <i data-lucide="users" class="w-4 h-4"></i>
                    Manage Students
                </a>
                <a href="<?php echo htmlspecialchars(app_url('admin/admins'), ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-900 text-white text-sm font-semibold transition-colors">
                    <i data-lucide="user-cog" class="w-4 h-4"></i>
                    Manage Admins
                </a>
            </div>
        </div>
    </section>

    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/30">
            <h3 class="font-bold text-lg text-slate-800">Statistics</h3>
            <p class="text-sm text-slate-500 mt-1">Current week booking count and overall environment averages.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 p-6">
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <p class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Bookings This Week</p>
                <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo number_format($bookingsThisWeek); ?></p>
                <p class="text-xs text-slate-500 mt-1"><?php echo htmlspecialchars($weekStart . ' to ' . $weekEnd, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <p class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Average Temperature</p>
                <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo $avgTemp === null ? '-' : number_format($avgTemp, 2) . '°C'; ?></p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <p class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Average Humidity</p>
                <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo $avgHumidity === null ? '-' : number_format($avgHumidity, 2) . '%'; ?></p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                <p class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Sensor Log Samples</p>
                <p class="text-2xl font-bold text-slate-800 mt-1"><?php echo number_format($sensorSamples); ?></p>
            </div>
        </div>
    </section>

    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/30">
            <h3 class="font-bold text-lg text-slate-800">Manage Room</h3>
            <p class="text-sm text-slate-500 mt-1">Take picture and open door using the existing IoT action flow.</p>
        </div>

        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            <?php if (empty($sensorData)): ?>
                <div class="col-span-full rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-sm text-slate-500 text-center">
                    No sensor logs yet. Room controls will appear when data is received.
                </div>
            <?php else: ?>
                <?php foreach ($sensorData as $row): ?>
                    <?php
                    $roomNumber = (string)($row['room_number'] ?? 'Unknown Room');
                    $temp = $row['temperature'] ?? null;
                    $hum = $row['humidity'] ?? null;
                    $status = (string)($row['system_status'] ?? 'Unknown');
                    $loggedAt = (string)($row['logged_at'] ?? '');

                    $tempText = ($temp === null || $temp === '') ? '-' : number_format((float)$temp, 1) . '°C';
                    $humText = ($hum === null || $hum === '') ? '-' : number_format((float)$hum, 1) . '%';

                    $statusClass = 'bg-slate-100 text-slate-700';
                    if ($status === 'Active') {
                        $statusClass = 'bg-emerald-100 text-emerald-700';
                    } elseif ($status === 'Error') {
                        $statusClass = 'bg-red-100 text-red-700';
                    }
                    ?>
                    <div class="rounded-2xl border border-slate-200 p-5 bg-white shadow-sm">
                        <div class="flex items-center justify-between mb-4">
                            <h4 class="font-semibold text-slate-800"><?php echo htmlspecialchars($roomNumber, ENT_QUOTES, 'UTF-8'); ?></h4>
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full <?php echo $statusClass; ?>"><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>

                        <div class="grid grid-cols-2 gap-3 mb-4">
                            <div class="rounded-xl bg-slate-50 border border-slate-100 px-3 py-2">
                                <p class="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Temp</p>
                                <p class="text-lg font-bold text-slate-700"><?php echo htmlspecialchars($tempText, ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <div class="rounded-xl bg-slate-50 border border-slate-100 px-3 py-2">
                                <p class="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Humidity</p>
                                <p class="text-lg font-bold text-slate-700"><?php echo htmlspecialchars($humText, ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                        </div>

                        <p class="text-xs text-slate-400 mb-4">Latest log: <?php echo htmlspecialchars($loggedAt !== '' ? $loggedAt : 'N/A', ENT_QUOTES, 'UTF-8'); ?></p>

                        <div class="flex gap-2">
                            <button type="button" onclick="handleRoomAction('picture', this, '<?php echo htmlspecialchars($roomNumber, ENT_QUOTES, 'UTF-8'); ?>')" class="flex-1 px-3 py-2 rounded-xl text-xs font-semibold bg-slate-100 text-slate-700 hover:bg-slate-200 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                                Take Picture
                            </button>
                            <button type="button" onclick="handleRoomAction('door', this, '<?php echo htmlspecialchars($roomNumber, ENT_QUOTES, 'UTF-8'); ?>')" class="flex-1 px-3 py-2 rounded-xl text-xs font-semibold bg-emerald-100 text-emerald-700 hover:bg-emerald-200 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                                Open Door
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/30 flex flex-col xl:flex-row xl:items-end xl:justify-between gap-4">
            <div>
                <h3 class="font-bold text-lg text-slate-800">Room Sensor History</h3>
                <p class="text-sm text-slate-500 mt-1">View past temperature, humidity, and distance logs in a scrollable chart by room and date range.</p>
            </div>

            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-1">From</label>
                    <input type="date" name="sensor_from" value="<?php echo htmlspecialchars($sensorFrom, ENT_QUOTES, 'UTF-8'); ?>" class="px-3 py-2 rounded-lg border border-slate-200 text-sm">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-1">To</label>
                    <input type="date" name="sensor_to" value="<?php echo htmlspecialchars($sensorTo, ENT_QUOTES, 'UTF-8'); ?>" class="px-3 py-2 rounded-lg border border-slate-200 text-sm">
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-slate-800 text-white text-sm font-semibold hover:bg-slate-900">Apply</button>
            </form>
        </div>

        <div class="p-6">
            <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-4">
                <div class="w-full lg:w-80">
                    <label for="sensor-trend-room" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Select Room</label>
                    <select id="sensor-trend-room" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"></select>
                </div>
                <div class="text-xs text-slate-500">
                    <p>Range: <?php echo htmlspecialchars($sensorFrom . ' to ' . $sensorTo, ENT_QUOTES, 'UTF-8'); ?></p>
                    <p>Total samples in range: <?php echo number_format($rangeSampleCount); ?></p>
                </div>
            </div>

            <div id="sensor-trend-empty" class="hidden rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500"></div>

            <div id="sensor-trend-scroll" class="hidden overflow-x-auto pb-2">
                <div id="sensor-trend-canvas-wrap" class="h-[340px] min-w-[960px]">
                    <canvas id="sensor-trend-chart"></canvas>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-2 text-[11px] font-semibold uppercase tracking-wider">
                <span class="px-2.5 py-1 rounded-full bg-cyan-50 text-cyan-700 border border-cyan-100">Temperature</span>
                <span class="px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-100">Humidity</span>
                <span class="px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-100">Distance</span>
                <span class="px-2.5 py-1 rounded-full bg-slate-100 text-slate-600 border border-slate-200">Scrollable timeline</span>
            </div>
        </div>
    </section>

    <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6 border-b border-slate-100 bg-slate-50/30">
            <h3 class="font-bold text-lg text-slate-800">All System Bookings</h3>
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
                <tbody>
                    <?php if (empty($bookings)): ?>
                        <tr>
                            <td colspan="5" class="p-4 text-slate-500">No booking records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($bookings as $booking): ?>
                            <?php
                            $status = (string)($booking['status'] ?? 'Unknown');
                            $statusColor = 'text-slate-500';
                            if ($status === 'Active') {
                                $statusColor = 'text-emerald-600';
                            } elseif ($status === 'Expired') {
                                $statusColor = 'text-red-500';
                            }
                            ?>
                            <tr class="border-b border-slate-100 hover:bg-slate-50/40">
                                <td class="p-4 font-mono text-xs text-slate-500"><?php echo htmlspecialchars((string)($booking['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-4">
                                    <p class="font-medium text-slate-700"><?php echo htmlspecialchars((string)($booking['student_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                    <p class="text-xs text-slate-500"><?php echo htmlspecialchars((string)($booking['student_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                </td>
                                <td class="p-4 text-slate-700"><?php echo htmlspecialchars((string)($booking['room'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="p-4">
                                    <p class="text-slate-700">
                                        <?php echo htmlspecialchars(substr((string)($booking['start_time'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8'); ?> -
                                        <?php echo htmlspecialchars(substr((string)($booking['end_time'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                    <p class="text-xs text-slate-500"><?php echo htmlspecialchars((string)($booking['booking_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                </td>
                                <td class="p-4">
                                    <span class="text-xs font-semibold uppercase tracking-wider <?php echo $statusColor; ?>"><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <script id="room-history-series" type="application/json"><?php echo htmlspecialchars($roomHistoryJson, ENT_NOQUOTES, 'UTF-8'); ?></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>
        (function() {
            const dataNode = document.getElementById('room-history-series');
            const roomSelect = document.getElementById('sensor-trend-room');
            const emptyState = document.getElementById('sensor-trend-empty');
            const scrollWrapper = document.getElementById('sensor-trend-scroll');
            const canvasWrapper = document.getElementById('sensor-trend-canvas-wrap');
            const canvas = document.getElementById('sensor-trend-chart');

            if (!dataNode || !roomSelect || !emptyState || !scrollWrapper || !canvasWrapper || !canvas) {
                return;
            }

            let series = [];
            try {
                series = JSON.parse(dataNode.textContent || '[]');
            } catch (err) {
                series = [];
            }

            if (!Array.isArray(series) || series.length === 0) {
                roomSelect.disabled = true;
                emptyState.textContent = 'No room data is available yet.';
                emptyState.classList.remove('hidden');
                return;
            }

            const formatTimestamp = function(rawTs) {
                if (typeof rawTs !== 'string' || rawTs === '') {
                    return '-';
                }

                const ts = new Date(rawTs.replace(' ', 'T'));
                if (Number.isNaN(ts.getTime())) {
                    return rawTs;
                }

                return ts.toLocaleString([], {
                    month: 'short',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false,
                });
            };

            const normalizeNumbers = function(values) {
                if (!Array.isArray(values)) {
                    return [];
                }

                return values.map(function(value) {
                    if (typeof value === 'number') {
                        return Number.isFinite(value) ? value : null;
                    }

                    if (value === null || value === undefined || value === '') {
                        return null;
                    }

                    const parsed = Number(value);
                    return Number.isFinite(parsed) ? parsed : null;
                });
            };

            let trendChart = null;

            const setEmptyState = function(message) {
                emptyState.textContent = message;
                emptyState.classList.remove('hidden');
                scrollWrapper.classList.add('hidden');
            };

            const showChart = function() {
                emptyState.classList.add('hidden');
                scrollWrapper.classList.remove('hidden');
            };

            const drawRoom = function(roomId) {
                const room = series.find(function(entry) {
                    return String(entry.room_id) === String(roomId);
                });

                if (!room) {
                    if (trendChart) {
                        trendChart.destroy();
                        trendChart = null;
                    }
                    setEmptyState('No room selected.');
                    return;
                }

                const labels = Array.isArray(room.labels) ? room.labels.map(formatTimestamp) : [];
                const temperature = normalizeNumbers(room.temperature);
                const humidity = normalizeNumbers(room.humidity);
                const distance = normalizeNumbers(room.distance);

                if (labels.length === 0) {
                    if (trendChart) {
                        trendChart.destroy();
                        trendChart = null;
                    }
                    setEmptyState('No sensor logs found for this room in the selected date range.');
                    return;
                }

                showChart();

                const points = Math.max(labels.length, 1);
                canvasWrapper.style.minWidth = Math.max(960, points * 70) + 'px';

                if (trendChart) {
                    trendChart.destroy();
                }

                trendChart = new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [
                            {
                                label: 'Temperature (°C)',
                                data: temperature,
                                borderColor: '#0891b2',
                                backgroundColor: 'rgba(8, 145, 178, 0.12)',
                                borderWidth: 2,
                                pointRadius: 2,
                                spanGaps: true,
                                tension: 0.35,
                                yAxisID: 'y',
                            },
                            {
                                label: 'Humidity (%)',
                                data: humidity,
                                borderColor: '#2563eb',
                                backgroundColor: 'rgba(37, 99, 235, 0.12)',
                                borderWidth: 2,
                                pointRadius: 2,
                                spanGaps: true,
                                tension: 0.35,
                                yAxisID: 'y1',
                            },
                            {
                                label: 'Distance (cm)',
                                data: distance,
                                borderColor: '#d97706',
                                backgroundColor: 'rgba(217, 119, 6, 0.12)',
                                borderWidth: 2,
                                pointRadius: 2,
                                spanGaps: true,
                                tension: 0.35,
                                yAxisID: 'y2',
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        plugins: {
                            legend: {
                                labels: {
                                    usePointStyle: true,
                                    boxWidth: 10,
                                },
                            },
                            tooltip: {
                                callbacks: {
                                    title: function(items) {
                                        return items[0] ? items[0].label : '';
                                    },
                                },
                            },
                        },
                        scales: {
                            x: {
                                ticks: {
                                    maxRotation: 0,
                                    autoSkip: true,
                                    maxTicksLimit: 12,
                                },
                                grid: {
                                    color: 'rgba(148, 163, 184, 0.12)',
                                },
                            },
                            y: {
                                position: 'left',
                                title: {
                                    display: true,
                                    text: 'Temp (°C)',
                                },
                                grid: {
                                    color: 'rgba(148, 163, 184, 0.12)',
                                },
                            },
                            y1: {
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Humidity (%)',
                                },
                                grid: {
                                    drawOnChartArea: false,
                                },
                            },
                            y2: {
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Distance (cm)',
                                },
                                grid: {
                                    drawOnChartArea: false,
                                },
                            },
                        },
                    },
                });
            };

            roomSelect.innerHTML = '';

            series.forEach(function(room, index) {
                const option = document.createElement('option');
                option.value = String(room.room_id);
                option.textContent = room.room_number + ' (' + (Array.isArray(room.labels) ? room.labels.length : 0) + ' logs)';
                if (index === 0) {
                    option.selected = true;
                }
                roomSelect.appendChild(option);
            });

            roomSelect.addEventListener('change', function(event) {
                drawRoom(event.target.value);
            });

            drawRoom(roomSelect.value);
        })();

        async function handleRoomAction(action, btnElement, roomLabel) {
            let originalContent = '';
            if (btnElement) {
                originalContent = btnElement.innerHTML;
                btnElement.disabled = true;
                btnElement.innerHTML = 'Please wait...';
            }

            try {
                const statusRes = await fetch('https://iot-room.samsam123.name.my/api/status');
                if (statusRes.status !== 200) {
                    showToast('Status request failed (HTTP ' + statusRes.status + ').', 'error');
                    return;
                }

                const statusData = await statusRes.json();
                if (!statusData || statusData.status !== 'online') {
                    showToast('System is not online for ' + roomLabel + '.', 'warning');
                    return;
                }

                if (action === 'door') {
                    const openRes = await fetch('https://iot-room.samsam123.name.my/api/open-door');
                    if (openRes.status === 200) {
                        showToast('Door opened for ' + roomLabel + '.', 'success');
                    } else {
                        showToast('Failed to open door (HTTP ' + openRes.status + ').', 'error');
                    }
                }

                if (action === 'picture') {
                    const pictureRes = await fetch('https://iot-room.samsam123.name.my/api/take-picture');
                    if (pictureRes.status !== 200) {
                        showToast('Failed to capture picture (HTTP ' + pictureRes.status + ').', 'error');
                        return;
                    }

                    const pictureData = await pictureRes.json();
                    if (pictureData && pictureData.data) {
                        showPictureModal('data:image/jpeg;base64,' + pictureData.data);
                        showToast('Picture captured for ' + roomLabel + '.', 'success');
                    } else {
                        showToast('No image data returned for ' + roomLabel + '.', 'warning');
                    }
                }
            } catch (err) {
                console.error(err);
                showToast('Connection error while calling IoT endpoints.', 'error');
            } finally {
                if (btnElement) {
                    btnElement.disabled = false;
                    btnElement.innerHTML = originalContent;
                }
            }
        }

        function showToast(message, type) {
            let container = document.getElementById('toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast-container';
                container.className = 'fixed bottom-4 right-4 z-[120] flex flex-col gap-2';
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            let classes = 'bg-slate-800 text-white border-slate-700';

            if (type === 'success') {
                classes = 'bg-emerald-50 text-emerald-800 border-emerald-200';
            } else if (type === 'error') {
                classes = 'bg-red-50 text-red-800 border-red-200';
            } else if (type === 'warning') {
                classes = 'bg-amber-50 text-amber-800 border-amber-200';
            }

            toast.className = 'px-4 py-3 rounded-xl border shadow-lg text-sm font-medium transition-opacity duration-200 opacity-0 ' + classes;
            toast.textContent = message;
            container.appendChild(toast);

            requestAnimationFrame(function() {
                toast.classList.remove('opacity-0');
            });

            setTimeout(function() {
                toast.classList.add('opacity-0');
                setTimeout(function() {
                    if (toast.parentElement) {
                        toast.remove();
                    }
                }, 220);
            }, 3500);
        }

        function showPictureModal(imgSrc) {
            let modal = document.getElementById('iot-picture-modal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'iot-picture-modal';
                modal.className = 'fixed inset-0 z-[130] bg-slate-900/70 p-4 flex items-center justify-center';
                modal.innerHTML = '<div class="bg-white rounded-2xl shadow-xl max-w-3xl w-full overflow-hidden">'
                    + '<div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">'
                    + '<h4 class="font-semibold text-slate-800">Room Picture</h4>'
                    + '<button type="button" class="text-slate-500 hover:text-slate-700" onclick="document.getElementById(\'iot-picture-modal\').style.display=\'none\'">Close</button>'
                    + '</div>'
                    + '<div class="p-4 bg-slate-50 flex justify-center">'
                    + '<img id="iot-picture-img" src="" alt="Room picture" class="max-h-[70vh] w-auto rounded-lg shadow-sm" />'
                    + '</div>'
                    + '</div>';
                document.body.appendChild(modal);
            }

            const img = document.getElementById('iot-picture-img');
            if (img) {
                img.src = imgSrc;
            }
            modal.style.display = 'flex';
        }
    </script>
</div>
