<?php
$errorMessage = '';
$stmt = $conn->prepare('SELECT * FROM rooms');
$stmt->execute();
$rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare('SELECT * FROM timeslots ORDER BY start_time ASC');
$stmt->execute();
$timeslots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $student_id   = $_SESSION['student_id'];
    $room_id      = (int)$_POST['room_id'];
    $booking_date = $_POST['booking_date'];
    $start_id     = (int)$_POST['start_time'];
    $duration     = (int)$_POST['duration'];

    // Find selected timeslot index
    $index = -1;
    foreach ($timeslots as $i => $slot) {
        if ($slot['timeslot_id'] == $start_id) {
            $index = $i;
            break;
        }
    }

    // Validate
    if ($index === -1) {
        $errorMessage = "Invalid timeslot selected.";

    } elseif ($booking_date < date('Y-m-d')) {
        $errorMessage = "Booking date cannot be in the past.";

    } elseif ($booking_date === date('Y-m-d') && $timeslots[$index]['end_time'] < date('H:i:s')) {
        $errorMessage = "This timeslot has already passed.";

    } elseif ($duration === 2 && !isset($timeslots[$index + 1])) {
        $errorMessage = "Cannot book beyond the last timeslot.";

    } else {

        // Build list of timeslot IDs to book
        $time_ids = [$timeslots[$index]['timeslot_id']];
        if ($duration === 2) {
            $time_ids[] = $timeslots[$index + 1]['timeslot_id'];
        }

        $conn->begin_transaction();

        try {
            foreach ($time_ids as $t) {

                // Check if slot is already taken
                $check = $conn->prepare("
                    SELECT 1 FROM bookings 
                    WHERE room_id = ? AND timeslot_id = ? AND booking_date = ?
                ");
                $check->bind_param('iis', $room_id, $t, $booking_date);
                $check->execute();

                if ($check->get_result()->num_rows > 0) {
                    throw new Exception("This timeslot is already booked.");
                }

                // Insert booking
                $insert = $conn->prepare("
                    INSERT INTO bookings (student_id, room_id, timeslot_id, booking_date)
                    VALUES (?, ?, ?, ?)
                ");
                $insert->bind_param('iiis', $student_id, $room_id, $t, $booking_date);
                $insert->execute();

                if ($insert->affected_rows <= 0) {
                    throw new Exception("Failed to save booking.");
                }
            }

            $conn->commit();
            header('Location: /booking/my-bookings');
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $errorMessage = $e->getMessage();
        }
    }
}
?>
<div class="max-w-2xl mx-auto">

    <?php if (!empty($errorMessage)): ?>
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8">
        <h3 class="text-xl font-bold mb-6">New Booking</h3>



        <form action="" method="POST" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Select Room</label>
                    <select name="room_id" required class="w-full px-4 py-3 rounded-xl border border-slate-200 outline-none">
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= $room['room_id'] ?>">
                                <?= $room['room_number'] ?> (<?= $room['capacity'] ?> Pax)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class=" block text-sm font-medium text-slate-700 mb-2">Booking Date</label>
                    <input type="date" name="booking_date" value="<?= date('Y-m-d') ?>" required class="w-full px-4 py-3 rounded-xl border border-slate-200">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Start Time</label>
                    <select name="start_time" required class="w-full px-4 py-3 rounded-xl border border-slate-200">
                        <?php foreach ($timeslots as $slot): ?>
                            <option value="<?= $slot['timeslot_id'] ?>">
                                <?= date('H:i', strtotime($slot['start_time'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-2">Duration</label>
                    <select name="duration" required class="w-full px-4 py-3 rounded-xl border border-slate-200">
                        <option value="1">1 Hour</option>
                        <option value="2">2 Hours</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 rounded-xl shadow-lg transition-all active:scale-[0.98]">
                Confirm Booking
            </button>
        </form>
    </div>
</div>