<?php
$url = 'https://eminal-clinic.jp/';
$apiKey = '91b5039ab597473f90ac4ad1de56202f';
$googleCloudVisionApiKey = 'AIzaSyASFBMI_Sgt5TsW9j4d_mifd2RR4U3P5cw';
$width = 1280;
$height = 550;

$apiUrl = sprintf(
    'https://api.apiflash.com/v1/urltoimage?access_key=%s&url=%s&width=%d&format=webp&response_type=image&full_page=true',
    $apiKey, urlencode($url), $width
);

echo "Fetching image...\n";
$imageData = file_get_contents($apiUrl);
if (!$imageData) die("Failed to get image\n");

// Crop to first view
$sourceImage = @imagecreatefromstring($imageData);
$croppedImage = imagecreatetruecolor(1280, 550);
imagecopy($croppedImage, $sourceImage, 0, 0, 0, 0, 1280, 550);
ob_start();
imagewebp($croppedImage);
$finalImageData = ob_get_clean();

echo "Calling Vision API...\n";
$base64Image = base64_encode($finalImageData);

$payload = [
    'requests' => [
        [
            'image' => ['content' => $base64Image],
            'features' => [
                ['type' => 'WEB_DETECTION', 'maxResults' => 20],
                ['type' => 'LABEL_DETECTION', 'maxResults' => 20]
            ]
        ]
    ]
];

$ch = curl_init('https://vision.googleapis.com/v1/images:annotate?key=' . $googleCloudVisionApiKey);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$response = curl_exec($ch);
curl_close($ch);

$res = json_decode($response, true);
// print nice output
echo "==== LABEL ANNOTATIONS ====\n";
if (isset($res['responses'][0]['labelAnnotations'])) {
    foreach ($res['responses'][0]['labelAnnotations'] as $l) {
        echo "- " . $l['description'] . " (" . $l['score'] . ")\n";
    }
}
echo "\n==== WEB ENTITIES ====\n";
if (isset($res['responses'][0]['webDetection']['webEntities'])) {
    foreach ($res['responses'][0]['webDetection']['webEntities'] as $e) {
        if (isset($e['description'])) {
            echo "- " . $e['description'] . " (" . $e['score'] . ")\n";
        }
    }
}
