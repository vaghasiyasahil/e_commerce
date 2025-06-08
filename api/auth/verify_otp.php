<?php
// Headers for API
header("Content-Type: application/json");

// Get OTP from user (via POST request)
$data = json_decode(file_get_contents("php://input"), true);
$userOtp = $data['otp'] ?? '';

// Check if OTP cookie exists
if (!isset($_COOKIE['otp']) || !isset($_COOKIE['email'])) {
    echo json_encode(["status" => "error", "message" => "OTP expired"]);
    exit;
}

$storedOtp = $_COOKIE['otp'];
$storedEmail = $_COOKIE['email'];

// Compare OTPs
if ($userOtp == $storedOtp) {
    
    // Optional: delete the cookie after successful verification
    setcookie("otp", "", time() - 3600, "/");
    setcookie("email", "", time() - 3600, "/");

    echo json_encode(["status" => "success", "message" => "OTP verified", "email" => $storedEmail]);
} else {
    echo json_encode(["status" => "error", "message" => "Invalid OTP"]);
}
?>
