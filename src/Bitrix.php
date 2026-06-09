<?php
class Bitrix {

    private static $userCache = null;

    private static function rest($portal, $method, $params = []) {
        $portal = preg_replace('/[^a-zA-Z0-9.\-]/', '', (string)$portal);
        if (!$portal || !$method) return null;
        $url = "https://{$portal}/rest/{$method}.json";
        $body = http_build_query($params);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp !== false && $resp !== '') {
                $data = json_decode($resp, true);
                if (is_array($data)) return $data;
                app_log("Bitrix REST {$method}: invalid JSON, HTTP {$code}, response=" . substr($resp, 0, 300));
            } else {
                app_log("Bitrix REST {$method}: cURL error={$err}, HTTP={$code}");
            }
        }

        $ctx = stream_context_create(['http'=>[
            'method' => 'POST',
            'timeout' => 4,
            'ignore_errors' => true,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp) {
            $data = json_decode($resp, true);
            if (is_array($data)) return $data;
            app_log("Bitrix REST {$method}: file_get_contents invalid JSON response=" . substr($resp, 0, 300));
        } else {
            app_log("Bitrix REST {$method}: empty response from {$url}");
        }
        return null;
    }

    private static function currentToken() {
        $token = $GLOBALS['accessToken'] ?? ($_REQUEST['AUTH_ID'] ?? ($_REQUEST['auth'] ?? ($_SESSION['access_token'] ?? null)));
        if (is_array($token)) { $token = $token['access_token'] ?? $token['AUTH_ID'] ?? $token['auth'] ?? null; }
        $token = trim((string)$token);
        if ($token) $_SESSION['access_token'] = $token;
        return $token;
    }

    public static function currentUser($portal) {
        if (self::$userCache !== null) return self::$userCache;

        $token = self::currentToken();
        if (!$token) { self::$userCache = []; return []; }

        $result = [];

        try {
            // ВАЖНО: user.current требует scope user/user_brief/user_basic.
            // В вашем приложении такого scope может не быть, поэтому сначала используем profile.
            // profile работает на basic scope и возвращает текущего пользователя.
            $profile = self::rest($portal, 'profile', ['auth' => $token]);
            if (!empty($profile['result']) && is_array($profile['result'])) {
                $p = $profile['result'];
                $result = array_merge($result, $p);
                if (!empty($p['ID'])) $_SESSION['user_id'] = (int)$p['ID'];
            } elseif (!empty($profile['error'])) {
                app_log('Bitrix::profile REST error: ' . ($profile['error'] ?? '') . ' ' . ($profile['error_description'] ?? ''));
            }

            // Проверяем, является ли текущий пользователь администратором приложения/портала.
            // user.admin также работает на basic scope.
            $admin = self::rest($portal, 'user.admin', ['auth' => $token]);
            if (array_key_exists('result', $admin ?? [])) {
                $isAdmin = !empty($admin['result']);
                $result['ADMIN'] = $isAdmin;
                $result['IS_ADMIN'] = $isAdmin;
            }

            // Для скорости не вызываем user.current на каждой загрузке.
            // В локальном приложении без user-scope этот метод часто даёт insufficient_scope
            // и замедляет iframe. Достаточно profile + user.admin.
            if (defined('APP_B24_EXTENDED_USER_INFO') && APP_B24_EXTENDED_USER_INFO) {
                $uc = self::rest($portal, 'user.current', ['auth' => $token]);
                if (!empty($uc['result']) && is_array($uc['result'])) {
                    $result = array_merge($result, $uc['result']);
                    if (!empty($uc['result']['WORK_POSITION'])) $_SESSION['user_position'] = $uc['result']['WORK_POSITION'];
                }
            }

            $knownId = (int)($_SESSION['user_id'] ?? $_REQUEST['user_id'] ?? 0);
            $tokenId = (int)($result['ID'] ?? 0);
            if ($knownId > 0 && $tokenId > 0 && $tokenId !== $knownId) {
                app_log('Bitrix::currentUser corrected user mismatch: url/session=' . $knownId . ', token=' . $tokenId);
            }
            if ($tokenId > 0) $_SESSION['user_id'] = $tokenId;

            self::$userCache = $tokenId > 0 ? $result : [];
            return self::$userCache;
        } catch (Throwable $e) {
            app_log('Bitrix::currentUser exception: ' . $e->getMessage());
        }

        self::$userCache = [];
        return [];
    }

    public static function getLocalKnownUsers($portal, $search = '') {
        $search = mb_strtolower(trim((string)$search));
        $rows = [];
        try {
            try {
                $rows = array_merge($rows, DB::fetchAll("SELECT bitrix_user_id AS ID, bitrix_position AS WORK_POSITION, COALESCE(NULLIF(bitrix_user_name,''), CONCAT('Сотрудник #', bitrix_user_id)) AS FULL_NAME FROM account_permissions WHERE portal=? AND bitrix_user_id IS NOT NULL AND bitrix_user_id>0", [$portal]));
            } catch (Throwable $e) {
                $rows = array_merge($rows, DB::fetchAll("SELECT bitrix_user_id AS ID, bitrix_position AS WORK_POSITION, CONCAT('Сотрудник #', bitrix_user_id) AS FULL_NAME FROM account_permissions WHERE portal=? AND bitrix_user_id IS NOT NULL AND bitrix_user_id>0", [$portal]));
            }
            $rows = array_merge($rows, DB::fetchAll("SELECT creator_id AS ID, NULL AS WORK_POSITION, creator_name AS FULL_NAME FROM approval_requests WHERE portal=? AND creator_id>0", [$portal]));
            $rows = array_merge($rows, DB::fetchAll("SELECT manager_id AS ID, NULL AS WORK_POSITION, manager_name AS FULL_NAME FROM approval_requests WHERE portal=? AND manager_id>0", [$portal]));
            $rows = array_merge($rows, DB::fetchAll("SELECT user_id AS ID, NULL AS WORK_POSITION, user_name AS FULL_NAME FROM approval_request_approvers WHERE request_id IN (SELECT id FROM approval_requests WHERE portal=?) AND user_id>0", [$portal]));
            $settings = DB::fetchAll("SELECT approver_ids, approver_names FROM approval_route_settings WHERE portal=?", [$portal]);
            foreach ($settings as $st) {
                preg_match_all('/\d+/', (string)($st['approver_ids'] ?? ''), $m);
                $ids = array_values(array_filter(array_map('intval', $m[0] ?? [])));
                $names = preg_split('/[\n;,]+/u', (string)($st['approver_names'] ?? '')) ?: [];
                foreach ($ids as $i=>$id) $rows[] = ['ID'=>$id, 'WORK_POSITION'=>null, 'FULL_NAME'=>trim((string)($names[$i] ?? ''))];
            }
        } catch (Throwable $e) { app_log('Bitrix::getLocalKnownUsers failed: '.$e->getMessage()); }
        $out = [];
        foreach ($rows as $u) {
            $id = (int)($u['ID'] ?? 0); if ($id <= 0) continue;
            $name = trim((string)($u['FULL_NAME'] ?? ''));
            if ($name === '' || preg_match('/^#?\d+$/', $name)) $name = 'Сотрудник #'.$id;
            $pos = trim((string)($u['WORK_POSITION'] ?? ''));
            $text = mb_strtolower($name.' '.$pos.' '.$id);
            if ($search !== '' && mb_strpos($text, $search) === false) continue;
            $out[$id] = ['ID'=>$id, 'FULL_NAME'=>$name, 'NAME'=>'', 'LAST_NAME'=>$name, 'WORK_POSITION'=>$pos];
        }
        usort($out, static fn($a,$b)=>strcmp($a['FULL_NAME'],$b['FULL_NAME']));
        return array_values($out);
    }

    public static function getUsers($portal, $search = '') {
        $token = self::currentToken();
        if (!$token) return self::getLocalKnownUsers($portal, $search);
        try {
            $search = trim((string)$search);
            $collect = function($filter = []) use ($portal, $token) {
                $out = [];
                $start = 0;
                for ($i = 0; $i < 8; $i++) { // до 400 сотрудников, чтобы не тормозить iframe
                    $params = [
                        'auth' => $token,
                        'ACTIVE' => true,
                        'FILTER' => $filter,
                        'ORDER' => ['LAST_NAME' => 'ASC'],
                        'SELECT' => ['ID','NAME','LAST_NAME','SECOND_NAME','WORK_POSITION','EMAIL','PERSONAL_PHOTO'],
                        'start' => $start,
                    ];
                    $data = self::rest($portal, 'user.get', $params);
                    foreach (($data['result'] ?? []) as $u) {
                        if (!empty($u['ID'])) $out[(int)$u['ID']] = $u;
                    }
                    if (!isset($data['next'])) break;
                    $start = (int)$data['next'];
                    if ($start <= 0) break;
                }
                return $out;
            };

            if ($search !== '') {
                $all = [];
                foreach ([['%NAME'=>$search], ['%LAST_NAME'=>$search], ['%SECOND_NAME'=>$search], ['%WORK_POSITION'=>$search]] as $f) {
                    $all += $collect($f);
                }
                // Если портал не поддержал фильтр, подгружаем первые страницы и фильтруем локально.
                if (!$all) $all = $collect([]);
                $needle = mb_strtolower($search);
                $all = array_filter($all, static function($u) use ($needle) {
                    $hay = mb_strtolower(trim(($u['LAST_NAME']??'').' '.($u['NAME']??'').' '.($u['SECOND_NAME']??'').' '.($u['WORK_POSITION']??'').' '.($u['ID']??'')));
                    return $needle === '' || mb_strpos($hay, $needle) !== false;
                });
                $users = array_values($all);
            } else {
                $users = array_values($collect([]));
            }
            foreach ($users as &$u) {
                $u['FULL_NAME'] = trim(($u['LAST_NAME']??'').' '.($u['NAME']??'').' '.($u['SECOND_NAME']??''));
                if ($u['FULL_NAME']==='') $u['FULL_NAME'] = 'Сотрудник #'.($u['ID']??'');
            }
            if (empty($users)) return self::getLocalKnownUsers($portal, $search);
            usort($users, static fn($a,$b)=>strcmp($a['FULL_NAME'],$b['FULL_NAME']));
            return $users;
        } catch (Throwable $e) { app_log('Bitrix::getUsers failed: '.$e->getMessage()); return self::getLocalKnownUsers($portal, $search); }
    }

    public static function getContacts($portal, $search = '') {
        $token = $_SESSION['access_token'] ?? '';
        if (!$token) return [];
        try {
            $params = ['auth'=>$token, 'select'=>['ID','NAME','LAST_NAME','COMPANY_TITLE']];
            if ($search) $params['filter'] = ['%NAME'=>$search];
            $data = self::rest($portal, 'crm.contact.list', $params);
            $contacts = $data['result'] ?? [];
            foreach ($contacts as &$c) {
                $c['FULL_NAME'] = trim(($c['LAST_NAME']??'').' '.($c['NAME']??''));
                if (!empty($c['COMPANY_TITLE'])) $c['FULL_NAME'] .= ' ('.$c['COMPANY_TITLE'].')';
            }
            return $contacts;
        } catch (Throwable $e) { return []; }
    }

    public static function getCompanies($portal, $search = '') {
        $token = $_SESSION['access_token'] ?? '';
        if (!$token) return [];
        try {
            $params = ['auth'=>$token, 'select'=>['ID','TITLE']];
            if ($search) $params['filter'] = ['%TITLE'=>$search];
            $data = self::rest($portal, 'crm.company.list', $params);
            return $data['result'] ?? [];
        } catch (Throwable $e) { return []; }
    }
    public static function notifyUser($portal, $userId, $message) {
        $token = self::currentToken();
        $userId = (int)$userId;
        $message = trim((string)$message);
        if (!$token || $userId <= 0 || $message === '') {
            app_log('Bitrix::notifyUser skipped: empty token/user/message. user='.$userId);
            return false;
        }
        $plainTitle = 'Финансовая система СПО БИМА';
        $safeMessage = trim($message);
        $tag = 'FINANCE_APPROVAL_' . md5($userId . '|' . $safeMessage);

        $attempts = [
            ['im.notify.system.add', [
                'USER_ID' => $userId,
                'MESSAGE' => '[B]' . $plainTitle . "[/B]\n" . $safeMessage,
                'MESSAGE_OUT' => $safeMessage,
                'TAG' => $tag,
                'SUB_TAG' => 'FINANCE_APPROVAL|' . $userId,
            ]],
            ['im.notify.personal.add', [
                'USER_ID' => $userId,
                'MESSAGE' => '[B]' . $plainTitle . "[/B]\n" . $safeMessage,
                'MESSAGE_OUT' => $safeMessage,
                'TAG' => $tag,
                'SUB_TAG' => 'FINANCE_APPROVAL|' . $userId,
            ]],
            ['im.notify', [
                'USER_ID' => $userId,
                'TYPE' => 'SYSTEM',
                'MESSAGE' => '[B]' . $plainTitle . "[/B]\n" . $safeMessage,
                'MESSAGE_OUT' => $safeMessage,
                'TAG' => $tag,
                'SUB_TAG' => 'FINANCE_APPROVAL|' . $userId,
            ]],
            ['im.message.add', [
                // Для личного диалога Bitrix24 принимает DIALOG_ID = ID пользователя.
                'DIALOG_ID' => (string)$userId,
                'MESSAGE' => '[B]' . $plainTitle . "[/B]\n" . $safeMessage,
                'URL_PREVIEW' => 'N',
            ]],
        ];

        foreach ($attempts as [$method, $params]) {
            try {
                $params['auth'] = $token;
                $data = self::rest($portal, $method, $params);
                if (empty($data['error']) && isset($data['result']) && $data['result'] !== false && $data['result'] !== null && $data['result'] !== '') {
                    app_log('Bitrix notification sent via ' . $method . ' to user ' . $userId);
                    return true;
                }
                if (!empty($data['error'])) {
                    app_log('Bitrix '.$method.' failed: ' . ($data['error'] ?? '') . ' ' . ($data['error_description'] ?? ''));
                } else {
                    app_log('Bitrix '.$method.' returned empty result for user '.$userId);
                }
            } catch (Throwable $e) {
                app_log('Bitrix::notifyUser '.$method.' exception: ' . $e->getMessage());
            }
        }
        return false;
    }

    public static function getUserById($portal, $userId) {
        $token = self::currentToken();
        $userId = (int)$userId;
        if (!$token || $userId <= 0) return [];
        try {
            $data = self::rest($portal, 'user.get', [
                'auth' => $token,
                'ID' => $userId,
                'select' => ['ID','NAME','LAST_NAME','EMAIL','WORK_POSITION']
            ]);
            $r = $data['result'] ?? [];
            if (isset($r[0]) && is_array($r[0])) return $r[0];
            if (is_array($r) && !empty($r['ID'])) return $r;
        } catch (Throwable $e) { app_log('Bitrix::getUserById failed: '.$e->getMessage()); }
        return [];
    }

    public static function emailUsers($portal, $userIds, $subject, $message) {
        $userIds = array_values(array_filter(array_unique(array_map('intval', (array)$userIds)), static fn($v)=>$v>0));
        if (!$userIds) return false;
        $sent = false;
        foreach ($userIds as $uid) {
            $u = self::getUserById($portal, $uid);
            $email = trim((string)($u['EMAIL'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "From: BIMA Finance <no-reply@bima.tj>\r\n";
            try { $sent = @mail($email, '=?UTF-8?B?'.base64_encode($subject).'?=', (string)$message, $headers) || $sent; }
            catch (Throwable $e) { app_log('Bitrix::emailUsers failed: '.$e->getMessage()); }
        }
        return $sent;
    }

}