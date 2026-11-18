<?php

namespace App\Http\Controllers;

use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a Generation's stored image to its owner, inline or as a download.
 */
class GenerationImageController extends Controller
{
    public function show(Generation $generation): StreamedResponse
    {
        return $this->disk()->response($this->imagePath($generation), headers: [
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    public function download(Generation $generation): StreamedResponse
    {
        return $this->disk()->download($this->imagePath($generation), "pixelforge-{$generation->id}.png");
    }

    private function imagePath(Generation $generation): string
    {
        Gate::authorize('view', $generation);

        abort_if($generation->image_path === null, 404);

        return $generation->image_path;
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk(StoreGenerationImage::DISK);
    }
}
