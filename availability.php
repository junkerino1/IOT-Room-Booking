<?php

if (!isset($conn)) {
    require_once __DIR__ . '/bootstrap.php';
}

$selectedDate = $_GET['date'] ?? date('Y-m-d');
$today = date('Y-m-d');
$nowTs = time();

$stmt = $conn->prepare("SELECT * FROM rooms");
$stmt->execute();
$rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare("SELECT * FROM timeslots");
$stmt->execute();
$timeslots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare("SELECT * FROM bookings WHERE booking_date = ?");
$stmt->bind_param('s', $selectedDate);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);


function checkBooking($roomId, $timeSlot, $selectedDate, $bookings) {
    foreach ($bookings as $booking) {
        if (
            $booking['room_id'] == $roomId &&
            $booking['timeslot_id'] == $timeSlot &&
            $booking['booking_date'] == $selectedDate
        ) {
            return true;
        }
    }
    return false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Room Availability</title>
<script src="https://cdn.tailwindcss.com"></script>

<style>
.slot-available {
    background-color: #f1f5f9;
}
.slot-available:hover {
    background-color: #dbeafe;
}
.slot-booked {
    background-color: #fee2e2;
    color: #ef4444;
}
</style>
</head>

<body class="bg-slate-50 p-8">

<div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">

    <!-- HEADER -->
    <div class="p-6 border-b flex justify-between items-center bg-slate-50">
        <div>
            <h3 class="font-bold text-lg">Room Availability</h3>

            <!-- DATE PICKER -->
            <form method="GET" class="flex items-center gap-2 mt-2">
                <span class="text-sm text-slate-500">Select Date:</span>
                <input type="date" name="date" value="<?= $selectedDate ?>"
                       onchange="this.form.submit()"
                       class="border rounded px-2 py-1">
            </form>
        </div>

        <div class="text-xs flex gap-4">
            <div class="flex items-center gap-1">
                <div class="w-3 h-3 bg-slate-100"></div> Available
            </div>
            <div class="flex items-center gap-1">
                <div class="w-3 h-3 bg-red-100"></div> Booked
            </div>
        </div>
    </div>

    <!-- TABLE -->
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">

            <!-- TABLE HEADER -->
            <thead>
                <tr class="bg-slate-50">
                    <th class="p-4 border w-32">Room</th>
                    <?php foreach ($timeslots as $slot): ?>
                        <th class="p-4 border text-center"><?= date('H:i', strtotime($slot['start_time'])) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>

            <!-- TABLE BODY -->
            <tbody>
                <?php foreach ($rooms as $room): ?>
                <tr>

                    <!-- ROOM NAME -->
                    <td class="p-4 border font-medium">
                        <?= $room['room_number'] ?>
                    </td>

                    <!-- TIME SLOTS -->
                    <?php foreach ($timeslots as $slot): 
                        $isBooked = checkBooking($room['room_id'], $slot['timeslot_id'], $selectedDate, $bookings);
                        $slotEndTime = $slot['end_time'] ?? ($slot['start_time'] ?? '');
                        $slotEndTs = strtotime($selectedDate . ' ' . $slotEndTime);

                        $isExpired = false;
                        if ($selectedDate < $today) {
                            $isExpired = true;
                        } elseif ($selectedDate === $today && $slotEndTs !== false && $slotEndTs <= $nowTs) {
                            $isExpired = true;
                        }

                        $cellClass = $isExpired ? 'bg-slate-200 text-slate-500 cursor-not-allowed' : ($isBooked ? 'slot-booked' : 'slot-available');
                    ?>
                        <td class="p-4 border text-center <?= $cellClass ?>">
                            <?php if ($isExpired): ?>
                                <span class="text-xs font-bold">Expired</span>
                            <?php elseif ($isBooked): ?>
                                <span class="text-xs font-bold">Booked</span>
                            <?php else: ?>
                                <span class="text-blue-500 font-bold text-xs">Available</span>
                            <?php endif; ?>

                        </td>
                    <?php endforeach; ?>

                </tr>
                <?php endforeach; ?>
            </tbody>

        </table>
    </div>
</div>

</body>
</html>