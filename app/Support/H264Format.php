<?php

namespace App\Support;

use FFMpeg\Format\Video\X264;

/**
 * php-ffmpeg's stock X264 format only allows "libx264".
 * Hardware encoders must be whitelisted here so HLS/export can use them.
 * Episode stream/download and course trailer all go through this format.
 */
class H264Format extends X264
{
    public function getAvailableVideoCodecs()
    {
        return ['libx264', 'h264_nvenc', 'h264_qsv', 'h264_amf'];
    }
}
