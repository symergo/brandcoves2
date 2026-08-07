<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JobStatus;
use App\Enums\Market;
use App\Enums\Source;
use Illuminate\Database\Eloquent\Model;

/**
 * Resumable cursor state for chunked ingestion.
 *
 * A feed can run to hundreds of megabytes and tens of thousands of rows. It
 * cannot be done in one pass, and a redeploy mid-run must not lose the work —
 * so each job records where it got to and resumes from there.
 */
class IngestionJob extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => Source::class,
            'market' => Market::class,
            'status' => JobStatus::class,
            'cursor' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function progressPercent(): ?int
    {
        if ($this->total === null || $this->total <= 0) {
            return null;
        }

        return (int) min(100, round(($this->processed / $this->total) * 100));
    }

    public function markRunning(): void
    {
        $this->update([
            'status' => JobStatus::Running,
            'started_at' => now(),
            'finished_at' => null,
            'last_error' => null,
            'attempts' => $this->attempts + 1,
        ]);
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => JobStatus::Completed,
            'finished_at' => now(),
            'cursor' => null,
            'last_error' => null,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => JobStatus::Failed,
            'finished_at' => now(),
            // Truncated: a stack trace in a status column makes the admin table
            // unreadable, and the full trace is already in the log.
            'last_error' => mb_substr($error, 0, 500),
        ]);
    }
}
