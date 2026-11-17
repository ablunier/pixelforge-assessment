<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.pixelforge.base_url' => 'https://pixelforge.test/v1']);
});

it('redirects guests to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('gives the generate form the current rates and the server-computed Price Range', function () {
    fakePixelforgePricing();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            // Nanodollars per token
            ->where('pricing.rates', [
                'input_text_tokens' => 5_000,
                'input_image_tokens' => 10_000,
                'output_tokens' => 40_000,
                'reasoning_tokens' => 12_500,
            ])
            ->where('pricing.tokens.output.high.1536x1024', 6_450)
            ->where('pricing.tokens.reasoning.medium', ['min' => 200, 'max' => 2_000])
            // The default form (empty prompt, 1024x1024, medium, no references):
            // 1,100 output and 200 to 2,000 reasoning tokens, plus Markup.
            ->where('priceRange', ['min' => 58_125_000, 'max' => 86_250_000])
        );
});

it('asks Pixelforge for its rates without authentication details leaking into the page', function () {
    config(['services.pixelforge.api_key' => 'pf_test_secret']);
    fakePixelforgePricing();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('pf_test_secret');

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://pixelforge.test/v1/pricing');
});

it('caches the rates for about an hour', function () {
    fakePixelforgePricing();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->travel(59)->minutes();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    Http::assertSentCount(1);

    $this->travel(2)->minutes();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    Http::assertSentCount(2);
});

/**
 * Live validation, as the form sends it: a precognitive request validating every field.
 *
 * @param  array<string, mixed>  $overrides
 */
function validateGenerateForm(array $overrides): TestResponse
{
    return test()->actingAs(User::factory()->create())
        ->withHeaders(['Precognition' => 'true'])
        ->postJson(route('generations.store'), array_merge([
            'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ], $overrides));
}

it('accepts a valid generate form without creating anything', function (array $input) {
    validateGenerateForm($input)
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');
})->with([
    'defaults' => [[]],
    'every option' => [[
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'http://example.com/b.png', 'https://example.com/c.png', 'https://example.com/d.png'],
    ]],
    '4,000 multibyte characters' => [['prompt' => str_repeat('🌅', 4_000)]],
]);

it('rejects an invalid generate form field by field', function (array $input, string $field) {
    validateGenerateForm($input)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors([$field]);
})->with([
    'empty prompt' => [['prompt' => ''], 'prompt'],
    'prompt over 4,000 characters' => [['prompt' => str_repeat('a', 4_001)], 'prompt'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'more than 4 references' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'reference that is not a URL' => [['reference_images' => ['not a url']], 'reference_images.0'],
    'reference that is not http(s)' => [['reference_images' => ['https://example.com/a.png', 'ftp://example.com/b.png']], 'reference_images.1'],
    'blank reference' => [['reference_images' => ['']], 'reference_images.0'],
]);

it('does not let guests validate the generate form', function () {
    $this->postJson(route('generations.store'), [])->assertUnauthorized();
});
