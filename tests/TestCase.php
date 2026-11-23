<?php

namespace Tests;

use App\Jobs\StoreGenerationImage;
use App\Pixelforge\CachingPixelforgeClient;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Features;
use Tests\Pixelforge\InMemoryPixelforgeClient;

abstract class TestCase extends BaseTestCase
{
    private InMemoryPixelforgeClient $pixelforge;

    protected function setUp(): void
    {
        parent::setUp();

        // Tests never call the real Pixelforge API: Pixelforge is held in memory,
        // behind the same rate caching as in production, and any other request must be faked.
        $this->pixelforge = new InMemoryPixelforgeClient;
        $this->app->instance(PixelforgeClient::class, new CachingPixelforgeClient($this->pixelforge, $this->app->make(Cache::class)));
        Http::preventStrayRequests();

        // A succeeded Generation's image is downloaded to this disk.
        Storage::fake(StoreGenerationImage::DISK);
    }

    /**
     * The in-memory Pixelforge this test's app talks to.
     */
    public function pixelforge(): InMemoryPixelforgeClient
    {
        return $this->pixelforge;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
