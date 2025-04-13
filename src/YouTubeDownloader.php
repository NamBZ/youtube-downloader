<?php

namespace YouTube;

use YouTube\Exception\TooManyRequestsException;
use YouTube\Exception\VideoNotFoundException;
use YouTube\Exception\YouTubeException;
use YouTube\Models\VideoInfo;
use YouTube\Models\YouTubeCaption;
use YouTube\Models\YouTubeConfigData;
use YouTube\Responses\PlayerApiResponse;
use YouTube\Responses\VideoPlayerJs;
use YouTube\Responses\WatchVideoPage;
use YouTube\Utils\Utils;

class YouTubeDownloader
{
    protected Browser $client;

    function __construct()
    {
        $this->client = new Browser();
        $this->api_clients = new PlayerApiClients();
    }

    // Specify network options to be used in all network requests
    public function getBrowser(): Browser
    {
        return $this->client;
    }

    // Specify the JavaScript runtime for nsig decryption
    public function getJsrt(): JsRuntime
    {
        return new JsRuntime();
    }

    // Specify client for video data request
    public function getApiClients(): PlayerApiClients
    {
        return $this->api_clients;
    }

    /**
     * @param string $query
     * @return array
     */
    public function getSearchSuggestions(string $query): array
    {
        $query = rawurlencode($query);

        $response = $this->client->get('http://suggestqueries.google.com/complete/search', [
            'client' => 'firefox',
            'ds' => 'yt',
            'q' => $query
        ]);
        $json = json_decode($response->body, true);

        if (is_array($json) && count($json) >= 2) {
            return $json[1];
        }

        return [];
    }

    public function getVideoInfo(string $video_id): ?VideoInfo
    {
        $page = $this->getPage($video_id);
        return $page->getVideoInfo();
    }

    public function getPage(string $url): WatchVideoPage
    {
        $video_id = Utils::extractVideoId($url);

        $response = $this->client->get('https://www.youtube.com/watch?' . http_build_query([
                'v' => $video_id,
                'bpctr' => 9999999999,
                'has_verified' => 1,
            ]));

        return new WatchVideoPage($response);
    }

    // Downloading player API JSON
    protected function getPlayerApiResponse(string $video_id, string $client_id, YouTubeConfigData $configData): PlayerApiResponse
    {
        $visitor_id = $configData->getGoogleVisitorId();
        $sig_timestamp = $configData->getSignatureTimestamp();

        $clients = $this->api_clients::$clients;

        $this->client->setUserAgent($clients[$client_id]['context']['client']['userAgent']
                                    ?? $_SERVER['HTTP_USER_AGENT']
                                    ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36');

        if (isset($clients[$client_id]['config_url'])) {
            $response = $this->client->get($clients[$client_id]['config_url']);
            $config = new WatchVideoPage($response);
            if (!empty($config->getYouTubeConfigData())) {
                $configData = $config->getYouTubeConfigData();
                $clients[$client_id]['context'] = $configData->deepGet('INNERTUBE_CONTEXT');
            }
        }

        foreach(['hl' => 'en', 'timeZone' => 'UTC', 'utcOffsetMinutes' => 0] as $k => $v){
            $clients[$client_id]['context']['client'][$k] = $v;
        }

        $response = $this->client->post('https://www.youtube.com/youtubei/v1/player?key=' . $configData->getApiKey(), json_encode([
                'context' => $clients[$client_id]['context'],
                'videoId' => $video_id,
                'playbackContext' => [
                    'contentPlaybackContext' => [
                        'html5Preference' => 'HTML5_PREF_WANTS',
                        'signatureTimestamp' => $sig_timestamp,
                    ]
                ],
                'racyCheckOk' => true,
            ]), [
                'Content-Type' => 'application/json',
                'X-Goog-Visitor-Id' => $visitor_id,
                'X-Youtube-Client-Name' => $configData->getClientName(),
                'X-Youtube-Client-Version' => $configData->getClientVersion(),
            ]);

        return new PlayerApiResponse($response);
    }

    /**
     *
     * @param string $video_id
     * @param array/string $clients     array or comma-delimited string (the 1st in the list the highest priority)
     * @param array $extra
     * @return DownloadOptions
     * @throws TooManyRequestsException
     * @throws VideoNotFoundException
     * @throws YouTubeException
     */
    public function getDownloadLinks(string $video_id, $clients = 'ios', array $extra = []): DownloadOptions
    {
        $video_id = Utils::extractVideoId($video_id);

        if (!$video_id) {
            throw new \InvalidArgumentException('Invalid video ID: ' . $video_id);
        }

        $page = $this->getPage($video_id);

        if ($page->isTooManyRequests()) {
            throw new TooManyRequestsException($page);
        } elseif (!$page->isStatusOkay()) {
            throw new YouTubeException('Page failed to load. HTTP error: ' . $page->getResponse()->error);
        } elseif ($page->getPlayerResponse()->getPlayabilityStatusReason()) {
            throw new YouTubeException($page->getPlayerResponse()->getPlayabilityStatusReason());
        } elseif ($page->isVideoNotFound()) {
            throw new VideoNotFoundException();
        }

        // a giant JSON object holding useful data
        $youtube_config_data = $page->getYouTubeConfigData();

        $links = [];
        $client_ids = is_array($clients) ? $clients : explode(',', preg_replace('/\s+/', '', $clients));
        foreach($client_ids as $client_id) {
            // the most reliable way of fetching all download links no matter what
            // query: /youtubei/v1/player for some additional data
            $player_response = $this->getPlayerApiResponse($video_id, strtolower($client_id), $youtube_config_data);

            // throws exception if player response does not belong to the requested video
            preg_match('/"videoId":\s"([^"]+)"/', print_r($player_response, true), $matches);
            if (($matches[1] ?? '') != $video_id)
                throw new YouTubeException('Invalid player response: got player response for video "' . ($matches[1] ?? '') . '" instead of "' . $video_id .'"');

            // get player.js location that holds URL signature decipher function
            $player_url = $page->getPlayerScriptUrl();
            $response = $this->client->get($player_url);
            $player = new VideoPlayerJs($response);

            $links = array_merge($links, SignatureLinkParser::parseLinks($player_response, $player));
        }

        if (count($client_ids) > 1) {
            // sorting order: combined (smaller itag first) >> video (higher resolution >> smaller itag) >> audio (lower quality first)
            usort($links, fn($a,$b) => $b->mimeType[0] <=> $a->mimeType[0] ?:
                                       ($a->mimeType[0]=='v' ? ((bool)$a->audioQuality ? $a->itag : 999) : str_replace(['_','D','H'], ['L','M','S'], substr($a->audioQuality,-4,1)))
                                           <=> ($b->mimeType[0]=='v' ? ((bool)$b->audioQuality ? $b->itag : 999) : str_replace(['_','D','H'], ['L','M','S'], substr($b->audioQuality,-4,1))) ?:
                                       $b->height <=> $a->height ?:
                                       $a->itag <=> $b->itag ?:
                                       $a->isDrc <=> $b->isDrc
            );
            // remove duplicated formats
            foreach($links as $k=>$v) {
                if ($v->itag === ($i ?? 0) && $v->isDrc === ($c ?? false)) {
                    unset($links[$k]);
                } else {
                    $i = $v->itag;
                    $c = $v->isDrc;
                }
            }
        }

        // since we already have that information anyways...
        $info = $page->getVideoInfo();
        $captions = $this->getCaptions($page->getPlayerResponse());

        return new DownloadOptions($links, $info, $captions);
    }

    /**
     * @param Models\InitialPlayerResponse $player_response
     * @return YouTubeCaption[]
     */
    protected function getCaptions(Models\InitialPlayerResponse $player_response): array
    {
        if ($player_response) {
            return array_map(function ($item) {
                $baseUrl = Utils::arrayGet($item, 'baseUrl');

                $temp = new YouTubeCaption();
                $temp->name = Utils::arrayGet($item, 'name.simpleText') ?? Utils::arrayGet($item, 'name.runs.0.text');
                $temp->baseUrl = ($baseUrl[0] == '/' ? 'https://www.youtube.com' : '') . $baseUrl;
                $temp->languageCode = Utils::arrayGet($item, 'languageCode');
                $vss = Utils::arrayGet($item, 'vssId');
                $temp->isAutomatic = Utils::arrayGet($item, 'kind') === 'asr' || strpos($vss, 'a.') !== false;
                return $temp;

            }, $player_response->getCaptionTracks());
        }

        return [];
    }

    public function getThumbnails(string $video_id): array
    {
        $video_id = Utils::extractVideoId($video_id);

        if ($video_id) {
            return [
                'default' => "https://img.youtube.com/vi/{$video_id}/default.jpg",
                'medium' => "https://img.youtube.com/vi/{$video_id}/mqdefault.jpg",
                'high' => "https://img.youtube.com/vi/{$video_id}/hqdefault.jpg",
                'standard' => "https://img.youtube.com/vi/{$video_id}/sddefault.jpg",
                'maxres' => "https://img.youtube.com/vi/{$video_id}/maxresdefault.jpg",
            ];
        }

        return [];
    }
}
