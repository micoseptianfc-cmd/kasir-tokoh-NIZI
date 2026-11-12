<?php
// pages/owner/riwayat_penjualan.php
session_start();

// Cek apakah owner sudah login
if (!isset($_SESSION['owner_logged_in'])) {
    header("Location: ../login_owner.php");
    exit();
}

require_once '../../config/koneksi.php';

if (empty($_SESSION['csrf_token_riwayat_penjualan'])) {
    $_SESSION['csrf_token_riwayat_penjualan'] = bin2hex(random_bytes(32));
}

$pesan_aksi = $_SESSION['pesan_aksi_riwayat_penjualan'] ?? '';
unset($_SESSION['pesan_aksi_riwayat_penjualan']);

$tanggal_valid = static function ($tanggal) {
    if (!is_string($tanggal) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return false;
    }

    [$tahun, $bulan, $hari] = array_map('intval', explode('-', $tanggal));
    return checkdate($bulan, $hari, $tahun);
};

$redirect_ke_riwayat = static function ($tanggal_mulai, $tanggal_selesai) {
    $parameter = [];
    if ($tanggal_mulai !== '') {
        $parameter['tanggal_mulai'] = $tanggal_mulai;
    }
    if ($tanggal_selesai !== '') {
        $parameter['tanggal_selesai'] = $tanggal_selesai;
    }
    header('Location: riwayat_penjualan.php' . ($parameter ? '?' . http_build_query($parameter) : ''));
    exit();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $tanggal_mulai_hapus = $_POST['tanggal_mulai'] ?? '';
    $tanggal_selesai_hapus = $_POST['tanggal_selesai'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token_riwayat_penjualan'], $token)) {
        $_SESSION['pesan_aksi_riwayat_penjualan'] = 'Permintaan tidak valid. Muat ulang halaman lalu coba kembali.';
        $redirect_ke_riwayat('', '');
    }

    if (
        !is_string($tanggal_mulai_hapus) ||
        !is_string($tanggal_selesai_hapus) ||
        ($tanggal_mulai_hapus !== '' && !$tanggal_valid($tanggal_mulai_hapus)) ||
        ($tanggal_selesai_hapus !== '' && !$tanggal_valid($tanggal_selesai_hapus)) ||
        ($tanggal_mulai_hapus !== '' && $tanggal_selesai_hapus !== '' && $tanggal_mulai_hapus > $tanggal_selesai_hapus)
    ) {
        $_SESSION['pesan_aksi_riwayat_penjualan'] = 'Rentang tanggal tidak valid. Periksa tanggal mulai dan tanggal selesai.';
        $redirect_ke_riwayat('', '');
    }

    $tanggal_transaksi_hapus = "COALESCE(DATE(NULLIF(t.tanggal_waktu, '0000-00-00 00:00:00')), STR_TO_DATE(SUBSTRING(t.no_struk, 7, 8), '%Y%m%d'))";
    $kondisi_hapus = [];
    $parameter_hapus = [];
    if ($tanggal_mulai_hapus !== '') {
        $kondisi_hapus[] = "$tanggal_transaksi_hapus >= ?";
        $parameter_hapus[] = $tanggal_mulai_hapus;
    }
    if ($tanggal_selesai_hapus !== '') {
        $kondisi_hapus[] = "$tanggal_transaksi_hapus <= ?";
        $parameter_hapus[] = $tanggal_selesai_hapus;
    }
    $kondisi_hapus_sql = $kondisi_hapus ? ' WHERE ' . implode(' AND ', $kondisi_hapus) : '';

    try {
        $pdo->beginTransaction();

        if (isset($_POST['hapus_transaksi'])) {
            $id_transaksi = filter_var($_POST['id_transaksi'] ?? null, FILTER_VALIDATE_INT);
            if (!$id_transaksi || $id_transaksi < 1) {
                throw new RuntimeException('ID transaksi tidak valid.');
            }

            $stmt_cari_transaksi = $pdo->prepare(
                "SELECT t.id_transaksi FROM transaksi t WHERE t.id_transaksi = ?$kondisi_hapus_sql FOR UPDATE"
            );
            $stmt_cari_transaksi->execute(array_merge([$id_transaksi], $parameter_hapus));
            if (!$stmt_cari_transaksi->fetchColumn()) {
                throw new RuntimeException('Transaksi tidak ditemukan pada rentang tanggal tersebut.');
            }

            $stmt_detail_hapus = $pdo->prepare(
                'DELETE FROM detail_transaksi WHERE id_transaksi = ?'
            );
            $stmt_detail_hapus->execute([$id_transaksi]);

            $stmt_transaksi_hapus = $pdo->prepare(
                'DELETE FROM transaksi WHERE id_transaksi = ?'
            );
            $stmt_transaksi_hapus->execute([$id_transaksi]);
            if ($stmt_transaksi_hapus->rowCount() !== 1) {
                throw new RuntimeException('Transaksi tidak ditemukan atau sudah dihapus.');
            }

            $pdo->commit();
            $_SESSION['pesan_aksi_riwayat_penjualan'] = 'Transaksi dan rincian produknya berhasil dihapus.';
        } elseif (isset($_POST['bersihkan_riwayat'])) {
            $kondisi_detail = $kondisi_hapus ? ' WHERE ' . implode(' AND ', $kondisi_hapus) : '';

            if ($kondisi_hapus) {
                $sql_hapus_detail = "DELETE d FROM detail_transaksi d
                    INNER JOIN transaksi t ON t.id_transaksi = d.id_transaksi$kondisi_detail";
                $stmt_detail_hapus = $pdo->prepare($sql_hapus_detail);
                $stmt_detail_hapus->execute($parameter_hapus);
            } else {
                $pdo->exec('DELETE FROM detail_transaksi');
            }

            $sql_hapus_transaksi = "DELETE t FROM transaksi t$kondisi_hapus_sql";
            $stmt_transaksi_hapus = $pdo->prepare($sql_hapus_transaksi);
            $stmt_transaksi_hapus->execute($parameter_hapus);
            $jumlah_dihapus = $stmt_transaksi_hapus->rowCount();

            $pdo->commit();
            $_SESSION['pesan_aksi_riwayat_penjualan'] = $jumlah_dihapus > 0
                ? "Riwayat berhasil dibersihkan ($jumlah_dihapus transaksi dihapus)."
                : 'Tidak ada transaksi pada rentang tanggal tersebut untuk dibersihkan.';
        } else {
            throw new RuntimeException('Aksi tidak dikenali.');
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Gagal menghapus riwayat penjualan: ' . $e->getMessage());
        $_SESSION['pesan_aksi_riwayat_penjualan'] = 'Gagal menghapus data riwayat. Tidak ada perubahan yang disimpan.';
    }

    $redirect_ke_riwayat($tanggal_mulai_hapus, $tanggal_selesai_hapus);
}

$nilai_tanggal_mulai = $_GET['tanggal_mulai'] ?? '';
$nilai_tanggal_selesai = $_GET['tanggal_selesai'] ?? '';
$tanggal_mulai = is_string($nilai_tanggal_mulai) ? $nilai_tanggal_mulai : '';
$tanggal_selesai = is_string($nilai_tanggal_selesai) ? $nilai_tanggal_selesai : '';
$pesan_filter = '';
$filter_valid = is_string($nilai_tanggal_mulai) && is_string($nilai_tanggal_selesai);

$filter_validasi_tanggal = ($tanggal_mulai === '' || $tanggal_valid($tanggal_mulai))
    && ($tanggal_selesai === '' || $tanggal_valid($tanggal_selesai));
if (!$filter_valid) {
    $pesan_filter = 'Format tanggal tidak valid. Silakan pilih tanggal yang benar.';
} elseif (!$filter_validasi_tanggal) {
    $pesan_filter = 'Format tanggal tidak valid. Silakan pilih tanggal yang benar.';
    $filter_valid = false;
} elseif ($tanggal_mulai !== '' && $tanggal_selesai !== '' && $tanggal_mulai > $tanggal_selesai) {
    $pesan_filter = 'Tanggal mulai tidak boleh melewati tanggal akhir.';
    $filter_valid = false;
}

if ($tanggal_mulai !== '' && !$tanggal_valid($tanggal_mulai)) {
    $tanggal_mulai = '';
}
if ($tanggal_selesai !== '' && !$tanggal_valid($tanggal_selesai)) {
    $tanggal_selesai = '';
}

// Gunakan tanggal pada nomor struk jika transaksi lama tidak memiliki waktu tersimpan.
$tanggal_tampil = "CASE
    WHEN NULLIF(t.tanggal_waktu, '0000-00-00 00:00:00') IS NOT NULL
    THEN DATE_FORMAT(t.tanggal_waktu, '%Y-%m-%d %H:%i:%s')
    ELSE CONCAT(DATE_FORMAT(STR_TO_DATE(SUBSTRING(t.no_struk, 7, 8), '%Y%m%d'), '%Y-%m-%d'), ' (waktu tidak tersimpan)')
END";
$tanggal_filter = "COALESCE(DATE(NULLIF(t.tanggal_waktu, '0000-00-00 00:00:00')), STR_TO_DATE(SUBSTRING(t.no_struk, 7, 8), '%Y%m%d'))";
$where = [];
$parameter = [];
if (!$filter_valid) {
    $where[] = '1 = 0';
} else {
    if ($tanggal_mulai !== '') {
        $where[] = "$tanggal_filter >= ?";
        $parameter[] = $tanggal_mulai;
    }
    if ($tanggal_selesai !== '') {
        $where[] = "$tanggal_filter <= ?";
        $parameter[] = $tanggal_selesai;
    }
}
$kondisi_tanggal = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT t.*, $tanggal_tampil AS tanggal_tampil,
                            COALESCE(GROUP_CONCAT(
                                CONCAT(COALESCE(p.nama_produk, 'Produk tidak tersedia'), ' (', d.qty, ')')
                                ORDER BY d.id_detail SEPARATOR ', '
                            ), 'Rincian produk tidak tersedia') AS daftar_produk
                     FROM transaksi t
                     LEFT JOIN detail_transaksi d ON d.id_transaksi = t.id_transaksi
                     LEFT JOIN produk p ON p.id_produk = d.id_produk
                     $kondisi_tanggal
                     GROUP BY t.id_transaksi
                     ORDER BY t.id_transaksi DESC");
$stmt->execute($parameter);
$daftar_transaksi = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Penjualan - Panel Pemilik</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #eef2f5; display: flex; height: 100vh; overflow: hidden; }
        
        .sidebar { width: 270px; background: #1e2b3c; color: white; display: flex; flex-direction: column; justify-content: space-between; padding: 25px 20px; box-shadow: 2px 0 10px rgba(0,0,0,0.1); z-index: 10; }
        .sidebar h2 { font-size: 28px; color: #fff; letter-spacing: 1px; margin-bottom: 2px; }
        .subtitle { font-size: 11px; color: #e74c3c; letter-spacing: 2px; margin-bottom: 20px; display: block; border-bottom: 1px solid #34495e; padding-bottom: 15px; }
        
        .menu-label { font-size: 11px; color: #7f8c8d; font-weight: bold; letter-spacing: 1px; margin-bottom: 12px; margin-top: 15px; display: block; }
        .menu-list { list-style: none; }
        .menu-list li { margin-bottom: 12px; }
        
        .menu-list a { 
            color: #ecf0f1; text-decoration: none; font-size: 15px; font-weight: 500;
            display: flex; align-items: center; padding: 14px 15px; border-radius: 8px; 
            background: #2c3e50; border: 1px solid #3a5068; box-shadow: 0 5px 0 #151e27; 
            transition: all 0.15s ease; 
        }
        .menu-list a:hover { background: #34495e; transform: translateY(-2px); box-shadow: 0 7px 0 #151e27; }
        .menu-list a:active { transform: translateY(5px); box-shadow: 0 0 0 #151e27; }
        
        .menu-list a.active { 
            background: #e74c3c; border-color: #c0392b; box-shadow: 0 5px 0 #962d22; color: white; font-weight: bold; 
        }

        .btn-keluar {
            background: #2c3e50 !important; border-color: #3a5068 !important; box-shadow: 0 5px 0 #151e27 !important; color: #e74c3c !important;
        }
        
        .owner-section { border-top: 1px solid #34495e; padding-top: 20px; }
        
        .main-content { flex: 1; display: flex; flex-direction: column; padding: 20px 30px; overflow-y: auto; }
        
        .header-kasir { display: flex; justify-content: space-between; align-items: center; background: white; padding: 15px 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 20px; }
        .header-title h1 { font-size: 24px; color: #2c3e50; font-weight: 700; margin-bottom: 5px; }
        .header-title p { font-size: 13px; color: #7f8c8d; }

        .panel { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); flex: 1; display: flex; flex-direction: column; }
        .panel h3 { margin-bottom: 20px; color: #2c3e50; font-size: 18px; border-bottom: 2px solid #ecf0f1; padding-bottom: 10px; font-weight: 600; }
        .filter-form { display: flex; align-items: flex-end; flex-wrap: wrap; gap: 12px; margin-bottom: 18px; }
        .filter-field { display: flex; flex-direction: column; gap: 5px; }
        .filter-field label { color: #34495e; font-size: 13px; font-weight: 600; }
        .filter-field input { padding: 9px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        .filter-button, .reset-button { display: inline-block; padding: 10px 15px; border: 0; border-radius: 6px; color: white; font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; }
        .filter-button { background: #2980b9; }
        .reset-button { background: #7f8c8d; }
        .filter-error { margin-bottom: 14px; padding: 10px 12px; border: 1px solid #f5c6cb; border-radius: 6px; background: #f8d7da; color: #721c24; font-size: 13px; }

        .table-container { flex: 1; overflow-y: auto; border: 1px solid #ecf0f1; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; }
        table th { background: #34495e; color: white; padding: 14px 15px; text-align: left; font-size: 14px; position: sticky; top: 0; }
        table td { padding: 14px 15px; text-align: left; border-bottom: 1px solid #ecf0f1; font-size: 15px; font-weight: 600; color: #333; }
        table tr:nth-child(even) { background-color: #f8f9fa; }
        table tr:hover { background-color: #f1f2f6; }
        .text-strong { font-weight: 800; }
        .text-amount { font-weight: 800; }
        .text-kembalian { color: #27ae60; font-weight: 800; }
        .action-message { margin-bottom: 14px; padding: 10px 12px; border: 1px solid #b8daff; border-radius: 6px; background: #d1ecf1; color: #0c5460; font-size: 13px; }
        .clear-history-form { display: flex; align-items: center; justify-content: space-between; gap: 15px; padding: 14px; margin-bottom: 18px; border: 1px solid #f5c6cb; border-radius: 8px; background: #fff5f5; color: #721c24; font-size: 13px; }
        .delete-button, .clear-history-button { border: 0; border-radius: 5px; color: white; font-weight: 600; cursor: pointer; }
        .delete-button { padding: 7px 10px; background: #e74c3c; }
        .clear-history-button { flex-shrink: 0; padding: 10px 14px; background: #c0392b; }
        @media (max-width: 700px) {
            body { height: auto; min-height: 100vh; overflow: auto; }
            .sidebar { width: 210px; padding: 20px 12px; }
            .main-content { padding: 15px; }
            .clear-history-form { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>

    <div class="sidebar">
        <div>
            <h2>Panel Pemilik</h2>
            <span class="subtitle">ADMINISTRATOR AREA</span>
            
            <span class="menu-label">LAPORAN & KEUANGAN</span>
            <ul class="menu-list">
                <li><a href="laporan.php">📊 Rekap Laporan</a></li>
                <li><a href="riwayat_penjualan.php" class="active">📋 Riwayat Penjualan</a></li>
                <li><a href="laporan_keuangan.php">💰 Laporan Keuangan</a></li>
            </ul>

            <span class="menu-label">MANAJEMEN TOKO</span>
            <ul class="menu-list">
                <li><a href="kelola_produk.php">📦 Kelola Produk</a></li>
                <li><a href="stok_masuk.php">📥 Stok Masuk</a></li>
                <li><a href="notes_pribadi.php">📝 Notes Pribadi</a></li>
            </ul>
        </div>
        
        <div class="owner-section">
            <ul class="menu-list">
                <li><a href="../kasir.php" style="background: #2980b9; border-color: #2471a3; box-shadow: 0 5px 0 #1b5275;">🖥️ Kembali ke Kasir</a></li>
                <li><a href="../../proses/proses_logout.php" class="btn-keluar">🚪 Logout / Keluar</a></li>
            </ul>
        </div>
    </div>

    <div class="main-content">
        <div class="header-kasir">
            <div class="header-title">
                <h1>Riwayat Penjualan</h1>
                <p>Data audit seluruh transaksi yang masuk ke sistem toko.</p>
            </div>
        </div>

        <div class="panel">
            <h3>Log Transaksi Berhasil</h3>
            <form method="GET" class="filter-form">
                <div class="filter-field">
                    <label for="tanggalMulai">Dari tanggal</label>
                    <input type="date" id="tanggalMulai" name="tanggal_mulai" value="<?= htmlspecialchars($tanggal_mulai) ?>">
                </div>
                <div class="filter-field">
                    <label for="tanggalSelesai">Sampai tanggal</label>
                    <input type="date" id="tanggalSelesai" name="tanggal_selesai" value="<?= htmlspecialchars($tanggal_selesai) ?>">
                </div>
                <button type="submit" class="filter-button">Tampilkan</button>
                <a href="riwayat_penjualan.php" class="reset-button">Reset</a>
            </form>
            <form method="POST" class="clear-history-form" onsubmit="return confirm('Data transaksi dan rincian produk akan dihapus permanen. Jika tanggal tidak dipilih, seluruh riwayat penjualan akan dihapus. Lanjutkan?');">
                <div>
                    <strong>Bersihkan riwayat penjualan</strong><br>
                    Menghapus transaksi beserta rincian produknya sesuai rentang tanggal di atas. Jika tanggal tidak dipilih, seluruh riwayat akan dihapus.
                </div>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token_riwayat_penjualan']) ?>">
                <input type="hidden" name="tanggal_mulai" value="<?= htmlspecialchars($tanggal_mulai) ?>">
                <input type="hidden" name="tanggal_selesai" value="<?= htmlspecialchars($tanggal_selesai) ?>">
                <button type="submit" name="bersihkan_riwayat" class="clear-history-button">🗑️ Bersihkan Riwayat</button>
            </form>
            <?php if ($pesan_aksi !== ''): ?>
                <div class="action-message" role="status"><?= htmlspecialchars($pesan_aksi) ?></div>
            <?php endif; ?>
            <?php if ($pesan_filter !== ''): ?>
                <div class="filter-error" role="alert"><?= htmlspecialchars($pesan_filter) ?></div>
            <?php endif; ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>No. Struk</th>
                            <th>Tanggal & Waktu</th>
                            <th>Nama Produk / Barang</th>
                            <th>Total Belanja</th>
                            <th>Tunai</th>
                            <th>Kembalian</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($daftar_transaksi)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: #95a5a6; padding: 40px; font-size: 16px; font-style: italic;"><?= $tanggal_mulai !== '' || $tanggal_selesai !== '' ? 'Tidak ada transaksi pada rentang tanggal tersebut.' : 'Belum ada riwayat transaksi yang tercatat.' ?></td>
                            </tr>
                        <?php else: ?>
                            <?php $no=1; foreach($daftar_transaksi as $t): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td class="text-strong" style="color: #2980b9;"><?= htmlspecialchars($t['no_struk']) ?></td>
                                <td><?= htmlspecialchars($t['tanggal_tampil']) ?></td>
                                <td><?= htmlspecialchars($t['daftar_produk']) ?></td>
                                <td class="text-amount" style="color: #e74c3c;">Rp <?= number_format($t['total_belanja']) ?></td>
                                <td class="text-amount">Rp <?= number_format($t['tunai']) ?></td>
                                <td class="text-kembalian">Rp <?= number_format($t['kembalian']) ?></td>
                                <td>
                                    <form method="POST" onsubmit="return confirm('Hapus transaksi <?= htmlspecialchars($t['no_struk'], ENT_QUOTES) ?> beserta seluruh rincian produknya secara permanen?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token_riwayat_penjualan']) ?>">
                                        <input type="hidden" name="id_transaksi" value="<?= (int)$t['id_transaksi'] ?>">
                                        <input type="hidden" name="tanggal_mulai" value="<?= htmlspecialchars($tanggal_mulai) ?>">
                                        <input type="hidden" name="tanggal_selesai" value="<?= htmlspecialchars($tanggal_selesai) ?>">
                                        <button type="submit" name="hapus_transaksi" class="delete-button">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>