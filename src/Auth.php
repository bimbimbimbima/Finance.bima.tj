<?php
class Auth {

    // Получить токен для портала
    // Сначала из REQUEST (Битрикс24 передаёт при каждом открытии),
    // потом из сессии пользователя, потом из БД
    public static function getToken($portal) {
        // 1. Прямо из текущего запроса — самый надёжный
        if (!empty($_REQUEST['AUTH_ID'])) {
            $token = $_REQUEST['AUTH_ID'];
            self::saveToSession($portal, $token, $_REQUEST['REFRESH_ID'] ?? '');
            return $token;
        }

        // 2. Из персональной сессии пользователя
        $sKey = self::sessionKey($portal);
        if (!empty($_SESSION[$sKey])) {
            return $_SESSION[$sKey];
        }

        // 3. Общий токен сессии (обратная совместимость)
        if (!empty($_SESSION['access_token']) && ($_SESSION['portal']??'') === $portal) {
            return $_SESSION['access_token'];
        }

        // 4. Из БД — последний сохранённый токен портала
        try {
            $row = DB::fetchOne(
                "SELECT access_token, refresh_token, expires_at FROM tokens WHERE portal=? ORDER BY expires_at DESC LIMIT 1",
                [$portal]
            );
            if ($row && !empty($row['access_token'])) {
                // Если токен не истёк
                if ($row['expires_at'] > time() - 300) {
                    self::saveToSession($portal, $row['access_token'], $row['refresh_token'] ?? '');
                    return $row['access_token'];
                }
                // Пробуем обновить через refresh_token
                if (!empty($row['refresh_token'])) {
                    $new = self::refreshToken($portal, $row['refresh_token']);
                    if ($new) return $new;
                }
            }
        } catch (Throwable $e) {
            error_log('Auth::getToken DB error: ' . $e->getMessage());
        }

        return null;
    }

    // Уникальный ключ сессии для пользователя
    private static function sessionKey($portal) {
        $uid = $_SESSION['user_id'] ?? $_SESSION['member_id'] ?? 'guest';
        return 'tok_' . substr(md5($portal . '_' . $uid), 0, 12);
    }

    // Сохранить токен в сессию персонально
    public static function saveToSession($portal, $token, $refresh = '') {
        $sKey = self::sessionKey($portal);
        $_SESSION[$sKey]          = $token;
        $_SESSION['access_token'] = $token; // совместимость
        $_SESSION['portal']       = $portal;
        if ($refresh) {
            $_SESSION[$sKey . '_r']  = $refresh;
            $_SESSION['refresh_token'] = $refresh;
        }
    }

    // Обменять код авторизации на токен
    public static function exchangeCode($portal, $code) {
        $url  = "https://{$portal}/oauth/token/";
        $body = http_build_query([
            'grant_type'    => 'authorization_code',
            'client_id'     => CLIENT_ID,
            'client_secret' => CLIENT_SECRET,
            'code'          => $code,
            'redirect_uri'  => APP_URL . '/install.php',
        ]);
        $resp = self::httpPost($url, $body);
        if (!$resp) return false;
        $data = json_decode($resp, true);
        if (empty($data['access_token'])) return false;

        self::saveToSession($portal, $data['access_token'], $data['refresh_token'] ?? '');
        self::saveToDB($portal, $data['access_token'], $data['refresh_token'] ?? '',
            time() + (int)($data['expires_in'] ?? 3600));
        return true;
    }

    // Обновить токен через refresh_token
    public static function refreshToken($portal, $refreshToken) {
        $url  = "https://{$portal}/oauth/token/";
        $body = http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => CLIENT_ID,
            'client_secret' => CLIENT_SECRET,
            'refresh_token' => $refreshToken,
        ]);
        $resp = self::httpPost($url, $body);
        if (!$resp) return null;
        $data = json_decode($resp, true);
        if (empty($data['access_token'])) return null;

        self::saveToSession($portal, $data['access_token'], $data['refresh_token'] ?? '');
        self::saveToDB($portal, $data['access_token'], $data['refresh_token'] ?? '',
            time() + (int)($data['expires_in'] ?? 3600));
        return $data['access_token'];
    }

    // Сохранить токен в БД
    public static function saveToDB($portal, $token, $refresh, $expiresAt) {
        try {
            DB::execute(
                "INSERT INTO tokens (portal, access_token, refresh_token, expires_at)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                 access_token=VALUES(access_token),
                 refresh_token=VALUES(refresh_token),
                 expires_at=VALUES(expires_at)",
                [$portal, $token, $refresh, $expiresAt]
            );
        } catch (Throwable $e) {
            error_log('Auth::saveToDB: ' . $e->getMessage());
        }
    }

    private static function httpPost($url, $body) {
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $body,
            'timeout' => 10,
        ]]);
        return @file_get_contents($url, false, $ctx);
    }
}
