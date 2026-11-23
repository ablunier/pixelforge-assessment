<?php

use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PricingUnavailable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Pixelforge\InMemoryPixelforgeClient;
use Tests\Pixelforge\NoAnswer;

it('redirects guests to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('gives the generate form the current rates and the server-computed Price Range', function () {
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
            ->where('pricing.markup', ['numerator' => 5, 'denominator' => 4])
            // The default form (empty prompt, 1024x1024, medium, no references):
            // 1,100 output and 200 to 2,000 reasoning tokens, plus Markup.
            ->where('priceRange', ['min' => 58_125_000, 'max' => 86_250_000])
        );
});

it('keeps the Pixelforge API key out of the page props', function () {
    config(['services.pixelforge.api_key' => 'pf_test_secret']);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('pf_test_secret');
});

it('still shows the history, with no pricing, when Pixelforge can\'t quote rates and none are known', function () {
    Exceptions::fake();
    pixelforge()->answerRates(InMemoryPixelforgeClient::refusal('overloaded'));
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pricing', null)
            ->where('priceRange', null)
            ->has('generations', 1)
        );

    Exceptions::assertReported(PricingUnavailable::class);
});

it('prices with the last rates Pixelforge quoted while it can\'t quote them', function () {
    Exceptions::fake();
    pixelforge()->answerRates(InMemoryPixelforgeClient::publishedRates(), InMemoryPixelforgeClient::refusal('http_502'));
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->travel(61)->minutes();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pricing.rates.output_tokens', 40_000)
            ->where('priceRange', ['min' => 58_125_000, 'max' => 86_250_000])
        );
});

it('never caches rates it can\'t read, so the next page asks again', function () {
    Exceptions::fake();
    pixelforge()->answerRates(NoAnswer::Unreadable);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('pricing', null));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('pricing.rates.output_tokens', 40_000));
});

it('submits nothing and says why when there are no rates to price a Generation with', function () {
    Exceptions::fake();
    Queue::fake();
    pixelforge()->answerRates(NoAnswer::Unreachable);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('generations.store'), [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
        ])
        ->assertSessionHasErrors(['generate' => "Prices can't be loaded right now, so nothing was submitted. Try again in a minute."]);

    expect($user->generations()->count())->toBe(0);
    Queue::assertNothingPushed();
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
