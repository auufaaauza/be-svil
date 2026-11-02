<?php

namespace App\Learning;

use Illuminate\Validation\ValidationException;

class YouTubeVideo
{
    public static function id(?string $input): ?string
    {
        $input = trim($input ?? '');
        if ($input === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9_-]{11}$/D', $input)) {
            return $input;
        }
        $url = parse_url($input);
        $id = null;
        if ($url && ($url['scheme'] ?? '') === 'https' && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['port'])) {
            $host = strtolower($url['host'] ?? '');
            $path = $url['path'] ?? '';
            if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
                $id = trim($path, '/');
            } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
                if ($path === '/watch') {
                    parse_str($url['query'] ?? '', $query);
                    $id = $query['v'] ?? null;
                } elseif (preg_match('~^/(?:embed|shorts|live)/([^/]+)/?$~', $path, $match)) {
                    $id = $match[1];
                }
            }
        }
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{11}$/D', $id)) {
            throw ValidationException::withMessages(['youtube_url' => ['Gunakan link YouTube HTTPS atau ID video yang valid, bukan kode HTML embed.']]);
        }

        return $id;
    }
}
