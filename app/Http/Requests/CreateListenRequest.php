<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ListenEndReason;
use App\Enums\ListenOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CreateListenRequest extends FormRequest
{
    /**
     * Slack for a listened time a little over the wall-clock duration
     * (timer granularity, rounding on the client).
     */
    private const int LISTENED_SLACK_SECONDS = 5;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'youtube_video_id' => ['required', 'string', 'max:11', 'regex:/^[A-Za-z0-9_-]+$/'],
            'title' => ['required', 'string', 'max:255'],
            'artists' => ['required', 'string', 'max:255'],
            'youtube_playlist_id' => ['nullable', 'string', 'max:64'],
            'origin' => ['required', Rule::enum(ListenOrigin::class)],
            'end_reason' => ['required', Rule::enum(ListenEndReason::class)],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after_or_equal:started_at', 'before_or_equal:'.now()->addMinute()->toIso8601String()],
            'position_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'listened_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['started_at', 'ended_at', 'listened_seconds'])) {
                    return;
                }

                $lasted = CarbonImmutable::parse($this->string('started_at')->toString())
                    ->diffInSeconds(CarbonImmutable::parse($this->string('ended_at')->toString()));

                if ($this->integer('listened_seconds') > $lasted + self::LISTENED_SLACK_SECONDS) {
                    $validator->errors()->add('listened_seconds', __('The listened time is longer than the listen itself.'));
                }
            },
        ];
    }
}
