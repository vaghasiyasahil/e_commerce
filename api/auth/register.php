<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

include('../../config.php');

if ($con->connect_error) {
    die(json_encode(["status" => "error", "message" => "Database connection failed"]));
}

$data = json_decode(file_get_contents("php://input"), true);

$username = isset($data['username']) ? trim($data['username']) : '';
$email = isset($data['email']) ? trim($data['email']) : '';
$password = isset($data['password']) ? $data['password'] : '';

if (empty($username) || empty($email) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "All fields are required"]);
    exit;
}

// Check if email already exists
$checkQuery = $con->prepare("SELECT id FROM users WHERE email = ?");
$checkQuery->bind_param("s", $email);
$checkQuery->execute();
$checkQuery->store_result();

if ($checkQuery->num_rows > 0) {
    echo json_encode(["status" => "error", "message" => "Email already registered"]);
    exit;
}

$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

$stmt = $con->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $username, $email, $hashedPassword);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "User registered successfully"]);
} else {
    echo json_encode(["status" => "error", "message" => "Registration failed"]);
}

$con->close();
?>
