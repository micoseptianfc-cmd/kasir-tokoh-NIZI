<?php
// pages/owner/laporan.php
session_start();

if (!isset($_SESSION['owner_logged_in'])) {
    header("Location: ../login_owner.php");
    exit();
}

require_once '../../config/koneksi.php';

$pesan_error = "";

$nama_bulan = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

// 1. PROSES BERSIHKAN / HAPUS DATA LAMA
if (isset($_POST['proses_arsip'])) {
    $tgl_mulai_arsip = $_POST['tgl_mulai_arsip'] ?? '';
    $tgl_selesai_arsip = $_POST['tgl_selesai_arsip'] ?? '';

    $tanggal_valid = static function ($tanggal) {
        if (!is_string($tanggal)) {
            return false;
        }
        $bagian = explode('-', $tanggal);
        return count($bagian) === 3 && checkdate((int)$bagian[1], (int)$bagian[2], (int)$bagian[0]);
    };

    if ($tanggal_valid($tgl_mulai_arsip) && $tanggal_valid($tgl_selesai_arsip) && $tgl_mulai_arsip <= $tgl_selesai_arsip) {
        $tanggal_arsip = "COALESCE(DATE(NULLIF(t.tanggal_waktu, '0000-00-00 00:00:00')), STR_TO_DATE(SUBSTRING(t.no_struk, 7, 8), '%Y%m%d'))";

        try {
            $pdo->beginTransaction();

            $stmt_hapus_detail = $pdo->prepare(
                "DELETE d FROM detail_transaksi d
                 INNER JOIN transaksi t ON t.id_transaksi = d.id_transaksi
                 WHERE $tanggal_arsip BETWEEN ? AND ?"
            );
            $stmt_hapus_detail->execute([$tgl_mulai_arsip, $tgl_selesai_arsip]);

            $stmt_hapus = $pdo->prepare("DELETE t FROM transaksi t WHERE $tanggal_arsip BETWEEN ? AND ?");
            $stmt_hapus->execute([$tgl_mulai_arsip, $tgl_selesai_arsip]);
            $jumlah_dihapus = $stmt_hapus->rowCount();

            $pdo->commit();

            if ($jumlah_dihapus > 0) {
                $pesan_error = "Data transaksi periode " . htmlspecialchars($tgl_mulai_arsip) . " s/d " . htmlspecialchars($tgl_selesai_arsip) . " berhasil dibersihkan (" . $jumlah_dihapus . " transaksi).";
            } else {
                $pesan_error = "Tidak ada data transaksi pada rentang tanggal tersebut untuk diarsipkan.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pesan_error = "Gagal memproses arsip: " . $e->getMessage();
        }
    } else {
        $pesan_error = "Pilih rentang tanggal yang valid. Tanggal mulai tidak boleh melewati tanggal selesai.";
    }
}

// 2. AMBIL DATA LANGSUNG DARI TABEL TRANSAKSI
$tanggal_mulai = isset($_GET['tanggal_mulai']) ? $_GET['tanggal_mulai'] : '';
$tanggal_selesai = isset($_GET['tanggal_selesai']) ? $_GET['tanggal_selesai'] : '';

$tanggal_transaksi = "COALESCE(DATE(NULLIF(t.tanggal_waktu, '0000-00-00 00:00:00')), STR_TO_DATE(SUBSTRING(t.no_struk, 7, 8), '%Y%m%d'))";
$tanggal_waktu_tampil = "CASE
    WHEN NULLIF(t.tanggal_waktu, '0000-00-00 00:00:00') IS NOT NULL
    THEN DATE_FORMAT(t.tanggal_waktu, '%Y-%m-%d %H:%i:%s')
    ELSE CONCAT(DATE_FORMAT(STR_TO_DATE(SUBSTRING(t.no_struk, 7, 8), '%Y%m%d'), '%Y-%m-%d'), ' (waktu tidak tersimpan)')
END";
$daftar_laporan = [];
try {
    $sql = "SELECT t.id_transaksi, t.no_struk, $tanggal_waktu_tampil AS tanggal_waktu,
                   $tanggal_transaksi AS tanggal_grafik, t.total_belanja,
                   d.id_detail, d.qty AS jumlah, d.subtotal, p.nama_produk
            FROM transaksi t
            LEFT JOIN detail_transaksi d ON d.id_transaksi = t.id_transaksi
            LEFT JOIN produk p ON p.id_produk = d.id_produk
            WHERE 1=1";
    $params = [];
    if (!empty($tanggal_mulai) && !empty($tanggal_selesai)) {
        $sql .= " AND $tanggal_transaksi BETWEEN ? AND ?";
        $params[] = $tanggal_mulai;
        $params[] = $tanggal_selesai;
    }
    $sql .= " ORDER BY $tanggal_transaksi DESC, t.id_transaksi DESC, d.id_detail ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
        $daftar_laporan[] = [
            'no_struk' => $r['no_struk'],
            'tanggal_waktu' => $r['tanggal_waktu'],
            'tanggal_grafik' => $r['tanggal_grafik'],
            'nama_produk' => !empty($r['id_detail']) ? ($r['nama_produk'] ?? 'Produk tidak tersedia') : 'Rincian produk tidak tersedia',
            'jumlah' => !empty($r['id_detail']) ? $r['jumlah'] : '-',
            'subtotal' => $r['subtotal'] ?? $r['total_belanja'],
            'total_belanja' => $r['total_belanja']
        ];
    }
} catch (Exception $e) {
    $daftar_laporan = [];
}

$total_omzet_keseluruhan = 0;
$grafik_data = [];
$rekap_bulanan = [];
$rekap_tahunan = [];
$transaksi_terhitung = [];

foreach ($daftar_laporan as $t) {
    if (isset($transaksi_terhitung[$t['no_struk']])) {
        continue;
    }
    $transaksi_terhitung[$t['no_struk']] = true;

    $sub = (float)$t['total_belanja'];
    $total_omzet_keseluruhan += $sub;
    
    if(isset($t['tanggal_grafik'])) {
        $tgl = date('Y-m-d', strtotime($t['tanggal_grafik']));
        $grafik_data[$tgl] = ($grafik_data[$tgl] ?? 0) + $sub;

        $thn_bln = date('Y-m', strtotime($t['tanggal_grafik']));
        $rekap_bulanan[$thn_bln] = ($rekap_bulanan[$thn_bln] ?? 0) + $sub;

        $thn = date('Y', strtotime($t['tanggal_grafik']));
        $rekap_tahunan[$thn] = ($rekap_tahunan[$thn] ?? 0) + $sub;
    }
}

$labels_json = json_encode(array_keys($grafik_data));
$data_json = json_encode(array_values($grafik_data));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Laporan & Omzet Bulanan - Panel Pemilik</title>
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .menu-list a.active { background: #e74c3c; border-color: #c0392b; box-shadow: 0 5px 0 #962d22; color: white; font-weight: bold; }

        .btn-keluar { background: #2c3e50 !important; border-color: #3a5068 !important; box-shadow: 0 5px 0 #151e27 !important; color: #e74c3c !important; }
        .owner-section { border-top: 1px solid #34495e; padding-top: 20px; }
        
        .main-content { flex: 1; display: flex; flex-direction: column; padding: 20px 30px; overflow-y: auto; }
        
        .header-kasir { display: flex; justify-content: space-between; align-items: center; background: white; padding: 15px 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 20px; }
        .header-title h1 { font-size: 24px; color: #2c3e50; font-weight: 700; margin-bottom: 5px; }
        .header-title p { font-size: 13px; color: #7f8c8d; }

        .filter-box { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 20px; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        .filter-group label { font-size: 13px; font-weight: bold; color: #34495e; }
        .filter-group input { padding: 8px 12px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        
        .btn-filter { background: #2980b9; color: white; border: none; padding: 9px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; height: 38px; }
        .btn-filter:hover { background: #2471a3; }
        .btn-reset { background: #95a5a6; color: white; text-decoration: none; padding: 9px 15px; border-radius: 6px; font-weight: bold; font-size: 14px; display: inline-flex; align-items: center; height: 38px; }
        .btn-reset:hover { background: #7f8c8d; }

        .summary-card-top { background: #27ae60; color: white; padding: 12px 20px; border-radius: 8px; margin-left: auto; display: flex; flex-direction: column; align-items: flex-end; }
        .summary-card-top span { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9; }
        .summary-card-top h3 { font-size: 18px; font-weight: bold; margin-top: 2px; }

        .archive-box { background: #fff5f5; border: 1px solid #f5c6cb; padding: 20px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .archive-info h4 { color: #c0392b; font-size: 16px; margin-bottom: 5px; }
        .archive-info p { color: #7f8c8d; font-size: 13px; }

        .dashboard-grid { display: flex; flex-direction: column; gap: 20px; flex: 1; padding-bottom: 20px; }
        .top-grid { display: grid; grid-template-columns: 350px 1fr; gap: 20px; }
        
        .monthly-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); max-height: 280px; display: flex; flex-direction: column; }
        .monthly-card h3 { font-size: 16px; color: #2c3e50; margin-bottom: 12px; border-bottom: 2px solid #ecf0f1; padding-bottom: 8px; }
        .monthly-list { flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; }
        .monthly-item { display: flex; justify-content: space-between; align-items: center; background: #f8f9fa; padding: 10px 14px; border-radius: 6px; border-left: 4px solid #2980b9; }
        .monthly-month { font-size: 13px; font-weight: 600; color: #34495e; }
        .monthly-total { font-size: 13px; font-weight: bold; color: #27ae60; }

        .chart-container { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); height: 280px; position: relative; }
        
        .panel { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); display: flex; flex-direction: column; min-height: 250px; }
        .panel h3 { margin-bottom: 15px; color: #2c3e50; font-size: 18px; border-bottom: 2px solid #ecf0f1; padding-bottom: 10px; font-weight: 600; }

        .table-container { flex: 1; overflow-y: auto; max-height: 280px; border: 1px solid #ecf0f1; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; }
        table th { background: #34495e; color: white; padding: 12px 15px; text-align: left; font-size: 14px; position: sticky; top: 0; }
        table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ecf0f1; font-size: 14px; font-weight: 500; color: #333; }
        table tr:nth-child(even) { background-color: #f8f9fa; }

        .alert-error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; border: 1px solid #f5c6cb; }

        .btn-print { background: #8e44ad; color: white; border: none; padding: 0 15px; border-radius: 6px; font-weight: bold; cursor: pointer; height: 34px; font-size: 13px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
        .btn-print:hover { background: #732d91; }

        @media print {
            body { background: white !important; display: block !important; height: auto !important; overflow: visible !important; padding: 15px !important; }
            .sidebar, .filter-box, .archive-box, .chart-container, .btn-print, .no-print { display: none !important; }
            .main-content { padding: 0 !important; overflow: visible !important; display: block !important; }
            .header-kasir { display: none !important; }
            .dashboard-grid { gap: 15px !important; }
            .top-grid { display: block !important; }
            .monthly-card { max-height: none !important; box-shadow: none !important; border: 1px solid #ccc !important; margin-bottom: 15px; }
            .panel { box-shadow: none !important; border: 1px solid #ccc !important; padding: 15px !important; }
            .table-container { max-height: none !important; overflow: visible !important; border: none !important; }
            table th { background: #2c3e50 !important; color: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table tr:nth-child(even) { background-color: #f9f9f9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>

    <div class="sidebar no-print">
        <div>
            <h2>Panel Pemilik</h2>
            <span class="subtitle">ADMINISTRATOR AREA</span>
            
            <span class="menu-label">LAPORAN & KEUANGAN</span>
            <ul class="menu-list">
                <li><a href="laporan.php" class="active">📊 Rekap Laporan</a></li>
                <li><a href="riwayat_penjualan.php">📋 Riwayat Penjualan</a></li>
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
        <div class="header-kasir no-print">
            <div class="header-title">
                <h1>Rekap Laporan & Omzet Bulanan</h1>
                <p>Analisis tren keuangan per bulan, grafik harian, dan manajemen pembersihan data.</p>
            </div>
        </div>

        <?php if(!empty($pesan_error)): ?>
            <div class="alert-error no-print"><?= $pesan_error ?></div>
        <?php endif; ?>

        <!-- Filter Tanggal -->
        <form method="GET" class="filter-box">
            <div class="filter-group">
                <label>Dari Tanggal:</label>
                <input type="date" name="tanggal_mulai" value="<?= htmlspecialchars($tanggal_mulai) ?>">
            </div>
            <div class="filter-group">
                <label>Sampai Tanggal:</label>
                <input type="date" name="tanggal_selesai" value="<?= htmlspecialchars($tanggal_selesai) ?>">
            </div>
            <button type="submit" class="btn-filter">🔍 Filter Data</button>
            <?php if(!empty($tanggal_mulai) || !empty($tanggal_selesai)): ?>
                <a href="laporan.php" class="btn-reset">Reset</a>
            <?php endif; ?>

            <div class="summary-card-top">
                <span>Total Omzet Terpilih</span>
                <h3>Rp <?= number_format($total_omzet_keseluruhan) ?></h3>
            </div>
        </form>

        <!-- Panel Arsip & Cetak PDF -->
        <div class="archive-box">
            <div class="archive-info">
                <h4>📦 Pembersihan Data Transaksi Lama & Cetak PDF</h4>
                <p>Hapus data transaksi masa lalu dari database setelah direkap, atau cetak laporan resmi langsung ke format PDF.</p>
            </div>
            <div style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                <form method="POST" style="display: flex; gap: 10px; align-items: flex-end;" onsubmit="return confirm('Yakin ingin MENGHAPUS permanen data transaksi pada rentang tanggal tersebut dari database?');">
                    <div class="filter-group">
                        <label style="font-size: 11px;">Mulai:</label>
                        <input type="date" name="tgl_mulai_arsip" required style="padding: 6px 10px;">
                    </div>
                    <div class="filter-group">
                        <label style="font-size: 11px;">Sampai:</label>
                        <input type="date" name="tgl_selesai_arsip" required style="padding: 6px 10px;">
                    </div>
                    <button type="submit" name="proses_arsip" style="background: #c0392b; color: white; border: none; padding: 8px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; height: 34px; font-size: 13px;">🗑️ Bersihkan Data</button>
                </form>

                <button type="button" onclick="window.print();" class="btn-print">🖨️ Cetak / Simpan ke PDF</button>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="top-grid">
                <!-- Ringkasan Omzet Bulanan -->
                <div class="monthly-card">
                    <h3>📅 Rekap Omzet Bulanan</h3>
                    <div class="monthly-list">
                        <?php if(empty($rekap_bulanan)): ?>
                            <p style="font-size: 13px; color: #95a5a6; text-align: center; padding: 20px; font-style: italic;">Belum ada data bulanan.</p>
                        <?php else: ?>
                            <?php foreach($rekap_bulanan as $thn_bln => $omzet_bln): 
                                list($tahun, $bulan) = explode('-', $thn_bln);
                                $nama_bln_indo = isset($nama_bulan[$bulan]) ? $nama_bulan[$bulan] . ' ' . $tahun : $thn_bln;
                            ?>
                            <div class="monthly-item">
                                <span class="monthly-month"><?= $nama_bln_indo ?></span>
                                <span class="monthly-total">Rp <?= number_format($omzet_bln) ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Grafik Omzet Harian -->
                <div class="chart-container">
                    <canvas id="grafikOmzet"></canvas>
                </div>
            </div>

            <!-- Tabel Transaksi & Detail Produk -->
            <div class="panel">
                <h3>Daftar Rincian Transaksi & Produk</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 40px;">No</th>
                                <th>No. Struk</th>
                                <th>Tanggal & Waktu</th>
                                <th>Nama Produk / Barang</th>
                                <th style="text-align: center; width: 60px;">Jml</th>
                                <th style="text-align: right;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($daftar_laporan)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: #95a5a6; padding: 30px; font-style: italic;">Tidak ada data transaksi pada periode tanggal tersebut.</td>
                                </tr>
                            <?php else: ?>
                                <?php $no=1; foreach($daftar_laporan as $t): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td style="font-weight: bold; color: #2980b9;"><?= htmlspecialchars($t['no_struk']) ?></td>
                                    <td><?= htmlspecialchars($t['tanggal_waktu']) ?></td>
                                    <td><?= htmlspecialchars($t['nama_produk']) ?></td>
                                    <td style="text-align: center;"><?= htmlspecialchars($t['jumlah']) ?></td>
                                    <td style="text-align: right; font-weight: bold; color: #e74c3c;">Rp <?= number_format($t['subtotal']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Script Chart.js -->
    <script>
        const ctx = document.getElementById('grafikOmzet').getContext('2d');
        const labelsData = <?= $labels_json ?>;
        const valuesData = <?= $data_json ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labelsData,
                datasets: [{
                    label: 'Omzet Harian (Rp)',
                    data: valuesData,
                    backgroundColor: 'rgba(41, 128, 185, 0.1)',
                    borderColor: '#2980b9',
                    borderWidth: 3,
                    pointBackgroundColor: '#e74c3c',
                    pointRadius: 5,
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'Rp ' + value.toLocaleString('id-ID');
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>