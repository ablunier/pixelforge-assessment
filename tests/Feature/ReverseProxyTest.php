<?php

it('builds https URLs for requests forwarded over HTTPS by the reverse proxy', function () {
    $response = $this->get(route('generate', absolute: false), [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'pixelforge.blunier.me',
        'X-Forwarded-Port' => '443',
    ]);

    $response->assertRedirect('https://pixelforge.blunier.me/login');
});
