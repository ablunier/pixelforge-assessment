<?php

namespace App\Console\Commands;

use App\Actions\CreateGeneration;
use App\Actions\TopUp;
use App\Enums\Quality;
use App\Enums\Size;
use App\Generations\GenerationParameters;
use App\Models\User;
use App\Money\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Sets up the reviewers' account from config('reviewer'). The password is
 * never committed: it comes from REVIEWER_PASSWORD, or is generated and
 * printed once when the account is first created.
 *
 * The reviewer is an admin, so they can see the admin report, and is topped
 * up to a Balance of CREDITS: enough for the examples plus one Generation of
 * every quality and size. A reviewer whose Balance already reaches CREDITS
 * gets no Top-up, so running this again adds nothing until they've spent some.
 *
 * With --examples it also presses Generate once per Pixelforge sandbox
 * keyword, through the real pipeline, so the reviewer's history holds real
 * Generations whose Prices match Pixelforge's own records.
 */
#[Signature('reviewer:create {--examples : Also submit one Generation per Pixelforge sandbox keyword}')]
#[Description('Create or update the reviewer account')]
class CreateReviewer extends Command
{
    /**
     * One example prompt per sandbox keyword, keyed by the keyword.
     *
     * @var array<string, string>
     */
    public const array EXAMPLE_PROMPTS = [
        '[succeed]' => 'A lighthouse on a cliff at dawn, oil painting [succeed]',
        '[fail]' => 'A fox reading a newspaper in a café, watercolour [fail]',
        '[slow]' => 'A busy harbour at night under a full moon, ink drawing [slow]',
        '[duplicate]' => 'A hot-air balloon over lavender fields, pastel [duplicate]',
        '[blocked]' => 'A street market in the rain, photograph [blocked]',
        '[overloaded]' => 'A mountain cabin in deep snow, woodcut [overloaded]',
    ];

    /** The Balance the reviewer is topped up to, in dollars. */
    public const string CREDITS = '10';

    public function handle(CreateGeneration $createGeneration, TopUp $topUp): int
    {
        $reviewer = $this->createOrUpdateReviewer();
        $this->topUpToCredits($reviewer, $topUp);

        if ($this->option('examples')) {
            $this->submitMissingExamples($reviewer, $createGeneration);
        }

        return self::SUCCESS;
    }

    private function createOrUpdateReviewer(): User
    {
        $email = (string) config('reviewer.email');
        $configuredPassword = config('reviewer.password');
        $generatedPassword = null;

        $reviewer = User::query()->firstOrNew(['email' => $email]);
        $reviewer->name = (string) config('reviewer.name');
        $reviewer->is_admin = true;

        if (is_string($configuredPassword) && $configuredPassword !== '') {
            $reviewer->password = $configuredPassword;
        } elseif (! $reviewer->exists) {
            $reviewer->password = $generatedPassword = Str::password(24, symbols: false);
        }

        $reviewer->save();

        $this->components->info("Reviewer account ready: {$email}");

        if ($generatedPassword !== null) {
            $this->line("Password: {$generatedPassword}");
            $this->components->warn('Shown once. Share it privately; it is not stored anywhere else.');
        }

        return $reviewer;
    }

    /**
     * Top the reviewer up by whatever their Balance lacks of CREDITS.
     */
    private function topUpToCredits(User $reviewer, TopUp $topUp): void
    {
        $shortfall = Money::fromDecimal(self::CREDITS)->minus($reviewer->balance());

        if ($shortfall->isGreaterThan(Money::ofNanodollars(0))) {
            $topUp->handle($reviewer, $shortfall);
        }

        $this->components->twoColumnDetail('Balance', '$'.$reviewer->balance()->formatForDisplay());
    }

    /**
     * Press Generate for each example the reviewer doesn't have yet, so
     * running this again never spends on duplicates.
     */
    private function submitMissingExamples(User $reviewer, CreateGeneration $createGeneration): void
    {
        foreach (self::EXAMPLE_PROMPTS as $keyword => $prompt) {
            if ($reviewer->generations()->where('prompt', $prompt)->exists()) {
                $this->components->twoColumnDetail($keyword, 'already submitted');

                continue;
            }

            $createGeneration->handle($reviewer, new GenerationParameters($prompt, Size::Square, Quality::Low, []));
            $this->components->twoColumnDetail($keyword, 'submitted');
        }
    }
}
