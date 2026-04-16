<?php
header('Content-Type: application/json');

$nfc_raw_data = $_POST["nfc_raw_data"] ?? null;

if (empty($nfc_raw_data)) {
    $msg = [
        "status" => "fail",
        "desc" => "No NFC Raw Data is provided!",
        "data" => null
    ];
} 
elseif (strpos($nfc_raw_data, '|') === false) {
    $msg = [
        "status" => "fail",
        "desc" => "Invalid format.",
        "data" => $nfc_raw_data
    ];
} 
else {
    list($card_uid, $student_id) = explode('|', $nfc_raw_data, 2);

    if (!preg_match('/^\d{2}[A-Za-z]{3}\d{5}$/', $student_id)) {
        $msg = [
            "status" => "fail",
            "desc" => "Invalid Card!",
            "data" => [
                "card_uid" => $card_uid,
                "student_id" => $student_id
            ]
        ];
    } else {
        $msg = [
            "status" => "success",
            "desc" => "Valid NFC Raw Data received!",
            "data" => [
                "card_uid" => $card_uid,
                "student_id" => $student_id
            ]
        ];
    }
}

echo json_encode($msg);
?>