<?php
error_log("Script started");

function fetchContent($url, $isTs = false) {
    $cookieFile = 'cookies.txt';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // เพิ่ม timeout เป็น 30 วินาที
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    $verboseLog = fopen('curl_verbose.log', 'a');
    curl_setopt($ch, CURLOPT_STDERR, $verboseLog);

    $headers = [
        'Referer: https://dookeela.live/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
        'Accept: */*',
        'Accept-Language: en-US,en;q=0.5',
        'Connection: keep-alive'
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_ENCODING, '');

    $startTime = microtime(true);
    $response = curl_exec($ch);
    $endTime = microtime(true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $totalTime = $endTime - $startTime;

    error_log("Requested URL: $url, Effective URL: $effectiveUrl, HTTP Code: $httpCode, Time: {$totalTime}s, Body Length: " . strlen($response));

    if ($response === false || $httpCode !== 200) {
        $error = curl_error($ch);
        curl_close($ch);
        fclose($verboseLog);
        if ($isTs) {
            header('HTTP/1.1 500 Internal Server Error');
            echo "Failed to load .ts: $error (HTTP $httpCode)";
            exit;
        } else {
            http_response_code(500);
            echo "#EXTM3U\n#EXT-X-ERROR: Failed to load content: $error (HTTP $httpCode)";
            exit;
        }
    }

    curl_close($ch);
    fclose($verboseLog);

    if ($isTs) {
        header('Content-Type: video/mp2t');
        header('Content-Length: ' . strlen($response));
        header('Content-Disposition: inline; filename="segment.ts"');
        echo $response;
        exit;
    }

    return $response;
}

// ดึง .ts segment
if (isset($_GET['ts'])) {
    header('Content-Type: video/mp2t');
    $tsUrl = base64_decode($_GET['ts']);
    error_log("Fetching .ts segment: $tsUrl");
    fetchContent($tsUrl, true);
}

// ดึง playlist
if (isset($_GET['url'])) {
    header('Content-Type: application/vnd.apple.mpegurl');
    $channelUrl = $_GET['url'];
    error_log("Fetching initial page: $channelUrl");
    $htmlContent = fetchContent($channelUrl);

    // ปรับ regex ให้ยืดหยุ่นและครอบคลุม
    $m3u8Pattern = '/https:\/\/streamamg-fa\.akamaiz\.com\/dookeela\/[^\/\s"\'?]+\/[^\s"\'?]+\.m3u8(?:\?[^"\']*wmsAuthSign=[^"\']+)?/';
    preg_match($m3u8Pattern, $htmlContent, $matches);

    if (empty($matches)) {
        $m3u8PatternFallback = '/https?:\/\/[^\s"\'?]+\.m3u8(?:\?[^"\']*)?/';
        preg_match($m3u8PatternFallback, $htmlContent, $matches);
        if (empty($matches)) {
            http_response_code(500);
            echo "#EXTM3U\n#EXT-X-ERROR: No valid .m3u8 URL found\n#Body: " . substr($htmlContent, 0, 3000);
            exit;
        }
    }

    $m3u8Url = $matches[0];
    error_log("Fetching master playlist: $m3u8Url");
    $masterM3u8Content = fetchContent($m3u8Url);

    if (strpos($masterM3u8Content, '#EXTM3U') === false) {
        http_response_code(500);
        echo "#EXTM3U\n#EXT-X-ERROR: Invalid master .m3u8 content\n#m3u8Content: " . substr($masterM3u8Content, 0, 3000);
        exit;
    }

    // เลือก chunk playlist โดยตรวจสอบ #EXT-X-STREAM-INF
    $baseUrl = dirname($m3u8Url) . '/';
    $masterLines = explode("\n", $masterM3u8Content);
    $chunkUrl = '';
    for ($i = 0; $i < count($masterLines) - 1; $i++) {
        if (str_starts_with($masterLines[$i], '#EXT-X-STREAM-INF')) {
            $nextLine = trim($masterLines[$i + 1]);
            if (!empty($nextLine) && !str_starts_with($nextLine, '#')) {
                $chunkUrl = filter_var($nextLine, FILTER_VALIDATE_URL) ? $nextLine : $baseUrl . $nextLine;
                break;
            }
        }
    }

    if (empty($chunkUrl)) {
        // ถ้าไม่เจอ chunk ลองใช้ master URL โดยตรง (บาง stream ไม่มี chunk playlist)
        $chunkUrl = $m3u8Url;
        error_log("No chunk URL found, falling back to master: $chunkUrl");
    } else {
        error_log("Fetching chunk playlist: $chunkUrl");
        $chunkM3u8Content = fetchContent($chunkUrl);
        if (strpos($chunkM3u8Content, '#EXTM3U') === false) {
            http_response_code(500);
            echo "#EXTM3U\n#EXT-X-ERROR: Invalid chunk .m3u8 content\n#ChunkContent: " . substr($chunkM3u8Content, 0, 3000);
            exit;
        }
    }

    // ใช้ chunk หรือ master playlist ขึ้นอยู่กับสถานการณ์
    $finalM3u8Content = empty($chunkUrl) || $chunkUrl === $m3u8Url ? $masterM3u8Content : $chunkM3u8Content;
    $chunkBaseUrl = dirname($chunkUrl) . '/';
    $chunkLines = explode("\n", $finalM3u8Content);
    $fixedChunkContent = '';

    foreach ($chunkLines as $line) {
        $line = trim($line);
        if (!empty($line)) {
            // คงค่า #EXT-X-KEY ไว้ถ้ามี
            if (str_starts_with($line, '#EXT-X-KEY')) {
                $fixedChunkContent .= $line . "\n";
                continue;
            }
            // แปลง segment URL เป็น proxy URL
            if (!str_starts_with($line, '#') && !filter_var($line, FILTER_VALIDATE_URL)) {
                $line = $chunkBaseUrl . $line; // แปลง relative URL เป็น absolute URL
            }
            if (!str_starts_with($line, '#') && filter_var($line, FILTER_VALIDATE_URL) && strpos($line, '.ts') !== false) {
                $line = "get_m3u8.php?ts=" . base64_encode($line); // Proxy .ts segment
            }
            $fixedChunkContent .= $line . "\n";
        }
    }

    // ตรวจสอบว่า .m3u8 มี segment และ key (ถ้าจำเป็น)
    if (strpos($fixedChunkContent, '.ts') !== false && strpos($fixedChunkContent, '#EXT-X-KEY') === false) {
        error_log("Warning: No #EXT-X-KEY found in encrypted stream, playback may fail");
    }

    error_log("Sending fixed chunk playlist:\n$fixedChunkContent");
    echo $fixedChunkContent;
} else {
    header('Content-Type: application/vnd.apple.mpegurl');
    http_response_code(400);
    echo "#EXTM3U\n#EXT-X-ERROR: No URL provided";
}

error_log("Script ended");
?>