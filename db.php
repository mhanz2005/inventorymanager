<?php
$host = "localhost";
$user = "root";
$pass = ""; // Default password in XAMPP is empty
$dbname = "automatedinventorymanager";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die(json_encode(["success" => false, "message" => "Database connection failed: " . $conn->connect_error]));
}
?>