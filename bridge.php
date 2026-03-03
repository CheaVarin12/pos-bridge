<?php
// bridge.php - POS Printer Bridge
// 1. INCREASE MEMORY for processing long receipt images
ini_set('memory_limit', '256M'); 

require __DIR__ . '/vendor/autoload.php';

use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\GdEscposImage;
use Mike42\Escpos\Printer;

// --- CONFIGURATION ---
$baseUrl = "http://127.0.0.1:8000/"; 
// $baseUrl = "https://admin.foodmonster.asia/";
// $baseUrl = "https://staging.foodmonster.asia/";
$apiUrl  = $baseUrl . "api/printer/sync"; 

echo "--- BRIDGE (SCREENSHOT MODE) STARTED ---\n";
echo "Target API: $apiUrl\n\n";

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
        'timeout' => 10,
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
        // Create a stream context with SSL disabled
        $ctx = stream_context_create($contextOptions);
        
        // Attempt to download JSON from the API
        $json = @file_get_contents($apiUrl, false, $ctx);

        if ($json === false) {
            // Echo a dot to show the script is running but waiting for connection
            echo "."; 
        } else {
            $data = json_decode($json, true);

            // Check if we found any orders/jobs
            if ($data && !empty($data['orders'])) {
                echo "\n[NEW JOB] Found " . count($data['orders']) . " orders!\n";
                
                foreach ($data['orders'] as $job) {
                    if (wasRecentlyPrinted($job, $recentlyPrintedJobs, $recentDuplicateWindowSeconds)) {
                        $sourceId = $job['source_id'] ?? $job['id'] ?? 'unknown';
                        echo "   [SKIP] Duplicate job detected for source $sourceId.\n";
                        ackJob($job, $baseUrl, $contextOptions);
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
                        continue;
                    }

                    if ($printMode === 'first') {
                        $printed = false;
                        foreach ($matchedPrinters as $printer) {
                            if (printImageJob($job, $printer, $baseUrl, $contextOptions)) {
                                $printed = true;
                                break;
                            }
                        }
                        if ($printed) {
                            rememberPrintedJob($job, $recentlyPrintedJobs, $recentDuplicateWindowSeconds);
                            ackJob($job, $baseUrl, $contextOptions);
                        } else {
                            echo "   [WARN] Print failed. Job will retry.\n";
                        }
                    } else {
                        $targetCount = count($matchedPrinters);
                        $successCount = 0;
                        foreach ($matchedPrinters as $printer) {
                            if (printImageJob($job, $printer, $baseUrl, $contextOptions)) {
                                $successCount++;
                            }
                        }
                        if ($successCount === $targetCount) {
                            rememberPrintedJob($job, $recentlyPrintedJobs, $recentDuplicateWindowSeconds);
                            ackJob($job, $baseUrl, $contextOptions);
                        } else {
                            echo "   [WARN] Some printers failed. Job will retry.\n";
                        }
                    }
                }
            }
        }
    } catch (Exception $e) { 
        echo "\n[Error] " . $e->getMessage() . "\n";
    }
    
    // Wait 3 seconds before checking for new jobs again
    sleep(3); 
}

// --- PRINT FUNCTION ---
function printImageJob($data, $printerConfig, $baseUrl, $contextOptions) {
    $success = false;

    // Determine Target (IP or USB Name)
    $target = $printerConfig['ip'];
    $isUsb = (empty($target) || $target === 'NULL');
    
    if ($isUsb) {
        $target = $printerConfig['name'];
    }

    echo " -> Printing to $target... ";

    try {
        // Connect to Printer
        if ($isUsb) {
            try {
                // Try SMB first (Shared Printer)
                $connector = new WindowsPrintConnector("smb://localhost/" . $target);
            } catch (Exception $e) {
                // Fallback to Direct Name
                $connector = new WindowsPrintConnector($target);
            }
        } else {
            // Network Printer
            $connector = new NetworkPrintConnector($target, 9100);
        }

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
                // Formatting: Ensure we have a valid Absolute URL
                if (preg_match('#^https?://#i', $rawUrl)) {
                    // Already a full URL
                    $imgUrl = $rawUrl;
                } elseif (preg_match('#^/https?://#i', $rawUrl)) {
                    // Full URL but prefixed with a slash
                    $imgUrl = ltrim($rawUrl, '/');
                } else {
                    // Relative path (e.g. storage/receipts...), so add base URL
                    $imgUrl = rtrim($baseUrl, '/') . '/' . ltrim($rawUrl, '/');
                }

                echo "\n    Downloading: $imgUrl\n";
                
                // USE SSL CONTEXT TO DOWNLOAD (Critical for HTTPS)
                $ctx = stream_context_create($contextOptions);
                $imageContent = @file_get_contents($imgUrl, false, $ctx);
            }

            // PRINT THE IMAGE
            if ($imageContent) {
                try {
                    printRasterImageInChunks($printer, $imageContent);
                    $success = true;
                } catch (Exception $e) {
                    echo " [Image Error] " . $e->getMessage();
                    $printer->text("Error: Could not process image.\n");
                }
            } else {
                echo " [Download Error] Could not fetch image.\n";
                $printer->text("Error: Image not found on server.\n");
            }

        } else {
            $printer->text("No Image Data.\n");
        }

        // Finish Job
        $printer->feed(3);
        $printer->cut();
        $printer->close();
        echo $success ? " [OK]\n" : " [FAILED]\n";

    } catch (Exception $e) {
        echo " [ERROR] " . $e->getMessage() . "\n";
    }

    return $success;
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

function buildRecentPrintKey($job) {
    $jobType = isset($job['job_type']) ? strtolower(trim((string) $job['job_type'])) : 'unknown';
    $sourceId = isset($job['source_id']) && $job['source_id'] !== null && $job['source_id'] !== ''
        ? (string) $job['source_id']
        : (string) ($job['id'] ?? 'unknown');
    $billType = isset($job['type']) ? strtolower(trim((string) $job['type'])) : '';
    $groupId = isset($job['group_id']) ? (string) $job['group_id'] : '';

    return implode('|', [$jobType, $sourceId, $billType, $groupId]);
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
function ackJob($job, $baseUrl, $contextOptions) {
    $ackUrl = rtrim($baseUrl, '/') . '/api/printer/ack';
    $payload = http_build_query([
        'id' => $job['id'] ?? null,
        'job_type' => $job['job_type'] ?? null,
    ]);

    $postOptions = $contextOptions;
    $postOptions['http'] = array_merge($postOptions['http'], [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $payload,
    ]);

    $ctx = stream_context_create($postOptions);
    $result = @file_get_contents($ackUrl, false, $ctx);

    if ($result === false) {
        echo "   [ACK ERROR] Could not notify server.\n";
        return false;
    }

    echo "   [ACK OK]\n";
    return true;
}
?>
