<?php

namespace App\Http\Requests;

use App\Reports\DateRange;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The admin report's date range: UTC days `from` to `to`, inclusive. Each
 * defaults so the range is the last DEFAULT_DAYS days. It ends today at the
 * latest, since nothing has been billed for a later day, and it can span at
 * most MAX_DAYS, the most Pixelforge's usage report covers.
 */
class AdminReportRequest extends FormRequest
{
    public const int DEFAULT_DAYS = 7;

    public const int MAX_DAYS = 92;

    private const string DATE_FORMAT = 'Y-m-d';

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:'.self::DATE_FORMAT],
            'to' => ['nullable', 'date_format:'.self::DATE_FORMAT],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                [$from, $to] = $this->bounds();

                if ($to->isAfter(CarbonImmutable::now('UTC')->startOfDay())) {
                    $validator->errors()->add('to', 'The end date can\'t be after today (UTC).');
                } elseif ($to->isBefore($from)) {
                    $validator->errors()->add('to', 'The end date must be on or after the start date.');
                } elseif ((new DateRange($from, $to))->dayCount() > self::MAX_DAYS) {
                    $validator->errors()->add('to', 'Choose a range of at most '.self::MAX_DAYS.' days.');
                }
            },
        ];
    }

    public function range(): DateRange
    {
        return new DateRange(...$this->bounds());
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function bounds(): array
    {
        $to = $this->dateOrNull('to') ?? CarbonImmutable::now('UTC');
        $from = $this->dateOrNull('from') ?? DateRange::endingOn($to, self::DEFAULT_DAYS)->from;

        return [$from->startOfDay(), $to->startOfDay()];
    }

    private function dateOrNull(string $key): ?CarbonImmutable
    {
        $value = $this->input($key);

        return is_string($value) && $value !== ''
            ? CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, $value, 'UTC') ?: null
            : null;
    }
}
