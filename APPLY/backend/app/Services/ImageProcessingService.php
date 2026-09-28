<?php

namespace App\Services;

use App\Models\ImageQueue;
use App\Models\Application;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ImageProcessingService
{
    private $googleDriveService;
    private $storageSettings;

    public function __construct(GoogleDriveService $googleDriveService)
    {
        $this->googleDriveService = $googleDriveService;
        $this->loadStorageSettings();
    }

    private function loadStorageSettings(): void
    {
        try {
            $settings = DB::table('settings_image_size')
                ->where('status', 'active')
                ->first();
            
            if ($settings) {
                $resizePercentage = $settings->image_size_value / 100;
                $this->storageSettings = [
                    'resize_percentage' => $resizePercentage,
                    'resize_enabled' => true,
                ];
                Log::info('Loaded active image resize settings', [
                    'percentage' => $settings->image_size_value . '%',
                    'status' => $settings->status
                ]);
            } else {
                $this->storageSettings = [
                    'resize_percentage' => 1.0,
                    'resize_enabled' => false,
                ];
                Log::warning('No active image resize settings found, resizing disabled');
            }
        } catch (\Exception $e) {
            Log::error('Failed to load storage settings: ' . $e->getMessage());
            $this->storageSettings = [
                'resize_percentage' => 1.0,
                'resize_enabled' => false,
            ];
        }
    }

    public function processPendingImages(int $limit = 10): array
    {
        $processed = 0;
        $failed = 0;
        $skipped = 0;

        $pendingImages = ImageQueue::where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();

        Log::info("Image Processing: Found {$pendingImages->count()} pending images to process");

        foreach ($pendingImages as $imageQueue) {
            try {
                $result = $this->processImage($imageQueue);
                
                if ($result['success']) {
                    $processed++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                Log::error("Image Processing Error for queue ID {$imageQueue->id}: " . $e->getMessage());
                $this->failImage($imageQueue, $e->getMessage());
                $failed++;
            }
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    private function processImage(ImageQueue $imageQueue): array
    {
        $imageQueue->markAsProcessing();

        Log::info("Processing image queue ID: {$imageQueue->id}, Field: {$imageQueue->field_name}", [
            'application_id' => $imageQueue->application_id,
            'local_path' => $imageQueue->local_path,
            'original_filename' => $imageQueue->original_filename
        ]);

        if (!file_exists($imageQueue->local_path)) {
            $errorMsg = "Local file not found: {$imageQueue->local_path}";
            Log::error($errorMsg, [
                'queue_id' => $imageQueue->id,
                'application_id' => $imageQueue->application_id
            ]);
            $this->failImage($imageQueue, $errorMsg);
            return ['success' => false, 'error' => $errorMsg];
        }

        try {
            $application = Application::find($imageQueue->application_id);
            if (!$application) {
                throw new \Exception("Application not found: {$imageQueue->application_id}");
            }

            $fullName = trim($application->first_name . ' ' . 
                ($application->middle_initial ? $application->middle_initial . '. ' : '') . 
                $application->last_name);
            
            Log::info("Application found", [
                'application_id' => $application->id,
                'full_name' => $fullName
            ]);

            $resizedImagePath = $this->resizeImageIfNeeded($imageQueue->local_path);
            
            Log::info("Image resize completed", [
                'original_path' => $imageQueue->local_path,
                'resized_path' => $resizedImagePath,
                'file_exists' => file_exists($resizedImagePath)
            ]);
            
            $requestFieldName = $this->getRequestFieldName($imageQueue->field_name);
            
            $files = [
                $requestFieldName => $resizedImagePath
            ];
            
            Log::info("Uploading to Google Drive", [
                'full_name' => $fullName,
                'db_field_name' => $imageQueue->field_name,
                'request_field_name' => $requestFieldName,
                'file_path' => $resizedImagePath
            ]);

            $uploadedUrls = $this->googleDriveService->uploadApplicationDocuments($fullName, $files);
            
            Log::info("Upload completed", [
                'uploaded_urls' => $uploadedUrls
            ]);

            if (isset($uploadedUrls[$imageQueue->field_name])) {
                $gdriveUrl = $uploadedUrls[$imageQueue->field_name];
            } else {
                throw new \Exception("Upload did not return URL for field: {$imageQueue->field_name}. Returned keys: " . implode(', ', array_keys($uploadedUrls)));
            }

            $application->update([
                $imageQueue->field_name => $gdriveUrl
            ]);
            Log::info("Updated application {$imageQueue->application_id} field {$imageQueue->field_name} with URL: {$gdriveUrl}");

            $this->replaceCustomerPlaceholder((int) $imageQueue->application_id, $imageQueue->field_name, $gdriveUrl);

            $imageQueue->markAsCompleted($gdriveUrl);

            try {
                if ($resizedImagePath !== $imageQueue->local_path && file_exists($resizedImagePath)) {
                    unlink($resizedImagePath);
                    Log::info("Deleted temporary resized file: {$resizedImagePath}");
                }
                
                if (file_exists($imageQueue->local_path)) {
                    unlink($imageQueue->local_path);
                    Log::info("Deleted local file: {$imageQueue->local_path}");
                }
            } catch (\Exception $e) {
                Log::warning("Failed to delete local files: " . $e->getMessage());
            }

            return [
                'success' => true,
                'gdrive_url' => $gdriveUrl,
            ];

        } catch (\Exception $e) {
            $errorMsg = "Failed to process image: " . $e->getMessage();
            Log::error($errorMsg, [
                'queue_id' => $imageQueue->id,
                'application_id' => $imageQueue->application_id,
                'field_name' => $imageQueue->field_name,
                'local_path' => $imageQueue->local_path,
                'exception' => $e->getTraceAsString()
            ]);
            $this->failImage($imageQueue, $errorMsg);
            
            return [
                'success' => false,
                'error' => $errorMsg,
            ];
        }
    }

    private function resizeImageIfNeeded(string $localPath): string
    {
        $mimeType = mime_content_type($localPath);
        
        if (!ImageResizeService::isImageFile($mimeType)) {
            Log::info("File is not an image, skipping resize: {$localPath}");
            return $localPath;
        }

        try {
            $resizedPath = $localPath . '.resized.jpg';
            
            $resized = ImageResizeService::resizeImage($localPath, $resizedPath);
            
            if ($resized && file_exists($resizedPath)) {
                Log::info("Image resized successfully", [
                    'original' => $localPath,
                    'resized' => $resizedPath,
                    'original_size' => filesize($localPath),
                    'resized_size' => filesize($resizedPath)
                ]);
                return $resizedPath;
            } else {
                Log::warning("Resize failed, using original image: {$localPath}");
                return $localPath;
            }
        } catch (\Exception $e) {
            Log::error("Error during resize: " . $e->getMessage());
            return $localPath;
        }
    }

    private function getRequestFieldName(string $dbFieldName): string
    {
        $mapping = [
            'proof_of_billing_url' => 'proofOfBilling',
            'government_valid_id_url' => 'governmentIdPrimary',
            'second_government_valid_id_url' => 'governmentIdSecondary',
            'house_front_picture_url' => 'houseFrontPicture',
            'promo_url' => 'promoProof',
        ];
        
        return $mapping[$dbFieldName] ?? $dbFieldName;
    }

    /**
     * How long a queue row may sit in 'processing' before it is treated as abandoned.
     * An upload finishes in seconds; a row older than this belongs to a run that
     * crashed or was killed, and processPendingImages() only picks up 'pending' rows,
     * so without this it would never be tried again.
     */
    private const STALE_PROCESSING_MINUTES = 15;

    /** The application/customer columns that hold the 'processing' placeholder. */
    private const PLACEHOLDER_FIELDS = [
        'proof_of_billing_url',
        'government_valid_id_url',
        'house_front_picture_url',
    ];

    /**
     * Put rows abandoned in 'processing' back in line, counting it as a failed attempt
     * so a row that keeps crashing the worker still runs out of retries.
     */
    public function recoverStaleProcessing(): array
    {
        $recovered = 0;

        $staleImages = ImageQueue::where('status', 'processing')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_PROCESSING_MINUTES))
            ->get();

        foreach ($staleImages as $imageQueue) {
            $this->failImage($imageQueue, 'Abandoned in processing for over ' . self::STALE_PROCESSING_MINUTES . ' minutes');

            if ($imageQueue->canRetry()) {
                $imageQueue->resetForRetry();
            }

            $recovered++;
        }

        if ($recovered > 0) {
            Log::warning("Recovered {$recovered} image queue row(s) stuck in processing");
        }

        return ['recovered' => $recovered];
    }

    /**
     * Mark a queue row failed, and once it has used its last retry, clear the
     * 'processing' placeholder so the record reads empty instead of pending forever.
     */
    private function failImage(ImageQueue $imageQueue, string $errorMessage): void
    {
        $imageQueue->markAsFailed($errorMessage);

        if ($imageQueue->canRetry() || !in_array($imageQueue->field_name, self::PLACEHOLDER_FIELDS, true)) {
            return;
        }

        try {
            $field = $imageQueue->field_name;

            Application::where('id', $imageQueue->application_id)
                ->where($field, 'processing')
                ->update([$field => null]);

            $this->replaceCustomerPlaceholder((int) $imageQueue->application_id, $field, null);

            Log::warning("Image upload gave up after retries; cleared placeholder", [
                'queue_id' => $imageQueue->id,
                'application_id' => $imageQueue->application_id,
                'field_name' => $field,
                'error' => $errorMessage,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to clear image placeholder: " . $e->getMessage(), [
                'queue_id' => $imageQueue->id,
            ]);
        }
    }

    /**
     * A job order approved while its images were still uploading copies the
     * 'processing' placeholder into the new customer. Replace it there too, but only
     * where it is still the placeholder, so a value set since is never overwritten.
     */
    private function replaceCustomerPlaceholder(int $applicationId, string $field, ?string $value): void
    {
        if (!in_array($field, self::PLACEHOLDER_FIELDS, true)) {
            return;
        }

        try {
            $updated = DB::table('customers')
                ->join('billing_accounts', 'billing_accounts.customer_id', '=', 'customers.id')
                ->join('job_orders', 'job_orders.account_id', '=', 'billing_accounts.id')
                ->where('job_orders.application_id', $applicationId)
                ->where("customers.{$field}", 'processing')
                ->update(["customers.{$field}" => $value]);

            if ($updated > 0) {
                Log::info("Replaced customer placeholder for application {$applicationId} field {$field}", [
                    'rows' => $updated,
                ]);
            }
        } catch (\Exception $e) {
            // The application already has its value; a customer-side miss must not
            // fail the upload.
            Log::error("Failed to update customer image placeholder: " . $e->getMessage(), [
                'application_id' => $applicationId,
                'field_name' => $field,
            ]);
        }
    }

    public function retryFailedImages(): array
    {
        $retried = 0;

        $failedImages = ImageQueue::where('status', 'failed')
            ->where('retry_count', '<', 3)
            ->get();

        foreach ($failedImages as $imageQueue) {
            if ($imageQueue->canRetry()) {
                $imageQueue->resetForRetry();
                $retried++;
            }
        }

        return ['retried' => $retried];
    }

    public function cleanupOldCompletedRecords(int $daysOld = 7): int
    {
        $deleted = ImageQueue::where('status', 'completed')
            ->where('processed_at', '<', now()->subDays($daysOld))
            ->delete();

        Log::info("Cleaned up {$deleted} old completed image queue records");

        return $deleted;
    }

    public function getQueueStats(): array
    {
        return [
            'pending' => ImageQueue::where('status', 'pending')->count(),
            'processing' => ImageQueue::where('status', 'processing')->count(),
            'completed' => ImageQueue::where('status', 'completed')->count(),
            'failed' => ImageQueue::where('status', 'failed')->count(),
            'total' => ImageQueue::count(),
        ];
    }
}
