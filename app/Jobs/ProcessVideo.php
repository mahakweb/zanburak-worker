<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\FtpBinaryPut;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Support\H264Format;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use FFMpeg\Format\Video\X264;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Fast path:
 * 1) Download raw from FTP ONCE → local work dir
 * 2) Encode locally (no per-quality FTP roundtrips)
 * 3) Upload outputs to FTP once
 *
 * Previous bottleneck: each quality re-downloaded the raw file from FTP (~40-50s each).
 */
class ProcessVideo implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Video $video;

    /** @var array{qualities?: int[], outputs?: string[]} */
    protected array $options;

    public $timeout = 7200;

    public $tries = 2;

    public $uniqueFor = 7200;

    private int $lastPersistedProgress = -1;

    private float $lastProgressWriteAt = 0;

    /** @var array<string,mixed>|null Normalized watermark options for encode-time filters */
    private ?array $watermark = null;

    private string $workDir = '';

    private string $localInputRelative = '';

    /** Disk used for HLS/download outputs (may differ from raw disk). */
    private string $outputDisk = '';

    public function __construct(Video $video, array $options = [])
    {
        $this->video = $video;
        $this->options = $options;
    }

    public function uniqueId(): string
    {
        return 'videoable:' . $this->video->videoable_type . ':' . $this->video->videoable_id;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(60)
                ->expireAfter(7200),
        ];
    }

    public function handle(): void
    {
        $started = microtime(true);

        Log::info('ProcessVideo started', [
            'video_id' => $this->video->id,
            'videoable_type' => $this->video->videoable_type,
            'videoable_id' => $this->video->videoable_id,
        ]);

        $this->video = $this->video->fresh() ?? $this->video;

        if ($this->video->type !== 'raw') {
            throw new RuntimeException('ProcessVideo requires a raw video record.');
        }

        $rawDisk = (string) ($this->video->disk ?: 'media');
        $rawPath = $this->video->path;
        // Per-job storage_disk (dl/static/media) wins; else VIDEO_DISK env; else raw disk.
        $configuredDisk = trim((string) ($this->options['storage_disk'] ?? ''));
        if ($configuredDisk === '') {
            $configuredDisk = trim((string) env('VIDEO_DISK', ''));
        }
        if (!in_array($configuredDisk, ['dl', 'static', 'media'], true)) {
            $configuredDisk = '';
        }
        $remoteDisk = $configuredDisk !== '' ? $configuredDisk : $rawDisk;
        $this->outputDisk = $remoteDisk;

        if ($this->isCancelled()) {
            $this->markStatus('uploaded', [
                'progress' => null,
                'error_message' => null,
                'processing_started_at' => null,
            ]);
            self::clearCancelFlag((int) $this->video->id);
            return;
        }

        self::clearCancelFlag((int) $this->video->id);

        $this->markStatus('processing', [
            'progress' => 0,
            'error_message' => null,
            'processing_started_at' => now(),
        ]);

        $this->assertNotCancelled();
        $this->prepareLocalWorkspace($rawDisk, $rawPath);
        $this->prepareWatermarkConfig();
        $this->updateProgress(5);

        try {
            $probe = $this->probeLocalInput();
            if ((int) ($this->video->duration ?? 0) <= 0 && ($probe['duration'] ?? 0) > 0) {
                DB::table('videos')->where('id', $this->video->id)->update([
                    'duration' => $probe['duration'],
                    'updated_at' => now(),
                ]);
                $this->video->duration = $probe['duration'];
            }

            $sourceBitrateKbps = $probe['bitrate_kbps'] ?? null;
            $streamQualities = $this->selectStreamQualities(
                $probe['height'] ?? 720,
                $this->options['stream_qualities'] ?? $this->options['qualities'] ?? []
            );
            $downloadQualities = $this->selectStreamQualities(
                $probe['height'] ?? 720,
                $this->options['download_qualities'] ?? $this->options['qualities'] ?? []
            );
            $availableQualities = $streamQualities !== [] ? $streamQualities : $downloadQualities;
            $this->updateProgress(12);

            $videoableType = $this->video->videoable_type;
            $videoableId = $this->video->videoable_id;
            $wantStream = $this->wantsOutput('stream');
            $wantDownload = $this->wantsOutput('download');

            Log::info('ProcessVideo options', [
                'video_id' => $this->video->id,
                'stream_qualities' => $streamQualities,
                'download_qualities' => $downloadQualities,
                'outputs' => $this->options['outputs'] ?? ['stream'],
            ]);

            if ($videoableType === 'App\\Models\\Course') {
                $course = DB::table('courses')->select('slug')->where('id', $videoableId)->first();
                if (!$course) {
                    throw new RuntimeException("Course not found for video {$this->video->id}");
                }
                $remoteTrailer = "trailer/{$course->slug}";
                $this->cleanupPreviousOutputs(['trailer'], $remoteDisk);
                $this->encodeTrailerLocal($availableQualities, $sourceBitrateKbps, $remoteTrailer, $remoteDisk);
            } elseif ($videoableType === 'App\\Models\\Episode') {
                $row = DB::table('episodes')
                    ->join('sections', 'episodes.section_id', '=', 'sections.id')
                    ->join('courses', 'sections.course_id', '=', 'courses.id')
                    ->where('episodes.id', $videoableId)
                    ->select('courses.slug as course_slug', 'episodes.id as episode_id')
                    ->first();
                if (!$row) {
                    throw new RuntimeException("Episode/course not found for video {$this->video->id}");
                }

                $remoteStream = "stream/{$row->course_slug}/{$row->episode_id}";
                $remoteDownload = "download/{$row->course_slug}/{$row->episode_id}";

                $typesToReplace = array_values(array_filter([
                    $wantStream ? 'stream' : null,
                    $wantDownload ? 'download' : null,
                ]));
                $this->cleanupPreviousOutputs($typesToReplace, $remoteDisk);

                if ($wantStream) {
                    $this->encodeEncryptedStreamLocal($streamQualities, $sourceBitrateKbps, $remoteStream, $remoteDisk);
                    $this->markStatus('processed', [
                        'progress' => 75,
                        'error_message' => null,
                    ]);
                    Log::info('ProcessVideo stream ready', [
                        'video_id' => $this->video->id,
                        'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
                    ]);
                }

                if ($wantDownload) {
                    try {
                        $this->encodeDownloadsLocal(
                            $downloadQualities !== [] ? $downloadQualities : $streamQualities,
                            $sourceBitrateKbps,
                            $remoteDownload,
                            $remoteDisk
                        );
                    } catch (Throwable $e) {
                        Log::warning('Download ladder failed', [
                            'video_id' => $this->video->id,
                            'error' => $e->getMessage(),
                        ]);
                        if (!$wantStream) {
                            throw $e;
                        }
                    }
                }

                if (!$wantStream && !$wantDownload) {
                    throw new RuntimeException('No process outputs selected (stream/download).');
                }
            } else {
                throw new RuntimeException("Unsupported videoable type: {$videoableType}");
            }

            $this->markStatus('processed', [
                'progress' => 100,
                'error_message' => null,
            ]);

            Log::info('ProcessVideo completed', [
                'video_id' => $this->video->id,
                'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
                'qualities' => $availableQualities,
            ]);

            // Keep raw on the same selected disk as outputs (FTP or local).
            try {
                if ($this->localInputRelative !== '' && Storage::disk('local')->exists($this->localInputRelative)) {
                    $rawAbs = Storage::disk('local')->path($this->localInputRelative);
                    // Prefer original non-watermarked inbox if present for archival raw.
                    $inboxFiles = Storage::disk('local')->files(self::rawInboxRelative($rawPath));
                    if ($inboxFiles === []) {
                        $inboxFiles = Storage::disk('local')->files('raw-inbox/video-' . (int) $this->video->id);
                    }
                    if ($inboxFiles !== []) {
                        $rawAbs = Storage::disk('local')->path($inboxFiles[0]);
                    }

                    $driver = (string) (config("filesystems.disks.{$remoteDisk}.driver") ?? '');
                    $remoteRaw = ltrim(str_replace('\\', '/', $rawPath), '/');
                    if ($driver === 'ftp') {
                        FtpBinaryPut::put((array) config("filesystems.disks.{$remoteDisk}"), $remoteRaw, $rawAbs);
                    } else {
                        $parent = dirname($remoteRaw);
                        if ($parent !== '.' && $parent !== '') {
                            Storage::disk($remoteDisk)->makeDirectory($parent);
                        }
                        $stream = fopen($rawAbs, 'rb');
                        if ($stream === false) {
                            throw new RuntimeException('Unable to open raw for disk store');
                        }
                        try {
                            Storage::disk($remoteDisk)->put($remoteRaw, $stream);
                        } finally {
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                        }
                    }

                    if ((string) $this->video->disk !== (string) $remoteDisk) {
                        DB::table('videos')->where('id', $this->video->id)->update([
                            'disk' => $remoteDisk,
                            'updated_at' => now(),
                        ]);
                        $this->video->disk = $remoteDisk;
                    }
                }
            } catch (Throwable $e) {
                Log::warning('Post-process raw sync failed', [
                    'video_id' => $this->video->id,
                    'disk' => $remoteDisk,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->clearRawInbox($rawPath);
            self::clearCancelFlag((int) $this->video->id);
        } catch (Throwable $e) {
            if ($this->isCancelException($e) || $this->isCancelled()) {
                $this->markStatus('uploaded', [
                    'progress' => null,
                    'error_message' => null,
                    'processing_started_at' => null,
                ]);
                self::clearCancelFlag((int) $this->video->id);
                self::killWorkerProcessesForVideo((int) $this->video->id);
                Log::info('ProcessVideo cancelled by user', [
                    'video_id' => $this->video->id,
                ]);
                return;
            }

            // Preserve raw for retry when FTP output upload failed after inbox was used.
            $this->preserveInboxFromWorkspace($rawPath);
            throw $e;
        } finally {
            $this->cleanupLocalWorkspace();
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($this->isCancelException($exception) || $this->isCancelled()) {
            try {
                DB::table('videos')->where('id', $this->video->id)->update([
                    'status' => 'uploaded',
                    'progress' => null,
                    'error_message' => null,
                    'processing_started_at' => null,
                    'updated_at' => now(),
                ]);
            } catch (Throwable $e) {
                // ignore
            }
            $this->cleanupLocalWorkspace();
            return;
        }

        $message = mb_substr($exception->getMessage(), 0, 2000);

        try {
            DB::table('videos')
                ->where('id', $this->video->id)
                ->update([
                    'status' => 'failed',
                    'error_message' => $message,
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            Log::warning('Failed to update video status to failed', [
                'video_id' => $this->video->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->cleanupLocalWorkspace();

        Log::error('ProcessVideo failed', [
            'video_id' => $this->video->id,
            'error' => $message,
        ]);
    }

    public static function rawInboxRelative(string $rawPath): string
    {
        return 'raw-inbox/' . md5($rawPath);
    }

    private function clearRawInbox(string $rawPath): void
    {
        foreach ([
            self::rawInboxRelative($rawPath),
            'raw-inbox/video-' . (int) $this->video->id,
        ] as $rel) {
            try {
                Storage::disk('local')->deleteDirectory($rel);
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    private function preserveInboxFromWorkspace(string $rawPath): void
    {
        if ($this->localInputRelative === '' || !Storage::disk('local')->exists($this->localInputRelative)) {
            return;
        }

        $ext = pathinfo($rawPath, PATHINFO_EXTENSION) ?: 'mp4';
        $src = Storage::disk('local')->path($this->localInputRelative);

        foreach ([
            self::rawInboxRelative($rawPath),
            'raw-inbox/video-' . (int) $this->video->id,
        ] as $rel) {
            try {
                Storage::disk('local')->makeDirectory($rel);
                @copy($src, Storage::disk('local')->path($rel . '/input.' . $ext));
            } catch (Throwable $e) {
                Log::warning('Failed preserving raw inbox after process error', [
                    'video_id' => $this->video->id,
                    'inbox' => $rel,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function prepareLocalWorkspace(string $remoteDisk, string $rawPath): void
    {
        $relativeDir = 'processing/' . $this->video->id . '_' . uniqid();
        Storage::disk('local')->makeDirectory($relativeDir);
        $this->workDir = Storage::disk('local')->path($relativeDir);

        $ext = pathinfo($rawPath, PATHINFO_EXTENSION) ?: 'mp4';
        $this->localInputRelative = $relativeDir . '/input.' . $ext;
        $localAbsolute = Storage::disk('local')->path($this->localInputRelative);

        $downloadStarted = microtime(true);
        $inboxRel = self::rawInboxRelative($rawPath);
        $inboxByVideoRel = 'raw-inbox/video-' . (int) $this->video->id;
        $inboxFiles = Storage::disk('local')->files($inboxRel);
        if ($inboxFiles === []) {
            $inboxFiles = Storage::disk('local')->files($inboxByVideoRel);
            if ($inboxFiles !== []) {
                $inboxRel = $inboxByVideoRel;
            }
        }
        $usedInbox = false;

        if ($inboxFiles !== []) {
            // Prefer the copy left by UploadController — skips multi-minute FTP re-download.
            $inboxFile = $inboxFiles[0];
            $src = Storage::disk('local')->path($inboxFile);
            if (!@copy($src, $localAbsolute)) {
                throw new RuntimeException('Unable to copy raw inbox file into workspace');
            }
            $usedInbox = true;
            // Keep inbox until job succeeds — otherwise FTP upload failures leave no raw for retry.
        } else {
            if (!Storage::disk($remoteDisk)->exists($rawPath)) {
                throw new RuntimeException("Raw video file missing: {$rawPath}");
            }

            $read = Storage::disk($remoteDisk)->readStream($rawPath);
            if ($read === false) {
                throw new RuntimeException("Unable to read raw video from {$remoteDisk}:{$rawPath}");
            }

            $write = fopen($localAbsolute, 'wb');
            if ($write === false) {
                if (is_resource($read)) {
                    fclose($read);
                }
                throw new RuntimeException('Unable to open local temp file for writing');
            }

            stream_copy_to_stream($read, $write);
            fclose($write);
            if (is_resource($read)) {
                fclose($read);
            }
        }

        if (!is_file($localAbsolute) || filesize($localAbsolute) < 64) {
            throw new RuntimeException('Local raw copy is missing or empty');
        }

        Log::info($usedInbox ? 'ProcessVideo raw taken from local inbox' : 'ProcessVideo raw downloaded once', [
            'video_id' => $this->video->id,
            'bytes' => filesize($localAbsolute),
            'elapsed_ms' => (int) ((microtime(true) - $downloadStarted) * 1000),
            'local' => $this->localInputRelative,
            'source' => $usedInbox ? 'inbox' : 'ftp',
        ]);
    }

    /**
     * Validate/prepare watermark once. Applied during encode (no extra full-video pass).
     */
    private function prepareWatermarkConfig(): void
    {
        $wm = $this->options['watermark'] ?? null;
        if (!is_array($wm)) {
            $this->watermark = null;
            return;
        }

        $enabled = $wm['enabled'] ?? false;
        if ($enabled === false || $enabled === 0 || $enabled === '0' || $enabled === '' || $enabled === null) {
            $this->watermark = null;
            return;
        }

        $type = (($wm['type'] ?? 'text') === 'image') ? 'image' : 'text';
        $position = (string) ($wm['position'] ?? 'bottom-right');
        if (!in_array($position, ['top-left', 'top-right', 'center', 'bottom-left', 'bottom-right'], true)) {
            $position = 'bottom-right';
        }

        $config = [
            'type' => $type,
            'position' => $position,
            'opacity' => max(0.15, min(1.0, (float) ($wm['opacity'] ?? 0.7))),
            'font_size' => max(12, min(96, (int) ($wm['font_size'] ?? 36))),
            'image_scale' => max(0.05, min(0.45, (float) ($wm['image_scale'] ?? 0.16))),
            'text' => '',
            'font' => '',
            'font_color' => $this->normalizeHexColor((string) ($wm['font_color'] ?? '#FFFFFF'), 'FFFFFF'),
            'bg_enabled' => (bool) ($wm['bg_enabled'] ?? false),
            'bg_color' => $this->normalizeHexColor((string) ($wm['bg_color'] ?? '#000000'), '000000'),
            'bg_opacity' => max(0.05, min(1.0, (float) ($wm['bg_opacity'] ?? 0.45))),
            'bg_padding' => max(0, min(48, (int) ($wm['bg_padding'] ?? 10))),
            'bg_radius' => max(0, min(40, (int) ($wm['bg_radius'] ?? 8))),
            'border_enabled' => (bool) ($wm['border_enabled'] ?? true),
            'border_color' => $this->normalizeHexColor((string) ($wm['border_color'] ?? '#000000'), '000000'),
            'border_width' => max(0, min(8, (int) ($wm['border_width'] ?? 2))),
            'image_radius' => max(0, min(48, (int) ($wm['image_radius'] ?? 0))),
            'image_abs' => '',
        ];

        if ($type === 'text') {
            $text = trim((string) ($wm['text'] ?? ''));
            if ($text === '') {
                throw new RuntimeException('Watermark text is empty');
            }
            $font = $this->resolveWatermarkFontPath((string) ($wm['font'] ?? 'tahoma'));
            $config['text'] = mb_substr($text, 0, 80);
            $config['font'] = $font;

            // Rounded text background needs a pre-rendered PNG (drawtext box is always square).
            if (!empty($config['bg_enabled']) && (int) $config['bg_radius'] > 0) {
                $config['image_abs'] = $this->renderRoundedTextWatermarkPng($config);
                $config['type'] = 'image';
                $config['image_scale'] = 0; // sized dynamically in buildGeometryFilter via render_h
                $config['render_h'] = $this->estimateTextWatermarkHeight($config);
                $config['image_radius'] = 0; // already rounded in PNG
            }
        } else {
            $imageRel = ltrim(str_replace('\\', '/', (string) ($wm['image_path'] ?? '')), '/');
            if ($imageRel === '' || !Storage::disk('local')->exists($imageRel)) {
                throw new RuntimeException('Watermark image is missing — reselect the image and process again');
            }
            $config['image_abs'] = str_replace('\\', '/', Storage::disk('local')->path($imageRel));
        }

        $this->watermark = $config;
        Log::info('ProcessVideo watermark ready (encode-time)', [
            'video_id' => $this->video->id,
            'type' => $config['type'],
            'position' => $position,
            'font' => $wm['font'] ?? null,
            'bg_radius' => $config['bg_radius'],
            'image_radius' => $config['image_radius'],
        ]);
    }

    private function resolveWatermarkFontPath(string $fontKey): string
    {
        $map = [
            'tahoma' => 'C:/Windows/Fonts/tahoma.ttf',
            'tahoma-bold' => 'C:/Windows/Fonts/tahomabd.ttf',
            'arial' => 'C:/Windows/Fonts/arial.ttf',
            'arial-bold' => 'C:/Windows/Fonts/arialbd.ttf',
            'segoe' => 'C:/Windows/Fonts/segoeui.ttf',
            'segoe-bold' => 'C:/Windows/Fonts/segoeuib.ttf',
            'verdana' => 'C:/Windows/Fonts/verdana.ttf',
            'georgia' => 'C:/Windows/Fonts/georgia.ttf',
            'times' => 'C:/Windows/Fonts/times.ttf',
            'impact' => 'C:/Windows/Fonts/impact.ttf',
            'courier' => 'C:/Windows/Fonts/cour.ttf',
            'comic' => 'C:/Windows/Fonts/comic.ttf',
        ];

        $candidates = [];
        if (isset($map[$fontKey])) {
            $candidates[] = $map[$fontKey];
        }
        $envFont = (string) env('FONT_TTF_FOR_WATERMARK', '');
        if ($envFont !== '') {
            $candidates[] = str_replace('\\', '/', $envFont);
        }
        $candidates[] = 'C:/Windows/Fonts/tahoma.ttf';
        $candidates[] = 'C:/Windows/Fonts/arial.ttf';

        foreach ($candidates as $path) {
            $path = str_replace('\\', '/', $path);
            if (is_file($path)) {
                return $path;
            }
        }

        throw new RuntimeException('Watermark font file not found');
    }

    private function normalizeHexColor(string $value, string $fallback = 'FFFFFF'): string
    {
        $value = ltrim(trim($value), '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $value)) {
            return strtoupper($fallback);
        }

        return strtoupper($value);
    }

    private function ffmpegColor(string $hex, float $alpha = 1.0): string
    {
        $hex = $this->normalizeHexColor($hex);
        $alpha = max(0.0, min(1.0, $alpha));

        return sprintf('0x%s@%.2f', $hex, $alpha);
    }

    /**
     * Scale (+ optional watermark) for one HLS/download rendition.
     * HLSVideoFilters::addFilter() MUST receive a plain string — never a Filter object.
     */
    private function applyGeometryFilters(object $media, int $width, int $height): void
    {
        $filter = $this->buildGeometryFilter($width, $height);

        // Duck-type HLSVideoFilters / any exporter filter bag: always pass STRING.
        if (!method_exists($media, 'addFilter')) {
            throw new RuntimeException('Geometry filters target does not support addFilter()');
        }

        $media->addFilter($filter);
    }

    private function buildGeometryFilter(int $width, int $height): string
    {
        $width = max(2, $width - ($width % 2));
        $height = max(2, $height - ($height % 2));

        if ($this->watermark === null) {
            return "scale={$width}:{$height}";
        }

        $wm = $this->watermark;
        $margin = max(18, (int) round(min($width, $height) * 0.035));

        if (($wm['type'] ?? '') === 'image') {
            $path = str_replace('\\', '/', (string) ($wm['image_abs'] ?? ''));
            $path = str_replace(':', '\\:', $path);
            $path = str_replace("'", "\\'", $path);
            [$ox, $oy] = $this->watermarkOverlayXy((string) $wm['position'], $margin);
            $alpha = (float) $wm['opacity'];

            // Pre-rendered text watermark: scale by target height; otherwise by width fraction.
            if (!empty($wm['render_h'])) {
                $targetH = max(20, (int) round(((int) $wm['render_h']) * ($height / 720)));
                $targetH -= $targetH % 2;
                $scaleFilter = sprintf('scale=-1:%d', $targetH);
                $radiusPx = 0;
            } else {
                $wmW = max(24, (int) round($width * (float) $wm['image_scale']));
                $wmW -= $wmW % 2;
                $scaleFilter = sprintf('scale=%d:-1', $wmW);
                $radiusPx = max(0, (int) round(((int) ($wm['image_radius'] ?? 0)) * ($wmW / 200)));
            }

            $roundFilter = $radiusPx > 0
                ? ',' . $this->ffmpegRoundedAlphaFilter($radiusPx)
                : '';

            // [in]/[out] so AdvancedMedia mapping replaces labels correctly.
            return sprintf(
                "[in]scale=%d:%d[bg];movie='%s',%s,format=rgba,colorchannelmixer=aa=%.2f%s[wm];[bg][wm]overlay=%s:%s[out]",
                $width,
                $height,
                $path,
                $scaleFilter,
                $alpha,
                $roundFilter,
                $ox,
                $oy
            );
        }

        $fontSize = max(14, (int) round(((int) $wm['font_size']) * ($height / 720)));
        $fontEsc = str_replace(':', '\\:', str_replace('\\', '/', (string) $wm['font']));
        $textEsc = $this->escapeFfmpegDrawText((string) $wm['text']);
        [$x, $y] = $this->watermarkDrawTextXy((string) $wm['position'], $margin);
        $opacity = (float) $wm['opacity'];
        $fontColor = $this->ffmpegColor((string) $wm['font_color'], $opacity);

        $parts = [
            sprintf("fontfile='%s'", $fontEsc),
            sprintf("text='%s'", $textEsc),
            'fontsize=' . $fontSize,
            'fontcolor=' . $fontColor,
            'x=' . $x,
            'y=' . $y,
        ];

        if (!empty($wm['border_enabled']) && (int) ($wm['border_width'] ?? 0) > 0) {
            $parts[] = 'borderw=' . (int) $wm['border_width'];
            $parts[] = 'bordercolor=' . $this->ffmpegColor((string) $wm['border_color'], min(1.0, $opacity + 0.1));
        } else {
            $parts[] = 'borderw=0';
        }

        if (!empty($wm['bg_enabled'])) {
            $pad = max(0, (int) round(((int) ($wm['bg_padding'] ?? 10)) * ($height / 720)));
            $parts[] = 'box=1';
            $parts[] = 'boxborderw=' . $pad;
            $parts[] = 'boxcolor=' . $this->ffmpegColor(
                (string) $wm['bg_color'],
                (float) ($wm['bg_opacity'] ?? 0.45) * $opacity
            );
        }

        return sprintf(
            'scale=%d:%d,drawtext=%s',
            $width,
            $height,
            implode(':', $parts)
        );
    }

    private function ffmpegRoundedAlphaFilter(int $radius): string
    {
        $r = max(1, $radius);

        // Keep RGB; zero alpha outside rounded-rect corners.
        return sprintf(
            "geq=r='r(X,Y)':g='g(X,Y)':b='b(X,Y)':a='if(gt(abs(W/2-X),W/2-%d)*gt(abs(H/2-Y),H/2-%d)*gt(hypot(abs(W/2-%d-X),abs(H/2-%d-Y)),%d),0,a(X,Y))'",
            $r,
            $r,
            $r,
            $r,
            $r
        );
    }

    private function estimateTextWatermarkHeight(array $config): int
    {
        $fontSize = max(12, (int) ($config['font_size'] ?? 36));
        $pad = max(0, (int) ($config['bg_padding'] ?? 10));
        $border = (!empty($config['border_enabled'])) ? max(0, (int) ($config['border_width'] ?? 0)) : 0;

        return (int) round($fontSize * 1.35) + ($pad * 2) + ($border * 2) + 4;
    }

    private function estimateTextWatermarkWidth(array $config): int
    {
        $fontSize = max(12, (int) ($config['font_size'] ?? 36));
        $pad = max(0, (int) ($config['bg_padding'] ?? 10));
        $border = (!empty($config['border_enabled'])) ? max(0, (int) ($config['border_width'] ?? 0)) : 0;
        $len = max(1, mb_strlen((string) ($config['text'] ?? '')));

        return (int) max($fontSize * 2, round($len * $fontSize * 0.62)) + ($pad * 2) + ($border * 2) + 8;
    }

    /**
     * Bake text + rounded background into a PNG (FFmpeg drawtext box cannot round corners).
     */
    private function renderRoundedTextWatermarkPng(array $config): string
    {
        if ($this->workDir === '') {
            throw new RuntimeException('Watermark work directory is not ready');
        }

        $out = rtrim(str_replace('\\', '/', $this->workDir), '/') . '/wm_text_rounded.png';
        $w = $this->estimateTextWatermarkWidth($config);
        $h = $this->estimateTextWatermarkHeight($config);
        $w += $w % 2;
        $h += $h % 2;
        $w = max(32, $w);
        $h = max(24, $h);

        $fontEsc = str_replace(':', '\\:', str_replace('\\', '/', (string) $config['font']));
        $textEsc = $this->escapeFfmpegDrawText((string) $config['text']);
        $fontSize = max(12, (int) $config['font_size']);
        $radius = max(1, (int) $config['bg_radius']);
        $bgAlpha = max(0.05, min(1.0, (float) ($config['bg_opacity'] ?? 0.45)));
        $bgColor = $this->ffmpegColor((string) $config['bg_color'], $bgAlpha);
        $fontColor = $this->ffmpegColor((string) $config['font_color'], 1.0);

        $drawParts = [
            sprintf("fontfile='%s'", $fontEsc),
            sprintf("text='%s'", $textEsc),
            'fontsize=' . $fontSize,
            'fontcolor=' . $fontColor,
            'x=(w-tw)/2',
            'y=(h-th)/2',
        ];
        if (!empty($config['border_enabled']) && (int) ($config['border_width'] ?? 0) > 0) {
            $drawParts[] = 'borderw=' . (int) $config['border_width'];
            $drawParts[] = 'bordercolor=' . $this->ffmpegColor((string) $config['border_color'], 1.0);
        }

        $vf = sprintf(
            "format=rgba,drawtext=%s,%s",
            implode(':', $drawParts),
            $this->ffmpegRoundedAlphaFilter($radius)
        );

        $result = Process::run([
            $this->ffmpegBinary(),
            '-y',
            '-f', 'lavfi',
            '-i', sprintf('color=c=%s:s=%dx%d:d=1', $bgColor, $w, $h),
            '-vf', $vf,
            '-frames:v', '1',
            $out,
        ]);

        if (!$result->successful() || !is_file($out)) {
            Log::error('Failed to render rounded text watermark', [
                'stderr' => $result->errorOutput(),
                'stdout' => $result->output(),
            ]);
            throw new RuntimeException('Failed to render rounded text watermark');
        }

        return $out;
    }

    private function escapeFfmpegDrawText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(':', '\\:', $text);
        $text = str_replace("'", "\\'", $text);
        $text = str_replace('%', '\\%', $text);

        return $text;
    }

    /** @return array{0:string,1:string} */
    private function watermarkDrawTextXy(string $position, int $margin = 24): array
    {
        $m = (string) max(8, $margin);

        return match ($position) {
            'top-left' => [$m, $m],
            'top-right' => ["w-tw-{$m}", $m],
            'center' => ['(w-tw)/2', '(h-th)/2'],
            'bottom-left' => [$m, "h-th-{$m}"],
            default => ["w-tw-{$m}", "h-th-{$m}"],
        };
    }

    /** @return array{0:string,1:string} */
    private function watermarkOverlayXy(string $position, int $margin = 24): array
    {
        $m = (string) max(8, $margin);

        return match ($position) {
            'top-left' => [$m, $m],
            'top-right' => ["W-w-{$m}", $m],
            'center' => ['(W-w)/2', '(H-h)/2'],
            'bottom-left' => [$m, "H-h-{$m}"],
            default => ["W-w-{$m}", "H-h-{$m}"],
        };
    }

    private function cleanupLocalWorkspace(): void
    {
        if ($this->workDir !== '' && is_dir($this->workDir)) {
            try {
                File::deleteDirectory($this->workDir);
            } catch (Throwable $e) {
                Log::warning('Failed cleaning processing workspace', [
                    'dir' => $this->workDir,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function probeLocalInput(): array
    {
        $media = FFMpeg::fromDisk('local')->open($this->localInputRelative);
        $duration = (int) round($media->getDurationInSeconds());
        $height = 720;
        $bitrateKbps = null;

        try {
            $dims = $media->getVideoStream()->getDimensions();
            $height = (int) $dims->getHeight();
            $bitrate = (int) ($media->getVideoStream()->get('bit_rate') ?? 0);
            if ($bitrate <= 0) {
                $bitrate = (int) ($media->getFormat()->get('bit_rate') ?? 0);
            }
            if ($bitrate > 0) {
                $bitrateKbps = (int) round($bitrate / 1000);
            }
        } catch (Throwable $e) {
            // keep defaults
        }

        return [
            'duration' => max(0, $duration),
            'height' => max(144, $height),
            'bitrate_kbps' => $bitrateKbps,
        ];
    }

    /**
     * Prefer explicit admin-selected qualities; otherwise default source + up to 2 lower rungs.
     */
    private function selectStreamQualities(int $sourceHeight, array $requested = []): array
    {
        $ladder = [1080, 720, 480, 360, 240, 144];
        if ($requested !== []) {
            $selected = array_values(array_unique(array_filter(
                array_map('intval', $requested),
                fn ($q) => in_array($q, $ladder, true) && $q <= $sourceHeight
            )));
            rsort($selected);
            if ($selected !== []) {
                return $selected;
            }
        }

        $available = array_values(array_filter($ladder, fn ($q) => $q <= $sourceHeight));
        if ($available === []) {
            $available = [$sourceHeight >= 720 ? 720 : max(360, $sourceHeight)];
        }

        return array_slice($available, 0, min(3, count($available)));
    }

    private function wantsOutput(string $type): bool
    {
        $outputs = $this->options['outputs'] ?? ['stream'];
        if (!is_array($outputs) || $outputs === []) {
            $outputs = ['stream'];
        }

        // Legacy env flag still enables downloads when outputs omit it.
        if ($type === 'download' && filter_var(env('VIDEO_GENERATE_DOWNLOADS', false), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        return in_array($type, $outputs, true);
    }

    private function shouldGenerateDownloads(): bool
    {
        return $this->wantsOutput('download');
    }

    private function cleanupPreviousOutputs(array $types, string $remoteDisk): void
    {
        $types = array_values(array_intersect($types, ['stream', 'download', 'trailer']));
        if ($types === []) {
            return;
        }

        $rows = DB::table('videos')
            ->where('videoable_id', $this->video->videoable_id)
            ->where('videoable_type', $this->video->videoable_type)
            ->whereIn('type', $types)
            ->get();

        foreach ($rows as $row) {
            try {
                $path = (string) $row->path;
                $disk = (string) ($row->disk ?: $remoteDisk);
                $dir = dirname($path);
                $storage = Storage::disk($disk);
                // One FTP rmdir tree — avoid per-file LIST/DELE roundtrips.
                if ($dir && $dir !== '.' && $dir !== '/') {
                    try {
                        $storage->deleteDirectory($dir);
                    } catch (Throwable $e) {
                        if ($path && $storage->exists($path)) {
                            $storage->delete($path);
                        }
                    }
                } elseif ($path) {
                    $storage->delete($path);
                }
            } catch (Throwable $e) {
                Log::warning('Failed cleaning previous processed outputs', [
                    'video_id' => $this->video->id,
                    'type' => $row->type ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        DB::table('videos')
            ->where('videoable_id', $this->video->videoable_id)
            ->where('videoable_type', $this->video->videoable_type)
            ->whereIn('type', $types)
            ->delete();
    }

    private function downloadQualities(array $availableQualities): array
    {
        // Downloads use the same selected qualities (already filtered).
        return $availableQualities !== [] ? $availableQualities : [720];
    }

    private function encodeTrailerLocal(array $qualities, ?int $sourceBitrateKbps, string $remoteTrailerPath, string $remoteDisk): void
    {
        $localOutRel = dirname($this->localInputRelative) . '/trailer';
        $masterRel = $localOutRel . '/playlist.m3u8';
        Storage::disk('local')->makeDirectory($localOutRel);

        $exporter = FFMpeg::fromDisk('local')
            ->open($this->localInputRelative)
            ->exportForHLS()
            ->toDisk('local')
            ->setSegmentLength(6)
            ->onProgress($this->bindProgressCallback(15, 70));

        foreach ($qualities as $quality) {
            [$width, $height] = $this->dimensionsFor($quality);
            $format = $this->makeVideoFormatForQuality($quality, $sourceBitrateKbps);
            $exporter->addFormat($format, function ($media) use ($width, $height) {
                $this->applyGeometryFilters($media, $width, $height);
            });
        }

        $exporter->save($masterRel);
        $this->updateProgress(85);
        $this->uploadDirectoryToRemote('local', $localOutRel, $remoteDisk, $remoteTrailerPath);
        $this->storeVideoRecord('trailer', null, $remoteTrailerPath . '/playlist.m3u8');
    }

    private function encodeEncryptedStreamLocal(array $qualities, ?int $sourceBitrateKbps, string $remoteStreamPath, string $remoteDisk): void
    {
        $localOutRel = dirname($this->localInputRelative) . '/stream';
        $masterRel = $localOutRel . '/playlist.m3u8';
        Storage::disk('local')->makeDirectory($localOutRel);

        $encodeStarted = microtime(true);

        // Longer segments ⇒ fewer HLS requests to the API/FTP (server load).
        // Override with VIDEO_HLS_SEGMENT if needed.
        $duration = max(1, (int) ($this->video->duration ?? 8));
        $segmentLength = (int) env('VIDEO_HLS_SEGMENT', min(10, max(6, min(8, $duration))));

        // Single FFmpeg HLS export for all renditions (shared encryption keys).
        $exporter = FFMpeg::fromDisk('local')
            ->open($this->localInputRelative)
            ->exportForHLS()
            ->toDisk('local')
            ->setSegmentLength($segmentLength)
            ->withRotatingEncryptionKey(function ($filename, $contents) use ($localOutRel) {
                // Match backend key resolver convention: keys live under keys/.
                Storage::disk('local')->put("{$localOutRel}/keys/{$filename}", $contents);
            }, 5000000)
            ->onProgress($this->bindProgressCallback(15, 55));

        foreach ($qualities as $quality) {
            [$width, $height] = $this->dimensionsFor($quality);
            $videoFormat = $this->makeVideoFormatForQuality($quality, $sourceBitrateKbps);
            $exporter->addFormat($videoFormat, function ($media) use ($width, $height) {
                $this->applyGeometryFilters($media, $width, $height);
            });
        }

        $exporter->save($masterRel);

        if (!Storage::disk('local')->exists($masterRel)) {
            throw new RuntimeException("Local HLS master playlist missing: {$masterRel}");
        }

        Log::info('ProcessVideo stream encoded locally', [
            'video_id' => $this->video->id,
            'qualities' => $qualities,
            'elapsed_ms' => (int) ((microtime(true) - $encodeStarted) * 1000),
        ]);

        $this->updateProgress(70);
        $uploadStarted = microtime(true);
        $this->uploadDirectoryToRemote('local', $localOutRel, $remoteDisk, $remoteStreamPath);
        Log::info('ProcessVideo stream uploaded', [
            'video_id' => $this->video->id,
            'elapsed_ms' => (int) ((microtime(true) - $uploadStarted) * 1000),
        ]);

        $this->storeVideoRecord('stream', null, $remoteStreamPath . '/playlist.m3u8');
    }

    private function encodeDownloadsLocal(array $qualities, ?int $sourceBitrateKbps, string $remoteDownloadBase, string $remoteDisk): void
    {
        $localOutRel = dirname($this->localInputRelative) . '/download';
        $count = max(1, count($qualities));
        $inputAbs = Storage::disk('local')->path($this->localInputRelative);
        $tasks = [];

        foreach ($qualities as $quality) {
            [$width, $height] = $this->dimensionsFor($quality);
            $bitrate = $this->bitrateFor($quality);
            if ($sourceBitrateKbps && $bitrate > $sourceBitrateKbps) {
                $bitrate = (int) floor($sourceBitrateKbps * 0.95);
            }
            $audioBitrate = $this->audioBitrateFor($quality);
            $relDir = "{$localOutRel}/{$quality}p";
            $relFile = "{$relDir}/video-{$quality}p.mp4";
            Storage::disk('local')->makeDirectory($relDir);
            $outputAbs = Storage::disk('local')->path($relFile);

            $tasks[] = [
                'quality' => $quality,
                'relDir' => $relDir,
                'relFile' => $relFile,
                'command' => $this->buildDownloadEncodeCommand(
                    $inputAbs,
                    $outputAbs,
                    $width,
                    $height,
                    $quality,
                    $bitrate,
                    $audioBitrate
                ),
            ];
        }

        // Parallel encodes (NVENC/CPU). Default 2 keeps GPU/CPU from thrashing.
        $concurrency = max(1, (int) env('VIDEO_DOWNLOAD_CONCURRENCY', 2));
        foreach (array_chunk($tasks, $concurrency) as $chunk) {
            $this->runFfmpegCommandPool($chunk);
        }

        foreach ($tasks as $index => $task) {
            if (!Storage::disk('local')->exists($task['relFile'])) {
                throw new RuntimeException("Download encode missing output: {$task['relFile']}");
            }
            $this->uploadDirectoryToRemote('local', $task['relDir'], $remoteDisk, "{$remoteDownloadBase}/{$task['quality']}p");
            $this->storeVideoRecord('download', $task['quality'], "{$remoteDownloadBase}/{$task['quality']}p/video-{$task['quality']}p.mp4");
            $this->updateProgress(78 + (int) floor((($index + 1) / $count) * 18));
        }
    }

    /**
     * @param  array<int, array{quality:int, command:array<int, string>}>  $chunk
     */
    private function runFfmpegCommandPool(array $chunk): void
    {
        if ($chunk === []) {
            return;
        }

        if (count($chunk) === 1) {
            $result = Process::timeout(3600)->run($chunk[0]['command']);
            if (!$result->successful()) {
                throw new RuntimeException(
                    'Download encode failed (' . $chunk[0]['quality'] . "p): " . $result->errorOutput()
                );
            }
            return;
        }

        $pool = Process::pool(function (Pool $pool) use ($chunk) {
            foreach ($chunk as $task) {
                $pool->as((string) $task['quality'])
                    ->timeout(3600)
                    ->command($task['command']);
            }
        })->start()->wait();

        foreach ($chunk as $task) {
            $result = $pool[(string) $task['quality']] ?? null;
            if (!$result || !$result->successful()) {
                $err = $result ? $result->errorOutput() : 'no process result';
                throw new RuntimeException(
                    'Download encode failed (' . $task['quality'] . "p): " . $err
                );
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function buildDownloadEncodeCommand(
        string $inputAbs,
        string $outputAbs,
        int $width,
        int $height,
        int $quality,
        int $videoBitrateKbps,
        int $audioBitrateKbps
    ): array {
        $encoder = $this->videoEncoder();
        $cmd = [
            $this->ffmpegBinary(),
            '-hide_banner',
            '-y',
            '-i',
            $inputAbs,
            '-vf',
            $this->buildGeometryFilter($width, $height),
            '-c:v',
            $encoder,
        ];

        $cmd = array_merge($cmd, $this->x264CommonParams($quality, $videoBitrateKbps));
        $cmd = array_merge($cmd, [
            '-b:v',
            $videoBitrateKbps . 'k',
            '-c:a',
            'aac',
            '-b:a',
            $audioBitrateKbps . 'k',
            '-movflags',
            '+faststart',
            $outputAbs,
        ]);

        return $cmd;
    }

    private function ffmpegBinary(): string
    {
        $configured = trim((string) env('FFMPEG_BINARIES', ''));
        if ($configured !== '') {
            return $configured;
        }

        return (string) (config('laravel-ffmpeg.ffmpeg.binaries') ?: 'ffmpeg');
    }

    private function uploadDirectoryToRemote(string $localDisk, string $localDir, string $remoteDisk, string $remoteDir): void
    {
        $files = Storage::disk($localDisk)->allFiles($localDir);
        if ($files === []) {
            throw new RuntimeException("No local files to upload from {$localDir}");
        }

        $diskConfig = config("filesystems.disks.{$remoteDisk}");
        if (($diskConfig['driver'] ?? null) === 'ftp') {
            // Host blocks newly uploaded PHP (Imunify), so HTTP zip-unpack is unavailable.
            // After freeing disk quota, FTP mkdir works — upload files sequentially.
            $this->uploadDirectoryViaPersistentFtp($localDisk, $localDir, $files, $diskConfig, $remoteDir);
            return;
        }

        $parents = [$remoteDir];
        foreach ($files as $file) {
            $relative = ltrim(substr($file, strlen($localDir)), '/\\');
            $remotePath = rtrim($remoteDir, '/') . '/' . str_replace('\\', '/', $relative);
            $parent = dirname($remotePath);
            if ($parent !== '.' && $parent !== '') {
                $parents[] = $parent;
            }
        }
        foreach (array_unique($parents) as $dir) {
            Storage::disk($remoteDisk)->makeDirectory($dir);
        }

        foreach ($files as $file) {
            $relative = ltrim(substr($file, strlen($localDir)), '/\\');
            $remotePath = rtrim($remoteDir, '/') . '/' . str_replace('\\', '/', $relative);
            $contents = Storage::disk($localDisk)->get($file);
            if ($contents === null) {
                throw new RuntimeException("Unable to read local file {$file}");
            }
            if (Storage::disk($remoteDisk)->put($remotePath, $contents) === false) {
                throw new RuntimeException("Unable to upload {$remotePath}");
            }
        }
    }

    /**
     * Pack HLS/download tree into one ZIP and deploy via HTTP unpack endpoint on STATIC_URL.
     * Avoids hundreds of FTP puts and FTP mkdir permission errors.
     */
    private function uploadDirectoryAsZipBundle(
        string $localDisk,
        string $localDir,
        array $files,
        array $diskConfig,
        string $remoteDir
    ): void {
        $this->assertNotCancelled();

        $target = trim(str_replace('\\', '/', $remoteDir), '/');
        $zipRel = dirname($this->localInputRelative) . '/deploy-' . md5($target) . '.zip';
        $zipAbs = Storage::disk($localDisk)->path($zipRel);

        $zip = new \ZipArchive();
        if ($zip->open($zipAbs, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create deploy zip');
        }

        foreach ($files as $file) {
            $this->assertNotCancelled();
            $relative = ltrim(str_replace('\\', '/', substr($file, strlen($localDir))), '/');
            if ($relative === '') {
                continue;
            }
            $abs = Storage::disk($localDisk)->path($file);
            $zip->addFile($abs, $relative);
        }
        $zip->close();

        if (!is_file($zipAbs) || filesize($zipAbs) < 32) {
            throw new RuntimeException('Deploy zip missing or empty');
        }

        Log::info('ProcessVideo deploy zip ready', [
            'video_id' => $this->video->id,
            'target' => $target,
            'bytes' => filesize($zipAbs),
            'files' => count($files),
        ]);

        try {
            $this->assertNotCancelled();
            $this->deployZipViaHttp($diskConfig, $target, $zipAbs);
        } finally {
            @unlink($zipAbs);
        }
    }

    private function deployZipViaHttp(array $diskConfig, string $target, string $zipAbs): void
    {
        $baseUrl = rtrim((string) ($diskConfig['url'] ?? env('STATIC_URL', '')), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('STATIC_URL is not configured for zip deploy');
        }

        $secret = (string) env('STATIC_DEPLOY_SECRET', '');
        if ($secret === '') {
            throw new RuntimeException(
                'STATIC_DEPLOY_SECRET is empty. Set it in worker .env and the same value in static hls-unpack.php'
            );
        }

        // New .php uploads on this host are not executed (Imunify); unpack lives in existing index.php.
        $unpackUrl = $baseUrl . '/index.php';
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts . "\n" . $target, $secret);

        $curlBin = $this->resolveCurlBinary();
        if ($curlBin === null) {
            // PHP curl fallback
            if (!function_exists('curl_init')) {
                throw new RuntimeException('curl not available for zip deploy');
            }
            $ch = curl_init($unpackUrl);
            $cfile = new \CURLFile($zipAbs, 'application/zip', 'bundle.zip');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => [
                    'target' => $target,
                    'ts' => $ts,
                    'sig' => $sig,
                    'archive' => $cfile,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT => max(120, (int) ($diskConfig['timeout'] ?? 600)),
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false || $code < 200 || $code >= 300) {
                throw new RuntimeException('Zip deploy HTTP failed (' . $code . '): ' . ($err ?: mb_substr((string) $body, 0, 300)));
            }
            $json = json_decode((string) $body, true);
            if (!is_array($json) || empty($json['ok'])) {
                throw new RuntimeException('Zip deploy rejected: ' . mb_substr((string) $body, 0, 300));
            }
            Log::info('ProcessVideo zip deployed via HTTP', [
                'video_id' => $this->video->id,
                'target' => $target,
                'files' => $json['files'] ?? null,
            ]);
            return;
        }

        $cmd = [
            $curlBin,
            '-sS',
            '-X', 'POST',
            '-F', 'target=' . $target,
            '-F', 'ts=' . $ts,
            '-F', 'sig=' . $sig,
            '-F', 'archive=@' . $zipAbs . ';type=application/zip',
            '--connect-timeout', '30',
            '--max-time', (string) max(120, (int) ($diskConfig['timeout'] ?? 600)),
            $unpackUrl,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            throw new RuntimeException('Unable to start curl for zip deploy');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit !== 0) {
            throw new RuntimeException('Zip deploy curl failed: ' . mb_substr($stderr ?: $stdout, 0, 400));
        }

        $json = json_decode($stdout, true);
        if (!is_array($json) || empty($json['ok'])) {
            throw new RuntimeException('Zip deploy rejected: ' . mb_substr($stdout, 0, 400));
        }

        Log::info('ProcessVideo zip deployed via HTTP', [
            'video_id' => $this->video->id,
            'target' => $target,
            'files' => $json['files'] ?? null,
        ]);
    }

    private function resolveCurlBinary(): ?string
    {
        foreach (['C:\\Windows\\System32\\curl.exe', 'curl.exe', 'curl'] as $candidate) {
            if ($candidate === 'curl.exe' || $candidate === 'curl') {
                $where = trim((string) shell_exec(
                    PHP_OS_FAMILY === 'Windows' ? 'where curl.exe 2>NUL' : 'command -v curl 2>/dev/null'
                ));
                if ($where !== '') {
                    $first = strtok($where, "\r\n");
                    if ($first && is_file($first)) {
                        return $first;
                    }
                }
                continue;
            }
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Sequential FTP upload over one connection (mkdir walk + put with retries).
     */
    private function uploadDirectoryViaPersistentFtp(
        string $localDisk,
        string $localDir,
        array $files,
        array $diskConfig,
        string $remoteDir
    ): void {
        $this->assertNotCancelled();

        $relativeBase = trim(str_replace('\\', '/', $remoteDir), '/');
        $conn = $this->ftpConnectEnterRoot($diskConfig);

        try {
            $home = @ftp_pwd($conn) ?: '/';
            $this->ftpMkdirWalk($conn, $home, $relativeBase);

            $created = [$relativeBase => true];

            foreach ($files as $file) {
                $this->assertNotCancelled();

                $relative = ltrim(str_replace('\\', '/', substr($file, strlen($localDir))), '/');
                $remoteRel = $relativeBase . '/' . $relative;
                $remoteParent = dirname($remoteRel);
                if ($remoteParent !== '.' && !isset($created[$remoteParent])) {
                    $this->ftpMkdirWalk($conn, $home, $remoteParent);
                    $created[$remoteParent] = true;
                }

                @ftp_chdir($conn, $home);

                $localPath = Storage::disk($localDisk)->path($file);
                $ok = false;
                for ($attempt = 1; $attempt <= 4; $attempt++) {
                    $this->assertNotCancelled();
                    if (@ftp_put($conn, $remoteRel, $localPath, FTP_BINARY)) {
                        $ok = true;
                        break;
                    }
                    @ftp_chdir($conn, $home);
                    $this->ftpMkdirWalk($conn, $home, $remoteParent);
                    @ftp_chdir($conn, $home);
                    usleep(150000 * $attempt);
                }

                if (!$ok) {
                    $err = error_get_last();
                    throw new RuntimeException(
                        'FTP put failed: ' . $remoteRel . ' (' . ($err['message'] ?? 'unknown') . ')'
                    );
                }
            }
        } finally {
            @ftp_close($conn);
        }
    }

    /**
     * @return resource|\FTP\Connection
     */
    private function ftpConnectEnterRoot(array $diskConfig)
    {
        $host = (string) ($diskConfig['host'] ?? '');
        $username = (string) ($diskConfig['username'] ?? '');
        $password = (string) ($diskConfig['password'] ?? '');
        $port = (int) ($diskConfig['port'] ?? 21);
        $timeout = (int) ($diskConfig['timeout'] ?? 600);
        $passive = (bool) ($diskConfig['passive'] ?? true);

        $conn = @ftp_connect($host, $port, $timeout);
        if ($conn === false) {
            throw new RuntimeException("FTP connect failed: {$host}");
        }

        if (!@ftp_login($conn, $username, $password)) {
            @ftp_close($conn);
            throw new RuntimeException('FTP login failed');
        }

        @ftp_pasv($conn, $passive);
        if (defined('FTP_USEPASVADDRESS')) {
            @ftp_set_option($conn, FTP_USEPASVADDRESS, false);
        }

        $root = trim(str_replace('\\', '/', (string) ($diskConfig['root'] ?? '')), '/');
        if ($root !== '') {
            // Flysystem also chdirs into root; ignore if account is already chrooted there.
            if (!@ftp_chdir($conn, '/' . $root) && !@ftp_chdir($conn, $root)) {
                Log::info('FTP root chdir skipped (likely already in root/chroot)', [
                    'root' => $root,
                    'pwd' => @ftp_pwd($conn),
                ]);
            }
        }

        return $conn;
    }

    /**
     * Create nested dirs relative to $home using walk-in mkdir (no absolute /public_html prefix).
     */
    private function ftpMkdirWalk($conn, string $home, string $relativeDir): void
    {
        $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
        if ($relativeDir === '') {
            return;
        }

        @ftp_chdir($conn, $home);
        foreach (explode('/', $relativeDir) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if (@ftp_chdir($conn, $part)) {
                continue;
            }
            if (!@ftp_mkdir($conn, $part)) {
                // Race / already exists
                if (!@ftp_chdir($conn, $part)) {
                    throw new RuntimeException('FTP mkdir failed: ' . $relativeDir . ' (at ' . $part . ')');
                }
                continue;
            }
            if (!@ftp_chdir($conn, $part)) {
                throw new RuntimeException('FTP chdir failed after mkdir: ' . $part);
            }
        }
        @ftp_chdir($conn, $home);
    }

    private function ensureRemoteFtpDirectory(array $diskConfig, string $relativeDir): void
    {
        $conn = $this->ftpConnectEnterRoot($diskConfig);
        try {
            $home = @ftp_pwd($conn) ?: '/';
            $this->ftpMkdirWalk($conn, $home, trim(str_replace('\\', '/', $relativeDir), '/'));
        } finally {
            @ftp_close($conn);
        }
    }

    /**
     * Hard-stop ffmpeg/curl tied to this video's local work folders (Windows + Linux).
     */
    public static function killWorkerProcessesForVideo(int $videoId): void
    {
        $needle = 'processing/' . $videoId . '_';
        $needleAlt = 'processing\\' . $videoId . '_';

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $ps = '$n1 = ' . var_export($needle, true) . '; $n2 = ' . var_export($needleAlt, true) . '; '
                    . 'Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object { '
                    . '$n = $_.Name; $c = $_.CommandLine; '
                    . '($n -eq \'ffmpeg.exe\' -or $n -eq \'ffprobe.exe\' -or $n -eq \'curl.exe\') '
                    . '-and $c -and ($c.Contains($n1) -or $c.Contains($n2)) '
                    . '} | ForEach-Object { try { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue } catch {} }';
                @exec('powershell -NoProfile -ExecutionPolicy Bypass -Command ' . escapeshellarg($ps) . ' 2>NUL');
            } else {
                @exec('pkill -f ' . escapeshellarg($needle) . ' 2>/dev/null');
            }
        } catch (\Throwable $e) {
            Log::warning('killWorkerProcessesForVideo failed', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function writeCancelFlag(int $videoId): void
    {
        try {
            Storage::disk('local')->put('cancel-flags/' . $videoId, (string) time());
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public static function clearCancelFlag(int $videoId): void
    {
        try {
            Storage::disk('local')->delete('cancel-flags/' . $videoId);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function hasCancelFlag(): bool
    {
        try {
            return Storage::disk('local')->exists('cancel-flags/' . (int) $this->video->id);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function markStatus(string $status, array $extra = []): void
    {
        if ($status === 'processing' && $this->isCancelled()) {
            throw new RuntimeException('CANCELLED');
        }

        DB::table('videos')
            ->where('id', $this->video->id)
            ->update(array_merge([
                'status' => $status,
                'updated_at' => now(),
            ], $extra));
    }

    private function updateProgress(int $percent): void
    {
        $this->assertNotCancelled();

        $percent = max(0, min(100, $percent));
        $now = microtime(true);

        if (
            $percent !== 100
            && $percent < $this->lastPersistedProgress + 5
            && ($now - $this->lastProgressWriteAt) < 5
        ) {
            return;
        }

        $this->lastPersistedProgress = $percent;
        $this->lastProgressWriteAt = $now;

        try {
            $currentStatus = DB::table('videos')->where('id', $this->video->id)->value('status');
            if ((string) $currentStatus === 'cancelled') {
                throw new RuntimeException('CANCELLED');
            }

            $updates = [
                'progress' => $percent,
                'updated_at' => now(),
            ];

            if (
                $percent > 0
                && $percent < 100
                && !in_array((string) $currentStatus, ['processed', 'failed', 'cancelled'], true)
            ) {
                $updates['status'] = 'processing';
                if (empty(DB::table('videos')->where('id', $this->video->id)->value('processing_started_at'))) {
                    $updates['processing_started_at'] = now();
                }
            }

            DB::table('videos')->where('id', $this->video->id)->update($updates);
        } catch (RuntimeException $e) {
            if ($this->isCancelException($e)) {
                throw $e;
            }
        } catch (Throwable $e) {
            // non-fatal
        }
    }

    private function assertNotCancelled(): void
    {
        if ($this->isCancelled()) {
            throw new RuntimeException('CANCELLED');
        }
    }

    private function isCancelled(): bool
    {
        if ($this->hasCancelFlag()) {
            return true;
        }
        try {
            $status = (string) DB::table('videos')->where('id', $this->video->id)->value('status');
            return $status === 'cancelled';
        } catch (Throwable $e) {
            return false;
        }
    }

    private function isCancelException(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'CANCELLED');
    }

    private function bindProgressCallback(int $base, int $span): callable
    {
        return function ($percentage) use ($base, $span) {
            $mapped = $base + (int) floor((max(0, min(100, (float) $percentage)) / 100) * $span);
            $this->updateProgress($mapped);
        };
    }

    private function storeVideoRecord(string $type, ?int $quality, string $path): void
    {
        $disk = $this->outputDisk !== '' ? $this->outputDisk : (string) $this->video->disk;
        $payload = [
            'duration' => $this->video->duration,
            'quality' => $quality,
            'disk' => $disk,
            'updated_at' => now(),
        ];

        $existing = DB::table('videos')
            ->where('videoable_id', $this->video->videoable_id)
            ->where('videoable_type', $this->video->videoable_type)
            ->where('type', $type)
            ->where('path', $path)
            ->first();

        if ($existing) {
            DB::table('videos')->where('id', $existing->id)->update($payload);
            return;
        }

        DB::table('videos')->insert(array_merge($payload, [
            'videoable_id' => $this->video->videoable_id,
            'videoable_type' => $this->video->videoable_type,
            'type' => $type,
            'path' => $path,
            'created_at' => now(),
        ]));
    }

    private function makeVideoFormatForQuality(int $quality, ?int $sourceBitrateKbps = null): X264
    {
        $targetVideoBitrate = $this->bitrateFor($quality);
        if ($sourceBitrateKbps && $targetVideoBitrate > $sourceBitrateKbps) {
            $targetVideoBitrate = (int) floor($sourceBitrateKbps * 0.95);
        }

        $encoder = $this->videoEncoder();
        $allowed = (new H264Format())->getAvailableVideoCodecs();
        if (!in_array($encoder, $allowed, true)) {
            $encoder = 'libx264';
        }

        // php-ffmpeg defaults to 2-pass when bitrate is set — that doubles encode time.
        // H264Format whitelists NVENC; stock X264 only allows libx264 and throws otherwise.
        return (new H264Format('aac', $encoder))
            ->setKiloBitrate($targetVideoBitrate)
            ->setAudioKiloBitrate($this->audioBitrateFor($quality))
            ->setPasses(1)
            ->setAdditionalParameters($this->x264CommonParams($quality, $targetVideoBitrate));
    }

    private function x264CommonParams(int $quality, int $videoBitrateKbps): array
    {
        $encoder = $this->videoEncoder();
        $maxrate = (int) round($videoBitrateKbps * 1.35);
        $bufsize = (int) round($videoBitrateKbps * 2.2);
        $cq = $this->crfFor($quality);

        if (str_contains($encoder, 'nvenc')) {
            // p4 = strong quality/speed balance on modern NVIDIA (much faster than libx264, no ultrafast mush).
            $preset = env('VIDEO_NVENC_PRESET', 'p4');

            return [
                '-preset', $preset,
                '-rc', 'vbr',
                '-cq', (string) $cq,
                '-maxrate', $maxrate . 'k',
                '-bufsize', $bufsize . 'k',
                '-profile:v', 'high',
                '-spatial-aq', '1',
                '-temporal-aq', '1',
                '-g', '48',
                '-pix_fmt', 'yuv420p',
            ];
        }

        // CPU fallback: veryfast keeps quality far above ultrafast while staying quick.
        $preset = env('VIDEO_X264_PRESET', 'veryfast');

        return [
            '-preset', $preset,
            '-crf', (string) $cq,
            '-maxrate', $maxrate . 'k',
            '-bufsize', $bufsize . 'k',
            '-profile:v', 'high',
            '-level', '4.1',
            '-threads', '0',
            '-g', '48',
            '-keyint_min', '48',
            '-sc_threshold', '0',
            '-pix_fmt', 'yuv420p',
        ];
    }

    private function bitrateFor(int $quality): int
    {
        return match ($quality) {
            144 => 200,
            240 => 350,
            360 => 600,
            480 => 1000,
            720 => 1800,
            1080 => 3500,
            default => 1800,
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
        // Lower = better quality. Tuned for NVENC CQ / x264 CRF with VBV cap.
        return match ($quality) {
            1080 => 20,
            720 => 21,
            480 => 23,
            default => 24,
        };
    }

    private function videoEncoder(): string
    {
        $configured = strtolower(trim((string) env('VIDEO_ENCODER', 'auto')));
        if ($configured !== '' && $configured !== 'auto') {
            return $configured;
        }

        return $this->resolveAutoEncoder();
    }

    private function resolveAutoEncoder(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        try {
            $result = Process::timeout(20)->run([
                $this->ffmpegBinary(),
                '-hide_banner',
                '-f', 'lavfi',
                '-i', 'color=c=black:s=256x256:d=0.2',
                '-c:v', 'h264_nvenc',
                '-preset', 'p4',
                '-f', 'null',
                '-',
            ]);
            $resolved = $result->successful() ? 'h264_nvenc' : 'libx264';
        } catch (Throwable $e) {
            $resolved = 'libx264';
            Log::warning('ProcessVideo NVENC probe failed, using libx264', [
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('ProcessVideo encoder selected', ['encoder' => $resolved]);

        return $resolved;
    }
}
