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

        $sourceBitrateKbps = $this->probeSourceBitrateKbps($disk, $inputPath);

        foreach ($qualities as $quality) {
            [$width, $height] = $this->dimensionsFor($quality);
            $format = $this->makeVideoFormatForQuality($quality, $sourceBitrateKbps);
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

        $sourceBitrateKbps = $this->probeSourceBitrateKbps($disk, $inputPath);
        $variantEntries = [];

        foreach ($qualities as $quality) {
            [$width, $height] = $this->dimensionsFor($quality);
            $videoFormat = $this->makeVideoFormatForQuality($quality, $sourceBitrateKbps);

            $variantPath = "{$hlsPath}/{$quality}p";
            Storage::disk($disk)->makeDirectory($variantPath);

            $exporter = FFMpeg::fromDisk($disk)
                ->open($inputPath)
                ->exportForHLS()
                ->toDisk($disk)
                ->setSegmentLength(6)
                // Use rotating API with a huge segmentsPerKey so effectively one key per quality,
                // and store the key alongside the variant playlist
                ->withRotatingEncryptionKey(function ($filename, $contents) use ($disk, $variantPath) {
                    Storage::disk($disk)->put("{$variantPath}/{$filename}", $contents);
                }, 1000000);

            $exporter->addFormat($videoFormat, function ($media) use ($width, $height) {
                $media->scale($width, $height);
            });

            $variantPlaylistPath = "{$variantPath}/playlist.m3u8";
            $exporter->save($variantPlaylistPath);

            $videoBitrate = $this->bitrateFor($quality);
            $audioBitrate = $this->audioBitrateFor($quality);
            $bandwidth = (int) (($videoBitrate + $audioBitrate) * 1000); // in bits per second

            $variantEntries[] = [
                'quality' => $quality,
                'resolution' => $width . 'x' . $height,
                'bandwidth' => $bandwidth,
                'uri' => "{$quality}p/playlist.m3u8",
            ];
        }

        $this->writeMasterPlaylist($disk, $hlsPath, $variantEntries);
        $this->storeVideoRecord('stream', null, $masterPlaylistPath);
    }

    private function processDownload(string $disk, string $inputPath, string $outputPath, int $quality): void
    {
        $storedPath = "$outputPath/video-{$quality}p.mp4";
        $bitrate = $this->bitrateFor($quality);
        $audioBitrate = $this->audioBitrateFor($quality);
        [$width, $height] = $this->dimensionsFor($quality);
        $sourceBitrateKbps = $this->probeSourceBitrateKbps($disk, $inputPath);
        if ($sourceBitrateKbps && $bitrate > $sourceBitrateKbps) {
            $bitrate = (int) floor($sourceBitrateKbps * 0.95);
        }

        FFMpeg::fromDisk($disk)
            ->open($inputPath)
            ->export()
            ->toDisk($disk)
            ->addFilter(function ($filters) use ($width, $height) {
                $filters->resize(new \FFMpeg\Coordinate\Dimension($width, $height));
            })
            ->inFormat(
                (new X264('aac', $this->videoEncoder()))
                    ->setKiloBitrate($bitrate)
                    ->setAudioKiloBitrate($audioBitrate)
                    ->setAdditionalParameters(array_merge(
                        $this->x264CommonParams($quality, $bitrate),
                        ['-movflags', '+faststart']
                    ))
            )
            ->save($storedPath);

        $this->storeVideoRecord('download', $quality, $storedPath);
    }

    private function bitrateFor(int $quality): int
    {
        return match ($quality) {
            144 => 200,
            240 => 300,
            360 => 500,
            480 => 800,
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

    private function writeMasterPlaylist(string $disk, string $hlsPath, array $variants): void
    {
        $lines = [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
        ];

        foreach ($variants as $v) {
            $lines[] = "#EXT-X-STREAM-INF:BANDWIDTH={$v['bandwidth']},RESOLUTION={$v['resolution']}";
            $lines[] = $v['uri'];
        }

        $content = implode("\n", $lines) . "\n";
        Storage::disk($disk)->put("{$hlsPath}/playlist.m3u8", $content);
    }

    private function makeVideoFormatForQuality(int $quality, ?int $sourceBitrateKbps = null): X264
    {
        $targetVideoBitrate = $this->bitrateFor($quality);
        if ($sourceBitrateKbps && $targetVideoBitrate > $sourceBitrateKbps) {
            $targetVideoBitrate = (int) floor($sourceBitrateKbps * 0.95);
        }

        $audioBitrate = $this->audioBitrateFor($quality);

        return (new X264('aac', $this->videoEncoder()))
            ->setKiloBitrate($targetVideoBitrate)
            ->setAudioKiloBitrate($audioBitrate)
            ->setAdditionalParameters($this->x264CommonParams($quality, $targetVideoBitrate));
    }

    private function x264CommonParams(int $quality, int $videoBitrateKbps): array
    {
        $crf = $this->crfFor($quality);
        $maxrate = (int) round($videoBitrateKbps * 1.3);
        $bufsize = (int) round($videoBitrateKbps * 2.0);
        $preset = env('VIDEO_X264_PRESET', 'faster');

        return [
            '-preset', $preset,
            '-crf', (string) $crf,
            '-maxrate', $maxrate . 'k',
            '-bufsize', $bufsize . 'k',
            '-profile:v', 'high',
            '-level', '4.0',
            '-g', '48',
            '-keyint_min', '48',
            '-sc_threshold', '0',
            '-pix_fmt', 'yuv420p',
        ];
    }

    private function audioBitrateFor(int $quality): int
    {
        return match ($quality) {
            1080, 720 => 128,
            480 => 112,
            default => 96,
        };
    }

    private function crfFor(int $quality): int
    {
        return match ($quality) {
            1080 => 22,
            720 => 22,
            480 => 23,
            360 => 24,
            default => 24,
        };
    }

    private function videoEncoder(): string
    {
        // Set VIDEO_ENCODER=h264_nvenc in env if NVENC is available for higher speed
        return env('VIDEO_ENCODER', 'libx264');
    }

    private function probeSourceBitrateKbps(string $disk, string $inputPath): ?int
    {
        try {
            $media = FFMpeg::fromDisk($disk)->open($inputPath);
            $bitrate = (int) ($media->getVideoStream()->get('bit_rate') ?? 0);
            if ($bitrate <= 0) {
                $bitrate = (int) ($media->getFormat()->get('bit_rate') ?? 0);
            }
            if ($bitrate > 0) {
                return (int) round($bitrate / 1000);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }
}