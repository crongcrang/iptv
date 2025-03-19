<?php
function getStreamUrl($channel) {
    $url = "https://www.inwiptv.com/player_demo.php?channel=" . urlencode($channel);

    // ใช้ cURL ดึง HTML ของหน้าเว็บ
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64)");
    $html = curl_exec($ch);
    curl_close($ch);

    // ค้นหา URL ที่ลงท้ายด้วย .m3u8
    preg_match("/'file':\s*'(https:\/\/.*?\.m3u8.*?)'/", $html, $matches);

    return !empty($matches[1]) ? $matches[1] : null;
}

// รับค่า channel จาก GET parameter
$channel = isset($_GET['channel']) ? $_GET['channel'] : '95262';

// ดึง URL ของ Stream
$stream_url = getStreamUrl($channel);

if ($stream_url) {
    // ส่ง header เพื่อ Redirect ไปยังไฟล์ .m3u8
    header("Location: $stream_url");
    exit();
} else {
    echo "ไม่พบ URL ของ Stream สำหรับ channel $channel";
}
?>
