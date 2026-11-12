<?php
session_start();
require_once '../config/koneksi.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM owner WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Menggunakan == agar sandi dibaca sebagai teks biasa, bukan kode acak
    if ($user && $password == $user['password']) {
        $_SESSION['owner_logged_in'] = true;
        header("Location: ../pages/owner/laporan.php");
        exit();
    } else {
        echo "<script>alert('Username atau Password salah!'); window.location='../pages/login_owner.php';</script>";
    }
}
?>