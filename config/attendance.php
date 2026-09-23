<?php

// EntryPoint — library check-in / check-out (POST /librarian/attendance-logs/scan)

return [

    // A second scan of the same student inside this window is refused, so a double beep
    // can't check someone in and straight back out.
    'double_scan_seconds' => (int) env('ATTENDANCE_DOUBLE_SCAN_SECONDS', 10),

    // What happens to a visit that was never scanned out:
    //  'closing_time' => closed at closing_time on the day the student came in
    //  'hours'        => closed auto_close_hours after they came in
    'auto_close'       => env('ATTENDANCE_AUTO_CLOSE', 'closing_time'),
    'closing_time'     => env('ATTENDANCE_CLOSING_TIME', '17:00'),
    'auto_close_hours' => (int) env('ATTENDANCE_AUTO_CLOSE_HOURS', 12),

];
