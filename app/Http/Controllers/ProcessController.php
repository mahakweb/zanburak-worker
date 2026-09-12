<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVideo;
use App\Models\Video;
use App\Services\VerifyUploadTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessController extends Controller
{
    public function reprocess(Request $request, Video $video)
    {
        $auth = $request->bearerToken();
        if (!$auth) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $claims = VerifyUploadTokenService::verify($auth);
        if (!$claims || ($claims['type'] ?? null) !== 'process') {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        if ((int) ($claims['videoId'] ?? 0) !== (int) $video->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($video->type !== 'raw') {
            $raw = Video::query()
                ->where('videoable_id', $video->videoable_id)
                ->where('videoable_type', $video->videoable_type)
                ->where('type', 'raw')
                ->first();

            if (!$raw) {
                return response()->json(['message' => 'Raw video not found'], 404);
            }

            $video = $raw;
        }

        $hasLocalInbox = false;
        if (is_string($video->path) && $video->path !== '') {
            $hasLocalInbox = Storage::disk('local')->files(ProcessVideo::rawInboxRelative($video->path)) !== [];
        }
        if (!$hasLocalInbox) {
            $hasLocalInbox = Storage::disk('local')->files('raw-inbox/video-' . (int) $video->id) !== [];
        }

        if (!$hasLocalInbox) {
            if (!$video->path || !Storage::disk($video->disk)->exists($video->path)) {
                return response()->json(['message' => 'Raw video file is missing'], 422);
            }
        }

        $video->refresh();

        // Job is already encoding (progress moves even if status was wrongly reset to queued).
        $isActivelyProcessing = $this->isActivelyProcessing($video);

        if ($isActivelyProcessing) {
            // Never wipe an in-flight encode — just report live status.
            if ((string) $video->status !== 'processing') {
                DB::table('videos')->where('id', $video->id)->update([
                    'status' => 'processing',
                    'processing_started_at' => $video->processing_started_at ?: now(),
                    'updated_at' => now(),
                ]);
                $video->refresh();
            }

            return response()->json([
                'message' => 'Video is already being processed.',
                'video_id' => $video->id,
                'status' => 'processing',
                'progress' => $video->progress,
            ], 200);
        }

        $videoableId = $video->videoable_id;
        $videoableType = $video->videoable_type;

        $options = $this->normalizeProcessOptions($request);

        if ($request->hasFile('watermark_image')) {
            $file = $request->file('watermark_image');
            $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                $ext = 'png';
            }
            $rel = 'watermarks/' . $video->id . '_' . uniqid('', true) . '.' . $ext;
            Storage::disk('local')->makeDirectory('watermarks');
            Storage::disk('local')->putFileAs('watermarks', $file, basename($rel));
            $options['watermark']['enabled'] = true;
            $options['watermark']['type'] = 'image';
            $options['watermark']['image_path'] = $rel;
        }

        DB::table('videos')
            ->where('id', $video->id)
            ->update([
                'status' => 'queued',
                'progress' => 0,
                'error_message' => null,
                'processing_started_at' => null,
                'updated_at' => now(),
            ]);

        $video->refresh();

        ProcessVideo::dispatch($video, $options)->onConnection('database')->onQueue('videos');

        Log::info('Video reprocess dispatched', [
            'video_id' => $video->id,
            'videoable_type' => $videoableType,
            'videoable_id' => $videoableId,
            'options' => $options,
        ]);

        return response()->json([
            'message' => 'Reprocessing started.',
            'video_id' => $video->id,
            'status' => 'queued',
            'progress' => 0,
            'options' => $options,
        ], 200);
    }

    public function cancel(Request $request, Video $video)
    {
        $auth = $request->bearerToken();
        if (!$auth) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $claims = VerifyUploadTokenService::verify($auth);
        if (!$claims || ($claims['type'] ?? null) !== 'process') {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        if ((int) ($claims['videoId'] ?? 0) !== (int) $video->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($video->type !== 'raw') {
            $raw = Video::query()
                ->where('videoable_id', $video->videoable_id)
                ->where('videoable_type', $video->videoable_type)
                ->where('type', 'raw')
                ->first();

            if (!$raw) {
                return response()->json(['message' => 'Raw video not found'], 404);
            }

            $video = $raw;
        }

        $status = (string) $video->status;
        if (!in_array($status, ['queued', 'processing', 'uploaded', 'cancelled', 'failed'], true)) {
            return response()->json([
                'message' => 'Video is not in a cancellable state.',
                'status' => $status,
            ], 422);
        }

        $wasProcessing = $status === 'processing' || (int) ($video->progress ?? 0) > 0;

        ProcessVideo::writeCancelFlag((int) $video->id);

        // Signal in-flight job to stop between stages / FTP puts / ffmpeg progress.
        DB::table('videos')->where('id', $video->id)->update([
            'status' => 'cancelled',
            'progress' => null,
            'error_message' => null,
            'processing_started_at' => null,
            'updated_at' => now(),
        ]);

        // Drop pending/reserved queue jobs for this videoable.
        $payloadNeedle = '"videoable_id";i:' . (int) $video->videoable_id;
        try {
            DB::table('jobs')
                ->where('queue', 'videos')
                ->where('payload', 'like', '%' . $payloadNeedle . '%')
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('Failed deleting queued ProcessVideo jobs on cancel', [
                'video_id' => $video->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Hard-kill ffmpeg/curl for this video's workdir so cancel is immediate.
        ProcessVideo::killWorkerProcessesForVideo((int) $video->id);

        // Always return to uploaded so admin can reprocess.
        DB::table('videos')->where('id', $video->id)->update([
            'status' => 'uploaded',
            'progress' => null,
            'error_message' => null,
            'updated_at' => now(),
        ]);

        Log::info('Video processing cancel requested', [
            'video_id' => $video->id,
            'was_processing' => $wasProcessing,
        ]);

        return response()->json([
            'message' => 'Processing cancelled.',
            'video_id' => $video->id,
            'status' => 'uploaded',
        ], 200);
    }

    private function normalizeProcessOptions(Request $request): array
    {
        $normalizeList = function ($value): array {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : array_filter(array_map('intval', explode(',', $value)));
            }
            if (!is_array($value)) {
                return [];
            }
            return array_values(array_unique(array_filter(array_map('intval', $value))));
        };

        $qualities = $normalizeList($request->input('qualities', []));
        $streamQualities = $normalizeList($request->input('stream_qualities', $qualities));
        $downloadQualities = $normalizeList($request->input('download_qualities', $qualities));

        $outputs = $request->input('outputs', ['stream']);
        if (is_string($outputs)) {
            $decoded = json_decode($outputs, true);
            $outputs = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $outputs)));
        }
        if (!is_array($outputs) || $outputs === []) {
            $outputs = ['stream'];
        }
        $outputs = array_values(array_intersect($outputs, ['stream', 'download', 'trailer']));

        $storageDisk = strtolower(trim((string) $request->input('storage_disk', env('VIDEO_DISK', 'dl'))));
        if (!in_array($storageDisk, ['dl', 'static', 'media'], true)) {
            $storageDisk = 'dl';
        }

        $watermark = $request->input('watermark');
        if (!is_array($watermark)) {
            $watermark = [
                'enabled' => $request->boolean('watermark_enabled'),
                'type' => (string) $request->input('watermark_type', 'text'),
                'text' => (string) $request->input('watermark_text', ''),
                'position' => (string) $request->input('watermark_position', 'bottom-right'),
                'opacity' => (float) $request->input('watermark_opacity', 0.7),
                'font_size' => (int) $request->input('watermark_font_size', 32),
                'image_scale' => (float) $request->input('watermark_image_scale', 0.18),
                'font' => (string) $request->input('watermark_font', 'tahoma'),
                'font_color' => (string) $request->input('watermark_font_color', '#FFFFFF'),
                'bg_enabled' => $request->boolean('watermark_bg_enabled'),
                'bg_color' => (string) $request->input('watermark_bg_color', '#000000'),
                'bg_opacity' => (float) $request->input('watermark_bg_opacity', 0.45),
                'bg_padding' => (int) $request->input('watermark_bg_padding', 10),
                'bg_radius' => (int) $request->input('watermark_bg_radius', 8),
                'image_radius' => (int) $request->input('watermark_image_radius', 0),
                'border_enabled' => $request->boolean('watermark_border_enabled', true),
                'border_color' => (string) $request->input('watermark_border_color', '#000000'),
                'border_width' => (int) $request->input('watermark_border_width', 2),
            ];
        }
        $wmType = (($watermark['type'] ?? 'text') === 'image') ? 'image' : 'text';
        $wmPos = (string) ($watermark['position'] ?? 'bottom-right');
        if (!in_array($wmPos, ['top-left', 'top-right', 'center', 'bottom-left', 'bottom-right'], true)) {
            $wmPos = 'bottom-right';
        }

        return [
            'qualities' => $qualities,
            'stream_qualities' => $streamQualities,
            'download_qualities' => $downloadQualities,
            'outputs' => $outputs,
            'storage_disk' => $storageDisk,
            'watermark' => [
                'enabled' => (bool) ($watermark['enabled'] ?? false),
                'type' => $wmType,
                'text' => mb_substr(trim((string) ($watermark['text'] ?? '')), 0, 80),
                'position' => $wmPos,
                'opacity' => max(0.05, min(1.0, (float) ($watermark['opacity'] ?? 0.7))),
                'font_size' => max(12, min(96, (int) ($watermark['font_size'] ?? 32))),
                'image_scale' => max(0.05, min(0.5, (float) ($watermark['image_scale'] ?? 0.18))),
                'image_path' => (string) ($watermark['image_path'] ?? ''),
                'font' => (string) ($watermark['font'] ?? 'tahoma'),
                'font_color' => (string) ($watermark['font_color'] ?? '#FFFFFF'),
                'bg_enabled' => (bool) ($watermark['bg_enabled'] ?? false),
                'bg_color' => (string) ($watermark['bg_color'] ?? '#000000'),
                'bg_opacity' => max(0.05, min(1.0, (float) ($watermark['bg_opacity'] ?? 0.45))),
                'bg_padding' => max(0, min(48, (int) ($watermark['bg_padding'] ?? 10))),
                'bg_radius' => max(0, min(40, (int) ($watermark['bg_radius'] ?? 8))),
                'image_radius' => max(0, min(48, (int) ($watermark['image_radius'] ?? 0))),
                'border_enabled' => (bool) ($watermark['border_enabled'] ?? true),
                'border_color' => (string) ($watermark['border_color'] ?? '#000000'),
                'border_width' => max(0, min(8, (int) ($watermark['border_width'] ?? 2))),
            ],
        ];
    }

    private function isActivelyProcessing(Video $video): bool
    {
        if ($video->status === 'processing') {
            return true;
        }

        // Progress advancing while status is still queued = encode in flight.
        if (in_array($video->status, ['queued', 'uploaded'], true) && (int) ($video->progress ?? 0) > 0) {
            return true;
        }

        // Reserved queue job for this videoable.
        $payloadNeedle = '"videoable_id";i:' . (int) $video->videoable_id;
        $hasReserved = DB::table('jobs')
            ->where('queue', 'videos')
            ->whereNotNull('reserved_at')
            ->where('payload', 'like', '%' . $payloadNeedle . '%')
            ->exists();

        return $hasReserved;
    }
}
