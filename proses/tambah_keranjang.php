<?php
session_start();
require_once '../config/koneksi.php';

if (!isset($_SESSION['keranjang'])) {
    $_SESSION['keranjang'] = [];
}

if (isset($_POST['barcode'])) {
    $barcode = trim((string) $_POST['barcode']);

    if ($barcode !== '') {
        try {
            $cek_id = $pdo->query("SHOW COLUMNS FROM produk LIKE 'id'");
            $kolom_id = ($cek_id->rowCount() > 0) ? 'id' : 'id_produk';

            $stmt = $pdo->prepare(
                "SELECT * FROM produk WHERE (barcode = ? OR kode_produk = ? OR $kolom_id = ?) AND status = 'aktif'"
            );
            $stmt->execute([$barcode, $barcode, $barcode]);
            $produk = $stmt->fetch();

            if ($produk) {
                $id_produk = $produk[$kolom_id];
                $stok_tersedia = (int) ($produk['stok'] ?? 0);
                $harga_eceran = $produk['harga_jual'] ?? $produk['harga'] ?? 0;
                $harga_grosir = $produk['harga_jual_grosir'] ?? 0;

                if ($stok_tersedia > 0) {
                    if (isset($_SESSION['keranjang'][$id_produk])) {
                        $_SESSION['keranjang'][$id_produk]['qty'] += 1;
                    } else {
                        $_SESSION['keranjang'][$id_produk] = [
                            'id' => $id_produk,
                            'nama_produk' => $produk['nama_produk'] ?? $produk['nama'] ?? 'Produk Tanpa Nama',
                            'harga_eceran' => $harga_eceran,
                            'harga_grosir' => $harga_grosir,
                            'jenis_harga' => 'eceran',
                            'qty' => 1,
                            'stok_maks' => $stok_tersedia
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore schema mismatches here; the main cashier page already handles them.
        }
    }
}
header("Location: ../pages/kasir.php");
exit();
?>