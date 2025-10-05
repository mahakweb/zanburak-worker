<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Services\VerifyUploadTokenService;
use App\Models\Video;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class UploadController extends Controller
{
    public function video(Request $request)
    {
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
        if (($claims['type'] ?? null) !== 'video') {
            return response()->json(['message' => 'Invalid token type'], 403);
        }
        if (($claims['path'] ?? null) !== $request->input('path')) {
            return response()->json(['message' => 'Path mismatch'], 422);
        }

        $disk = $claims['disk'] ?? 'static';
        $file = $request->file('file');
        if ((int)$file->getSize() > (int)($claims['size'] ?? 0)) {
            return response()->json(['message' => 'File too large'], 422);
        }

        // Skip duration probing here to return response faster
        $duration = 0;

        try {
            $dir = ltrim(dirname($claims['path']), '/');
            $basename = basename($claims['path']);
            if (!Storage::disk($disk)->exists($dir)) {
                Storage::disk($disk)->makeDirectory($dir);
            }
            $ok = Storage::disk($disk)->putFileAs($dir, $file, $basename);
            if (!$ok) {
                Log::error("Upload failed to write file (putFileAs)", ['disk' => $disk, 'dir' => $dir, 'name' => $basename]);
                return response()->json(['message' => 'Failed to store file'], 500);
            }
        } catch (\Throwable $e) {
            Log::error("Upload exception on write (putFileAs)", ['error' => $e->getMessage(), 'disk' => $disk, 'path' => $claims['path']]);
            return response()->json(['message' => 'Failed to store file', 'error' => $e->getMessage()], 500);
        }

        // Insert raw video record with computed duration
        $video = Video::create([
            'type' => 'raw',
            'path' => $claims['path'],
            'disk' => $disk,
            'duration' => (int) $duration,
            'videoable_id' => $claims['episodeId'] ?? $claims['courseId'],
            'videoable_type' => isset($claims['episodeId']) ? 'App\\Models\\Episode' : 'App\\Models\\Course',
            'status' => 'queued',
        ]);

        // Dispatch processing job asynchronously to dedicated 'videos' queue
        \App\Jobs\ProcessVideo::dispatch($video)->onConnection('database')->onQueue('videos');

        $diskUrl = config("filesystems.disks.$disk.url");
        $publicUrl = $diskUrl ? rtrim($diskUrl, '/') . '/' . ltrim($claims['path'] ?? '', '/') : ($claims['path'] ?? '');

        return response()->json([
            'message' => 'File uploaded successfully.',
            'video_id' => $video->id,
            'path' => $publicUrl,
            'size' => (int) $file->getSize(),
            'duration' => (int) $duration,
        ], 200);
    }

    public function attachment(Request $request)
    {
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
        if (($claims['type'] ?? null) !== 'attachment' && ($claims['type'] ?? null) !== 'poster') {
            return response()->json(['message' => 'Invalid token type'], 403);
        }
        if (($claims['path'] ?? null) !== $request->input('path')) {
            return response()->json(['message' => 'Path mismatch'], 422);
        }

        $disk = $claims['disk'] ?? 'static';
        $file = $request->file('file');
        if ((int)$file->getSize() > (int)($claims['size'] ?? 0)) {
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
                Log::error("Attachment failed to write file (putFileAs)", ['disk' => $disk, 'dir' => $dir, 'name' => $basename]);
                return response()->json(['message' => 'Failed to store file', "ok" => $ok], 500);
            }
        } catch (\Throwable $e) {
            Log::error("Attachment exception on write (putFileAs)", ['error' => $e->getMessage(), 'disk' => $disk, 'path' => $claims['path']]);
            return response()->json(['message' => 'Failed to store file'], 500);
        }

        // Persist attachment URL to DB using shared models (course/episode relation)
        $diskUrl = config("filesystems.disks.$disk.url");
        $url = $diskUrl ? rtrim($diskUrl, '/') . '/' . ltrim($claims['path'], '/') : $claims['path'];

        if (($claims['type'] ?? null) === 'poster' && isset($claims['courseId'])) {
            DB::table('courses')->where('id', $claims['courseId'])->update(['poster' => $url, 'updated_at' => now()]);
        } else {
            // attaches table is polymorphic
            $attachableType = isset($claims['episodeId']) ? 'App\\Models\\Episode' : 'App\\Models\\Course';
            DB::table('attaches')->insert([
                'attachable_id' => $claims['episodeId'] ?? $claims['courseId'],
                'attachable_type' => $attachableType,
                'title' => 'پیوست',
                'url' => $url,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'File uploaded successfully.',
            'url' => $url,
        ], 200);
    }

    public function status(Video $video)
    {
        // Optional security: require the same signed upload token
        $auth = request()->bearerToken();
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
        $claimedVideoableId = $claims['episodeId'] ?? $claims['courseId'] ?? null;
        if (!$claimedVideoableId || (int) $claimedVideoableId !== (int) $video->videoable_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Ensure we report status from the raw record
        if ($video->type !== 'raw') {
            $raw = DB::table('videos')
                ->where('videoable_id', $video->videoable_id)
                ->where('videoable_type', $video->videoable_type)
                ->where('type', 'raw')
                ->first();
            $status = $raw->status ?? null;
        } else {
            $status = $video->status ?? null;
        }

        return response()->json([
            'video_id' => $video->id,
            'status' => $status ?? 'pending',
        ], 200);
    }
    private function getVideoDuration(string $filePath): int
    {
        try {
            $ffprobe = env('FFPROBE_BINARIES', 'ffprobe');
            $cmd = escapeshellcmd($ffprobe) . ' -v quiet -print_format json -show_format -show_streams ' . escapeshellarg($filePath);
            $output = shell_exec($cmd);
            if (!$output) return 0;
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


