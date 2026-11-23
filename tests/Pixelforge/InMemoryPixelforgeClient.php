<?php

namespace Tests\Pixelforge;

use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Money\Money;
use App\Pixelforge\BilledCosts;
use App\Pixelforge\GenerationSnapshot;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeError;
use App\Pixelforge\PixelforgeUnreachable;
use App\Pixelforge\UnexpectedPixelforgeResponse;
use App\Pricing\Rates;
use Carbon\CarbonImmutable;
use Closure;
use InvalidArgumentException;

/**
 * Pixelforge held in memory, for tests. It keeps each Generation it accepts by
 * Idempotency-Key, so creating one twice returns the same Generation and bills
 * it once. Tests script what it answers next and read back what it was asked.
 *
 * It doesn't model the Sandbox's prompt keywords or the account's concurrency
 * limit: script those Refusals like any other.
 *
 * An answer is what Pixelforge reports the Generation as, a Refusal, no answer,
 * or a closure that runs while the request is in flight and returns one of those.
 *
 * @phpstan-type Answer Reported|PixelforgeError|NoAnswer|Closure(): (Reported|PixelforgeError|NoAnswer)
 */
final class InMemoryPixelforgeClient implements PixelforgeClient
{
    /** @var array<string, GenerationSnapshot> Generations by Pixelforge id */
    private array $generations = [];

    /** @var array<string, string> Pixelforge ids by Idempotency-Key */
    private array $idempotencyKeys = [];

    /** @var list<Answer> */
    private array $createAnswers = [];

    /** @var array<string, list<Answer>> by Pixelforge id */
    private array $retrieveAnswers = [];

    /** @var list<Rates|PixelforgeError|NoAnswer> */
    private array $rateAnswers = [];

    /** @var list<string|PixelforgeError|NoAnswer> */
    private array $downloadAnswers = [];

    /** @var list<BilledCosts|PixelforgeError|NoAnswer> */
    private array $usageAnswers = [];

    /** @var list<UsageRequest> */
    private array $usageRequests = [];

    /** @var list<CreateRequest> */
    private array $createRequests = [];

    /** @var list<string> Pixelforge ids */
    private array $retrieved = [];

    private int $rateRequests = 0;

    /** @var list<string> image URLs */
    private array $downloads = [];

    /** The image Pixelforge's image host serves unless told otherwise: a PNG signature and nothing else. */
    public const string PNG = "\x89PNG\r\n\x1a\n";

    /**
     * The rates Pixelforge's docs publish: $5.00, $10.00, $40.00 and $12.50 per
     * million input text, input image, output and reasoning tokens.
     */
    public static function publishedRates(): Rates
    {
        return Rates::fromPixelforge([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);
    }

    /**
     * The `gen_` id Pixelforge gives the Generation it creates for local id `$generationId`.
     */
    public static function pixelforgeIdFor(string $generationId): string
    {
        return "gen_{$generationId}";
    }

    /**
     * Pixelforge refusing a request with `$errorCode`, at the HTTP status its docs give that code.
     */
    public static function refusal(string $errorCode, ?int $retryAfter = null, string $message = 'Refused by Pixelforge.'): PixelforgeError
    {
        $httpStatus = match (true) {
            $errorCode === 'invalid_api_key' => 401,
            $errorCode === 'not_found' => 404,
            in_array($errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'daily_limit_exceeded'], true) => 429,
            $errorCode === 'internal_error' => 500,
            $errorCode === 'overloaded' => 503,
            str_starts_with($errorCode, 'http_') => (int) substr($errorCode, 5),
            default => 400,
        };

        return new PixelforgeError($errorCode, $message, $httpStatus, $retryAfter);
    }

    /**
     * What the next create requests get, in order. After these, Pixelforge
     * accepts a new Generation as `queued` and replays one it already has.
     *
     * @param  Answer  ...$answers
     */
    public function answerCreates(Reported|PixelforgeError|NoAnswer|Closure ...$answers): void
    {
        array_push($this->createAnswers, ...$answers);
    }

    /**
     * What the next retrieves of `$generation` get, in order. After these,
     * Pixelforge reports it as it last stood. Pixelforge holds `$generation`
     * from now on, as `queued` if it didn't already.
     *
     * @param  Answer  ...$answers
     */
    public function answerRetrieves(Generation $generation, Reported|PixelforgeError|NoAnswer|Closure ...$answers): void
    {
        $pixelforgeId = $generation->pixelforge_id ?? $this->idempotencyKeys[$generation->id] ?? self::pixelforgeIdFor($generation->id);

        $this->generations[$pixelforgeId] ??= Reported::queued()->snapshot($pixelforgeId, $generation->id);
        $this->idempotencyKeys[$generation->id] ??= $pixelforgeId;
        $this->retrieveAnswers[$pixelforgeId] ??= [];
        array_push($this->retrieveAnswers[$pixelforgeId], ...$answers);
    }

    /**
     * What the next requests for rates get, in order. After these, Pixelforge
     * answers with its published rates.
     */
    public function answerRates(Rates|PixelforgeError|NoAnswer ...$answers): void
    {
        array_push($this->rateAnswers, ...$answers);
    }

    /**
     * What the next usage requests get, in order. After these, Pixelforge
     * reports nothing billed on any day.
     */
    public function answerUsage(BilledCosts|PixelforgeError|NoAnswer ...$answers): void
    {
        array_push($this->usageAnswers, ...$answers);
    }

    /**
     * Pixelforge reporting it billed `$byDate` (nanodollars by `Y-m-d`) and,
     * unless told otherwise, their sum in total.
     *
     * @param  array<string, int>  $byDate
     */
    public static function billed(array $byDate, ?int $total = null): BilledCosts
    {
        return new BilledCosts(
            array_map(Money::ofNanodollars(...), $byDate),
            Money::ofNanodollars($total ?? array_sum($byDate)),
        );
    }

    /**
     * What the next image downloads get, in order: the bytes served (which
     * needn't be a PNG), an error status from the image host as a
     * PixelforgeError, or no answer. After these, the image host serves PNG at
     * any URL. A download has no response to misread, so NoAnswer::Unreadable
     * isn't an answer it can get.
     */
    public function answerDownloads(string|PixelforgeError|NoAnswer ...$answers): void
    {
        if (in_array(NoAnswer::Unreadable, $answers, true)) {
            throw new InvalidArgumentException('An image download gets bytes, an error status or no answer, never an unreadable response.');
        }

        array_push($this->downloadAnswers, ...$answers);
    }

    /**
     * @return list<CreateRequest>
     */
    public function createRequests(): array
    {
        return $this->createRequests;
    }

    public function retrievesOf(Generation $generation): int
    {
        $pixelforgeId = $generation->pixelforge_id ?? $this->idempotencyKeys[$generation->id] ?? null;

        return count(array_keys($this->retrieved, $pixelforgeId, true));
    }

    public function retrieves(): int
    {
        return count($this->retrieved);
    }

    /**
     * The image URLs downloaded, in order.
     *
     * @return list<string>
     */
    public function downloads(): array
    {
        return $this->downloads;
    }

    /**
     * @return list<UsageRequest>
     */
    public function usageRequests(): array
    {
        return $this->usageRequests;
    }

    public function rateRequests(): int
    {
        return $this->rateRequests;
    }

    /**
     * How many Generations Pixelforge holds, and so may bill.
     */
    public function heldGenerations(): int
    {
        return count($this->generations);
    }

    public function rates(): Rates
    {
        $this->rateRequests++;
        $answer = array_shift($this->rateAnswers) ?? self::publishedRates();

        if ($answer instanceof Rates) {
            return $answer;
        }

        throw self::failure($answer);
    }

    public function billedCosts(CarbonImmutable $startDate, CarbonImmutable $endDate): BilledCosts
    {
        $this->usageRequests[] = new UsageRequest($startDate->toDateString(), $endDate->toDateString());
        $answer = array_shift($this->usageAnswers);

        if ($answer === null) {
            $days = [];
            for ($day = $startDate; ! $day->isAfter($endDate); $day = $day->addDay()) {
                $days[$day->toDateString()] = 0;
            }

            return self::billed($days);
        }

        return $answer instanceof BilledCosts ? $answer : throw self::failure($answer);
    }

    public function createGeneration(string $generationId, string $prompt, Size $size, Quality $quality, array $referenceImages): GenerationSnapshot
    {
        $this->createRequests[] = new CreateRequest($generationId, $prompt, $size, $quality, $referenceImages);
        $answer = self::resolve(array_shift($this->createAnswers));

        if ($answer instanceof PixelforgeError || $answer === NoAnswer::Unreachable) {
            throw self::failure($answer);
        }

        $pixelforgeId = $this->idempotencyKeys[$generationId] ?? self::pixelforgeIdFor($generationId);
        $held = $this->generations[$pixelforgeId] ?? null;
        $reported = $answer instanceof Reported ? $answer : ($held === null ? Reported::queued() : null);

        $this->idempotencyKeys[$generationId] = $pixelforgeId;
        $this->generations[$pixelforgeId] = $reported?->snapshot($pixelforgeId, $generationId, $held?->rates) ?? $held;

        return $answer instanceof NoAnswer ? throw self::failure($answer) : $this->generations[$pixelforgeId];
    }

    public function retrieveGeneration(string $pixelforgeId): GenerationSnapshot
    {
        $this->retrieved[] = $pixelforgeId;
        $this->retrieveAnswers[$pixelforgeId] ??= [];
        $answer = self::resolve(array_shift($this->retrieveAnswers[$pixelforgeId]));

        if ($answer instanceof PixelforgeError || $answer === NoAnswer::Unreachable) {
            throw self::failure($answer);
        }

        $held = $this->generations[$pixelforgeId] ?? throw self::refusal('not_found', message: 'No such generation.');

        if ($answer instanceof Reported) {
            $this->generations[$pixelforgeId] = $answer->snapshot($pixelforgeId, $held->generationId, $held->rates);
        }

        return $answer instanceof NoAnswer ? throw self::failure($answer) : $this->generations[$pixelforgeId];
    }

    public function downloadImage(string $url): string
    {
        $this->downloads[] = $url;
        $answer = array_shift($this->downloadAnswers) ?? self::PNG;

        return is_string($answer) ? $answer : throw self::failure($answer);
    }

    /**
     * @param  Answer|null  $answer
     */
    private static function resolve(Reported|PixelforgeError|NoAnswer|Closure|null $answer): Reported|PixelforgeError|NoAnswer|null
    {
        return $answer instanceof Closure ? $answer() : $answer;
    }

    private static function failure(PixelforgeError|NoAnswer $answer): PixelforgeError|PixelforgeUnreachable|UnexpectedPixelforgeResponse
    {
        return match ($answer) {
            NoAnswer::Unreachable, NoAnswer::ResponseLost => new PixelforgeUnreachable("Pixelforge can't be reached: operation timed out."),
            NoAnswer::Unreadable => new UnexpectedPixelforgeResponse("Pixelforge sent a response this app can't read."),
            default => $answer,
        };
    }
}
