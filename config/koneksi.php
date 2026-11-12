<?php
// config/koneksi.php

$host = "localhost";
$dbname = "db_pos_nizi"; // Disesuaikan dengan nama database baru Anda
$username = "root";
$password = ""; // Sesuaikan jika xampp/laragon Anda menggunakan password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}
?>