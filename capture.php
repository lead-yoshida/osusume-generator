<?php

// エラーハンドリング
ini_set('display_errors', 0);
error_reporting(E_ALL);

// --- 設定 ---
$apiKey = '91b5039ab597473f90ac4ad1de56202f'; // ApiFlash API Key
$googleCloudVisionApiKey = 'AIzaSyASFBMI_Sgt5TsW9j4d_mifd2RR4U3P5cw'; // Google Cloud Vision API Keyを指定してください
$geminiApiKey = 'AIzaSyDymNDleDXe2STcrdWEQ9Ue9u50VyNrgf4'; // Gemini API Key
$usageFile = 'usage.json';
$limit = 100;
$fallbackUrl = 'https://yahoo.co.jp/'; // 暫定のFallback URL (NG時に取得するURL)

// 医療広告ガイドライン関連のNGワードリスト
$ngWords = [
    '絶対治る', '必ず治る', '100%', '完治する', '日本一', 'No.1', 'ナンバーワン',
    '最高', '最先端', '最新', '完璧', '当院だけ', '他院より', '類を見ない',
    '絶対安全', '副作用なし', '痛みなし', '無痛', '失敗しない', '最善'
];

// --- ユーティリティ関数 ---
function send_json_error($message, $statusCode)
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

/**
 * 画像データをGoogle Cloud Vision APIに送信し、NGワードが含まれているかチェックする
 * 
 * @param string $imageData 画像のバイナリデータ
 * @param array $ngWords NGワードの配列
 * @param string $visionApiKey Google Cloud Vision APIキー
 * @return array|bool NGワード等が含まれている場合は原因と座標(array)、それ以外はfalse
 * @throws Exception API呼び出しに失敗した場合（エラーレスポンスなど）
 */
function check_image_for_ng_words($imageData, $ngWords, $visionApiKey, &$extractedText = null)
{
    if (empty($visionApiKey) || $visionApiKey === 'YOUR_GOOGLE_CLOUD_VISION_API_KEY') {
        throw new Exception("Google Cloud Vision API key is not set.");
    }

    $base64Image = base64_encode($imageData);

    $payload = [
        'requests' => [
            [
                'image' => [
                    'content' => $base64Image
                ],
                'features' => [
                    ['type' => 'DOCUMENT_TEXT_DETECTION'],
                    ['type' => 'FACE_DETECTION']
                ]
            ]
        ]
    ];

    $ch = curl_init();
    $url = 'https://vision.googleapis.com/v1/images:annotate?key=' . $visionApiKey;

    // 現在のサーバーのURLをRefererとして自動設定する
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $referer = $protocol . "://" . $host . "/";

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Referer: ' . $referer
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $result = json_decode($response, true);

        // Google Vision API自体が成功(200 OK)でも、内部でエラー情報を返しているケースのハンドリング
        if (isset($result['responses'][0]['error'])) {
            $errorMsg = $result['responses'][0]['error']['message'] ?? 'Unknown API Error';
            throw new Exception("Vision API Internal Error: " . $errorMsg);
        }

        // 1. NGワードチェック (テキスト検出)
        if (isset($result['responses'][0]['fullTextAnnotation']['text'])) {
            $extractedText = $result['responses'][0]['fullTextAnnotation']['text'];
            $fullText = $extractedText;

            // 空白などを除去してチェックしやすくする
            $normalizedText = str_replace(["\r", "\n", " ", "　"], "", $fullText);

            foreach ($ngWords as $word) {
                if (mb_strpos($normalizedText, $word) !== false) {
                    error_log("NG Word Detected: " . $word);
                    $ngVertices = null;
                    if (isset($result['responses'][0]['textAnnotations'])) {
                        $annotations = $result['responses'][0]['textAnnotations'];
                        for ($i = 1; $i < count($annotations); $i++) {
                            // 該当ワードが含まれているか、または該当ワードの一部である場合
                            if (mb_strpos($annotations[$i]['description'], $word) !== false || mb_strpos($word, $annotations[$i]['description']) !== false) {
                                if (isset($annotations[$i]['boundingPoly']['vertices'])) {
                                    $ngVertices = $annotations[$i]['boundingPoly']['vertices'];
                                    break;
                                }
                            }
                        }
                    }
                    return ['reason' => "NGワード「{$word}」", 'vertices' => $ngVertices];
                }
            }
        }

        // 2. 見た目（画像情報）からの「顔」識別
        // ガイドライン対応として、人物の顔がはっきり写っている場合はNG扱いとする
        if (isset($result['responses'][0]['faceAnnotations'])) {
            foreach ($result['responses'][0]['faceAnnotations'] as $face) {
                // 信頼度スコアが一定以上（例：0.5以上）の顔を検出
                if (isset($face['detectionConfidence']) && $face['detectionConfidence'] > 0.5) {
                    if (isset($face['boundingPoly']['vertices'])) {
                        error_log("Face Detected with confidence: " . $face['detectionConfidence']);
                        return ['reason' => "人物の顔（AI視覚判定）", 'vertices' => $face['boundingPoly']['vertices']];
                    }
                }
            }
        }
    }
    else {
        // HTTPエラーコード（403など）が返ってきた場合
        $errorMsg = "HTTP " . $httpCode;
        if ($response) {
            $result = json_decode($response, true);
            if (isset($result['error']['message'])) {
                $errorMsg .= " - " . $result['error']['message'];
            }
        }
        error_log("Vision API Error: " . $errorMsg);
        throw new Exception("Vision API 実行エラー: " . $errorMsg);
    }
    return false; // NGワードなし
}

/**
 * フルページのWebP画像を指定の開始Y座標から指定の高さでクロップする関数
 */
function crop_webp_image($imageData, $targetWidth, $targetHeight, $startY = 0)
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
        return $imageData; // GDライブラリがない場合はそのまま返す
    }

    $sourceImage = @imagecreatefromstring($imageData);
    if (!$sourceImage) {
        return $imageData;
    }

    $sourceWidth = imagesx($sourceImage);
    $sourceHeight = imagesy($sourceImage);

    // 画像がすでにターゲットより小さい、またはstartYが画像範囲外の場合は調整
    if ($sourceHeight <= $targetHeight && $startY == 0) {
        imagedestroy($sourceImage);
        return $imageData;
    }

    // Y座標の開始位置が元画像の高さを超えないように調整
    if ($startY >= $sourceHeight) {
        $startY = max(0, $sourceHeight - $targetHeight);
    }

    // 切り取る高さが元画像の残りの高さを超えないように調整
    $actualCropHeight = min($targetHeight, $sourceHeight - $startY);

    $croppedImage = imagecreatetruecolor($sourceWidth, $targetHeight);

    // WebPの透過などの情報を保持
    imagealphablending($croppedImage, false);
    imagesavealpha($croppedImage, true);
    $transparent = imagecolorallocatealpha($croppedImage, 255, 255, 255, 127);
    imagefilledrectangle($croppedImage, 0, 0, $sourceWidth, $targetHeight, $transparent);

    // 指定位置から切り取る
    imagecopy($croppedImage, $sourceImage, 0, 0, 0, $startY, $sourceWidth, $actualCropHeight);

    ob_start();
    imagewebp($croppedImage); // 品質はデフォルト
    $croppedData = ob_get_clean();

    imagedestroy($sourceImage);
    imagedestroy($croppedImage);

    return $croppedData ? $croppedData : $imageData;
}

/**
 * 画像Base64から店舗の紹介文とおすすめポイントを生成する（Gemini 2.5 Flash使用）
 */
function generate_store_info_from_gemini($base64Image, $geminiApiKey) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $geminiApiKey;
    $prompt = "この店舗WEBページのキャプチャ画像から、店舗の紹介文（200文字程度）と、おすすめポイント（1行で3点）を作成してください。出力は必ず以下のJSON形式のみとし、Markdown表記（```jsonなど）は絶対に付けず、JSONのテキスト文字列のみを返してください。\n{\n    \"intro\": \"紹介文テキスト\",\n    \"points\": [\"ポイント1\", \"ポイント2\", \"ポイント3\"]\n}";

    $payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt],
                    ["inline_data" => ["mime_type" => "image/png", "data" => $base64Image]]
                ]
            ]
        ],
        "generationConfig" => [
            "temperature" => 0.2
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $text = trim(preg_replace('/^```json\s*|\s*```$/i', '', $text));
        $parsed = json_decode($text, true);
        if ($parsed && isset($parsed['intro'])) {
            return [
                'intro' => $parsed['intro'],
                'points' => isset($parsed['points']) && is_array($parsed['points']) ? $parsed['points'] : []
            ];
        }
    }

    return [
         'intro' => '店舗紹介文の自動生成中にエラーが発生しました。',
         'points' => ['エラーにより生成できませんでした']
    ];
}

// --- リクエスト処理 ---

// GETリクエストでNGワード一覧を返す
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    echo json_encode(['ngWords' => $ngWords]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (isset($input['action']) && $input['action'] === 'zip') {
    if (!class_exists('ZipArchive')) {
        send_json_error('サーバーでZipArchiveが有効になっていません。', 500);
    }
    $images = $input['images'] ?? [];
    if (empty($images)) {
        send_json_error('画像データがありません。', 400);
    }

    $zipFileName = tempnam(sys_get_temp_dir(), 'screenshots_') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipFileName, ZipArchive::CREATE) !== TRUE) {
        send_json_error('Zipファイルの作成に失敗しました。', 500);
    }

    foreach ($images as $img) {
        // もし "data:image/webp;base64," などのプレフィックスがあれば削除
        $base64 = preg_replace('#^data:image/\w+;base64,#i', '', $img['data']);
        $imgData = base64_decode($base64);
        if ($imgData !== false) {
            $zip->addFromString($img['name'], $imgData);
        }
    }
    
    if (!empty($input['texts_content'])) {
        $zip->addFromString('store_info_list.txt', $input['texts_content']);
    }

    $zip->close();
    
    $zipData = file_get_contents($zipFileName);
    unlink($zipFileName);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'zip_base64' => base64_encode($zipData)]);
    exit;
}

$urls = $input['urls'] ?? [];
$width = $input['width'] ?? 1280;
$height = $input['height'] ?? 550;

if (empty($urls)) {
    send_json_error('URLが指定されていません', 400);
}

// --- 使用状況の読み込みと上限チェック ---
$currentMonth = date('Y-m');
$count = 0;
$numToProcess = count($urls);

$fp = fopen($usageFile, 'c+');
if (!flock($fp, LOCK_EX)) {
    send_json_error('サーバーが混み合っています。ロックの取得に失敗しました。', 503);
}

$contents = fread($fp, filesize($usageFile) ?: 1);
$data = json_decode($contents, true);

if (isset($data['month']) && $data['month'] === $currentMonth) {
    $count = (int)($data['count'] ?? 0);
}

// キャプチャ取得（および可能ならFallback）を行うため、この時点での上限チェックは厳密な枚数と乖離する可能性あり
if ($count + $numToProcess > $limit) {
    flock($fp, LOCK_UN);
    fclose($fp);
    send_json_error("API上限に達します。今月の残り作成可能枚数は " . ($limit - $count) . " 枚です。", 429);
}

// ストリーミングレスポンスのためのバッファリング無効化
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
while (ob_get_level() > 0) {
    @ob_end_clean();
}

function send_progress($percent, $msg)
{
    echo json_encode(['progress' => true, 'percent' => $percent, 'message' => $msg]) . "\n";
    flush();
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Encoding: none');
header('Cache-Control: no-cache, must-revalidate');
header('X-Accel-Buffering: no'); // Nginx用

// ブラウザやProxyのバッファを強制フラッシュするためのダミーデータ
echo str_pad(' ', 4096) . "\n";
flush();

send_progress(5, "処理を開始します…");

// --- 画像生成処理 ---
$successful_captures = 0;
$final_results = [];
$process_logs = [];
$numUrls = count($urls);

foreach ($urls as $index => $url) {
    if (empty($url))
        continue;

    $baseProgress = ($index / $numUrls) * 100;
    $step = 100 / $numUrls;

    send_progress(round($baseProgress + ($step * 0.1)), "{$url} のキャプチャを取得中… (約10〜30秒)");

    // 1. 初回のキャプチャ取得 (full_page=true で OCRの精度を上げる)
    $apiUrl = sprintf(
        'https://api.apiflash.com/v1/urltoimage?access_key=%s&url=%s&width=%d&format=webp&response_type=image&full_page=true&ttl=2592000',
        $apiKey, urlencode($url), $width
    );
    $imageData = @file_get_contents($apiUrl);

    if ($imageData !== false) {
        $finalUrl = $url;
        send_progress(round($baseProgress + ($step * 0.5)), "画像を切り出し中…");
        $finalImageData = crop_webp_image($imageData, $width, $height); // まずファーストビューを切り出す

        // 2. 画像診断（OCRおよび顔検出）
        // ユーザーが指定したサイズ領域（ファーストビュー）のみを対象に判定を行う
        $extractedText = '';
        send_progress(round($baseProgress + ($step * 0.6)), "AI画像診断（OCR等）をサーバーで実行中…");
        try {
            $isNg = check_image_for_ng_words($finalImageData, $ngWords, $googleCloudVisionApiKey, $extractedText);
        }
        catch (Exception $e) {
            flock($fp, LOCK_UN);
            fclose($fp);
            send_json_error("テキスト解析(OCR)中にエラーが発生しました: " . $e->getMessage(), 500);
        }

        // 3. テキスト自動生成 (Gemini)
        $base64Image = base64_encode($finalImageData);
        send_progress(round($baseProgress + ($step * 0.8)), "AIテキスト生成(Gemini)を実行中…");
        $storeInfo = generate_store_info_from_gemini($base64Image, $geminiApiKey);

        if ($isNg !== false) {
            $reason = is_array($isNg) ? $isNg['reason'] : $isNg;
            $vertices = is_array($isNg) ? $isNg['vertices'] : null;
            $firstViewImageData = $finalImageData; // 赤枠描画用に元のファーストビューを保持

            // セカンドビュー代替取得は廃止。最初のファーストビューをそのまま保持する
            $process_logs[] = "{$url} は「{$reason}」が検出されました。";

            // 赤枠付きの証拠画像を作成する
            if ($vertices && function_exists('imagecreatefromstring')) {
                $sourceImage = @imagecreatefromstring($firstViewImageData);
                if ($sourceImage) {
                    $red = imagecolorallocate($sourceImage, 255, 0, 0);
                    imagesetthickness($sourceImage, 5); // 枠線の太さ
                    $points = [];
                    $numPoints = 0;
                    foreach ($vertices as $v) {
                        $points[] = isset($v['x']) ? $v['x'] : 0;
                        $points[] = isset($v['y']) ? $v['y'] : 0;
                        $numPoints++;
                    }
                    if ($numPoints >= 3) {
                        imagepolygon($sourceImage, $points, $numPoints, $red);
                    }
                    ob_start();
                    imagewebp($sourceImage);
                    $ngImageData = ob_get_clean();
                    imagedestroy($sourceImage);

                    // 証拠画像(赤枠付きファーストビュー全体)もZipやJSONに追加するために保存
                    $originalHost = parse_url($url, PHP_URL_HOST);
                    $imageName = preg_replace('/^www\./', '', $originalHost) . '.webp';
                    $ngImageName = preg_replace('/^www\./', '', $originalHost) . '_NG_evidence.webp';
                    $final_results[] = [
                        'url' => $url,
                        'name' => $imageName,
                        'status' => 'ng',
                        'ng_reason' => $reason,
                        'image' => 'data:image/webp;base64,' . base64_encode($firstViewImageData),
                        'ng_image' => 'data:image/webp;base64,' . base64_encode($ngImageData),
                        'intro' => $storeInfo['intro'],
                        'points' => $storeInfo['points']
                    ];
                }
            } else {
                // 顔検出のみ等の理由でverticesがない場合でも結果を追加
                $originalHost = parse_url($url, PHP_URL_HOST);
                $imageName = preg_replace('/^www\./', '', $originalHost) . '.webp';
                $final_results[] = [
                    'url' => $url,
                    'name' => $imageName,
                    'status' => 'ng',
                    'ng_reason' => $reason,
                    'image' => 'data:image/webp;base64,' . base64_encode($firstViewImageData),
                    'ng_image' => 'data:image/webp;base64,' . base64_encode($firstViewImageData), // ng_imageが作れなかった場合のフォールバック
                    'intro' => $storeInfo['intro'],
                    'points' => $storeInfo['points']
                ];
            }
        }
        else {
            $process_logs[] = "{$url} は正常に取り込みが完了しました。";
            $originalHost = parse_url($url, PHP_URL_HOST);
            $imageName = preg_replace('/^www\./', '', $originalHost) . '.webp';
            $final_results[] = [
                'url' => $url,
                'name' => $imageName,
                'status' => 'ok',
                'image' => 'data:image/webp;base64,' . base64_encode($finalImageData),
                'intro' => $storeInfo['intro'],
                'points' => $storeInfo['points']
            ];
        }

        $successful_captures++;
    }
    else {
        $process_logs[] = "{$url} はキャプチャの取得自体に失敗しました。";
    }

    // API呼び出し後は少し待機 (ApiFlash, OCR.space共に)
    if (count($urls) > 1) {
        sleep(1);
    }
}

if ($successful_captures === 0) {
    flock($fp, LOCK_UN);
    fclose($fp);
    send_json_error("有効な画像を1枚も取得できませんでした。\nログ:\n" . implode("\n", $process_logs), 500);
}

// --- 使用状況の更新 ---
$newCount = $count + $successful_captures; // 実際に成功した枚数をカウント
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode(['month' => $currentMonth, 'count' => $newCount]));
flock($fp, LOCK_UN);
fclose($fp);

// --- レスポンスの生成 ---
header('X-Usage-Count: ' . $newCount);

// ログ文字列を生成
$logString = implode("\n", $process_logs);
// ログ文字列をBase64エンコードしてヘッダーに入れる (マルチバイト文字対応)
header('X-Process-Logs: ' . base64_encode($logString));

send_progress(95, "最終データを作成中…");

header('Content-Type: application/json');

$response = [
    'success' => true,
    'logs' => $process_logs,
    'results' => $final_results
];

echo json_encode($response) . "\n";
exit;