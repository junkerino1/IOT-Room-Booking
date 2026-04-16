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

$roomHistorySeries = [];
$roomsStmt = $conn->prepare('SELECT room_id, room_number FROM rooms ORDER BY room_number ASC');
if ($roomsStmt) {
    $roomsStmt->execute();
    $roomsResult = $roomsStmt->get_result();
    $rooms = $roomsResult ? $roomsResult->fetch_all(MYSQLI_ASSOC) : [];

    $historyStmt = $conn->prepare('
        SELECT temperature, humidity, distance, logged_at
        FROM sensor_logs
        WHERE room_id = ?
        ORDER BY logged_at DESC
        LIMIT 180
    ');

    if ($historyStmt) {
        foreach ($rooms as $roomRow) {
            $roomId = (int)($roomRow['room_id'] ?? 0);
            if ($roomId <= 0) {
                continue;
            }

            $historyStmt->bind_param('i', $roomId);
            $historyStmt->execute();
            $historyResult = $historyStmt->get_result();
            $historyRows = $historyResult ? $historyResult->fetch_all(MYSQLI_ASSOC) : [];
            $historyRows = array_reverse($historyRows);

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

                    <div class="mt-4 flex gap-2">
                        <button type="button" onclick="handleRoomAction('picture', this)" class="flex-1 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 px-3 py-2 rounded-xl text-xs font-bold uppercase tracking-wider transition-colors inline-flex justify-center items-center gap-1.5 shadow-sm disabled:opacity-50 disabled:cursor-not-allowed">
                            View Picture
                        </button>
                        <button type="button" onclick="handleRoomAction('door', this)" class="flex-1 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 px-3 py-2 rounded-xl text-xs font-bold uppercase tracking-wider transition-colors inline-flex justify-center items-center gap-1.5 shadow-sm border border-emerald-100 disabled:opacity-50 disabled:cursor-not-allowed">
                            Open Door
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="mt-6">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-slate-50/30">
                <h3 class="font-bold text-lg text-slate-800 flex items-center gap-2">
                    <i data-lucide="line-chart" class="w-5 h-5 text-blue-600"></i>
                    Room Condition Trends
                </h3>
                <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500 bg-slate-100 px-3 py-1 rounded-full">
                    <i data-lucide="move-horizontal" class="w-3.5 h-3.5"></i>
                    Scrollable timeline
                </span>
            </div>

            <div class="p-6">
                <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-4">
                    <div class="w-full lg:w-72">
                        <label for="trend-room-select" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Select Room</label>
                        <select id="trend-room-select" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"></select>
                    </div>
                    <p class="text-xs text-slate-400">Scroll horizontally in the graph area to browse older and newer temperature, humidity, and distance readings.</p>
                </div>

                <div id="trend-empty-state" class="hidden rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500"></div>

                <div id="trend-scroll-wrapper" class="hidden overflow-x-auto pb-2">
                    <div id="trend-canvas-wrapper" class="h-[340px] min-w-[960px]">
                        <canvas id="room-trend-chart"></canvas>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2 text-[11px] font-semibold uppercase tracking-wider">
                    <span class="px-2.5 py-1 rounded-full bg-cyan-50 text-cyan-700 border border-cyan-100">Temperature</span>
                    <span class="px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-100">Humidity</span>
                    <span class="px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-100">Distance</span>
                </div>
            </div>
        </div>
    </section>

    <script id="room-history-series" type="application/json"><?php echo htmlspecialchars($roomHistoryJson, ENT_NOQUOTES, 'UTF-8'); ?></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>
        (function() {
            const dataNode = document.getElementById('room-history-series');
            const roomSelect = document.getElementById('trend-room-select');
            const emptyState = document.getElementById('trend-empty-state');
            const scrollWrapper = document.getElementById('trend-scroll-wrapper');
            const canvasWrapper = document.getElementById('trend-canvas-wrapper');
            const canvas = document.getElementById('room-trend-chart');

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
                emptyState.textContent = 'No sensor history is available yet.';
                emptyState.classList.remove('hidden');
                return;
            }

            const formatTimestamp = function(rawTs) {
                if (typeof rawTs !== 'string' || rawTs === '') {
                    return '—';
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
                    if (value === null || value === '' || Number.isNaN(Number(value))) {
                        return null;
                    }
                    return Number(value);
                });
            };

            series.forEach(function(room) {
                const option = document.createElement('option');
                option.value = String(room.room_id ?? '');
                option.textContent = String(room.room_number ?? ('Room ' + option.value));
                roomSelect.appendChild(option);
            });

            let trendChart = null;

            const renderSelectedRoom = function() {
                const selectedRoom = series.find(function(room) {
                    return String(room.room_id ?? '') === roomSelect.value;
                }) || series[0];

                if (!selectedRoom) {
                    if (trendChart) {
                        trendChart.destroy();
                        trendChart = null;
                    }
                    scrollWrapper.classList.add('hidden');
                    emptyState.textContent = 'No room data available.';
                    emptyState.classList.remove('hidden');
                    return;
                }

                const labels = Array.isArray(selectedRoom.labels)
                    ? selectedRoom.labels.map(formatTimestamp)
                    : [];
                const temperatures = normalizeNumbers(selectedRoom.temperature);
                const humidities = normalizeNumbers(selectedRoom.humidity);
                const distances = normalizeNumbers(selectedRoom.distance);

                if (labels.length === 0) {
                    if (trendChart) {
                        trendChart.destroy();
                        trendChart = null;
                    }
                    scrollWrapper.classList.add('hidden');
                    emptyState.textContent = 'No trend points recorded for this room yet.';
                    emptyState.classList.remove('hidden');
                    return;
                }

                if (typeof window.Chart === 'undefined') {
                    scrollWrapper.classList.add('hidden');
                    emptyState.textContent = 'Unable to load chart library.';
                    emptyState.classList.remove('hidden');
                    return;
                }

                emptyState.classList.add('hidden');
                scrollWrapper.classList.remove('hidden');

                const chartWidth = Math.max(960, labels.length * 34);
                canvasWrapper.style.width = chartWidth + 'px';

                if (trendChart) {
                    trendChart.destroy();
                }

                trendChart = new window.Chart(canvas.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Temperature (°C)',
                            data: temperatures,
                            borderColor: '#0891b2',
                            backgroundColor: 'rgba(8, 145, 178, 0.14)',
                            yAxisID: 'yTemp',
                            pointRadius: 0,
                            borderWidth: 2,
                            tension: 0.32,
                            fill: true,
                        }, {
                            label: 'Humidity (%)',
                            data: humidities,
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37, 99, 235, 0.08)',
                            yAxisID: 'yHum',
                            pointRadius: 0,
                            borderWidth: 2,
                            tension: 0.32,
                            fill: false,
                        }, {
                            label: 'Distance (cm)',
                            data: distances,
                            borderColor: '#d97706',
                            backgroundColor: 'rgba(217, 119, 6, 0.08)',
                            yAxisID: 'yDist',
                            borderDash: [6, 4],
                            pointRadius: 0,
                            borderWidth: 2,
                            tension: 0.32,
                            fill: false,
                        }],
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
                                position: 'top',
                                labels: {
                                    usePointStyle: true,
                                    boxWidth: 8,
                                    color: '#475569',
                                    font: {
                                        size: 11,
                                        weight: '600',
                                    },
                                },
                            },
                            tooltip: {
                                backgroundColor: 'rgba(15, 23, 42, 0.92)',
                                titleFont: {
                                    size: 11,
                                    weight: '700',
                                },
                                bodyFont: {
                                    size: 11,
                                },
                                padding: 10,
                                displayColors: true,
                            },
                        },
                        scales: {
                            x: {
                                ticks: {
                                    maxRotation: 0,
                                    autoSkip: true,
                                    maxTicksLimit: 12,
                                    color: '#64748b',
                                    font: {
                                        size: 10,
                                    },
                                },
                                grid: {
                                    color: 'rgba(148, 163, 184, 0.12)',
                                },
                            },
                            yTemp: {
                                type: 'linear',
                                position: 'left',
                                title: {
                                    display: true,
                                    text: '°C',
                                    color: '#0891b2',
                                },
                                ticks: {
                                    color: '#0891b2',
                                },
                                grid: {
                                    color: 'rgba(8, 145, 178, 0.08)',
                                },
                            },
                            yHum: {
                                type: 'linear',
                                position: 'right',
                                title: {
                                    display: true,
                                    text: '%',
                                    color: '#2563eb',
                                },
                                ticks: {
                                    color: '#2563eb',
                                },
                                grid: {
                                    drawOnChartArea: false,
                                },
                            },
                            yDist: {
                                type: 'linear',
                                position: 'right',
                                offset: true,
                                title: {
                                    display: true,
                                    text: 'cm',
                                    color: '#d97706',
                                },
                                ticks: {
                                    color: '#d97706',
                                },
                                grid: {
                                    drawOnChartArea: false,
                                },
                            },
                        },
                    },
                });

                scrollWrapper.scrollLeft = scrollWrapper.scrollWidth;
            };

            roomSelect.addEventListener('change', renderSelectedRoom);
            renderSelectedRoom();
        })();

        async function handleRoomAction(action, btnElement) {
            let originalContent = '';
            if (btnElement) {
                originalContent = btnElement.innerHTML;
                btnElement.disabled = true;
                btnElement.innerHTML = '<svg class="animate-spin h-4 w-4 text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Please wait...';
            }
            try {
                const res = await fetch('https://iot-room.samsam123.name.my/api/status');
                if (res.status === 200) {
                    const data = await res.json();
                    if (data.status === 'online') {
                        if (action === 'door') {
                            const openRes = await fetch('https://iot-room.samsam123.name.my/api/open-door');
                            if (openRes.status === 200) {
                                showToast('Door opened successfully!', 'success');
                            } else {
                                showToast('Failed to open door: HTTP ' + openRes.status, 'error');
                            }
                        } else if (action === 'picture') {
                            const picRes = await fetch('https://iot-room.samsam123.name.my/api/take-picture');
                            if (picRes.status === 200) {
                                const picData = await picRes.json();
                                if (picData && picData.data) {
                                    showPictureModal('data:image/jpeg;base64,' + picData.data);
                                } else {
                                    showToast('Warning: No base64 image data string found in response.', 'warning');
                                }
                            } else {
                                showToast('Failed to get picture: HTTP ' + picRes.status, 'error');
                            }
                        }
                    } else {
                        showToast('System is not online according to status (' + data.status + ')', 'warning');
                    }
                } else {
                    showToast('Status request failed. HTTP ' + res.status, 'error');
                }
            } catch (e) {
                console.error(e);
                showToast('Connection error occurred while fetching IoT endpoints.', 'error');
            } finally {
                if (btnElement) {
                    btnElement.disabled = false;
                    btnElement.innerHTML = originalContent;
                }
            }
        }

        function showToast(message, type = 'info') {
            let container = document.getElementById('toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast-container';
                container.className = 'fixed bottom-4 right-4 z-[110] flex flex-col gap-2';
                document.body.appendChild(container);
            }

            const toast = document.createElement('div');
            let bgClass = 'bg-slate-800';
            let borderClass = 'border-slate-700';
            let textClass = 'text-white';
            let icon = '<i data-lucide="info" class="w-4 h-4"></i>';

            if (type === 'success') {
                bgClass = 'bg-emerald-50';
                borderClass = 'border-emerald-200';
                textClass = 'text-emerald-800';
                icon = '<svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
            } else if (type === 'error') {
                bgClass = 'bg-red-50';
                borderClass = 'border-red-200';
                textClass = 'text-red-800';
                icon = '<svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
            } else if (type === 'warning') {
                bgClass = 'bg-amber-50';
                borderClass = 'border-amber-200';
                textClass = 'text-amber-800';
                icon = '<svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>';
            }

            toast.className = `flex items-center justify-between gap-3 px-4 py-3 rounded-xl border shadow-lg transition-all duration-300 transform translate-y-4 opacity-0 ${bgClass} ${borderClass} ${textClass} text-sm font-medium min-w-[250px]`;
            toast.innerHTML = `
                <div class="flex items-center gap-2">
                    <div class="flex-shrink-0">${icon}</div>
                    <div>${message}</div>
                </div>
                <button type="button" class="ml-2 opacity-50 hover:opacity-100 flex-shrink-0 transition-opacity" onclick="this.closest('.flex.items-center.justify-between').remove()">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            `;

            container.appendChild(toast);

            requestAnimationFrame(() => {
                toast.classList.remove('translate-y-4', 'opacity-0');
                toast.classList.add('translate-y-0', 'opacity-100');
            });

            setTimeout(() => {
                toast.classList.remove('translate-y-0', 'opacity-100');
                toast.classList.add('translate-y-4', 'opacity-0');
                setTimeout(() => {
                    if (toast.parentElement) toast.remove();
                }, 300);
            }, 4000);
        }

        function showPictureModal(imgSrc) {
            let container = document.getElementById('iot-picture-modal');
            if (!container) {
                container = document.createElement('div');
                container.id = 'iot-picture-modal';
                container.className = 'fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm';
                container.innerHTML = `
                    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg overflow-hidden flex flex-col items-center relative transition-transform">
                        <button type="button" class="absolute top-4 right-4 p-1.5 w-8 h-8 flex items-center justify-center bg-slate-100 rounded-full hover:bg-slate-200 text-slate-500 font-bold" onclick="document.getElementById('iot-picture-modal').style.display='none'">&times;</button>
                        <div class="w-full text-center p-4 border-b border-slate-100 font-bold text-slate-800 uppercase tracking-wider text-sm">Room Picture</div>
                        <div class="w-full bg-slate-50 flex items-center justify-center min-h-[250px] p-4">
                            <img id="iot-picture-img" src="" class="max-w-full rounded shadow-sm object-contain" style="max-height: 60vh;" alt="Room perspective" />
                        </div>
                    </div>
                `;
                document.body.appendChild(container);
            }
            document.getElementById('iot-picture-img').src = imgSrc;
            container.style.display = 'flex';
        }
    </script>

</div>