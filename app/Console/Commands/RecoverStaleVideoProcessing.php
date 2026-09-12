<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecoverStaleVideoProcessing extends Command
{
    protected $signature = 'videos:recover-stale-processing
                            {--hours=4 : Mark processing older than this many hours as failed}
                            {--queued-hours=24 : Mark queued older than this many hours as failed}';

    protected $description = 'Mark stuck course video processing records as failed so they can be retried';

    public function handle(): int
    {
        $processingHours = max(1, (int) $this->option('hours'));
        $queuedHours = max(1, (int) $this->option('queued-hours'));

        $processingCutoff = now()->subHours($processingHours);
        $queuedCutoff = now()->subHours($queuedHours);

        $staleProcessing = DB::table('videos')
            ->where('type', 'raw')
            ->where('status', 'processing')
            ->where(function ($q) use ($processingCutoff) {
                $q->where('processing_started_at', '<=', $processingCutoff)
                    ->orWhere(function ($q2) use ($processingCutoff) {
                        $q2->whereNull('processing_started_at')
                            ->where('updated_at', '<=', $processingCutoff);
                    });
            })
            ->update([
                'status' => 'failed',
                'error_message' => 'Processing timed out or worker stopped unexpectedly.',
                'updated_at' => now(),
            ]);

        $staleQueued = DB::table('videos')
            ->where('type', 'raw')
            ->where('status', 'queued')
            ->where('updated_at', '<=', $queuedCutoff)
            ->update([
                'status' => 'failed',
                'error_message' => 'Queued for too long without being processed.',
                'updated_at' => now(),
            ]);

        if ($staleProcessing > 0 || $staleQueued > 0) {
            Log::warning('Recovered stale video processing records', [
                'stale_processing' => $staleProcessing,
                'stale_queued' => $staleQueued,
                'processing_hours' => $processingHours,
                'queued_hours' => $queuedHours,
            ]);
        }

        $this->info("Marked {$staleProcessing} processing and {$staleQueued} queued videos as failed.");

        return self::SUCCESS;
    }
}
