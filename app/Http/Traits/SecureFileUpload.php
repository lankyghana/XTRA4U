<?php

namespace App\Http\Traits;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait SecureFileUpload
{
    /**
     * Allowed image MIME types
     */
    protected array $allowedImageMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * Allowed image extensions
     */
    protected array $allowedImageExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'webp'
    ];

    /**
     * Maximum file size in bytes (2MB default)
     */
    protected int $maxFileSize = 2097152; // 2MB

    /**
     * Securely upload an image file
     * 
     * @param UploadedFile $file
     * @param string $directory
     * @param string $disk
     * @return string|null Path to stored file or null if validation fails
     */
    protected function secureImageUpload(
        UploadedFile $file, 
        string $directory = 'uploads', 
        string $disk = 'public'
    ): ?string {
        // Validate file exists and is valid
        if (!$file->isValid()) {
            return null;
        }

        // Check file size
        if ($file->getSize() > $this->maxFileSize) {
            return null;
        }

        // Validate MIME type by reading file contents (not just extension)
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, $this->allowedImageMimeTypes)) {
            return null;
        }

        // Double-check extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $this->allowedImageExtensions)) {
            return null;
        }

        // Verify it's actually an image by checking headers
        if (!$this->isValidImage($file)) {
            return null;
        }

        // Generate a safe filename (prevent directory traversal)
        $safeFilename = $this->generateSafeFilename($extension);

        // Store the file securely
        return $file->storeAs($directory, $safeFilename, $disk);
    }

    /**
     * Verify the file is actually an image by checking its contents
     */
    protected function isValidImage(UploadedFile $file): bool
    {
        try {
            // Use getimagesize to verify it's a real image
            $imageInfo = @getimagesize($file->getPathname());
            
            if ($imageInfo === false) {
                return false;
            }

            // Check if the image type matches allowed types
            $allowedTypes = [
                IMAGETYPE_JPEG,
                IMAGETYPE_PNG,
                IMAGETYPE_GIF,
                IMAGETYPE_WEBP,
            ];

            return in_array($imageInfo[2], $allowedTypes);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate a safe, random filename to prevent overwrites and path traversal
     */
    protected function generateSafeFilename(string $extension): string
    {
        return Str::uuid() . '.' . $extension;
    }

    /**
     * Safely delete a file
     */
    protected function secureFileDelete(?string $path, string $disk = 'public'): bool
    {
        if (empty($path)) {
            return true;
        }

        // Prevent directory traversal in delete operations
        $path = basename($path);
        
        if (Storage::disk($disk)->exists($path)) {
            return Storage::disk($disk)->delete($path);
        }

        return true;
    }

    /**
     * Get file upload validation rules for images
     */
    protected function imageValidationRules(bool $required = false): array
    {
        $rules = ['image', 'mimes:jpeg,jpg,png,gif,webp', 'max:2048'];
        
        if ($required) {
            array_unshift($rules, 'required');
        } else {
            array_unshift($rules, 'nullable');
        }

        return $rules;
    }
}
