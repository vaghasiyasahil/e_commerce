<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

include('../../config.php');

if ($con->connect_error) {
    die(json_encode(["status" => "error", "message" => "Database connection failed"]));
}

// Read JSON data from POST
$data = json_decode(file_get_contents("php://input"), true);

// Get email from POST
$email = isset($data['email']) ? trim($data['email']) : '';

if (empty($email)) {
    echo json_encode(["status" => "error", "message" => "Email is required"]);
    exit;
}

// Prepare and execute query
$stmt = $con->prepare("SELECT verify FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $user = $result->fetch_assoc();

    if ($user['verify'] === 'true') {
        echo json_encode(["status" => "success", "message" => "User is verified"]);
    } else {
        echo json_encode(["status" => "error", "message" => "User is not verified"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "User not found"]);
}

$stmt->close();
$con->close();
?>
