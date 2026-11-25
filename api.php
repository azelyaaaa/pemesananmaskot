<?php
header('Content-Type: application/json');
require_once 'koneksi.php';

$action = $_GET['action'] ?? '';
$input  = json_decode(file_get_contents("php://input"), true);

// 1. AMBIL SEMUA DATA KOSTUM & JADWAL TERPESAN (ABAIKAN PESANAN YANG DITOLAK)
if ($action === 'get_kostum') {
    $kostumList = [];
    $resKostum = $conn->query("SELECT * FROM kostum ORDER BY id ASC");
    
    while ($row = $resKostum->fetch_assoc()) {
        $idKostum = (string)$row['id'];
        $jadwal = [];
        // Hanya mengambil jadwal dari pesanan yang belum ditolak admin
        $resJadwal = $conn->query("SELECT tanggal_pemakaian, nama_pemesan, kostum_ids FROM pesanan WHERE status_pesanan != 'Ditolak Admin'");
        while ($p = $resJadwal->fetch_assoc()) {
            $idsArray = explode(',', $p['kostum_ids']);
            if (in_array($idKostum, $idsArray)) {
                $jadwal[] = [
                    "tanggal" => $p['tanggal_pemakaian'],
                    "jam"     => "-",
                    "pemesan" => $p['nama_pemesan']
                ];
            }
        }
        $row['id'] = (int)$row['id'];
        $row['harga'] = (int)$row['harga'];
        $row['jadwalTerpesan'] = $jadwal;
        $kostumList[] = $row;
    }
    echo json_encode($kostumList);
    exit;
}

// 2. SIMPAN PESANAN BARU DARI KERANJANG (CEK BENTROK JADWAL AKTIF)
if ($action === 'simpan_pesanan' && $input) {
    $kode       = $conn->real_escape_string($input['idPesanan']);
    $nama       = $conn->real_escape_string($input['namaPemesan']);
    $alamat     = $conn->real_escape_string($input['alamatPemesan']);
    $telp       = $conn->real_escape_string($input['noTelp']);
    $tglPakai   = $conn->real_escape_string($input['tanggalPemakaian']);
    $tglAmbil   = $conn->real_escape_string($input['tanggalPengambilan']);
    $kostumIds  = $conn->real_escape_string($input['kostumIds']);
    $daftarNama = $conn->real_escape_string($input['daftarKostum']);
    $total      = (int)$input['totalHarga'];

    $reqIds = explode(',', $kostumIds);
    $bentrokNama = [];
    $cekSql = $conn->query("SELECT kostum_ids FROM pesanan WHERE tanggal_pemakaian = '$tglPakai' AND status_pesanan != 'Ditolak Admin'");
    while ($row = $cekSql->fetch_assoc()) {
        $bookedIds = explode(',', $row['kostum_ids']);
        foreach ($reqIds as $rId) {
            if (in_array($rId, $bookedIds)) {
                $kRes = $conn->query("SELECT nama FROM kostum WHERE id = " . (int)$rId);
                if ($kData = $kRes->fetch_assoc()) {
                    $bentrokNama[] = $kData['nama'];
                }
            }
        }
    }

    if (count($bentrokNama) > 0) {
        echo json_encode([
            "status" => "conflict",
            "message" => implode(', ', array_unique($bentrokNama)) . " sudah dipesan customer lain pada tanggal $tglPakai."
        ]);
        exit;
    }

    $sql = "INSERT INTO pesanan (kode_pesanan, nama_pemesan, alamat_pemesan, no_telp, tanggal_pemakaian, tanggal_pengambilan, kostum_ids, daftar_kostum, total_harga)
            VALUES ('$kode', '$nama', '$alamat', '$telp', '$tglPakai', '$tglAmbil', '$kostumIds', '$daftarNama', $total)";
    
    if ($conn->query($sql)) {
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error", "message" => $conn->error]);
    }
    exit;
}

// 3. AMBIL RIWAYAT & DATA PEMESAN DARI DATABASE (TERMASUK ID KOSTUM & ALASAN PENOLAKAN)
if ($action === 'get_pesanan') {
    $list = [];
    $res = $conn->query("SELECT * FROM pesanan ORDER BY id DESC");
    while ($row = $res->fetch_assoc()) {
        $list[] = [
            "idPesanan"          => $row['kode_pesanan'],
            "namaPemesan"        => $row['nama_pemesan'],
            "alamatPemesan"      => $row['alamat_pemesan'],
            "noHp"               => $row['no_telp'],
            "tanggal"            => $row['tanggal_pemakaian'],
            "tanggalPengambilan" => $row['tanggal_pengambilan'],
            "kostumIds"          => $row['kostum_ids'],
            "daftarKostum"       => $row['daftar_kostum'],
            "total"              => (int)$row['total_harga'],
            "status"             => $row['status_pesanan'],
            "catatanAdmin"       => $row['catatan_admin'],
            "alasanPenolakan"    => $row['alasan_penolakan'] ?? ''
        ];
    }
    echo json_encode($list);
    exit;
}

// 4. LOGIN ADMIN DARI DATABASE
if ($action === 'login_admin' && $input) {
    $user = $conn->real_escape_string($input['username']);
    $pass = $conn->real_escape_string($input['password']);
    $res  = $conn->query("SELECT * FROM admin WHERE username = '$user' AND password = '$pass'");
    if ($res->num_rows > 0) {
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error"]);
    }
    exit;
}

// 5A. KONFIRMASI PESANAN OLEH ADMIN
if ($action === 'konfirmasi_pesanan' && $input) {
    $kode = $conn->real_escape_string($input['idPesanan']);
    $conn->query("UPDATE pesanan SET status_pesanan = 'Sudah Dikonfirmasi Admin', alasan_penolakan = '' WHERE kode_pesanan = '$kode'");
    echo json_encode(["status" => "success"]);
    exit;
}

// 5B. TOLAK PESANAN & SIMPAN ALASAN PENOLAKAN OLEH ADMIN
if ($action === 'tolak_pesanan' && $input) {
    $kode   = $conn->real_escape_string($input['idPesanan']);
    $alasan = $conn->real_escape_string($input['alasan']);
    $conn->query("UPDATE pesanan SET status_pesanan = 'Ditolak Admin', alasan_penolakan = '$alasan' WHERE kode_pesanan = '$kode'");
    echo json_encode(["status" => "success"]);
    exit;
}

// 6. SIMPAN CATATAN PEMESAN OLEH ADMIN
if ($action === 'simpan_catatan' && $input) {
    $kode    = $conn->real_escape_string($input['idPesanan']);
    $catatan = $conn->real_escape_string($input['catatan']);
    $conn->query("UPDATE pesanan SET catatan_admin = '$catatan' WHERE kode_pesanan = '$kode'");
    echo json_encode(["status" => "success"]);
    exit;
}

// 7A. UPDATE HARGA KOSTUM OLEH ADMIN
if ($action === 'update_harga' && $input) {
    $id    = (int)$input['id'];
    $harga = (int)$input['harga'];
    $conn->query("UPDATE kostum SET harga = $harga WHERE id = $id");
    echo json_encode(["status" => "success"]);
    exit;
}

// 7B. UPDATE NAMA & CATATAN KELENGKAPAN KOSTUM OLEH ADMIN
if ($action === 'update_info_kostum' && $input) {
    $id          = (int)$input['id'];
    $nama        = $conn->real_escape_string($input['nama']);
    $kelengkapan = $conn->real_escape_string($input['kelengkapan']);
    $conn->query("UPDATE kostum SET nama = '$nama', kelengkapan = '$kelengkapan' WHERE id = $id");
    echo json_encode(["status" => "success"]);
    exit;
}

// 8. TAMBAH KOSTUM BARU OLEH ADMIN (SIMPAN FILE GAMBAR FISIK KE FOLDER img/)
if ($action === 'tambah_kostum' && $input) {
    $nama        = $conn->real_escape_string($input['nama']);
    $harga       = (int)$input['harga'];
    $kelengkapan = $conn->real_escape_string($input['kelengkapan']);
    $gambarData  = $input['gambar'];

    $pathGambarDB = $gambarData;

    if (strpos($gambarData, 'data:image/') === 0) {
        if (!is_dir('img')) {
            mkdir('img', 0777, true);
        }

        preg_match('/^data:image\/(\w+);base64,/', $gambarData, $type);
        $ext = strtolower($type[1] ?? 'jpg');
        if ($ext === 'jpeg') $ext = 'jpg';

        $base64Str = substr($gambarData, strpos($gambarData, ',') + 1);
        $fileData  = base64_decode($base64Str);

        $namaFileBaru = 'img/kostum_' . time() . '.' . $ext;
        file_put_contents($namaFileBaru, $fileData);

        $pathGambarDB = $conn->real_escape_string($namaFileBaru);
    }

    $conn->query("INSERT INTO kostum (nama, harga, gambar, kelengkapan) VALUES ('$nama', $harga, '$pathGambarDB', '$kelengkapan')");
    echo json_encode(["status" => "success", "gambar" => $pathGambarDB]);
    exit;
}
// 9. HAPUS KOSTUM DARI KATALOG & DATABASE OLEH ADMIN
if ($action === 'hapus_kostum' && $input) {
    $id = (int)$input['id'];

    // Ambil path gambar untuk menghapus file fisik di folder img/ (jika bukan bawaan)
    $res = $conn->query("SELECT gambar FROM kostum WHERE id = $id");
    if ($row = $res->fetch_assoc()) {
        $fileGambar = $row['gambar'];
        if (strpos($fileGambar, 'img/kostum_') === 0 && file_exists($fileGambar)) {
            @unlink($fileGambar);
        }
    }

    if ($conn->query("DELETE FROM kostum WHERE id = $id")) {
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error", "message" => $conn->error]);
    }
    exit;
}
?>