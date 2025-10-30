<?php 
namespace App\Jobs;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use FFMpeg\Format\Video\X264;

class ProcessVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Video $video;
    public $timeout = 7200;

    public function __construct(Video $video)
    {
        $this->video = $video;

    }

    public function handle(): void
    {
        Log::info('ProcessVideo started', [
            'video_id' => $this->video->id,
            'queue_connection' => config('queue.default'),
        ]);

        // Update status to processing
        try {
            DB::table('videos')
                ->where('id', $this->video->id)
                ->update(['status' => 'processing', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('Failed to update video status to processing', ['video_id' => $this->video->id, 'error' => $e->getMessage()]);
        }
        $disk = $this->video->disk;
        $rawPath = $this->video->path;
        $videoableType = $this->video->videoable_type;
        $videoableId = $this->video->videoable_id;

        // Ensure duration is set (compute if missing)
        if ((int)($this->video->duration ?? 0) <= 0) {
            try {
                $computed = (int) round(FFMpeg::fromDisk($disk)->open($rawPath)->getDurationInSeconds());
                if ($computed > 0) {
                    DB::table('videos')->where('id', $this->video->id)->update(['duration' => $computed, 'updated_at' => now()]);
                    $this->video->duration = $computed;
                }
            } catch (\Throwable $e) {
                // ignore; derived records will still be created
            }
        }

        $availableQualities = $this->detectAvailableQualities($disk, $rawPath);
        if (empty($availableQualities)) {
            $availableQualities = [720, 480, 360];
        }

        if ($videoableType === 'App\\Models\\Course') {
            $course = DB::table('courses')->select('slug')->where('id', $videoableId)->first();
            if (!$course) return;
            $slug = $course->slug;
            $trailerPath = "trailer/{$slug}";
            $this->processTrailer($disk, $rawPath, $trailerPath, $availableQualities);
        } elseif ($videoableType === 'App\\Models\\Episode') {
            $row = DB::table('episodes')
                ->join('sections', 'episodes.section_id', '=', 'sections.id')
                ->join('courses', 'sections.course_id', '=', 'courses.id')
                ->where('episodes.id', $videoableId)
                ->select('courses.slug as course_slug', 'episodes.id as episode_id')
                ->first();
            if (!$row) return;
            $courseSlug = $row->course_slug;
            $episodeId = $row->episode_id;

            // match backend paths
            $streamPath = "stream/{$courseSlug}/{$episodeId}";
            $downloadBase = "download/{$courseSlug}/{$episodeId}";

            $this->processStream($disk, $rawPath, $streamPath, $availableQualities);
            foreach ($availableQualities as $quality) {
                $this->processDownload($disk, $rawPath, "$downloadBase/{$quality}p", $quality);
            }
        }

        // Mark processed when outputs are saved
        try {
            DB::table('videos')
                ->where('id', $this->video->id)
                ->update(['status' => 'processed', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('Failed to update video status to processed', ['video_id' => $this->video->id, 'error' => $e->getMessage()]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        try {
            DB::table('videos')
                ->where('id', $this->video->id)
                ->update(['status' => 'failed', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('Failed to update video status to failed', ['video_id' => $this->video->id, 'error' => $e->getMessage()]);
        }
        Log::error('ProcessVideo failed', ['video_id' => $this->video->id, 'error' => $exception->getMessage()]);
    }

    private function detectAvailableQualities(string $disk, string $inputPath): array
    {
        try {
            $media = FFMpeg::fromDisk($disk)->open($inputPath);
            $dimensions = $media->getVideoStream()->getDimensions();
            $maxHeight = $dimensions->getHeight();
            return collect([1080, 720, 480, 360, 240, 144])
                ->filter(fn ($q) => $q <= $maxHeight)
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [720, 480, 360];
        }
    }

    private function processTrailer(string $disk, string $inputPath, string $outputPath, array $qualities): void
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '1800');

        $hlsPath = $outputPath;
        $masterPlaylistPath = "{$hlsPath}/playlist.m3u8";

        $exporter = FFMpeg::fromDisk($disk)
            ->open($inputPath)
            ->exportForHLS()
            ->toDisk($disk)
            ->setSegmentLength(10);

        foreach ($qualities as $quality) {
            $bitrate = $this->bitrateFor($quality);
            [$width, $height] = $this->dimensionsFor($quality);
            $format = (new X264('aac'))->setKiloBitrate($bitrate);
            $exporter->addFormat($format, function ($media) use ($width, $height) {
                $media->scale($width, $height);
            });
        }

        $exporter->save($masterPlaylistPath);

        $this->storeVideoRecord('trailer', null, $masterPlaylistPath);
    }

    private function processStream(string $disk, string $inputPath, string $outputPath, array $qualities): void
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '1800');

        $hlsPath = $outputPath;
        $masterPlaylistPath = "{$hlsPath}/playlist.m3u8";

        $exporter = FFMpeg::fromDisk($disk)
            ->open($inputPath)
            ->exportForHLS()
            ->toDisk($disk)
            ->setSegmentLength(10)
            ->withRotatingEncryptionKey(function ($filename, $contents) use ($disk, $hlsPath) {
                Storage::disk($disk)->put("{$hlsPath}/keys/{$filename}", $contents);
            });

        foreach ($qualities as $quality) {
            $bitrate = $this->bitrateFor($quality);
            [$width, $height] = $this->dimensionsFor($quality);
            $format = (new X264('aac'))->setKiloBitrate($bitrate);
            $exporter->addFormat($format, function ($media) use ($width, $height) {
                $media->scale($width, $height);
            });
        }

        $exporter->save($masterPlaylistPath);
        $this->storeVideoRecord('stream', null, $masterPlaylistPath);
    }

    private function processDownload(string $disk, string $inputPath, string $outputPath, int $quality): void
    {
        $storedPath = "$outputPath/video-{$quality}p.mp4";
        $bitrate = $this->bitrateFor($quality);

        FFMpeg::fromDisk($disk)
            ->open($inputPath)
            ->export()
            ->toDisk($disk)
            ->inFormat((new X264('aac'))->setKiloBitrate($bitrate))
            ->save($storedPath);

        $this->storeVideoRecord('download', $quality, $storedPath);
    }

    private function bitrateFor(int $quality): int
    {
        return match ($quality) {
            144 => 200,
            240 => 300,
            360 => 500,
            480 => 1000,
            720 => 1500,
            1080 => 3000,
            default => 1500,
        };
    }

    private function dimensionsFor(int $quality): array
    {
        return match ($quality) {
            144 => [256, 144],
            240 => [426, 240],
            360 => [640, 360],
            480 => [854, 480],
            720 => [1280, 720],
            1080 => [1920, 1080],
            default => [1280, 720],
        };
    }

    private function storeVideoRecord(string $type, ?int $quality, string $path): void
    {
        DB::table('videos')->insert([
            'videoable_id' => $this->video->videoable_id,
            'videoable_type' => $this->video->videoable_type,
            'disk' => $this->video->disk,
            'type' => $type,
            'path' => $path,
            'duration' => $this->video->duration,
            'quality' => $quality,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}