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

$checkQuery = $con->prepare("SELECT verify FROM users WHERE email = ?");
$checkQuery->bind_param("s", $email);
$checkQuery->execute();
$checkQuery->store_result();
$checkQuery->bind_result($verifyStatus);
$checkQuery->fetch();

if ($checkQuery->num_rows > 0) {
    if ($verifyStatus === 'true') {
        echo json_encode(["status" => "error", "message" => "Email already registered"]);
        exit;
    } else {
        echo json_encode(["status" => "success", "message" => "User registered successfully"]);
        exit;
    }
}

$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

$stmt = $con->prepare("INSERT INTO users (name, email, password, verify) VALUES (?, ?, ?, 'false')");
$stmt->bind_param("sss", $username, $email, $password);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "User registered successfully"]);
} else {
    echo json_encode(["status" => "error", "message" => "Registration failed"]);
}

$con->close();
?>
