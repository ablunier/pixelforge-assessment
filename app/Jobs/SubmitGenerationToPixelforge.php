<?php

namespace App\Jobs;

use App\Actions\GenerationLifecycle;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\Answer;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeError;
use App\Pixelforge\Refusal;
use App\Pixelforge\RetryIn;
use App\Pixelforge\UnexpectedPixelforgeResponse;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Sends a `pending` Generation to Pixelforge and applies the `202` response
 * (ADR-0003). The local id is the Idempotency-Key, so running this again for
 * the same Generation never creates a second one at Pixelforge.
 *
 * Every answer is recorded through GenerationLifecycle::recordSubmissionAttempt(),
 * and SubmissionRules decides what it means (ADR-0005): this job only sends
 * the request, and queues itself again when told to retry.
 *
 * The attempt budget lives on the Generation, so the queue never retries this
 * job itself ($tries = 1), and at most one is queued per Generation: a retry
 * waiting its turn keeps reconciliation from queueing a second one.
 */
class SubmitGenerationToPixelforge implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Longer than any retry delay and queue wait, should a queued job ever be lost. */
    public int $uniqueFor = 600;

    public function __construct(public Generation $generation) {}

    public function uniqueId(): string
    {
        return $this->generation->id;
    }

    public function handle(PixelforgeClient $pixelforge, GenerationLifecycle $lifecycle): void
    {
        $generation = $this->generation->fresh();

        // Already accepted (e.g. an early webhook) or failed: nothing to submit.
        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        $decision = $lifecycle->recordSubmissionAttempt($generation, $this->submit($generation, $pixelforge, $lifecycle));

        // A fresh job per attempt: the attempt budget lives on the Generation, not on the queue.
        if ($decision->next instanceof RetryIn) {
            self::dispatch($generation)->delay($decision->next->seconds);
        }
    }

    /**
     * Send the create request and apply the `202`, returning Pixelforge's answer.
     */
    private function submit(Generation $generation, PixelforgeClient $pixelforge, GenerationLifecycle $lifecycle): Answer
    {
        try {
            $snapshot = $pixelforge->createGeneration(
                $generation->id,
                $generation->prompt,
                $generation->size,
                $generation->quality,
                $generation->reference_images,
            );
        } catch (PixelforgeError $error) {
            // Our own misconfiguration, not the Generation's fault: report it loudly.
            if ($error->refusal() === Refusal::KeyRefused) {
                report($error);
            }

            return Answer::refused($error->refusal(), $error->errorCode, $error->getMessage(), $error->retryAfter);
        } catch (ConnectionException|UnexpectedPixelforgeResponse $exception) {
            // The response was lost, or Pixelforge accepted the request (and may bill it)
            // in a body this app can't read: it may or may not have the Generation, and
            // resending with the same Idempotency-Key is safe either way.
            if ($exception instanceof UnexpectedPixelforgeResponse) {
                report($exception);
            }

            return Answer::none($exception->getMessage());
        }

        try {
            // Possibly an idempotent replay of an earlier attempt, already final: applied like any snapshot.
            $lifecycle->apply($generation, $snapshot);
        } catch (Throwable $exception) {
            // Pixelforge has the Generation but it couldn't be recorded. Failing it locally
            // would ignore a later `succeeded` webhook and lose its Provider Cost, so this is
            // no answer: resend, within the budget.
            report($exception);

            return Answer::none($exception->getMessage());
        }

        return Answer::accepted();
    }
}
