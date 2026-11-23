<?php

use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\SubmissionRules;

it('resubmits a pending Generation only after its submit job\'s longest retry delay has passed', function () {
    expect(SubmissionRules::MAX_RETRY_DELAY_SECONDS)->toBeLessThan(Generation::PENDING_STALE_AFTER_SECONDS);
});

it('downloads an image again only after StoreGenerationImage has had all its tries', function () {
    $job = new StoreGenerationImage(new Generation);
    $wholeRunSeconds = array_sum($job->backoff()) + $job->tries * PixelforgeClient::IMAGE_DOWNLOAD_TIMEOUT_SECONDS;

    expect($wholeRunSeconds)->toBeLessThan(Generation::IMAGE_RETRY_AFTER_MINUTES * 60);
});
