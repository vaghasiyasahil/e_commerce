<?php
    include("../../config.php");
    include("../../services/send_otp_in_mail.php");

    $data = json_decode(file_get_contents("php://input"), true);
    $email = $data['email'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["status" => "error", "message" => "Invalid Email"]);
        exit;
    }

    $otp = rand(100000, 999999);

    setcookie("otp", $otp, time() + 60, "/");        // OTP valid for 60 sec
    setcookie("email", $email, time() + 60, "/");    // Store email (optional)

    if (sendOTP($email, $otp)) {
        echo json_encode(["status" => "success", "message" => "OTP sent to email", "otp" => "$otp"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to send OTP"]);
    }
?>
