<?php
declare(strict_types=1);

// Simple OCR API endpoint with auto pre-processing
// - Accepts: POST multipart/form-data with field `image` (preferred), or `image_url`
// - Base64 uploads are intentionally NOT accepted to enforce multipart usage
// - Returns: JSON { success, text, confidence?, error? }

// CORS headers for browsers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'error' => 'Method Not Allowed. Use POST.'
    ]);
}

// Configure OCR provider (OCR.Space)
// Set environment variable OCR_SPACE_API_KEY in your server for production use.
$ocrApiKey = getenv('OCR_SPACE_API_KEY');
if ($ocrApiKey === false || $ocrApiKey === '') {
    // Demo key from OCR.Space (limited: max ~1MB, rate-limited)
    $ocrApiKey = 'helloworld';
}

// Language is fixed to English ('eng')
$language = 'eng';

$tmpFile = null;
$usingFileUpload = false;
$usingUrl = false;
$imageUrl = null;

try {
    // 1) Multipart file
    if (isset($_FILES['image']) && is_array($_FILES['image']) && (int)$_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $tmpFile = moveUploadToTemp($_FILES['image']);
        $usingFileUpload = true;
    }

    // 2) Explicitly reject base64 to force multipart usage
    $base64InImageBase64 = postParam('image_base64');
    $base64InImageField = (isset($_POST['image']) && !isset($_FILES['image'])) ? (string)$_POST['image'] : null;
    if ($tmpFile === null && ($base64InImageBase64 || $base64InImageField)) {
        respond(415, [
            'success' => false,
            'error' => 'Base64 is not accepted. Upload the image as multipart with field name `image`.'
        ]);
    }

    // 3) Public URL
    if ($tmpFile === null) {
        $imageUrl = postParam('image_url');
        if ($imageUrl !== null && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            $usingUrl = true;
        }
    }

    if (!$usingFileUpload && !$usingUrl) {
        respond(400, [
            'success' => false,
            'error' => 'Provide an image via multipart `image` or `image_url`.'
        ]);
    }

    $ocrResponse = null;
    $mime = '';
    if ($usingFileUpload && $tmpFile !== null) {
        // Basic mime validation
        $mime = mime_content_type($tmpFile) ?: '';
        $allowed = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/tiff'
        ];
        if (!in_array($mime, $allowed, true)) {
            cleanup($tmpFile);
            respond(415, [
                'success' => false,
                'error' => 'Unsupported Media Type. Provide a valid image file.'
            ]);
        }

        // Build preprocessed variants and try OCR on each, pick best
        $variants = buildPreprocessedVariants($tmpFile, $mime);
        $best = null;
        $bestVariantPath = $tmpFile;
        $cleanupPaths = [];
        foreach ($variants as $variantPath) {
            $candidate = callOcrSpace([
                'apiKey' => $ocrApiKey,
                'language' => $language,
                'filePath' => $variantPath,
                'imageUrl' => null,
                'scale' => true,
                'detectOrientation' => true,
            ]);
            if (isBetterOcrResult($best, $candidate)) {
                $best = $candidate;
                $bestVariantPath = $variantPath;
            }
            if ($variantPath !== $tmpFile) {
                $cleanupPaths[] = $variantPath;
            }
        }
        $ocrResponse = $best;

        // If OCR.Space produced empty/weak text, try Google Vision when available
        $gcpKey = getenv('GOOGLE_VISION_API_KEY') ?: '';
        if ($gcpKey !== '') {
            list($osText, $osConf) = extractOcrSpaceTextAndConfidence($ocrResponse);
            if (trim($osText) === '') {
                $visionResp = callGoogleVision($bestVariantPath, $gcpKey);
                if (is_array($visionResp)) {
                    $visionText = extractVisionText($visionResp);
                    if (trim($visionText) !== '') {
                        $ocrResponse = [
                            'IsErroredOnProcessing' => false,
                            'ParsedResults' => [[
                                'ParsedText' => $visionText,
                                'MeanConfidence' => extractVisionMeanConfidence($visionResp),
                            ]]
                        ];
                    }
                }
            }
        }

        // If still empty, try local Tesseract (helps with handwriting/single characters)
        list($tmpTextAfterCloud, $tmpConfAfterCloud) = extractOcrSpaceTextAndConfidence($ocrResponse);
        if (trim($tmpTextAfterCloud) === '') {
            $tesseractPath = getenv('TESSERACT_PATH') ?: 'tesseract';
            $tessInput = $bestVariantPath ?: $tmpFile;
            $tessVariant = $tessInput;
            if (extension_loaded('gd')) {
                // Create a high-contrast threshold variant for better OCR
                $imgForTess = createImageFromFile($tessInput, $mime ?: (mime_content_type($tessInput) ?: ''));
                if ($imgForTess) {
                    imagefilter($imgForTess, IMG_FILTER_GRAYSCALE);
                    applyThreshold($imgForTess, 160);
                    $tessVariant = tempPathLike($tessInput, 'tess');
                    imagepng($imgForTess, $tessVariant);
                    imagedestroy($imgForTess);
                    $cleanupPaths[] = $tessVariant;
                }
            }

            // Try PSM modes tuned for single character/word/line and whitelist A-Z
            $tessText = callTesseract($tesseractPath, $tessVariant, 'eng', ['10','13','8','7'], 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz');
            if (is_string($tessText) && trim($tessText) !== '') {
                $ocrResponse = [
                    'IsErroredOnProcessing' => false,
                    'ParsedResults' => [[
                        'ParsedText' => trim($tessText),
                        'MeanConfidence' => null,
                    ]]
                ];
            }
        }

        // Cleanup temp variants
        foreach ($cleanupPaths as $p) {
            if ($p !== $tmpFile) {
                cleanup($p);
            }
        }
    } else {
        // URL path (no preprocessing available)
        $ocrResponse = callOcrSpace([
            'apiKey' => $ocrApiKey,
            'language' => $language,
            'filePath' => null,
            'imageUrl' => $usingUrl ? $imageUrl : null,
            'scale' => true,
            'detectOrientation' => true,
        ]);
    }

    // Parse response
    if (!is_array($ocrResponse)) {
        cleanup($tmpFile);
        respond(502, [
            'success' => false,
            'error' => 'Invalid response from OCR service.'
        ]);
    }

    if (!empty($ocrResponse['IsErroredOnProcessing'])) {
        $msg = 'OCR processing error';
        if (!empty($ocrResponse['ErrorMessage'])) {
            $msg = is_array($ocrResponse['ErrorMessage'])
                ? implode('; ', $ocrResponse['ErrorMessage'])
                : (string)$ocrResponse['ErrorMessage'];
        }
        cleanup($tmpFile);
        respond(502, [
            'success' => false,
            'error' => $msg
        ]);
    }

    $parsedText = '';
    $confidence = null;
    if (!empty($ocrResponse['ParsedResults']) && is_array($ocrResponse['ParsedResults'])) {
        $first = $ocrResponse['ParsedResults'][0] ?? null;
        if (is_array($first)) {
            $parsedText = (string)($first['ParsedText'] ?? '');
            if (isset($first['MeanConfidence'])) {
                $confidence = is_numeric($first['MeanConfidence'])
                    ? (float)$first['MeanConfidence']
                    : null;
            }
        }
    }

    cleanup($tmpFile);
    respond(200, [
        'success' => true,
        'text' => $parsedText,
        'confidence' => $confidence,
    ]);
} catch (Throwable $e) {
    cleanup($tmpFile);
    respond(500, [
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
    ]);
}

// Helpers
function postParam(string $key, ?string $default = null): ?string
{
    // Support JSON request bodies as well as form-encoded
    static $jsonBody = null;
    if ($jsonBody === null && isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $jsonBody = $decoded;
        } else {
            $jsonBody = [];
        }
    } elseif ($jsonBody === null) {
        $jsonBody = [];
    }

    if (array_key_exists($key, $jsonBody)) {
        $val = $jsonBody[$key];
        return is_string($val) ? $val : ($val === null ? null : (string)$val);
    }

    if (isset($_POST[$key])) {
        $val = $_POST[$key];
        return is_string($val) ? $val : ($val === null ? null : (string)$val);
    }

    return $default;
}

function moveUploadToTemp(array $file): string
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        respond(400, [
            'success' => false,
            'error' => 'Invalid upload.'
        ]);
    }

    $size = isset($file['size']) ? (int)$file['size'] : 0;
    // If using demo key, keep file under ~1MB
    $maxBytes = 5 * 1024 * 1024; // 5MB default safeguard
    if ($size <= 0 || $size > $maxBytes) {
        respond(413, [
            'success' => false,
            'error' => 'File too large. Max 5MB.'
        ]);
    }

    $target = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . uniqid('ocr_', true);
    $ext = pathinfo($file['name'] ?? '', PATHINFO_EXTENSION);
    if ($ext !== '') {
        $target .= '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext);
    }

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        respond(500, [
            'success' => false,
            'error' => 'Failed to store uploaded file.'
        ]);
    }

    return $target;
}

// Removed base64 handler to enforce multipart uploads

function callOcrSpace(array $opts): array
{
    $apiKey = (string)($opts['apiKey'] ?? '');
    $language = (string)($opts['language'] ?? 'eng');
    $filePath = isset($opts['filePath']) ? (string)$opts['filePath'] : null;
    $imageUrl = isset($opts['imageUrl']) ? (string)$opts['imageUrl'] : null;
    $scale = (bool)($opts['scale'] ?? false);
    $detectOrientation = (bool)($opts['detectOrientation'] ?? false);

    $endpoint = 'https://api.ocr.space/parse/image';
    $ch = curl_init();

    $postFields = [
        'apikey' => $apiKey,
        'language' => $language,
        'isOverlayRequired' => 'false',
        'OCREngine' => '2',
        'scale' => $scale ? 'true' : 'false',
        'detectOrientation' => $detectOrientation ? 'true' : 'false',
    ];

    if ($filePath !== null) {
        $postFields['file'] = new CURLFile($filePath);
    } elseif ($imageUrl !== null) {
        $postFields['url'] = $imageUrl;
    } else {
        return [];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [
            'IsErroredOnProcessing' => true,
            'ErrorMessage' => 'cURL error: ' . $err,
        ];
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'IsErroredOnProcessing' => true,
            'ErrorMessage' => 'Non-JSON response (HTTP ' . $status . ')',
        ];
    }

    return $decoded;
}

// Helpers to extract OCR.Space values
function extractOcrSpaceTextAndConfidence(?array $resp): array
{
    if (!is_array($resp) || !empty($resp['IsErroredOnProcessing'])) {
        return ['', null];
    }
    $text = '';
    $conf = null;
    if (!empty($resp['ParsedResults']) && is_array($resp['ParsedResults'])) {
        $first = $resp['ParsedResults'][0] ?? null;
        if (is_array($first)) {
            $text = (string)($first['ParsedText'] ?? '');
            if (isset($first['MeanConfidence']) && is_numeric($first['MeanConfidence'])) {
                $conf = (float)$first['MeanConfidence'];
            }
        }
    }
    return [$text, $conf];
}

// Decide if candidate OCR is better than current best
function isBetterOcrResult(?array $best, $candidate): bool
{
    if (!is_array($candidate)) {
        return false;
    }
    if ($best === null) {
        return true;
    }
    $bestScore = ocrScore($best);
    $candScore = ocrScore($candidate);
    return $candScore > $bestScore;
}

function ocrScore(array $resp): float
{
    if (!empty($resp['IsErroredOnProcessing'])) {
        return 0.0;
    }
    $text = '';
    $conf = 0.0;
    if (!empty($resp['ParsedResults']) && is_array($resp['ParsedResults'])) {
        $first = $resp['ParsedResults'][0] ?? null;
        if (is_array($first)) {
            $text = (string)($first['ParsedText'] ?? '');
            if (isset($first['MeanConfidence']) && is_numeric($first['MeanConfidence'])) {
                $conf = (float)$first['MeanConfidence'];
            }
        }
    }
    // Weighted score: confidence + log(text length)
    $len = max(0, strlen(trim($text)));
    return $conf + log(1 + $len);
}

// Build several preprocessed variants using GD where available
function buildPreprocessedVariants(string $srcPath, string $mime): array
{
    if (!extension_loaded('gd')) {
        return [$srcPath];
    }

    $img = createImageFromFile($srcPath, $mime);
    if ($img === null) {
        return [$srcPath];
    }

    $variants = [];
    $variants[] = $srcPath; // original

    // Smart resize: scale down extremely large images; scale up small ones
    $w = imagesx($img);
    $h = imagesy($img);
    $minSide = min($w, $h);
    $maxSide = max($w, $h);

    if ($maxSide > 2200) {
        $variants[] = saveResizedVariant($img, $srcPath, 0.6);
    }
    if ($minSide < 600) {
        $variants[] = saveResizedVariant($img, $srcPath, 2.0);
    }

    // Grayscale + moderate contrast
    $variants[] = saveFilteredVariant($img, $srcPath, function($im) {
        imagefilter($im, IMG_FILTER_GRAYSCALE);
        imagefilter($im, IMG_FILTER_CONTRAST, -25);
    });

    // Grayscale + strong contrast + sharpen
    $variants[] = saveFilteredVariant($img, $srcPath, function($im) {
        imagefilter($im, IMG_FILTER_GRAYSCALE);
        imagefilter($im, IMG_FILTER_CONTRAST, -40);
        // simple sharpen kernel
        $matrix = [
            [0, -1, 0],
            [-1, 5, -1],
            [0, -1, 0],
        ];
        imageconvolution($im, $matrix, 1, 0);
    });

    // Binarize (threshold)
    $variants[] = saveFilteredVariant($img, $srcPath, function($im) {
        imagefilter($im, IMG_FILTER_GRAYSCALE);
        applyThreshold($im, 140);
    });

    imagedestroy($img);

    // Deduplicate and drop nulls
    $clean = [];
    foreach ($variants as $v) {
        if (is_string($v) && $v !== '' && file_exists($v) && !in_array($v, $clean, true)) {
            $clean[] = $v;
        }
    }
    return $clean;
}

function createImageFromFile(string $path, string $mime)
{
    $mime = strtolower($mime);
    try {
        if (strpos($mime, 'jpeg') !== false || strpos($mime, 'jpg') !== false) {
            return imagecreatefromjpeg($path);
        }
        if (strpos($mime, 'png') !== false) {
            return imagecreatefrompng($path);
        }
        if (strpos($mime, 'gif') !== false) {
            return imagecreatefromgif($path);
        }
        if (strpos($mime, 'webp') !== false && function_exists('imagecreatefromwebp')) {
            return imagecreatefromwebp($path);
        }
        if (strpos($mime, 'bmp') !== false && function_exists('imagecreatefrombmp')) {
            return imagecreatefrombmp($path);
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

function saveResizedVariant($img, string $srcPath, float $scale): ?string
{
    $w = imagesx($img);
    $h = imagesy($img);
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $file = tempPathLike($srcPath, 'resize');
    imagepng($dst, $file);
    imagedestroy($dst);
    return $file;
}

function saveFilteredVariant($img, string $srcPath, callable $filter): ?string
{
    $w = imagesx($img);
    $h = imagesy($img);
    $copy = imagecreatetruecolor($w, $h);
    imagecopy($copy, $img, 0, 0, 0, 0, $w, $h);
    $filter($copy);
    $file = tempPathLike($srcPath, 'flt');
    imagepng($copy, $file);
    imagedestroy($copy);
    return $file;
}

function applyThreshold($im, int $threshold): void
{
    $w = imagesx($im);
    $h = imagesy($im);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($im, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $gray = (int)round(0.299 * $r + 0.587 * $g + 0.114 * $b);
            $val = ($gray >= $threshold) ? 255 : 0;
            $col = imagecolorallocate($im, $val, $val, $val);
            imagesetpixel($im, $x, $y, $col);
        }
    }
}

function tempPathLike(string $srcPath, string $suffix): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    $name = 'ocr_' . $suffix . '_' . uniqid('', true) . '.png';
    return $dir . DIRECTORY_SEPARATOR . $name;
}

// Google Vision fallback for handwriting
function callGoogleVision(string $imagePath, string $apiKey)
{
    if (!is_file($imagePath)) {
        return null;
    }
    $data = file_get_contents($imagePath);
    if ($data === false) {
        return null;
    }
    $b64 = base64_encode($data);

    $payload = [
        'requests' => [[
            'image' => [ 'content' => $b64 ],
            'features' => [[ 'type' => 'DOCUMENT_TEXT_DETECTION' ]]
        ]]
    ];

    $url = 'https://vision.googleapis.com/v1/images:annotate?key=' . urlencode($apiKey);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        curl_close($ch);
        return null;
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
    curl_close($ch);
    $decoded = json_decode($raw, true);
    if ($status >= 200 && $status < 300 && is_array($decoded)) {
        return $decoded;
    }
    return null;
}

function extractVisionText(array $resp): string
{
    if (!empty($resp['responses'][0]['fullTextAnnotation']['text'])) {
        return (string)$resp['responses'][0]['fullTextAnnotation']['text'];
    }
    if (!empty($resp['responses'][0]['textAnnotations'][0]['description'])) {
        return (string)$resp['responses'][0]['textAnnotations'][0]['description'];
    }
    return '';
}

function extractVisionMeanConfidence(array $resp): ?float
{
    // Vision does not provide a single mean confidence easily. Try average over blocks if present.
    $responses = $resp['responses'][0] ?? [];
    $pages = $responses['fullTextAnnotation']['pages'] ?? [];
    $sum = 0.0;
    $count = 0;
    foreach ($pages as $page) {
        $blocks = $page['blocks'] ?? [];
        foreach ($blocks as $block) {
            if (isset($block['confidence'])) {
                $sum += (float)$block['confidence'];
                $count++;
            }
        }
    }
    if ($count > 0) {
        return $sum / $count * 100.0; // scale to 0-100 similar to OCR.Space
    }
    return null;
}

// Local Tesseract fallback
function callTesseract(string $tesseractBinary, string $inputImagePath, string $lang, array $psmModes, string $whitelist = ''): ?string
{
    if (!is_file($inputImagePath)) {
        return null;
    }
    $best = '';
    foreach ($psmModes as $psm) {
        $outBase = tempnam(sys_get_temp_dir(), 'tess_');
        if ($outBase === false) {
            continue;
        }
        // Tesseract writes to <outBase>.txt
        $cmd = escapeshellcmd($tesseractBinary) . ' ' . escapeshellarg($inputImagePath) . ' ' . escapeshellarg($outBase) . ' -l ' . escapeshellarg($lang) . ' --psm ' . escapeshellarg($psm);
        if ($whitelist !== '') {
            $cmd .= ' -c tessedit_char_whitelist=' . escapeshellarg($whitelist);
        }
        $cmd .= ' 2>&1';
        exec($cmd, $output, $ret);
        $txt = @file_get_contents($outBase . '.txt');
        @unlink($outBase . '.txt');
        if (is_string($txt) && trim($txt) !== '') {
            $best = trim($txt);
            break;
        }
    }
    return $best !== '' ? $best : null;
}

function cleanup(?string $filePath): void
{
    if ($filePath !== null && is_file($filePath)) {
        @unlink($filePath);
    }
}

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


