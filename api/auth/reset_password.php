<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

include('../../config.php');

$data = json_decode(file_get_contents("php://input"), true);

$email = isset($data['email']) ? trim($data['email']) : '';
$new_password = isset($data['password']) ? $data['password'] : '';

if (empty($email) || empty($new_password)) {
    echo json_encode(["status" => "error", "message" => "Email and password are required"]);
    exit;
}

// Password hash karo
$hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

// Check karo email exist karta hai ya nahi
$stmt = $con->prepare("SELECT id FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    // Password update karo
    $update = $con->prepare("UPDATE users SET password = ? WHERE email = ?");
    $update->bind_param("ss", $hashed_password, $email);
    $update->execute();

    echo json_encode(["status" => "success", "message" => "Password reset successfully"]);
} else {
    echo json_encode(["status" => "error", "message" => "Email not found"]);
}

$con->close();
