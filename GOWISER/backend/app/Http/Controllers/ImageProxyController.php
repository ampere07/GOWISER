<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImageProxyController extends Controller
{
    /** How long a fetched image is served without asking Google again. */
    private const FRESH_SECONDS = 86400;

    /** How long the last good copy is kept to fall back on when Google does not answer. */
    private const KEEP_SECONDS = 30 * 86400;

    /** Larger files are passed through but not kept; this is meant for logos and photos. */
    private const MAX_CACHED_BYTES = 5 * 1024 * 1024;

    public function proxyGoogleDriveImage(Request $request)
    {
        $url = $request->query('url');

        if (!$url) {
            return response()->json(['error' => 'URL parameter is required'], 400);
        }

        // Extract file ID from Google Drive URL
        $fileId = null;
        if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $fileId = $matches[1];
        } elseif (preg_match('/id=([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $fileId = $matches[1];
        }

        if (!$fileId) {
            return response()->json(['error' => 'Invalid Google Drive URL'], 400);
        }

        // The same few images (the company logo above all) are asked for on every page view.
        // Fetching each one from Drive every time meant every slow answer from Google became a
        // failed image and an error in the log, so a fetched image is kept and reused.
        $cacheKey = 'drive_image_proxy:' . $fileId;
        $cached = Cache::get($cacheKey);

        if ($cached && (time() - $cached['fetched_at']) < self::FRESH_SECONDS) {
            return $this->imageResponse($cached);
        }

        // Construct direct download URL
        $directUrl = "https://drive.google.com/uc?export=download&id={$fileId}";

        try {
            $response = Http::timeout(20)->retry(2, 500, null, false)->get($directUrl);

            if ($response->successful()) {
                $image = [
                    'content_type' => $response->header('Content-Type') ?: 'image/png',
                    // Base64 so the copy survives any cache store, not only the file one.
                    'body' => base64_encode($response->body()),
                    'fetched_at' => time(),
                ];

                if (strlen($response->body()) <= self::MAX_CACHED_BYTES) {
                    Cache::put($cacheKey, $image, self::KEEP_SECONDS);
                }

                return $this->imageResponse($image);
            }

            $failure = 'HTTP ' . $response->status();
        } catch (\Exception $e) {
            $failure = $e->getMessage();
        }

        // Google did not answer in time. The copy from an earlier fetch is the same file.
        if ($cached) {
            Log::warning('Google Drive image fetch failed, serving the cached copy', [
                'file_id' => $fileId,
                'cached_at' => date('Y-m-d H:i:s', $cached['fetched_at']),
                'error' => mb_substr($failure, 0, 300),
            ]);

            return $this->imageResponse($cached);
        }

        Log::error('Error proxying Google Drive image', [
            'error' => $failure,
            'url' => $url
        ]);

        return response()->json(['error' => 'Failed to fetch image'], 502);
    }

    private function imageResponse(array $image)
    {
        return response(base64_decode($image['body']))
            ->header('Content-Type', $image['content_type'])
            ->header('Cache-Control', 'public, max-age=86400') // Cache for 24 hours
            ->header('Access-Control-Allow-Origin', '*');
    }
}
