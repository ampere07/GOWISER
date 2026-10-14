<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImageResizeService
{
    /**
     * The smallest long side, in pixels, a photo is resized to. Matches the size the job order
     * forms optimize to on the phone: enough to read a serial label or a signed contract.
     */
    public const MIN_LONG_EDGE = 1600;

    public static function resizeImage($sourcePath, $destinationPath)
    {
        $setting = self::getActiveSetting();
        
        Log::info('=== IMAGE RESIZE SERVICE CALLED ===', [
            'source' => $sourcePath,
            'destination' => $destinationPath,
            'active_setting_found' => $setting ? 'YES' : 'NO',
            'resize_percentage' => $setting ? $setting->image_size_value . '%' : 'N/A',
            'status' => $setting ? $setting->status : 'N/A'
        ]);
        
        if (!$setting || $setting->status !== 'active') {
            Log::warning('No active image size setting found. Skipping resize.');
            return false;
        }

        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            Log::error('Unable to get image info for: ' . $sourcePath);
            return false;
        }

        list($originalWidth, $originalHeight, $imageType) = $imageInfo;
        
        Log::info('Original image dimensions', [
            'width' => $originalWidth,
            'height' => $originalHeight,
            'type' => $imageType
        ]);

        $resizePercentage = $setting->image_size_value / 100;

        // The percentage alone shrank every photo, including ones the app had already sized
        // to stay legible, so labels and readings came out too blurred to read. It still
        // applies to large photos, but never takes the long side below MIN_LONG_EDGE.
        $longEdge = max($originalWidth, $originalHeight);
        $targetLongEdge = min($longEdge, max($longEdge * $resizePercentage, self::MIN_LONG_EDGE));

        if ($targetLongEdge >= $longEdge) {
            Log::info('Image already within the readable size, not resized', [
                'width' => $originalWidth,
                'height' => $originalHeight,
                'min_long_edge' => self::MIN_LONG_EDGE,
            ]);
            return false;
        }

        $scale = $targetLongEdge / $longEdge;
        $newWidth = round($originalWidth * $scale);
        $newHeight = round($originalHeight * $scale);

        Log::info('Resizing image based on percentage', [
            'percentage' => $setting->image_size_value . '%',
            'new_width' => $newWidth,
            'new_height' => $newHeight,
            'original_width' => $originalWidth,
            'original_height' => $originalHeight
        ]);

        $sourceImage = self::createImageFromType($sourcePath, $imageType);
        if (!$sourceImage) {
            Log::error('Failed to create image resource for: ' . $sourcePath);
            return false;
        }

        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

        if ($imageType === IMAGETYPE_PNG) {
            imagealphablending($resizedImage, false);
            imagesavealpha($resizedImage, true);
            $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
            imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
        }

        imagecopyresampled(
            $resizedImage,
            $sourceImage,
            0, 0, 0, 0,
            $newWidth,
            $newHeight,
            $originalWidth,
            $originalHeight
        );

        $quality = 85;
        $result = self::saveImageByType($resizedImage, $destinationPath, $imageType, $quality);
        
        Log::info('Image resize completed', [
            'success' => $result,
            'destination' => $destinationPath
        ]);

        imagedestroy($sourceImage);
        imagedestroy($resizedImage);

        return $result;
    }

    private static function getActiveSetting()
    {
        return DB::table('settings_image_size')
            ->where('status', 'active')
            ->first();
    }

    private static function createImageFromType($path, $imageType)
    {
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($path);
            case IMAGETYPE_PNG:
                return imagecreatefrompng($path);
            case IMAGETYPE_GIF:
                return imagecreatefromgif($path);
            case IMAGETYPE_WEBP:
                return imagecreatefromwebp($path);
            default:
                return false;
        }
    }

    private static function saveImageByType($image, $path, $imageType, $quality)
    {
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                return imagejpeg($image, $path, $quality);
            case IMAGETYPE_PNG:
                $pngQuality = round((100 - $quality) / 10);
                return imagepng($image, $path, $pngQuality);
            case IMAGETYPE_GIF:
                return imagegif($image, $path);
            case IMAGETYPE_WEBP:
                return imagewebp($image, $path, $quality);
            default:
                return false;
        }
    }

    public static function isImageFile($mimeType)
    {
        return in_array($mimeType, [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp'
        ]);
    }
}
