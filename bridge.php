<?php
// 1. INCREASE MEMORY for long receipt images
ini_set('memory_limit', '256M'); 

require __DIR__ . '/vendor/autoload.php';

use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\Printer;

// CONFIG
// $baseUrl = "http://127.0.0.1:8000/"; 
$baseUrl = "https://admin.foodmonster.asia/";
$apiUrl  = $baseUrl . "api/printer/sync"; 

echo "--- BRIDGE (SCREENSHOT MODE) STARTED ---\n";

while (true) {
    try {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $json = @file_get_contents($apiUrl, false, $ctx);
        $data = $json ? json_decode($json, true) : null;
        if ($data && !empty($data['orders'][0]['print_image_url'])) {
            echo "RAW print_image_url: " . $data['orders'][0]['print_image_url'] . "\n";
        }

        if ($data && !empty($data['orders'])) {
            echo "Processing " . count($data['orders']) . " jobs...\n";
            
            foreach ($data['orders'] as $job) {
                foreach ($data['printers'] as $printer) {
                    $shouldPrint = false;

                    // Match Bills
                    if ($job['job_type'] == 'bill' && $printer['print_type'] == 'bill') {
                        if ($job['type'] == $printer['bill_type']) {
                            if ($job['type'] == 'chef') {
                                if ($job['group_id'] == $printer['product_group_id']) $shouldPrint = true;
                            } else {
                                $shouldPrint = true;
                            }
                        }
                    }
                    // Match Receipts
                    if ($job['job_type'] == 'receipt' && $printer['print_type'] == 'receipt') {
                        $shouldPrint = true;
                    }

                    if ($shouldPrint) {
                        printImageJob($job, $printer, $baseUrl);
                    }
                }
            }
        }
    } catch (Exception $e) { }
    sleep(3); 
}

function printImageJob($data, $printerConfig, $baseUrl) {
    $target = $printerConfig['ip'];
    $isUsb = (empty($target) || $target === 'NULL');
    if ($isUsb) $target = $printerConfig['name'];

    echo " -> Printing to $target... ";

    try {
        if ($isUsb) {
            try {
                $connector = new WindowsPrintConnector("smb://localhost/" . $target);
            } catch (Exception $e) {
                $connector = new WindowsPrintConnector($target);
            }
        } else {
            $connector = new NetworkPrintConnector($target, 9100);
        }

        $printer = new Printer($connector);
        $printer->setJustification(Printer::JUSTIFY_CENTER);

        // --- PRINT THE SCREENSHOT ---
        if (!empty($data['print_image_url'])) {
            $rawUrl = trim($data['print_image_url']);
            $imageContent = null;

            if (preg_match('#^data:image/[^;]+;base64,#i', $rawUrl)) {
                $base64 = preg_replace('#^data:image/[^;]+;base64,#i', '', $rawUrl);
                $base64 = str_replace(["\r", "\n", ' '], '', $base64);
                $imageContent = base64_decode($base64, true);
                echo "\n    Decoding base64 image\n";
            } elseif (strlen($rawUrl) > 2000 && preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $rawUrl)) {
                $candidate = base64_decode($rawUrl, true);
                if ($candidate !== false) {
                    $header = substr($candidate, 0, 4);
                    if ($header === "\x89PNG" || $header === "\xFF\xD8\xFF\xE0" || $header === "\xFF\xD8\xFF\xE1" || $header === "GIF8") {
                        $imageContent = $candidate;
                        echo "\n    Decoding base64 image\n";
                    }
                }
            }

            if ($imageContent === null) {
                // --- FIX: Check if URL is already full ---
                if (preg_match('#^https?://#i', $rawUrl)) {
                    // Already a full URL.
                    $imgUrl = $rawUrl;
                } elseif (preg_match('#^/https?://#i', $rawUrl)) {
                    // Full URL but prefixed with a slash.
                    $imgUrl = ltrim($rawUrl, '/');
                } else {
                    // Relative path (e.g. storage/receipts...), so add base URL.
                    $imgUrl = rtrim($baseUrl, '/') . '/' . ltrim($rawUrl, '/');
                }

                echo "\n    Downloading: $imgUrl\n";
                $imageContent = @file_get_contents($imgUrl);
            }

            if ($imageContent) {
                // 2. Write to Temp File
                $tempFile = __DIR__ . '/print_' . rand() . '.png';
                file_put_contents($tempFile, $imageContent);

                // 3. Print Image
                try {
                    $escposImg = EscposImage::load($tempFile);
                    $printer->bitImage($escposImg);
                } catch (Exception $e) {
                    echo " [Image Error] " . $e->getMessage();
                    $printer->text("Error: Could not process image.\n");
                }

                if (file_exists($tempFile)) unlink($tempFile);
            } else {
                echo " [Download Error] Could not fetch image.\n";
                $printer->text("Error: Image not found on server.\n");
            }

        } else {
            $printer->text("No Image Data.\n");
        }

        $printer->feed(3);
        $printer->cut();
        $printer->close();
        echo " [OK]\n";

    } catch (Exception $e) {
        echo " [ERROR] " . $e->getMessage() . "\n";
    }
}
