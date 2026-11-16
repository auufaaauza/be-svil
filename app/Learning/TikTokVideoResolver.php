<?php

namespace App\Learning;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class TikTokVideoResolver
{
    public function resolve(string $url): ?array
    {
        if (! QuestionMedia::isTikTokShortLink($url)) {
            return null;
        }
        $key = 'question-video:v1:'.hash('sha256', $url);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached ?: null;
        }
        $current = $url;
        for ($hop = 0; $hop < 3; $hop++) {
            try {
                $response = Http::connectTimeout(2)->timeout(3)->withoutRedirecting()->head($current);
                if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                    break;
                }
                $location = $response->header('Location');
                if (str_starts_with($location, '/') && ! str_starts_with($location, '//')) {
                    $location = 'https://'.parse_url($current, PHP_URL_HOST).$location;
                }
                $video = QuestionMedia::video($location);
                if (($video['provider'] ?? '') !== 'tiktok') {
                    break;
                }
                if (! empty($video['id'])) {
                    Cache::put($key, $video, now()->addDays(7));

                    return $video;
                }
                if (! QuestionMedia::isTikTokShortLink($video['url'])) {
                    break;
                }
                $current = $video['url'];
            } catch (ConnectionException|ValidationException) {
                break;
            }
        }
        Cache::put($key, false, now()->addMinute());

        return null;
    }
}
