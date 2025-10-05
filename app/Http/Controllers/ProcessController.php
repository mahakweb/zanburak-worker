<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVideo;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessController extends Controller
{
    public function reprocess(Request $request, Video $video)
    {
        // Remove previous processed outputs for this videoable (stream/download/trailer)
        $videoableId = $video->videoable_id;
        $videoableType = $video->videoable_type;

        $processed = DB::table('videos')
            ->where('videoable_id', $videoableId)
            ->where('videoable_type', $videoableType)
            ->whereIn('type', ['stream', 'download', 'trailer'])
            ->get();

        foreach ($processed as $row) {
            $disk = $row->disk;
            $path = $row->path;
            $dir = dirname($path);
            $storage = Storage::disk($disk);
            if ($storage->exists($dir)) {
                foreach ($storage->allFiles($dir) as $f) {
                    $storage->delete($f);
                }
                $storage->deleteDirectory($dir);
            }
        }

        DB::table('videos')
            ->where('videoable_id', $videoableId)
            ->where('videoable_type', $videoableType)
            ->whereIn('type', ['stream', 'download', 'trailer'])
            ->delete();

        // Dispatch processing again using the raw video
        ProcessVideo::dispatch($video)->onConnection('database')->onQueue('videos');

        return response()->json(['message' => 'Reprocessing started.'], 200);
    }
}


