<?php
// bridge.php - POS Printer Bridge
// 1. INCREASE MEMORY for processing long receipt images
ini_set('memory_limit', '256M'); 

require __DIR__ . '/vendor/autoload.php';

use Mike42\Escpos\PrintConnectors\CupsPrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\GdEscposImage;
use Mike42\Escpos\Printer;

// --- CONFIGURATION ---
$baseUrl = "http://127.0.0.1:8000/";
// $baseUrl = "https://admin.foodmonster.asia/";
// $baseUrl = "https://staging.foodmonster.asia/";
$bridgeConfig = [
    'user_id' => trim((string) (getenv('BRIDGE_USER_ID') ?: '')),
    'token' => trim((string) (getenv('BRIDGE_TOKEN') ?: '')),
    'bridge_id' => trim((string) (getenv('BRIDGE_ID') ?: '')) ?: ('bridge-' . substr(sha1((string) php_uname('n')), 0, 12)),
];
$apiUrl = applyBridgeAuthToUrl($baseUrl . "api/printer/sync", $bridgeConfig);

echo "--- BRIDGE (SCREENSHOT MODE) STARTED ---\n";
echo "Target API: $apiUrl\n\n";
echo "Bridge ID: {$bridgeConfig['bridge_id']}\n";
if ($bridgeConfig['user_id'] !== '') {
    echo "Bridge User ID: {$bridgeConfig['user_id']}\n";
}
if ($bridgeConfig['token'] !== '') {
    echo "Bridge Token: configured\n";
}
echo "\n";

assertRuntimeRequirements();

// --- SINGLE INSTANCE LOCK ---
$lockFile = __DIR__ . '/bridge.lock';
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[ERROR] Another bridge instance is already running.\n";
    exit(1);
}
ftruncate($lockHandle, 0);
fwrite($lockHandle, (string) getmypid());

// --- SSL BYPASS CONFIGURATION ---
// This allows the portable PHP to connect to HTTPS without certificate errors
$contextOptions = [
    'http' => [
        'timeout' => 30,
        'ignore_errors' => true
    ],
    "ssl" => [
        "verify_peer" => false,
        "verify_peer_name" => false,
    ],
];

// --- PRINT MODE ---
// 'first' = stop after first successful printer (avoids double print)
// 'all'   = print to every matching printer, ACK only if all succeed
$printMode = 'first';
$recentDuplicateWindowSeconds = 20;
$recentlyPrintedJobs = [];
// Split tall receipts into smaller raster blocks so ESC/POS printers do not choke on one huge image.
$maxRasterChunkHeight = 512;
$rasterChunkPauseMicros = 20000;

// --- MAIN LOOP ---
while (true) {
    try {
        $syncResponse = httpRequest('GET', $apiUrl, $contextOptions, buildBridgeHeaders($bridgeConfig));

        if (!$syncResponse['ok']) {
            // Echo a dot to show the script is running but waiting for connection
            echo ".";
        } else {
            $data = json_decode($syncResponse['body'], true);

            if (!is_array($data)) {
                echo "\n[SYNC ERROR] Invalid JSON from sync endpoint (HTTP {$syncResponse['status']}).\n";
                sleep(3);
                continue;
            }

            // Check if we found any orders/jobs
            if ($data && !empty($data['orders'])) {
                echo "\n[NEW JOB] Found " . count($data['orders']) . " orders!\n";
                
                foreach ($data['orders'] as $job) {
                    if (wasRecentlyPrinted($job, $recentlyPrintedJobs, $recentDuplicateWindowSeconds)) {
                        $sourceId = $job['source_id'] ?? $job['id'] ?? 'unknown';
                        echo "   [SKIP] Duplicate job detected for source $sourceId.\n";
                        ackJob($job, $baseUrl, $contextOptions, $bridgeConfig);
                        continue;
                    }

                    // Log image URL for debugging
                    if (!empty($job['print_image_url'])) {
                        echo "   Image: " . substr($job['print_image_url'], 0, 50) . "...\n";
                    }

                    $matchedPrinters = [];
                    $matchedKeys = [];

                    foreach ($data['printers'] as $printer) {
                        $shouldPrint = false;

                        // Logic: Match Bills
                        if ($job['job_type'] == 'bill' && $printer['print_type'] == 'bill') {
                            if ($job['type'] == $printer['bill_type']) {
                                if ($job['type'] == 'chef') {
                                    if ($job['group_id'] == $printer['product_group_id']) $shouldPrint = true;
                                } else {
                                    $shouldPrint = true;
                                }
                            }
                        }
                        // Logic: Match Receipts
                        if ($job['job_type'] == 'receipt' && $printer['print_type'] == 'receipt') {
                            $shouldPrint = true;
                        }

                        if ($shouldPrint && !jobTargetsPrinter($job, $printer)) {
                            $shouldPrint = false;
                        }

                        // Execute Print Job
                        if ($shouldPrint) {
                            $printerIp = isset($printer['ip']) ? trim((string) $printer['ip']) : '';
                            $printerName = isset($printer['name']) ? trim((string) $printer['name']) : '';
                            $printType = isset($printer['print_type']) ? strtolower(trim((string) $printer['print_type'])) : '';
                            $billType = isset($printer['bill_type']) ? strtolower(trim((string) $printer['bill_type'])) : '';
                            $groupId = isset($printer['product_group_id']) ? (string) $printer['product_group_id'] : '';
                            $key = implode('|', [$printType, $billType, $groupId, $printerIp, $printerName]);

                            if (!isset($matchedKeys[$key])) {
                                $matchedKeys[$key] = true;
                                $matchedPrinters[] = $printer;
                            }
                        }
                    }

                    if (count($matchedPrinters) === 0) {
                        echo "   [WARN] No matching printer for job.\n";
                        ackJob($job, $baseUrl, $contextOptions, $bridgeConfig, 'failed', 'No matching printer found for this job.');
                        continue;
                    }

                    if ($printMode === 'first') {
                        $printed = false;
                        $errors = [];
                        foreach ($matchedPrinters as $printer) {
                            $result = printImageJob($job, $printer, $baseUrl, $contextOptions, $bridgeConfig);
                            if ($result['ok']) {
                                $printed = true;
                                break;
                            }

                            if (!empty($result['error'])) {
                                $errors[] = buildPrinterFailureMessage($printer, $result['error']);
                            }
                        }
                        if ($printed) {
                            rememberPrintedJob($job, $recentlyPrintedJobs, $recentDuplicateWindowSeconds);
                            ackJob($job, $baseUrl, $contextOptions, $bridgeConfig);
                        } else {
                            $failureMessage = implode(' | ', array_slice($errors, 0, 3));
                            echo "   [WARN] Print failed. Marking job as failed.\n";
                            ackJob($job, $baseUrl, $contextOptions, $bridgeConfig, 'failed', $failureMessage);
                        }
                    } else {
                        $targetCount = count($matchedPrinters);
                        $successCount = 0;
                        $errors = [];
                        foreach ($matchedPrinters as $printer) {
                            $result = printImageJob($job, $printer, $baseUrl, $contextOptions, $bridgeConfig);
                            if ($result['ok']) {
                                $successCount++;
                                continue;
                            }

                            if (!empty($result['error'])) {
                                $errors[] = buildPrinterFailureMessage($printer, $result['error']);
                            }
                        }
                        if ($successCount === $targetCount) {
                            rememberPrintedJob($job, $recentlyPrintedJobs, $recentDuplicateWindowSeconds);
                            ackJob($job, $baseUrl, $contextOptions, $bridgeConfig);
                        } else {
                            $failureMessage = implode(' | ', array_slice($errors, 0, 3));
                            echo "   [WARN] Some printers failed. Marking job as failed.\n";
                            ackJob($job, $baseUrl, $contextOptions, $bridgeConfig, 'failed', $failureMessage);
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) { 
        echo "\n[Error] " . $e->getMessage() . "\n";
    }
    
    // Wait 3 seconds before checking for new jobs again
    sleep(3); 
}

// --- PRINT FUNCTION ---
function printImageJob($data, $printerConfig, $baseUrl, $contextOptions, $bridgeConfig) {
    $success = false;
    $errorMessage = null;
    $printer = null;

    // Determine Target (IP or USB Name)
    $target = $printerConfig['ip'] ?? '';
    $isUsb = (empty($target) || $target === 'NULL');
    
    if ($isUsb) {
        $target = $printerConfig['name'];
    }

    echo " -> Printing to $target... ";

    try {
        $connector = createPrinterConnector($target, $isUsb);

        $printer = new Printer($connector);
        $printer->setJustification(Printer::JUSTIFY_CENTER);

        // --- PROCESS IMAGE ---
        if (!empty($data['print_image_url'])) {
            $rawUrl = trim($data['print_image_url']);
            $imageContent = null;

            // CHECK 1: Is it Base64 Data? (starts with data:image...)
            if (preg_match('#^data:image/[^;]+;base64,#i', $rawUrl)) {
                $base64 = preg_replace('#^data:image/[^;]+;base64,#i', '', $rawUrl);
                $base64 = str_replace(["\r", "\n", ' '], '', $base64);
                $imageContent = base64_decode($base64, true);
                echo "\n    Decoding base64 image\n";
            } 
            // CHECK 2: Is it Raw Base64 string? (no header, just code)
            elseif (strlen($rawUrl) > 2000 && preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $rawUrl)) {
                $candidate = base64_decode($rawUrl, true);
                if ($candidate !== false) {
                    $header = substr($candidate, 0, 4);
                    // Check magic bytes for PNG, JPG, GIF
                    if ($header === "\x89PNG" || $header === "\xFF\xD8\xFF\xE0" || $header === "\xFF\xD8\xFF\xE1" || $header === "GIF8") {
                        $imageContent = $candidate;
                        echo "\n    Decoding raw base64 image\n";
                    }
                }
            }

            // CHECK 3: It is a URL (Download it)
            if ($imageContent === null) {
                $downloadResult = downloadImagePayload($rawUrl, $baseUrl, $contextOptions, $bridgeConfig);
                if ($downloadResult['ok']) {
                    $imageContent = $downloadResult['body'];
                } else {
                    $errorMessage = $downloadResult['error'];
                    echo " [Download Error] {$errorMessage}";
                }
            }

            // PRINT THE IMAGE
            if ($imageContent) {
                try {
                    printRasterImageInChunks($printer, $imageContent);
                    $success = true;
                } catch (Exception $e) {
                    $errorMessage = $e->getMessage();
                    echo " [Image Error] " . $errorMessage;
                }
            } else {
                $errorMessage = $errorMessage ?: 'Could not fetch image.';
                echo " [Download Error] {$errorMessage}";
            }

        } else {
            $errorMessage = 'No image data received for print job.';
            echo " [Image Error] {$errorMessage}";
        }

        // Finish Job
        if ($success) {
            $printer->feed(3);
            $printer->cut();
        }
        $printer->close();
        echo $success ? " [OK]\n" : " [FAILED]\n";

    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        echo " [ERROR] " . $errorMessage . "\n";
    } finally {
        if ($printer instanceof Printer) {
            try {
                $printer->close();
            } catch (Throwable $closeError) {
            }
        }
    }

    return [
        'ok' => $success,
        'error' => $errorMessage,
    ];
}

function assertRuntimeRequirements() {
    $missing = [];

    if (!extension_loaded('intl') || !class_exists('IntlBreakIterator') || !class_exists('UConverter')) {
        $missing[] = 'intl';
    }

    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        $missing[] = 'gd';
    }

    if (count($missing) === 0) {
        return;
    }

    echo "[ERROR] Missing PHP extension(s): " . implode(', ', $missing) . "\n";
    echo "        Current PHP: " . PHP_VERSION . " on " . PHP_OS_FAMILY . "\n";

    if (PHP_OS_FAMILY === 'Linux') {
        $packages = [];
        $phpMinorVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        foreach ($missing as $extension) {
            $packages[] = "php{$phpMinorVersion}-{$extension}";
        }

        echo "        Install with: sudo apt install " . implode(' ', $packages) . "\n";
    } else {
        echo "        Enable these extensions in php.ini before running the bridge.\n";
    }

    exit(1);
}

function createPrinterConnector($target, $isUsb) {
    if (!$isUsb) {
        return new NetworkPrintConnector($target, 9100);
    }

    $target = trim((string) $target);
    $isSmbTarget = preg_match('#^smb://#i', $target) === 1;

    if (PHP_OS_FAMILY === 'Windows') {
        if ($isSmbTarget) {
            return new WindowsPrintConnector($target);
        }

        try {
            return new WindowsPrintConnector("smb://localhost/" . $target);
        } catch (Exception $e) {
            return new WindowsPrintConnector($target);
        }
    }

    if ($isSmbTarget) {
        return new WindowsPrintConnector($target);
    }

    return new CupsPrintConnector($target);
}

function printRasterImageInChunks(Printer $printer, $imageContent) {
    global $maxRasterChunkHeight, $rasterChunkPauseMicros;

    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        throw new Exception("GD extension is required for receipt image printing.");
    }

    $sourceImage = @imagecreatefromstring($imageContent);
    if ($sourceImage === false) {
        throw new Exception("Could not decode receipt image.");
    }

    try {
        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);

        if ($width <= 0 || $height <= 0) {
            throw new Exception("Receipt image has invalid dimensions.");
        }

        $chunkHeight = resolveRasterChunkHeight($width, $maxRasterChunkHeight);
        $chunkCount = (int) ceil($height / $chunkHeight);

        echo "\n    Rendering {$width}x{$height}px in {$chunkCount} chunk(s)\n";

        for ($offsetY = 0, $chunkIndex = 1; $offsetY < $height; $offsetY += $chunkHeight, $chunkIndex++) {
            $currentHeight = min($chunkHeight, $height - $offsetY);
            $chunkImage = imagecreatetruecolor($width, $currentHeight);

            if ($chunkImage === false) {
                throw new Exception("Could not allocate printer image chunk.");
            }

            $white = imagecolorallocate($chunkImage, 255, 255, 255);
            imagefill($chunkImage, 0, 0, $white);
            imagealphablending($chunkImage, true);
            imagecopy($chunkImage, $sourceImage, 0, 0, 0, $offsetY, $width, $currentHeight);

            try {
                $escposImg = new GdEscposImage();
                $escposImg->readImageFromGdResource($chunkImage);
                $printer->bitImage($escposImg);
            } finally {
                imagedestroy($chunkImage);
            }

            if ($chunkIndex < $chunkCount && $rasterChunkPauseMicros > 0) {
                usleep($rasterChunkPauseMicros);
            }
        }
    } finally {
        imagedestroy($sourceImage);
    }
}

function resolveRasterChunkHeight($widthPixels, $maxChunkHeight) {
    $widthBytes = max(1, (int) ceil($widthPixels / 8));
    $maxBytesPerChunk = 48000;
    $chunkHeightFromWidth = (int) floor($maxBytesPerChunk / $widthBytes);

    return max(64, min((int) $maxChunkHeight, $chunkHeightFromWidth));
}

function normalizePrinterMatchValue($value) {
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    return strtolower($value);
}

function jobTargetsPrinter($job, $printer) {
    $targetPrinterId = normalizePrinterMatchValue($job['printer_list_id'] ?? null);
    $targetPrinterIp = normalizePrinterMatchValue($job['printer_ip'] ?? null);
    $targetPrinterName = normalizePrinterMatchValue($job['printer_name'] ?? null);

    if ($targetPrinterId !== null) {
        return normalizePrinterMatchValue($printer['id'] ?? null) === $targetPrinterId;
    }

    if ($targetPrinterIp !== null) {
        return normalizePrinterMatchValue($printer['ip'] ?? null) === $targetPrinterIp;
    }

    if ($targetPrinterName !== null) {
        return normalizePrinterMatchValue($printer['name'] ?? null) === $targetPrinterName;
    }

    return true;
}

function buildRecentPrintKey($job) {
    $jobType = isset($job['job_type']) ? strtolower(trim((string) $job['job_type'])) : 'unknown';
    $sourceId = isset($job['source_id']) && $job['source_id'] !== null && $job['source_id'] !== ''
        ? (string) $job['source_id']
        : (string) ($job['id'] ?? 'unknown');
    $billType = isset($job['type']) ? strtolower(trim((string) $job['type'])) : '';
    $groupId = isset($job['group_id']) ? (string) $job['group_id'] : '';
    $printerId = normalizePrinterMatchValue($job['printer_list_id'] ?? null) ?? '';
    $printerIp = normalizePrinterMatchValue($job['printer_ip'] ?? null) ?? '';
    $printerName = normalizePrinterMatchValue($job['printer_name'] ?? null) ?? '';

    return implode('|', [$jobType, $sourceId, $billType, $groupId, $printerId, $printerIp, $printerName]);
}

function pruneRecentPrintedJobs(&$recentlyPrintedJobs, $windowSeconds) {
    $cutoff = time() - max(1, (int) $windowSeconds);

    foreach ($recentlyPrintedJobs as $key => $printedAt) {
        if ($printedAt < $cutoff) {
            unset($recentlyPrintedJobs[$key]);
        }
    }
}

function wasRecentlyPrinted($job, &$recentlyPrintedJobs, $windowSeconds) {
    pruneRecentPrintedJobs($recentlyPrintedJobs, $windowSeconds);
    $key = buildRecentPrintKey($job);

    return isset($recentlyPrintedJobs[$key]);
}

function rememberPrintedJob($job, &$recentlyPrintedJobs, $windowSeconds) {
    pruneRecentPrintedJobs($recentlyPrintedJobs, $windowSeconds);
    $recentlyPrintedJobs[buildRecentPrintKey($job)] = time();
}

// --- ACK FUNCTION ---
function ackJob($job, $baseUrl, $contextOptions, $bridgeConfig, $status = 'printed', $errorMessage = null) {
    $ackUrl = rtrim($baseUrl, '/') . '/api/printer/ack';
    $payloadData = [
        'id' => $job['id'] ?? null,
        'job_type' => $job['job_type'] ?? null,
        'status' => $status,
    ];
    if ($errorMessage !== null && trim($errorMessage) !== '') {
        $payloadData['error'] = trim((string) $errorMessage);
    }
    if ($bridgeConfig['user_id'] !== '') {
        $payloadData['user_id'] = $bridgeConfig['user_id'];
    }
    if ($bridgeConfig['token'] !== '') {
        $payloadData['token'] = $bridgeConfig['token'];
    }

    $payload = http_build_query($payloadData);
    $response = httpRequest(
        'POST',
        $ackUrl,
        $contextOptions,
        buildBridgeHeaders($bridgeConfig, [
            'Content-Type: application/x-www-form-urlencoded',
        ]),
        $payload
    );

    if (!$response['ok']) {
        $bodyPreview = trim(substr((string) $response['body'], 0, 180));
        echo "   [ACK ERROR] HTTP {$response['status']}";
        if ($bodyPreview !== '') {
            echo " - {$bodyPreview}";
        }
        echo "\n";
        return false;
    }

    echo "   [ACK OK] {$status}\n";
    return true;
}

function buildPrinterFailureMessage($printer, $error) {
    $name = trim((string) ($printer['name'] ?? ''));
    $ip = trim((string) ($printer['ip'] ?? ''));
    $target = $ip !== '' ? $ip : ($name !== '' ? $name : 'unknown-printer');

    return "{$target}: " . trim((string) $error);
}

function downloadImagePayload($rawUrl, $baseUrl, $contextOptions, $bridgeConfig) {
    if (preg_match('#^https?://#i', $rawUrl)) {
        $imgUrl = $rawUrl;
    } elseif (preg_match('#^/https?://#i', $rawUrl)) {
        $imgUrl = ltrim($rawUrl, '/');
    } else {
        $imgUrl = rtrim($baseUrl, '/') . '/' . ltrim($rawUrl, '/');
    }

    $imgUrl = applyBridgeAuthToUrl($imgUrl, $bridgeConfig);
    echo "\n    Downloading: $imgUrl\n";

    $response = httpRequest('GET', $imgUrl, $contextOptions, buildBridgeHeaders($bridgeConfig), null, 45);
    if (!$response['ok']) {
        return [
            'ok' => false,
            'error' => 'Server returned HTTP ' . $response['status'] . ' for image request.',
        ];
    }

    $contentType = strtolower((string) findResponseHeader($response['headers'], 'Content-Type'));
    $hasImageBytes = isRecognizedImageBytes($response['body']);
    if ($contentType !== '' && strpos($contentType, 'image/') !== 0 && !$hasImageBytes) {
        $bodyPreview = trim(substr((string) $response['body'], 0, 180));
        return [
            'ok' => false,
            'error' => $bodyPreview !== ''
                ? "Expected image response but received {$contentType}: {$bodyPreview}"
                : "Expected image response but received {$contentType}",
        ];
    }

    if (!$hasImageBytes) {
        return [
            'ok' => false,
            'error' => 'Downloaded payload is not a valid PNG/JPG/GIF image.',
        ];
    }

    return [
        'ok' => true,
        'body' => $response['body'],
    ];
}

function buildBridgeHeaders($bridgeConfig, $headers = []) {
    $result = $headers;
    $result[] = 'Accept: application/json, image/png, image/jpeg, image/gif;q=0.9, */*;q=0.8';
    $result[] = 'X-Bridge-Id: ' . $bridgeConfig['bridge_id'];

    if ($bridgeConfig['token'] !== '') {
        $result[] = 'X-Bridge-Token: ' . $bridgeConfig['token'];
    }

    return $result;
}

function applyBridgeAuthToUrl($url, $bridgeConfig) {
    $params = [];

    if ($bridgeConfig['user_id'] !== '') {
        $params['user_id'] = $bridgeConfig['user_id'];
    }
    if ($bridgeConfig['token'] !== '') {
        $params['token'] = $bridgeConfig['token'];
    }

    if (!$params) {
        return $url;
    }

    $parts = parse_url($url);
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    foreach ($params as $key => $value) {
        if (!array_key_exists($key, $query) || trim((string) $query[$key]) === '') {
            $query[$key] = $value;
        }
    }

    $rebuilt = '';
    if (isset($parts['scheme'])) {
        $rebuilt .= $parts['scheme'] . '://';
    }
    if (isset($parts['user'])) {
        $rebuilt .= $parts['user'];
        if (isset($parts['pass'])) {
            $rebuilt .= ':' . $parts['pass'];
        }
        $rebuilt .= '@';
    }
    if (isset($parts['host'])) {
        $rebuilt .= $parts['host'];
    }
    if (isset($parts['port'])) {
        $rebuilt .= ':' . $parts['port'];
    }
    $rebuilt .= $parts['path'] ?? '';
    if ($query) {
        $rebuilt .= '?' . http_build_query($query);
    }
    if (isset($parts['fragment'])) {
        $rebuilt .= '#' . $parts['fragment'];
    }

    return $rebuilt;
}

function httpRequest($method, $url, $contextOptions, $headers = [], $content = null, $timeout = null) {
    $requestOptions = $contextOptions;
    $requestOptions['http'] = $requestOptions['http'] ?? [];
    $requestOptions['http']['method'] = strtoupper($method);
    $requestOptions['http']['ignore_errors'] = true;

    if ($timeout !== null) {
        $requestOptions['http']['timeout'] = $timeout;
    }
    if ($headers) {
        $requestOptions['http']['header'] = implode("\r\n", $headers) . "\r\n";
    }
    if ($content !== null) {
        $requestOptions['http']['content'] = $content;
    } else {
        unset($requestOptions['http']['content']);
    }

    $ctx = stream_context_create($requestOptions);
    $body = @file_get_contents($url, false, $ctx);
    $responseHeaders = $http_response_header ?? [];
    $statusCode = extractHttpStatusCode($responseHeaders);
    $ok = $body !== false && $statusCode >= 200 && $statusCode < 300;

    return [
        'ok' => $ok,
        'status' => $statusCode,
        'body' => $body === false ? '' : $body,
        'headers' => $responseHeaders,
    ];
}

function extractHttpStatusCode($responseHeaders) {
    foreach ($responseHeaders as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $headerLine, $matches)) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function findResponseHeader($responseHeaders, $headerName) {
    foreach ($responseHeaders as $headerLine) {
        if (stripos($headerLine, $headerName . ':') === 0) {
            return trim(substr($headerLine, strlen($headerName) + 1));
        }
    }

    return null;
}

function isRecognizedImageBytes($bytes) {
    if (!is_string($bytes) || $bytes === '') {
        return false;
    }

    if (strncmp($bytes, "\x89PNG", 4) === 0) {
        return true;
    }
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
        return true;
    }
    if (strncmp($bytes, "GIF8", 4) === 0) {
        return true;
    }

    return false;
}
?>
