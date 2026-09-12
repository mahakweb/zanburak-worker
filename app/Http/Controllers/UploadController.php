<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Services\VerifyUploadTokenService;
use App\Models\Video;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Jobs\ProcessVideo;
use App\Services\FtpBinaryPut;

class UploadController extends Controller
{
    public function video(Request $request)
    {
        // Large FTP uploads routinely exceed PHP's default 60s limit.
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');

        $validator = Validator::make($request->all(), [
            'path' => ['required', 'string'],
            'file' => ['required', 'file'],
        ]);
        if (!$validator->passes()) {
            return response()->json(['message' => 'Validation error!', 'errors' => $validator->errors()->toArray()], 422);
        }

        $ext = strtolower(pathinfo((string) $request->input('path'), PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'mkv', 'webm', 'mov'], true)) {
            return response()->json(['message' => 'Invalid video extension'], 422);
        }

        $file = $request->file('file');
        $clientMime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType() ?: ''));
        $allowedMimes = [
            'video/mp4',
            'video/x-matroska',
            'video/webm',
            'video/quicktime',
            'application/octet-stream',
            '',
        ];
        // Extension already validated; accept common browser MIME quirks.
        if ($clientMime !== '' && !in_array($clientMime, $allowedMimes, true) && !str_starts_with($clientMime, 'video/')) {
            return response()->json([
                'message' => 'Invalid video MIME type',
                'mime' => $clientMime,
            ], 422);
        }

        $auth = $request->bearerToken();
        if (!$auth) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $claims = VerifyUploadTokenService::verify($auth);
        if (!$claims) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        if (($claims['type'] ?? null) !== 'video') {
            return response()->json(['message' => 'Invalid token type'], 403);
        }
        if (($claims['path'] ?? null) !== $request->input('path')) {
            return response()->json(['message' => 'Path mismatch'], 422);
        }

        $disk = $claims['disk'] ?? 'static';
        if ((int) $file->getSize() > (int) ($claims['size'] ?? 0)) {
            return response()->json(['message' => 'File too large'], 422);
        }

        $localTemp = $file->getRealPath() ?: $file->getPathname();
        return $this->finalizeVideoFromLocalPath($localTemp, $claims, $request, (int) $file->getSize());
    }

    /**
     * Chunked upload — avoids HTTP 413 from reverse proxies / PHP body limits on large videos.
     */
    public function videoChunk(Request $request)
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');

        $validator = Validator::make($request->all(), [
            'path' => ['required', 'string'],
            'file' => ['required', 'file'],
            'chunkIndex' => ['required', 'integer', 'min:0'],
            'totalChunks' => ['required', 'integer', 'min:1'],
        ]);
        if (!$validator->passes()) {
            return response()->json(['message' => 'Validation error!', 'errors' => $validator->errors()->toArray()], 422);
        }

        $ext = strtolower(pathinfo((string) $request->input('path'), PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp4', 'mkv', 'webm', 'mov'], true)) {
            return response()->json(['message' => 'Invalid video extension'], 422);
        }

        $auth = $request->bearerToken();
        if (!$auth) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $claims = VerifyUploadTokenService::verify($auth);
        if (!$claims) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        if (($claims['type'] ?? null) !== 'video') {
            return response()->json(['message' => 'Invalid token type'], 403);
        }
        if (($claims['path'] ?? null) !== $request->input('path')) {
            return response()->json(['message' => 'Path mismatch'], 422);
        }

        $chunkIndex = (int) $request->input('chunkIndex');
        $totalChunks = (int) $request->input('totalChunks');
        if ($chunkIndex >= $totalChunks) {
            return response()->json(['message' => 'Invalid chunk index'], 422);
        }

        $file = $request->file('file');
        $expectedSize = (int) ($claims['size'] ?? 0);
        $chunkDir = 'upload-chunks/' . md5((string) $claims['path']);
        Storage::disk('local')->makeDirectory($chunkDir);

        $partRel = $chunkDir . '/' . $chunkIndex . '.part';
        $stored = Storage::disk('local')->putFileAs($chunkDir, $file, $chunkIndex . '.part');
        if (!$stored) {
            return response()->json(['message' => 'Failed to store chunk'], 500);
        }

        $receivedBytes = 0;
        for ($i = 0; $i < $totalChunks; $i++) {
            $rel = $chunkDir . '/' . $i . '.part';
            if (!Storage::disk('local')->exists($rel)) {
                if ($i === $chunkIndex) {
                    continue;
                }
                // Not all chunks yet (except we just wrote current).
                if ($chunkIndex < $totalChunks - 1 || $i < $totalChunks) {
                    // fall through — check completeness below
                }
            }
        }

        $missing = [];
        for ($i = 0; $i < $totalChunks; $i++) {
            $rel = $chunkDir . '/' . $i . '.part';
            if (!Storage::disk('local')->exists($rel)) {
                $missing[] = $i;
            } else {
                $receivedBytes += (int) Storage::disk('local')->size($rel);
            }
        }

        if ($expectedSize > 0 && $receivedBytes > $expectedSize + 1024) {
            try {
                Storage::disk('local')->deleteDirectory($chunkDir);
            } catch (\Throwable $e) {
                // ignore
            }
            return response()->json(['message' => 'File too large'], 422);
        }

        if ($missing !== []) {
            return response()->json([
                'message' => 'Chunk received',
                'chunkIndex' => $chunkIndex,
                'totalChunks' => $totalChunks,
                'complete' => false,
            ], 200);
        }

        // Assemble all parts into one temp file on local disk.
        $assembledRel = $chunkDir . '/assembled.' . $ext;
        $assembledAbs = Storage::disk('local')->path($assembledRel);
        $out = fopen($assembledAbs, 'wb');
        if ($out === false) {
            return response()->json(['message' => 'Unable to assemble upload'], 500);
        }
        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $partAbs = Storage::disk('local')->path($chunkDir . '/' . $i . '.part');
                $in = fopen($partAbs, 'rb');
                if ($in === false) {
                    fclose($out);
                    return response()->json(['message' => 'Missing chunk during assemble'], 500);
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
        }

        if ($expectedSize > 0 && abs(filesize($assembledAbs) - $expectedSize) > 1024) {
            // Allow small variance; hard fail if drastically short.
            if (filesize($assembledAbs) < (int) ($expectedSize * 0.98)) {
                try {
                    Storage::disk('local')->deleteDirectory($chunkDir);
                } catch (\Throwable $e) {
                    // ignore
                }
                return response()->json(['message' => 'Assembled file size mismatch'], 422);
            }
        }

        try {
            $response = $this->finalizeVideoFromLocalPath($assembledAbs, $claims, $request, (int) filesize($assembledAbs));
        } finally {
            try {
                Storage::disk('local')->deleteDirectory($chunkDir);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $response;
    }

    /**
     * Stage inbox + FTP + DB from an already-local absolute file path.
     */
    private function finalizeVideoFromLocalPath(string $localAbsolutePath, array $claims, Request $request, int $byteSize)
    {
        $ext = strtolower(pathinfo((string) ($claims['path'] ?? ''), PATHINFO_EXTENSION)) ?: 'mp4';
        $disk = $claims['disk'] ?? 'static';

        $meta = $this->probeLocalMeta($localAbsolutePath);
        $duration = (int) ($meta['duration'] ?? 0);
        $sourceHeight = (int) ($meta['height'] ?? 0);

        $inboxRel = ProcessVideo::rawInboxRelative($claims['path']);
        try {
            Storage::disk('local')->deleteDirectory($inboxRel);
        } catch (\Throwable $e) {
            // ignore
        }
        Storage::disk('local')->makeDirectory($inboxRel);
        $inboxAbs = Storage::disk('local')->path($inboxRel . '/input.' . $ext);
        $inboxOk = @copy($localAbsolutePath, $inboxAbs);
        if (!$inboxOk) {
            Log::warning('Failed to stage local raw inbox; process will fall back to FTP download', [
                'path' => $claims['path'],
            ]);
        }

        $sourceForStore = ($inboxOk && is_file($inboxAbs)) ? $inboxAbs : $localAbsolutePath;
        $remoteRelative = ltrim(str_replace('\\', '/', (string) $claims['path']), '/');
        $storeOk = false;
        $driver = (string) (config("filesystems.disks.{$disk}.driver") ?? '');

        try {
            if ($driver === 'ftp') {
                // Avoid Flysystem writeStream/exists/makeDirectory — they trigger flaky ftp_fput errors.
                FtpBinaryPut::put((array) config("filesystems.disks.{$disk}"), $remoteRelative, $sourceForStore);
            } else {
                $parent = dirname($remoteRelative);
                if ($parent !== '.' && $parent !== '') {
                    Storage::disk($disk)->makeDirectory($parent);
                }
                $stream = fopen($sourceForStore, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('Unable to open local upload for storage');
                }
                try {
                    if (Storage::disk($disk)->put($remoteRelative, $stream) === false) {
                        throw new \RuntimeException('Unable to store upload on disk ' . $disk);
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }
            $storeOk = true;
        } catch (\Throwable $e) {
            Log::error('Upload store failed', [
                'error' => $e->getMessage(),
                'disk' => $disk,
                'driver' => $driver,
                'path' => $claims['path'],
                'inbox' => $inboxOk,
            ]);

            // Local inbox is enough to process immediately; keep going so admin is not blocked.
            if (!$inboxOk) {
                return response()->json([
                    'message' => 'Failed to store file',
                    'error' => config('app.debug') ? $e->getMessage() : null,
                ], 500);
            }
        }

        $videoableId = $claims['episodeId'] ?? $claims['courseId'] ?? $claims['pathId'] ?? null;
        if (!$videoableId) {
            return response()->json(['message' => 'Missing videoable target in token'], 422);
        }

        $videoableType = isset($claims['episodeId'])
            ? 'App\\Models\\Episode'
            : (isset($claims['courseId']) ? 'App\\Models\\Course' : 'App\\Models\\Path');

        $this->removeOldVideos($videoableId, $videoableType);

        $shouldProcess = array_key_exists('process', $claims)
            ? (bool) $claims['process']
            : $request->boolean('process', false);

        $processOptions = $this->normalizeProcessOptions($request, $claims);

        $video = Video::create([
            'type' => 'raw',
            'path' => $claims['path'],
            'disk' => $disk,
            'duration' => (int) $duration,
            'videoable_id' => $videoableId,
            'videoable_type' => $videoableType,
            'status' => $shouldProcess ? 'queued' : 'uploaded',
            'progress' => $shouldProcess ? 0 : null,
            'error_message' => null,
            'processing_started_at' => null,
        ]);

        if ($inboxOk) {
            try {
                $byIdRel = 'raw-inbox/video-' . $video->id;
                Storage::disk('local')->deleteDirectory($byIdRel);
                Storage::disk('local')->makeDirectory($byIdRel);
                @copy($inboxAbs, Storage::disk('local')->path($byIdRel . '/input.' . $ext));
            } catch (\Throwable $e) {
                Log::warning('Failed to mirror raw inbox by video id', [
                    'video_id' => $video->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (isset($claims['episodeId'])) {
            $this->updateEpisodeDuration($claims['episodeId'], (int) $duration);
        }

        if ($shouldProcess) {
            ProcessVideo::dispatch($video, $processOptions)->onConnection('database')->onQueue('videos');
            Log::info('ProcessVideo dispatched after upload', [
                'video_id' => $video->id,
                'queue' => 'videos',
                'options' => $processOptions,
            ]);
        }

        $diskUrl = config("filesystems.disks.$disk.url");
        $publicUrl = $diskUrl
            ? rtrim($diskUrl, '/') . '/' . ltrim($claims['path'] ?? '', '/')
            : ($claims['path'] ?? '');

        return response()->json([
            'message' => $storeOk
                ? 'File uploaded successfully.'
                : 'File staged locally; remote sync deferred (will retry during processing).',
            'video_id' => $video->id,
            'path' => $publicUrl,
            'size' => $byteSize,
            'duration' => (int) $duration,
            'height' => $sourceHeight > 0 ? $sourceHeight : null,
            'status' => $video->status,
            'store_ok' => $storeOk,
            'ftp_ok' => $storeOk,
        ], 200);
    }

    public function attachment(Request $request)
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        $validator = Validator::make($request->all(), [
            'path' => ['required', 'string'],
            'file' => ['required', 'file'],
        ]);
        if (!$validator->passes()) {
            return response()->json(['message' => 'Validation error!', 'errors' => $validator->errors()->toArray()], 422);
        }

        $auth = $request->bearerToken();
        if (!$auth) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $claims = VerifyUploadTokenService::verify($auth);
        if (!$claims) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        if (! in_array($claims['type'] ?? null, ['attachment', 'poster', 'cover'], true)) {
            return response()->json(['message' => 'Invalid token type'], 403);
        }
        if (($claims['path'] ?? null) !== $request->input('path')) {
            return response()->json(['message' => 'Path mismatch'], 422);
        }

        $disk = $claims['disk'] ?? 'static';
        $file = $request->file('file');
        if ((int) $file->getSize() > (int) ($claims['size'] ?? 0)) {
            return response()->json(['message' => 'File too large'], 422);
        }

        try {
            $dir = ltrim(dirname($claims['path']), '/');
            $basename = basename($claims['path']);
            if (!Storage::disk($disk)->exists($dir)) {
                Storage::disk($disk)->makeDirectory($dir);
            }
            $ok = Storage::disk($disk)->putFileAs($dir, $file, $basename);
            if (!$ok) {
                Log::error('Attachment failed to write file (putFileAs)', ['disk' => $disk, 'dir' => $dir, 'name' => $basename]);
                return response()->json(['message' => 'Failed to store file'], 500);
            }
        } catch (\Throwable $e) {
            Log::error('Attachment exception on write (putFileAs)', [
                'error' => $e->getMessage(),
                'disk' => $disk,
                'path' => $claims['path'],
            ]);
            return response()->json(['message' => 'Failed to store file'], 500);
        }

        $diskUrl = config("filesystems.disks.$disk.url");
        $url = $diskUrl ? rtrim($diskUrl, '/') . '/' . ltrim($claims['path'], '/') : $claims['path'];

        if (($claims['type'] ?? null) === 'poster' && isset($claims['courseId'])) {
            $this->removeOldCoursePoster($claims['courseId']);
            DB::table('courses')->where('id', $claims['courseId'])->update(['poster' => $url, 'updated_at' => now()]);
        } elseif (($claims['type'] ?? null) === 'poster' && isset($claims['pathId'])) {
            $this->removeOldPathPoster($claims['pathId']);
            DB::table('paths')->where('id', $claims['pathId'])->update(['poster' => $url, 'updated_at' => now()]);
        } elseif (($claims['type'] ?? null) === 'attachment' && isset($claims['pathId'])) {
            $this->removeOldPathIcon($claims['pathId']);
            DB::table('paths')->where('id', $claims['pathId'])->update(['icon' => $url, 'updated_at' => now()]);
        } elseif (($claims['type'] ?? null) === 'cover' && isset($claims['articleId'])) {
            $this->removeOldArticleCover($claims['articleId']);
            DB::table('articles')->where('id', $claims['articleId'])->update(['cover_image' => $url, 'updated_at' => now()]);
        } else {
            $attachableType = isset($claims['episodeId']) ? 'App\\Models\\Episode' : 'App\\Models\\Course';
            $attachableId = $claims['episodeId'] ?? $claims['courseId'];
            $replace = array_key_exists('replace', $claims)
                ? (bool) $claims['replace']
                : empty($claims['episodeId']);
            if ($replace) {
                $this->removeOldAttachments($attachableId, $attachableType);
            }

            DB::table('attaches')->insert([
                'attachable_id' => $attachableId,
                'attachable_type' => $attachableType,
                'url' => $url,
                'title' => $this->attachmentTitleFromClaims($claims),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'File uploaded successfully.',
            'path' => $url,
            'size' => (int) $file->getSize(),
        ], 200);
    }

    private function normalizeProcessOptions(Request $request, array $claims = []): array
    {
        $qualities = $request->input('qualities', $claims['qualities'] ?? null);
        if (is_string($qualities)) {
            $decoded = json_decode($qualities, true);
            $qualities = is_array($decoded) ? $decoded : array_filter(array_map('intval', explode(',', $qualities)));
        }
        if (!is_array($qualities)) {
            $qualities = [];
        }
        $qualities = array_values(array_unique(array_filter(array_map('intval', $qualities))));

        $outputs = $request->input('outputs', $claims['outputs'] ?? ['stream']);
        if (is_string($outputs)) {
            $decoded = json_decode($outputs, true);
            $outputs = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $outputs)));
        }
        if (!is_array($outputs) || $outputs === []) {
            $outputs = ['stream'];
        }
        $outputs = array_values(array_intersect($outputs, ['stream', 'download', 'trailer']));

        return [
            'qualities' => $qualities,
            'outputs' => $outputs,
        ];
    }

    private function probeLocalMeta(?string $localPath): array
    {
        if (!$localPath || !is_file($localPath)) {
            return ['duration' => 0, 'height' => 0];
        }

        try {
            $ffprobe = env('FFPROBE_BINARIES', 'ffprobe');
            $cmd = escapeshellcmd($ffprobe) . ' -v quiet -print_format json -show_format -show_streams ' . escapeshellarg($localPath);
            $output = shell_exec($cmd);
            if (!$output) {
                return ['duration' => 0, 'height' => 0];
            }
            $data = json_decode($output, true) ?: [];
            $duration = isset($data['format']['duration']) ? (int) round((float) $data['format']['duration']) : 0;
            $height = 0;
            foreach (($data['streams'] ?? []) as $stream) {
                if (($stream['codec_type'] ?? '') === 'video' && !empty($stream['height'])) {
                    $height = (int) $stream['height'];
                    break;
                }
            }
            return ['duration' => $duration, 'height' => $height];
        } catch (\Throwable $e) {
            Log::warning('Local meta probing failed', ['error' => $e->getMessage()]);
            return ['duration' => 0, 'height' => 0];
        }
    }

    private function probeLocalDuration(?string $localPath): int
    {
        return (int) ($this->probeLocalMeta($localPath)['duration'] ?? 0);
    }

    private function removeOldCoursePoster($courseId): void
    {
        $row = DB::table('courses')->select('poster')->where('id', $courseId)->first();
        if ($row?->poster) {
            $this->deleteFileFromUrl($row->poster);
        }
    }

    private function removeOldPathPoster($pathId): void
    {
        $row = DB::table('paths')->select('poster')->where('id', $pathId)->first();
        if ($row?->poster) {
            $this->deleteFileFromUrl($row->poster);
        }
    }

    private function removeOldPathIcon($pathId): void
    {
        $row = DB::table('paths')->select('icon')->where('id', $pathId)->first();
        if ($row?->icon) {
            $this->deleteFileFromUrl($row->icon);
        }
    }

    private function removeOldArticleCover($articleId): void
    {
        $row = DB::table('articles')->select('cover_image')->where('id', $articleId)->first();
        if ($row?->cover_image) {
            $this->deleteFileFromUrl($row->cover_image);
        }
    }

    private function attachmentTitleFromClaims(array $claims): string
    {
        $title = trim((string) ($claims['title'] ?? ''));
        if ($title !== '') {
            return mb_substr($title, 0, 255);
        }
        $filename = (string) ($claims['filename'] ?? '');
        $base = $filename !== '' ? pathinfo($filename, PATHINFO_FILENAME) : '';
        if ($base !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $base)) {
            return mb_substr($base, 0, 255);
        }
        return mb_substr((string) ($claims['filename'] ?? basename($claims['path'] ?? 'file')), 0, 255);
    }

    private function removeOldAttachments($attachableId, $attachableType): void
    {
        $rows = DB::table('attaches')
            ->where('attachable_id', $attachableId)
            ->where('attachable_type', $attachableType)
            ->get();

        foreach ($rows as $row) {
            try {
                $this->deleteFileFromUrl($row->url ?? null);
            } catch (\Throwable $e) {
                Log::warning('Failed to delete old attachment file', ['error' => $e->getMessage()]);
            }
        }

        DB::table('attaches')
            ->where('attachable_id', $attachableId)
            ->where('attachable_type', $attachableType)
            ->delete();
    }

    /**
     * Remove old videos for course or episode.
     * DB rows are deleted first; FTP cleanup is best-effort so uploads are not blocked.
     */
    private function removeOldVideos($videoableId, $videoableType): void
    {
        $videos = DB::table('videos')
            ->where('videoable_id', $videoableId)
            ->where('videoable_type', $videoableType)
            ->get();

        DB::table('videos')
            ->where('videoable_id', $videoableId)
            ->where('videoable_type', $videoableType)
            ->delete();

        if ($videoableType === 'App\\Models\\Episode') {
            $this->updateEpisodeDuration($videoableId, 0);
        }

        foreach ($videos as $video) {
            try {
                $this->deleteVideoFiles($video);
            } catch (\Throwable $e) {
                Log::warning('Best-effort old video cleanup failed', [
                    'video_id' => $video->id ?? null,
                    'path' => $video->path ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function deleteVideoFiles($video): void
    {
        $folderPath = dirname($video->path);
        $storage = Storage::disk($video->disk);

        if ($video->type == 'raw' && $storage->exists($video->path)) {
            if ($storage->exists($folderPath)) {
                foreach ($storage->allFiles($folderPath) as $file) {
                    $storage->delete($file);
                }
                $storage->deleteDirectory($folderPath);
            }
        } elseif ($storage->exists($video->path)) {
            $storage->delete($video->path);
            $storage->deleteDirectory($folderPath);
        }
    }

    private function updateEpisodeDuration($episodeId, $duration): void
    {
        DB::table('episodes')
            ->where('id', $episodeId)
            ->update([
                'total_time' => $duration,
                'updated_at' => now(),
            ]);
    }

    private function deleteFileFromUrl($url): void
    {
        if (!$url) {
            return;
        }

        foreach (config('filesystems.disks') as $disk => $config) {
            if (!isset($config['url'])) {
                continue;
            }

            $baseUrl = rtrim($config['url'], '/');
            if (str_starts_with($url, $baseUrl)) {
                $relativePath = ltrim(str_replace($baseUrl, '', $url), '/');
                if (Storage::disk($disk)->exists($relativePath)) {
                    Storage::disk($disk)->delete($relativePath);
                }
                break;
            }
        }
    }

    public function status(Video $video)
    {
        $auth = request()->bearerToken();
        if (!$auth) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $claims = VerifyUploadTokenService::verify($auth);
        if (!$claims) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        if (!in_array(($claims['type'] ?? null), ['video', 'process'], true)) {
            return response()->json(['message' => 'Invalid token type'], 403);
        }
        $claimedVideoableId = $claims['episodeId'] ?? $claims['courseId'] ?? null;
        if (!$claimedVideoableId || (int) $claimedVideoableId !== (int) $video->videoable_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $raw = null;
        if ($video->type !== 'raw') {
            $raw = DB::table('videos')
                ->where('videoable_id', $video->videoable_id)
                ->where('videoable_type', $video->videoable_type)
                ->where('type', 'raw')
                ->first();
            $status = $raw->status ?? null;
            $progress = $raw->progress ?? null;
            $errorMessage = $raw->error_message ?? null;
        } else {
            $status = $video->status ?? null;
            $progress = $video->progress ?? null;
            $errorMessage = $video->error_message ?? null;
        }

        return response()->json([
            'video_id' => $video->id,
            'status' => (
                in_array(($status ?? 'pending'), ['queued', 'uploaded', 'pending'], true)
                && (int) ($progress ?? 0) > 0
            ) ? 'processing' : ($status ?? 'pending'),
            'progress' => $progress,
            'error_message' => $errorMessage,
        ], 200);
    }

    private function getVideoDuration(string $filePath): int
    {
        try {
            $ffprobe = env('FFPROBE_BINARIES', 'ffprobe');
            $cmd = escapeshellcmd($ffprobe) . ' -v quiet -print_format json -show_format -show_streams ' . escapeshellarg($filePath);
            $output = shell_exec($cmd);
            if (!$output) {
                return 0;
            }
            $data = json_decode($output, true);
            if (isset($data['format']['duration'])) {
                return (int) round((float) $data['format']['duration']);
            }
        } catch (\Throwable $e) {
            Log::warning('ffprobe duration failed', ['error' => $e->getMessage()]);
        }
        return 0;
    }
}
