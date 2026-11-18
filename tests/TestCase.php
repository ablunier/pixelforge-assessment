<?php

namespace Tests;

use App\Jobs\StoreGenerationImage;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests never call the real Pixelforge API: every request must be faked.
        Http::preventStrayRequests();

        // A succeeded Generation's image is downloaded to this disk. Tests that
        // need the download to behave differently use another image URL.
        Storage::fake(StoreGenerationImage::DISK);
        Http::fake([
            'pixelforge.promptedit.dev/images/*' => Http::response("\x89PNG\r\n\x1a\n", 200, ['Content-Type' => 'image/png']),
        ]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
