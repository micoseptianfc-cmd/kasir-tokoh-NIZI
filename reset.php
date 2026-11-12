<?php
require_once 'config/koneksi.php';

// 1. Menghapus data akun lama yang sandinya lupa
$pdo->query("DELETE FROM owner");

// 2. Memasukkan akun baru yang mudah diingat
$pdo->query("INSERT INTO owner (username, password) VALUES ('admin', '12345')");

echo "<h1>Akun berhasil direset!</h1>";
echo "<p>Silakan login menggunakan:<br><br>Username: <b>admin</b><br>Password: <b>12345</b></p>";
echo "<a href='pages/login_owner.php'>Klik di sini untuk langsung Login</a>";
?>