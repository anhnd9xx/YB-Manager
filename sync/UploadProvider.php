<?php
declare(strict_types=1);
/**
 * UploadProvider - Abstraction upload engine (API-first, khong browser-click).
 * YouTubeApiProvider: OAuth2 refresh + resumable upload chunked (that) + schedule.
 * SimulatedProvider: mac dinh khi chua cau hinh OAuth — deterministic, ho tro
 *   progress/resume/failure-injection cho test/dev. Khong bao gio goi browser.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UploadStore.php';
require_once __DIR__ . '/SyncLogger.php';

interface UploadProviderInterface
{
    /** @return array{ok, error?} */
    public function validateAuth(int $profileId): array;
    /**
     * Upload file (resumable). $progress($bytesSent, $total) goi dinh ky.
     * @return array{ok, provider_video_id?, bytes_uploaded, error?, error_code?, fatal?}
     */
    public function uploadVideo(int $profileId, string $filePath, array $metadata, string $sessionRef, int $resumeFrom, callable $progress): array;
    /** @return array{ok, error?} */
    public function schedulePublish(int $profileId, string $providerVideoId, string $publishAt, array $metadata): array;
    /** @return array{ok, status?, error?} */
    public function getUploadStatus(int $profileId, string $providerVideoId): array;
    /** @return array{ok} */
    public function cancelUpload(int $profileId, string $providerVideoId): array;
}

class SimulatedProvider implements UploadProviderInterface
{
    public function validateAuth(int $profileId): array
    {
        return ['ok' => true];
    }

    public function uploadVideo(int $profileId, string $filePath, array $metadata, string $sessionRef, int $resumeFrom, callable $progress): array
    {
        if (!is_file($filePath)) {
            return ['ok' => false, 'bytes_uploaded' => 0, 'error' => 'File missing', 'error_code' => 'FILE_MISSING', 'fatal' => true];
        }
        $total = filesize($filePath);
        if ($total === false || $total <= 0) {
            return ['ok' => false, 'bytes_uploaded' => 0, 'error' => 'Empty file', 'error_code' => 'EMPTY_FILE', 'fatal' => true];
        }
        // Failure injection cho test: sessionRef chua 'FAIL_AT_<pct>'
        $failAt = -1;
        if (preg_match('/FAIL_AT_(\d+)/', $sessionRef, $m)) $failAt = max(0, min(100, (int)$m[1]));
        // Mo phong chunked copy (khong doc het file vao RAM)
        $sent = max(0, $resumeFrom);
        if ($sent >= $total) {
            return ['ok' => true, 'provider_video_id' => 'sim_' . substr(md5($filePath . $total), 0, 12),
                'bytes_uploaded' => $total];
        }
        $fh = @fopen($filePath, 'rb');
        if ($fh) {
            fseek($fh, min($sent, $total));
            $chunk = 262144; // 256KB
            while ($sent < $total) {
                $c = fread($fh, $chunk);
                if ($c === false || $c === '') break;
                $sent += strlen($c);
                $progress($sent, $total);
                if ($failAt >= 0 && ($sent * 100 / $total) >= $failAt) {
                    fclose($fh);
                    return ['ok' => false, 'bytes_uploaded' => $sent, 'error' => 'Simulated network drop', 'error_code' => 'NETWORK_ERROR', 'fatal' => false];
                }
            }
            fclose($fh);
        } else {
            // Khong doc duoc -> van tien progress theo toc do gia lap
            while ($sent < $total) {
                $sent = min($total, $sent + 1048576);
                $progress($sent, $total);
            }
        }
        if ($sent < $total) {
            return ['ok' => false, 'bytes_uploaded' => $sent, 'error' => 'Read error', 'error_code' => 'NETWORK_ERROR', 'fatal' => false];
        }
        return ['ok' => true, 'provider_video_id' => 'sim_' . substr(md5($filePath . $total), 0, 12),
            'bytes_uploaded' => $total];
    }

    public function schedulePublish(int $profileId, string $providerVideoId, string $publishAt, array $metadata): array
    {
        return ['ok' => true];
    }

    public function getUploadStatus(int $profileId, string $providerVideoId): array
    {
        return ['ok' => true, 'status' => 'READY_TO_PUBLISH'];
    }

    public function cancelUpload(int $profileId, string $providerVideoId): array
    {
        return ['ok' => true];
    }
}

class YouTubeApiProvider implements UploadProviderInterface
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const UPLOAD_INIT_URL = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status';
    public const VIDEO_URL = 'https://www.googleapis.com/youtube/v3/videos';
    public const CHUNK = 8388608; // 8MB (boi cua 256KB theo yeu cau resumable)

    private function accessToken(int $profileId): ?string
    {
        $sec = UploadStore::credentialSecret($profileId);
        if (!$sec || empty($sec['refresh_token'])) {
            UploadStore::setAuthStatus($profileId, 'NOT_CONFIGURED', 'missing refresh_token');
            return null;
        }
        $post = http_build_query([
            'client_id' => (string)($sec['client_id'] ?? ''),
            'client_secret' => (string)($sec['client_secret'] ?? ''),
            'refresh_token' => (string)$sec['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post, CURLOPT_TIMEOUT => 20]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = is_string($body) ? json_decode($body, true) : null;
        if ($http !== 200 || !is_array($j) || empty($j['access_token'])) {
            UploadStore::setAuthStatus($profileId, ($http === 400 || $http === 401) ? 'REAUTH_REQUIRED' : 'ERROR', 'token_http_' . $http);
            return null;
        }
        return (string)$j['access_token'];
    }

    public function validateAuth(int $profileId): array
    {
        $tok = $this->accessToken($profileId);
        if ($tok === null) {
            $cur = UploadStore::getCredential($profileId);
            return ['ok' => false, 'error' => (string)($cur['last_error'] ?? 'auth_failed')];
        }
        $ch = curl_init(self::VIDEO_URL . '?part=id&mine=true&maxResults=1');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok]]);
        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http === 200) {
            UploadStore::setAuthStatus($profileId, 'VALID');
            return ['ok' => true];
        }
        UploadStore::setAuthStatus($profileId, $http === 401 ? 'REAUTH_REQUIRED' : 'ERROR', 'validate_http_' . $http);
        return ['ok' => false, 'error' => 'validate_http_' . $http];
    }

    public function uploadVideo(int $profileId, string $filePath, array $metadata, string $sessionRef, int $resumeFrom, callable $progress): array
    {
        if (!is_file($filePath)) {
            return ['ok' => false, 'bytes_uploaded' => 0, 'error' => 'File missing', 'error_code' => 'FILE_MISSING', 'fatal' => true];
        }
        $total = filesize($filePath);
        if ($total === false || $total <= 0) {
            return ['ok' => false, 'bytes_uploaded' => 0, 'error' => 'Empty file', 'error_code' => 'EMPTY_FILE', 'fatal' => true];
        }
        $tok = $this->accessToken($profileId);
        if ($tok === null) {
            return ['ok' => false, 'bytes_uploaded' => 0, 'error' => 'Auth failed', 'error_code' => 'AUTH_INVALID', 'fatal' => true];
        }
        $snippet = ['title' => mb_substr((string)($metadata['title'] ?? 'Untitled'), 0, 100),
            'description' => (string)($metadata['description'] ?? ''),
            'tags' => array_slice((array)($metadata['tags'] ?? []), 0, 30),
            'categoryId' => (string)($metadata['category_id'] ?? '22'),
            'defaultLanguage' => (string)($metadata['language'] ?? 'vi')];
        $status = ['privacyStatus' => 'private', 'madeForKids' => false];
        // Session moi hoac resume: hoi offset hien tai neu co sessionRef (URL)
        $uploadUrl = $sessionRef !== '' && str_starts_with($sessionRef, 'http') ? $sessionRef : '';
        if ($uploadUrl === '') {
            $loc = '';
            $ch = curl_init(self::UPLOAD_INIT_URL);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json; charset=UTF-8',
                    'X-Upload-Content-Length: ' . $total, 'X-Upload-Content-Type: video/*'],
                CURLOPT_POSTFIELDS => json_encode(['snippet' => $snippet, 'status' => $status], JSON_UNESCAPED_UNICODE),
                CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$loc) {
                    if (stripos($h, 'Location:') === 0) $loc = trim(substr($h, 9));
                    return strlen($h);
                }]);
            curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http !== 200 || $loc === '') {
                $fatal = in_array($http, [400, 401, 403], true);
                return ['ok' => false, 'bytes_uploaded' => 0,
                    'error' => 'Init upload http_' . $http, 'error_code' => $fatal ? 'AUTH_INVALID' : 'NETWORK_ERROR',
                    'fatal' => $fatal, 'session_ref' => ''];
            }
            $uploadUrl = $loc;
        } else {
            // Resume: hoi server da nhan bao nhieu (PUT rong + Content-Range */total)
            $off = $this->queryOffset($uploadUrl, $total);
            if ($off !== null) $resumeFrom = max($resumeFrom, $off);
        }
        $fh = @fopen($filePath, 'rb');
        if (!$fh) {
            return ['ok' => false, 'bytes_uploaded' => $resumeFrom, 'error' => 'Read error', 'error_code' => 'NETWORK_ERROR', 'fatal' => false, 'session_ref' => $uploadUrl];
        }
        $sent = max(0, min($resumeFrom, $total));
        fseek($fh, $sent);
        $progress($sent, $total);
        while ($sent < $total) {
            $data = fread($fh, self::CHUNK);
            if ($data === false || $data === '') break;
            $len = strlen($data);
            $last = $sent + $len - 1;
            $ch = curl_init($uploadUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Content-Length: ' . $len,
                    'Content-Range: bytes ' . $sent . '-' . $last . '/' . $total],
                CURLOPT_POSTFIELDS => $data]);
            $body = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);
            if ($errno !== 0) {
                fclose($fh);
                return ['ok' => false, 'bytes_uploaded' => $sent, 'error' => 'Connection reset', 'error_code' => 'NETWORK_ERROR', 'fatal' => false, 'session_ref' => $uploadUrl];
            }
            if ($http === 200 || $http === 201) {
                $sent = $total;
                $progress($sent, $total);
                $j = is_string($body) ? json_decode($body, true) : null;
                fclose($fh);
                $vid = (string)($j['id'] ?? '');
                if ($vid === '') {
                    return ['ok' => false, 'bytes_uploaded' => $sent, 'error' => 'No video id', 'error_code' => 'NETWORK_ERROR', 'fatal' => false, 'session_ref' => $uploadUrl];
                }
                return ['ok' => true, 'provider_video_id' => $vid, 'bytes_uploaded' => $total];
            }
            if ($http === 308) {
                $sent = $last + 1;
                $progress($sent, $total);
                continue;
            }
            fclose($fh);
            $fatal = in_array($http, [400, 401, 403, 404], true);
            return ['ok' => false, 'bytes_uploaded' => $sent, 'error' => 'Chunk http_' . $http,
                'error_code' => $fatal ? 'PROVIDER_REJECT' : 'NETWORK_ERROR', 'fatal' => $fatal, 'session_ref' => $uploadUrl];
        }
        fclose($fh);
        return ['ok' => false, 'bytes_uploaded' => $sent, 'error' => 'Read error', 'error_code' => 'NETWORK_ERROR', 'fatal' => false, 'session_ref' => $uploadUrl];
    }

    private function queryOffset(string $uploadUrl, int $total): ?int
    {
        $range = '';
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Content-Length: 0',
                'Content-Range: bytes */' . $total],
            CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$range) {
                if (stripos($h, 'Range:') === 0) $range = trim(substr($h, 6));
                return strlen($h);
            }]);
        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http === 308 && preg_match('/bytes=0-(\d+)/', $range, $m)) {
            return (int)$m[1] + 1;
        }
        if ($http === 308) return 0;
        return null;
    }

    public function schedulePublish(int $profileId, string $providerVideoId, string $publishAt, array $metadata): array
    {
        $tok = $this->accessToken($profileId);
        if ($tok === null) return ['ok' => false, 'error' => 'Auth failed'];
        $body = ['id' => $providerVideoId,
            'status' => ['privacyStatus' => 'private', 'publishAt' => $this->rfc3339($publishAt),
                'madeForKids' => false]];
        if (!empty($metadata['playlist_id'])) {
            // Playlist add tach rieng (warning-only neu fail) — o day chi schedule
        }
        $ch = curl_init(self::VIDEO_URL . '?part=status');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok,
                'Content-Type: application/json; charset=UTF-8'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http >= 200 && $http < 300) return ['ok' => true];
        return ['ok' => false, 'error' => 'schedule_http_' . $http];
    }

    public function getUploadStatus(int $profileId, string $providerVideoId): array
    {
        $tok = $this->accessToken($profileId);
        if ($tok === null) return ['ok' => false, 'error' => 'Auth failed'];
        $ch = curl_init(self::VIDEO_URL . '?part=status,processingDetails&id=' . urlencode($providerVideoId));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok]]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http !== 200) return ['ok' => false, 'error' => 'status_http_' . $http];
        $j = is_string($body) ? json_decode($body, true) : null;
        $item = $j['items'][0] ?? null;
        if (!is_array($item)) return ['ok' => false, 'error' => 'not_found'];
        $proc = (string)($item['processingDetails']['processingStatus'] ?? 'succeeded');
        $priv = (string)($item['status']['privacyStatus'] ?? 'private');
        if ($priv === 'public') return ['ok' => true, 'status' => 'PUBLISHED'];
        return ['ok' => true, 'status' => $proc === 'succeeded' ? 'READY_TO_PUBLISH' : 'PROCESSING'];
    }

    public function cancelUpload(int $profileId, string $providerVideoId): array
    {
        $tok = $this->accessToken($profileId);
        if ($tok === null) return ['ok' => false, 'error' => 'Auth failed'];
        $ch = curl_init(self::VIDEO_URL . '?id=' . urlencode($providerVideoId));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok]]);
        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['ok' => $http === 204 || $http === 404];
    }

    private function rfc3339(string $dt): string
    {
        try {
            $d = new DateTime($dt, new DateTimeZone('Asia/Ho_Chi_Minh'));
            return $d->format('Y-m-d\TH:i:sP');
        } catch (Throwable $e) {
            return date('Y-m-d\TH:i:sP', strtotime($dt) ?: time());
        }
    }

    public static function make(string $provider): UploadProviderInterface
    {
        return strtoupper($provider) === 'YOUTUBE_API' ? new YouTubeApiProvider() : new SimulatedProvider();
    }
}
