<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CommunityQuestion;
use App\Services\Community\QuestionToPeople;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tell the asker's people about a question that was just published.
 *
 * Queued from `CommunityQuestion::publish()`, which both the triage job and
 * the admin's Publish button call, so every way a question reaches the board
 * reaches this too, and no way that stops short of the board does. The
 * deciding is in {@see QuestionToPeople}.
 */
class SendQuestionToPeople implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $questionId) {}

    public function handle(QuestionToPeople $people): void
    {
        $question = CommunityQuestion::query()->find($this->questionId);

        if ($question !== null) {
            $people->send($question);
        }
    }
}
