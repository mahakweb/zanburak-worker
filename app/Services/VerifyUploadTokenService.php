<?php

namespace App\Services;

class VerifyUploadTokenService
{
    protected static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }

    public static function verify(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerB64, $payloadB64, $sigB64] = $parts;
        $signingInput = $headerB64 . '.' . $payloadB64;
        $signature = self::base64UrlDecode($sigB64);
        $expected = hash_hmac('sha256', $signingInput, (string) config('upload.signing_key', env('UPLOAD_TOKEN_SECRET', env('APP_KEY'))), true);
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        $payloadJson = self::base64UrlDecode($payloadB64);
        $claims = json_decode($payloadJson, true);
        if (!is_array($claims)) {
            return null;
        }
        if (isset($claims['exp']) && time() >= (int) $claims['exp']) {
            return null;
        }
        return $claims;
    }
}


