<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Services\VerifyUploadTokenService;
use App\Models\Video;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

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

        // Default duration before probing
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

        // Probe duration using FFMpeg (fallback to ffprobe)
        try {
            $duration = (int) round(FFMpeg::fromDisk($disk)->open($claims['path'])->getDurationInSeconds());
        } catch (\Throwable $e) {
            try {
                $absolutePath = Storage::disk($disk)->path($claims['path']);
                $duration = $this->getVideoDuration($absolutePath);
            } catch (\Throwable $inner) {
                Log::warning('Duration probing failed', ['error' => $inner->getMessage(), 'disk' => $disk, 'path' => $claims['path']]);
                $duration = 0;
            }
        }

        // Handle different videoable types
        $videoableId = $claims['episodeId'] ?? $claims['courseId'] ?? $claims['pathId'];
        $videoableType = isset($claims['episodeId']) ? 'App\\Models\\Episode' : 
                        (isset($claims['courseId']) ? 'App\\Models\\Course' : 'App\\Models\\Path');
        
        if ($videoableType !== 'App\\Models\\Path') {
            $this->removeOldVideos($videoableId, $videoableType);
        }

        // Determine if video should be processed
        $shouldProcess = (bool)($claims['process'] ?? true);
        
        // Insert raw video record with computed duration
        $video = Video::create([
            'type' => 'raw',
            'path' => $claims['path'],
            'disk' => $disk,
            'duration' => (int) $duration,
            'videoable_id' => $videoableId,
            'videoable_type' => $videoableType,
            'status' => $shouldProcess ? 'queued' : 'uploaded',
        ]);

        // Update episode duration if this is an episode video
        if (isset($claims['episodeId'])) {
            $this->updateEpisodeDuration($claims['episodeId'], (int) $duration);
        }

        // Dispatch processing job only if process flag is true (default: true)
        if ($shouldProcess) {
            \App\Jobs\ProcessVideo::dispatch($video)->onConnection('database')->onQueue('videos');
        }

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
            // Delete old poster before updating
            $this->removeOldCoursePoster($claims['courseId']);
            DB::table('courses')->where('id', $claims['courseId'])->update(['poster' => $url, 'updated_at' => now()]);
        } elseif (($claims['type'] ?? null) === 'poster' && isset($claims['pathId'])) {
            // Update path poster
            DB::table('paths')->where('id', $claims['pathId'])->update(['poster' => $url, 'updated_at' => now()]);
        } elseif (($claims['type'] ?? null) === 'attachment' && isset($claims['pathId'])) {
            // Update path icon
            DB::table('paths')->where('id', $claims['pathId'])->update(['icon' => $url, 'updated_at' => now()]);
        } else {
            // Delete old attachments before inserting new one
            $attachableType = isset($claims['episodeId']) ? 'App\\Models\\Episode' : 'App\\Models\\Course';
            $attachableId = $claims['episodeId'] ?? $claims['courseId'];
            $this->removeOldAttachments($attachableId, $attachableType);
            
            // attaches table is polymorphic
            DB::table('attaches')->insert([
                'attachable_id' => $attachableId,
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

    /**
     * Remove old course poster
     */
    private function removeOldCoursePoster($courseId)
    {
        $course = DB::table('courses')->where('id', $courseId)->first();
        if ($course && $course->poster) {
            $this->deleteFileFromUrl($course->poster);
        }
    }

    /**
     * Remove old attachments for course or episode
     */
    private function removeOldAttachments($attachableId, $attachableType)
    {
        $attachments = DB::table('attaches')
            ->where('attachable_id', $attachableId)
            ->where('attachable_type', $attachableType)
            ->get();

        foreach ($attachments as $attachment) {
            $this->deleteFileFromUrl($attachment->url);
        }

        // Delete from database
        DB::table('attaches')
            ->where('attachable_id', $attachableId)
            ->where('attachable_type', $attachableType)
            ->delete();
    }

    /**
     * Remove old videos for course or episode
     */
    private function removeOldVideos($videoableId, $videoableType)
    {
        $videos = DB::table('videos')
            ->where('videoable_id', $videoableId)
            ->where('videoable_type', $videoableType)
            ->get();

        foreach ($videos as $video) {
            $this->deleteVideoFiles($video);
        }

        // If this is an episode, reset its duration to 0
        if ($videoableType === 'App\\Models\\Episode') {
            $this->updateEpisodeDuration($videoableId, 0);
        }

        // Delete from database
        DB::table('videos')
            ->where('videoable_id', $videoableId)
            ->where('videoable_type', $videoableType)
            ->delete();
    }

    /**
     * Delete video files from storage
     */
    private function deleteVideoFiles($video)
    {
        $folderPath = dirname($video->path);
        $storage = Storage::disk($video->disk);
        
        if ($video->type == 'raw' && Storage::disk($video->disk)->exists($video->path)) {
            if ($storage->exists($folderPath)) {
                $files = $storage->allFiles($folderPath);
                foreach ($files as $file) {
                    $storage->delete($file);
                }
                $storage->deleteDirectory($folderPath);
            }
        } else if (Storage::disk($video->disk)->exists($video->path)) {
            Storage::disk($video->disk)->delete($video->path);
            $storage->deleteDirectory($folderPath);
        }
    }

    /**
     * Update episode duration
     */
    private function updateEpisodeDuration($episodeId, $duration)
    {
        DB::table('episodes')
            ->where('id', $episodeId)
            ->update([
                'total_time' => $duration,
                'updated_at' => now()
            ]);
    }

    /**
     * Delete file from storage based on URL
     */
    private function deleteFileFromUrl($url)
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


