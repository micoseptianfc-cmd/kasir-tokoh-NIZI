<?php
// proses/proses_transaksi.php
session_start();
require_once '../config/koneksi.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (empty($_SESSION['keranjang'])) {
        $_SESSION['pesan_error_transaksi'] = "Keranjang kosong.";
        header("Location: ../pages/kasir.php");
        exit();
    }

    $total_belanja = 0;
    foreach ($_SESSION['keranjang'] as $item) {
        $jenis_harga_item = $item['jenis_harga'] ?? 'eceran';
        if (!in_array($jenis_harga_item, ['eceran', 'grosir'], true)) {
            $_SESSION['pesan_error_transaksi'] = "Jenis penjualan produk tidak valid.";
            header("Location: ../pages/kasir.php");
            exit();
        }
        $harga = $jenis_harga_item === 'grosir'
            ? ($item['harga_grosir'] ?? 0)
            : ($item['harga_eceran'] ?? $item['harga'] ?? 0);
        if ($jenis_harga_item === 'grosir' && (float)$harga <= 0) {
            $_SESSION['pesan_error_transaksi'] = "Harga grosiran belum tersedia untuk produk " . ($item['nama_produk'] ?? '') . ".";
            header("Location: ../pages/kasir.php");
            exit();
        }
        $total_belanja += $harga * (int)$item['qty'];
    }

    $uang_tunai = floatval($_POST['uang_tunai'] ?? 0);

    if ($uang_tunai < $total_belanja) {
        echo "<script>alert('Uang tunai kurang dari total belanja!'); window.location='../pages/kasir.php';</script>";
        exit();
    }

    $kembalian = $uang_tunai - $total_belanja;
    $no_struk = "STRUK-" . date('Ymd') . "-" . rand(100, 999);
    $waktu_sekarang = date('Y-m-d H:i:s');

    // Simpan sementara ke session untuk pengaman data struk
    $_SESSION['last_struk'] = $no_struk;
    $_SESSION['last_total'] = $total_belanja;
    $_SESSION['last_uang_tunai'] = $uang_tunai;
    $_SESSION['last_kembalian'] = $kembalian;

    $pdo->beginTransaction();
    try {
        // Ambil daftar kolom tabel transaksi secara fleksibel
        $cek_kolom_t = $pdo->query("SHOW COLUMNS FROM transaksi");
        $kolom_transaksi = $cek_kolom_t->fetchAll(PDO::FETCH_COLUMN);

        $kolom_query = ['no_struk'];
        $nilai_query = [$no_struk];

        foreach (['tanggal_waktu', 'tanggal', 'created_at', 'waktu', 'tanggal_transaksi', 'tgl'] as $kand_tgl) {
            if (in_array($kand_tgl, $kolom_transaksi)) {
                $kolom_query[] = $kand_tgl;
                $nilai_query[] = $waktu_sekarang;
                break;
            }
        }

        foreach (['total_belanja', 'total', 'grand_total'] as $kand_tot) {
            if (in_array($kand_tot, $kolom_transaksi)) {
                $kolom_query[] = $kand_tot;
                $nilai_query[] = $total_belanja;
                break;
            }
        }

        foreach (['tunai', 'uang_tunai'] as $kolom_tunai) {
            if (in_array($kolom_tunai, $kolom_transaksi, true)) {
                $kolom_query[] = $kolom_tunai;
                $nilai_query[] = $uang_tunai;
            }
        }

        if (in_array('kembalian', $kolom_transaksi)) {
            $kolom_query[] = 'kembalian';
            $nilai_query[] = $kembalian;
        }

        $str_kolom = implode(", ", $kolom_query);
        $str_tanda = implode(", ", array_fill(0, count($kolom_query), "?"));
        
        $sql = "INSERT INTO transaksi ($str_kolom) VALUES ($str_tanda)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($nilai_query);
        $id_transaksi = $pdo->lastInsertId();

        $cek_kolom_detail = $pdo->query("SHOW COLUMNS FROM detail_transaksi");
        $kolom_detail = $cek_kolom_detail->fetchAll(PDO::FETCH_COLUMN);
        $kolom_referensi = in_array('id_transaksi', $kolom_detail, true) ? 'id_transaksi' : 'no_struk';
        $kolom_harga = in_array('harga_saat_transaksi', $kolom_detail, true) ? 'harga_saat_transaksi' : 'harga';
        $kolom_jumlah = in_array('qty', $kolom_detail, true) ? 'qty' : 'jumlah';

        if (!in_array($kolom_referensi, $kolom_detail, true) ||
            !in_array('id_produk', $kolom_detail, true) ||
            !in_array($kolom_harga, $kolom_detail, true) ||
            !in_array($kolom_jumlah, $kolom_detail, true) ||
            !in_array('subtotal', $kolom_detail, true)) {
            throw new RuntimeException("Struktur tabel detail_transaksi tidak sesuai.");
        }

        // Deteksi primary key tabel produk untuk kurangi stok
        $cek_kolom_p = $pdo->query("SHOW COLUMNS FROM produk");
        $kolom_produk = $cek_kolom_p->fetchAll(PDO::FETCH_COLUMN);
        
        $primary_key_produk = 'id';
        foreach (['id', 'id_produk', 'kode_produk'] as $pk_kandidat) {
            if (in_array($pk_kandidat, $kolom_produk)) {
                $primary_key_produk = $pk_kandidat;
                break;
            }
        }

        foreach ($_SESSION['keranjang'] as $item) {
            $id_produk = $item['id'];
            $qty = $item['qty'];
            $jenis_harga_item = $item['jenis_harga'] ?? 'eceran';
            $harga = $jenis_harga_item === 'grosir'
                ? $item['harga_grosir']
                : ($item['harga_eceran'] ?? $item['harga'] ?? 0);
            $subtotal = $harga * $qty;

            $kolom_insert_detail = [$kolom_referensi, 'id_produk', $kolom_harga, $kolom_jumlah, 'subtotal'];
            $nilai_insert_detail = [
                $kolom_referensi === 'id_transaksi' ? $id_transaksi : $no_struk,
                $id_produk,
                $harga,
                $qty,
                $subtotal
            ];
            if (in_array('nama_produk', $kolom_detail, true)) {
                $kolom_insert_detail[] = 'nama_produk';
                $nilai_insert_detail[] = $item['nama_produk'];
            }

            $str_kolom_detail = implode(", ", $kolom_insert_detail);
            $str_tanda_detail = implode(", ", array_fill(0, count($kolom_insert_detail), "?"));
            $stmt_detail = $pdo->prepare("INSERT INTO detail_transaksi ($str_kolom_detail) VALUES ($str_tanda_detail)");
            $stmt_detail->execute($nilai_insert_detail);

            $stmt_stok = $pdo->prepare("UPDATE produk SET stok = stok - ? WHERE $primary_key_produk = ?");
            $stmt_stok->execute([$qty, $id_produk]);
        }

        $pdo->commit();
        unset($_SESSION['keranjang']);

        // Redirect ke halaman sukses dengan jalur folder yang benar
        header("Location: ../pages/transaksi_sukses.php?struk=" . $no_struk);
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['pesan_error_transaksi'] = "Transaksi gagal disimpan. Silakan coba kembali.";
        header("Location: ../pages/kasir.php");
        exit();
    }
} else {
    header("Location: ../pages/kasir.php");
    exit();
}