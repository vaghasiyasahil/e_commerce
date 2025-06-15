<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

include('../../config.php');

if ($con->connect_error) {
    die(json_encode(["status" => "error", "message" => "Database connection failed"]));
}

// Get JSON input
$data = json_decode(file_get_contents("php://input"), true);

// Validate input
$email = isset($data['email']) ? trim($data['email']) : '';
$verify = isset($data['verify']) ? trim($data['verify']) : '';

if (empty($email) || $verify === '') {
    echo json_encode(["status" => "error", "message" => "Email and verify value are required"]);
    exit;
}

// Prepare and execute update query
$stmt = $con->prepare("UPDATE users SET verify = ? WHERE email = ?");
$stmt->bind_param("ss", $verify, $email);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(["status" => "success", "message" => "Verify status updated successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => "No user found with this email or already updated"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Failed to update verify status"]);
}

$stmt->close();
$con->close();
?>
