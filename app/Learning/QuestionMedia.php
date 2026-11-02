<?php

namespace App\Learning;

use Illuminate\Validation\ValidationException;

final class QuestionMedia
{
    public static function video(?string $url, string $field = 'video_url'): ?array
    {
        if (! $url || ! trim($url)) {
            return null;
        }
        $parts = parse_url(trim($url));
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw ValidationException::withMessages([$field => 'Gunakan tautan video HTTPS yang lengkap.']);
        }
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';
        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
            try {
                $id = YouTubeVideo::id($url);
            } catch (ValidationException $error) {
                throw ValidationException::withMessages([$field => 'Gunakan link YouTube HTTPS yang valid, bukan kode HTML embed.']);
            }

            return ['provider' => 'youtube', 'id' => $id, 'url' => 'https://www.youtube.com/watch?v='.$id];
        }
        if (in_array($host, ['tiktok.com', 'www.tiktok.com', 'm.tiktok.com'], true) && preg_match('~/(?:@[^/]+/video|player/v1)/(\d{12,25})(?:/|$)~D', $path, $match)) {
            return ['provider' => 'tiktok', 'id' => $match[1], 'url' => 'https://www.tiktok.com'.$path];
        }
        if (! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)) {
            throw ValidationException::withMessages([$field => 'Gunakan tautan video dari situs publik.']);
        }

        return ['provider' => preg_match('/\.(mp4|webm)$/iD', $path) ? 'file' : 'link', 'url' => $url];
    }

    public static function fields(array $fields, int $module, ?int $classroom): array
    {
        return array_map(function (array $field) use ($module, $classroom): array {
            $field['image_url'] = $classroom && ! empty($field['image_id']) ? route('learning-modules.question-image', ['module' => $module, 'image' => $field['image_id'], 'classroom_id' => $classroom], false) : null;

            return $field;
        }, $fields);
    }
}
