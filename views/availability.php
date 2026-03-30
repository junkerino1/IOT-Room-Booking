<div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-slate-50/30">
        <div>
            <h3 class="font-bold text-lg">Room Availability</h3>
            <form action="/availability" method="GET" class="flex items-center gap-2 mt-1">
                <p class="text-sm text-slate-500">Select Date:</p>
                <input type="date" name="date" value="<?= $selectedDate ?>" onchange="this.form.submit()" class="text-sm font-semibold bg-white border border-slate-200 rounded-lg px-2 py-1">
            </form>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left border-collapse">
            <thead>
                <tr class="bg-slate-50/50">
                    <th class="p-4 font-semibold text-slate-600 border-b border-slate-100 sticky left-0 bg-slate-50 z-10 w-32">Room</th>
                    <?php foreach($timeSlots as $slot): ?>
                        <th class="p-4 font-semibold text-slate-600 border-b border-slate-100 text-center"><?= $slot ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach($rooms as $room): ?>
                <tr class="border-b border-slate-50 hover:bg-slate-50/30 transition-colors">
                    <td class="p-4 font-medium text-slate-700 sticky left-0 bg-white z-10 border-r border-slate-50"><?= $room['NAME'] ?></td>
                    <?php foreach($timeSlots as $slot): 
                        $isBooked = checkBooking($room['ID'], $slot, $bookings); ?>
                        <td class="p-4 text-center border-r border-slate-50 <?= $isBooked ? 'slot-booked' : 'slot-available' ?>">
                            <?php if($isBooked): ?>
                                <span class="text-[10px] font-bold uppercase tracking-tighter">Booked</span>
                            <?php else: ?>
                                <a href="/booking/new?room=<?= $room['ID'] ?>&time=<?= $slot ?>" class="block w-full h-full text-blue-400 hover:text-blue-600">Available</a>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>