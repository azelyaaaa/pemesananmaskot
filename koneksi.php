<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "db_sewa_kostum";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Koneksi database gagal: " . $conn->connect_error]);
    exit;
}
?>