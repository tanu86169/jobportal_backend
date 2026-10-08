<?php

$host = "localhost";
$username = "root";
$password = "";
$database = "job_portal";

$conn = new mysqli(
    $host,
    $username,
    $password,
    $database
);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error); // Yahan thek kar diya hai ($conn$conn ki jagah sirf $conn)
}

$conn->set_charset("utf8mb4");

// Dynamic URL detection (Ye automatically detect kar lega ki tum khud chala rahe ho ya dusra banda IP se)
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host_ip = $_SERVER['HTTP_HOST']; // Ye tumhara IP ya localhost dono automatically utha lega

define('BASE_URL', "$protocol://$host_ip/job_portal/");

?>