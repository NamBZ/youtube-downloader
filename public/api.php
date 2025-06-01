<?php

require('../vendor/autoload.php');

$url = isset($_GET['url']) ? $_GET['url'] : (isset($_POST['link']) ? $_POST['link'] : null);

function send_json($data)
{
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$url) {
    send_json([
        'error' => 'No URL provided!'
    ]);
}

$youtube = new \YouTube\YouTubeDownloader();

try {
    $links = $youtube->getDownloadLinks($url, 'android_vr');
    // Info
    $Info = $youtube->getPage($url)->getVideoInfo();
    // Downlaod
    $allFormats = $links->getAllFormats();

    if ($Info && $allFormats) {
        //Lọc video và audio
        $audio = YouTube\Utils\Utils::arrayFilterReset($allFormats, function ($format) {
            /** @var $format StreamFormat */
            return strpos($format->mimeType, 'audio/mp4') === 0;
        });
        $video = YouTube\Utils\Utils::arrayFilterReset($allFormats, function ($format) {
            /** @var $format StreamFormat */
            return strpos($format->mimeType, 'video/mp4') === 0 && !empty($format->audioQuality);
        });
        $videoNoAudio = YouTube\Utils\Utils::arrayFilterReset($allFormats, function ($format) {
            /** @var $format StreamFormat */
            return strpos($format->mimeType, 'video/mp4; codecs="avc') === 0 && empty($format->audioQuality);
        });
        send_json([
            'success' => [
                'info' => $Info,
                'downloads' => [
                    'audio' => $audio,
                    'video' => $video,
                    'videoNoAudio' => $videoNoAudio
                ]
            ]
        ]);
    } else {
        send_json(['error' => 'No links found']);
    }
} catch (\YouTube\Exception\YouTubeException $e) {

    send_json([
        'error' => $e->getMessage()
    ]);
}
