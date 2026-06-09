<?php
class AccessControl {

    private static function hasColumn($column) {
        static $cache = [];
        if (!array_key_exists($column, $cache)) {
            try {
                DB::fetchOne("SELECT `{$column}` FROM account_permissions LIMIT 1");
                $cache[$column] = true;
            } catch (Throwable $e) {
                $cache[$column] = false;
            }
        }
        return $cache[$column];
    }

    private static function managerIds() {
        $raw = defined('APP_MANAGER_USER_IDS') ? APP_MANAGER_USER_IDS : '';
        return array_values(array_filter(array_map('intval', preg_split('/[,;\s]+/', (string)$raw))));
    }

    private static function normalizePosition($position) {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$position)));
    }

    private static function matchSql($portal, $userId, $position, $extraWhere = '1=1') {
        $parts = [];
        $params = [$portal];
        if ((int)$userId > 0) {
            $parts[] = 'bitrix_user_id=?';
            $params[] = (int)$userId;
        }
        $position = trim((string)$position);
        if ($position !== '') {
            $parts[] = 'LOWER(TRIM(bitrix_position))=?';
            $params[] = self::normalizePosition($position);
        }
        if (!$parts) return [null, []];
        return ["portal=? AND ({$extraWhere}) AND (" . implode(' OR ', $parts) . ")", $params];
    }

    private static function hasExplicitRule($portal, $userId, $position = '') {
        try {
            [$where, $params] = self::matchSql($portal, $userId, $position, '1=1');
            if (!$where) return false;
            return (bool)DB::fetchOne("SELECT id FROM account_permissions WHERE {$where} LIMIT 1", $params);
        } catch (Throwable $e) {
            app_log('AccessControl::hasExplicitRule failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function hasExplicitManagerRule($portal, $userId, $position = '') {
        try {
            [$where, $params] = self::matchSql($portal, $userId, $position, 'is_manager=1');
            if (!$where) return false;
            return (bool)DB::fetchOne("SELECT id FROM account_permissions WHERE {$where} LIMIT 1", $params);
        } catch (Throwable $e) {
            app_log('AccessControl::hasExplicitManagerRule failed: ' . $e->getMessage());
            return false;
        }
    }

    private static function explicitFlagValue($portal, $userId, $position, $flag) {
        $flag = preg_replace('/[^a-z_]/', '', (string)$flag);
        if ($flag === '' || !self::hasColumn($flag)) return null;
        try {
            [$where, $params] = self::matchSql($portal, $userId, $position, '1=1');
            if (!$where) return null;
            $row = DB::fetchOne("SELECT MAX(CASE WHEN `{$flag}`=1 THEN 1 ELSE 0 END) AS allowed FROM account_permissions WHERE {$where}", $params);
            if ($row === false || $row === null) return null;
            return !empty($row['allowed']);
        } catch (Throwable $e) {
            app_log('AccessControl::explicitFlagValue failed: ' . $e->getMessage());
            return null;
        }
    }


    private static function strictFlagNames() {
        return [
            // Эти права нельзя получать автоматически только из-за статуса руководителя/админа.
            // Они должны быть явно включены в финансовой системе.
            'can_change_request_route',
            'can_settings_approval',
        ];
    }

    private static function isStrictFlag($flag) {
        return in_array((string)$flag, self::strictFlagNames(), true);
    }

    public static function hasStrictFlag($portal, $userId, $position, $flag) {
        $flag = preg_replace('/[^a-z_]/', '', (string)$flag);
        if ($flag === '' || !self::hasColumn($flag)) return false;
        $explicit = self::explicitFlagValue($portal, $userId, $position, $flag);
        return $explicit === true;
    }

    public static function isManager($portal, $userId, $position = '', $currentUser = null) {
        $userId = (int)$userId;
        $position = trim((string)$position);

        // Финансовая система имеет приоритет над правами администратора Bitrix24.
        // Если для пользователя/должности уже создано правило в разделе "Права",
        // доступ руководителя выдаётся только при is_manager=1 в этом правиле.
        if (self::hasExplicitRule($portal, $userId, $position)) {
            return self::hasExplicitManagerRule($portal, $userId, $position);
        }

        // Жёстко заданные руководители приложения остаются bootstrap-доступом.
        // Если хотите ограничить такого пользователя, создайте для него явное правило выше.
        if ($userId > 0 && in_array($userId, self::managerIds(), true)) {
            return true;
        }

        // Bitrix-админ — только fallback, когда в финансовой системе для него нет отдельного правила.
        // Это нужно для первичной установки, но не должно перебивать внутренние права касс.
        if ($userId > 0 && is_array($currentUser)) {
            $cuId = (int)($currentUser['ID'] ?? 0);
            if ($cuId === $userId && (!empty($currentUser['ADMIN']) || !empty($currentUser['IS_ADMIN']))) {
                return true;
            }
        }

        return false;
    }

    public static function canEditReport($portal, $userId, $position = '', $currentUser = null) {
        if (self::isManager($portal, $userId, $position, $currentUser)) return true;
        if (!self::hasColumn('can_edit_report')) return false;
        [$where, $params] = self::matchSql($portal, $userId, $position, 'can_edit_report=1');
        if (!$where) return false;
        try {
            return (bool)DB::fetchOne("SELECT id FROM account_permissions WHERE {$where} LIMIT 1", $params);
        } catch (Throwable $e) {
            app_log('AccessControl::canEditReport failed: ' . $e->getMessage());
            return false;
        }
    }


    private static function sectionColumn($section) {
        $map = [
            'dashboard' => 'can_view_dashboard',
            'payments'  => 'can_view_payments',
            'add'       => 'can_add_payment',
            'import'    => 'can_view_import',
            'report'    => 'can_view_report',
            'settings'  => 'can_view_settings',
            'requests'  => 'can_view_requests',
        ];
        return $map[$section] ?? '';
    }

    public static function systemSections() {
        return [
            'dashboard' => '📊 Дашборд',
            'payments'  => '📋 Платежи',
            'add'       => '➕ Добавление',
            'import'    => '📥 Импорт',
            'report'    => '📈 Отчёты',
            'settings'  => '⚙️ Настройки',
            'requests'  => '🧾 Согласование',
        ];
    }

    public static function canViewSection($portal, $userId, $position, $section, $isManager = false, $currentUser = null) {
        $section = preg_replace('/[^a-z_]/', '', (string)$section);
        if ($section === '') return false;

        $col = self::sectionColumn($section);
        if ($col === '') return false;

        // Настройки показываем только если есть доступ хотя бы к одному табу настроек.
        if ($section === 'settings') {
            $tabs = self::allowedSettingsTabs($portal, $userId, $position, false, $currentUser);
            if (empty($tabs)) return false;
        }

        // Явное правило по разделу имеет приоритет над статусом Bitrix-админа.
        $explicit = self::explicitFlagValue($portal, $userId, $position, $col);
        if ($explicit !== null) return (bool)$explicit;

        if ($isManager || self::isManager($portal, $userId, $position, $currentUser)) return true;

        if (!self::hasColumn($col)) {
            return $section !== 'settings';
        }
        return false;
    }

    public static function allowedSystemSections($portal, $userId, $position, $isManager = false, $currentUser = null) {
        $out = [];
        foreach (self::systemSections() as $k => $label) {
            if (self::canViewSection($portal, $userId, $position, $k, $isManager, $currentUser)) {
                $out[$k] = $label;
            }
        }
        return $out;
    }

    private static function settingsColumn($tab) {
        $map = [
            'accounts'    => 'can_settings_accounts',
            'categories'  => 'can_settings_categories',
            'currencies'  => 'can_settings_currencies',
            'departments' => 'can_settings_departments',
            'access'      => 'can_settings_access',
            'import'      => 'can_settings_import',
            'approval'    => 'can_settings_approval',
        ];
        return $map[$tab] ?? '';
    }

    public static function settingsTabs() {
        return [
            'accounts'    => '💰 Счета / кассы',
            'categories'  => '📂 Статьи',
            'currencies'  => '💱 Валюты',
            'departments' => '🏢 Отделы',
            'access'      => '🔐 Права',
            'approval'    => '🧭 Маршрут заявок',
        ];
    }

    public static function canSettingsTab($portal, $userId, $position, $tab, $isManager = false, $currentUser = null) {
        $tab = preg_replace('/[^a-z_]/', '', (string)$tab);
        $col = self::settingsColumn($tab);
        if ($col === '') return false;

        // Раздел прав доступа нельзя делегировать обычному сотруднику. Только руководитель/админ без явного запрета.
        if ($tab === 'access') {
            return self::isManager($portal, $userId, $position, $currentUser);
        }

        // Маршрут согласования — строгое право. Даже руководитель/админ Bitrix24 видит его только если
        // в финансовой системе включена галочка "Маршрут заявок".
        if ($tab === 'approval') {
            return self::hasStrictFlag($portal, $userId, $position, 'can_settings_approval');
        }

        // Явное правило в финансовой системе важнее статуса Bitrix-админа или руководителя.
        $explicit = self::explicitFlagValue($portal, $userId, $position, $col);
        if ($explicit !== null) return (bool)$explicit;

        if ($isManager || self::isManager($portal, $userId, $position, $currentUser)) return true;
        if (!self::hasColumn($col)) return false;
        return false;
    }

    public static function allowedSettingsTabs($portal, $userId, $position, $isManager = false, $currentUser = null) {
        $tabs = [];
        foreach (self::settingsTabs() as $k => $label) {
            if (self::canSettingsTab($portal, $userId, $position, $k, $isManager, $currentUser)) {
                $tabs[$k] = $label;
            }
        }
        return $tabs;
    }


    public static function hasFlag($portal, $userId, $position, $flag, $isManager = false, $currentUser = null) {
        $flag = preg_replace('/[^a-z_]/', '', (string)$flag);
        if ($flag === '') return false;

        // Строгие права не наследуются автоматически от руководителя/админа.
        if (self::isStrictFlag($flag)) {
            return self::hasStrictFlag($portal, $userId, $position, $flag);
        }

        // Если для пользователя/должности создано явное правило в финансовой системе,
        // конкретный флаг из этого правила имеет приоритет даже над статусом руководителя/админа.
        $explicit = self::explicitFlagValue($portal, $userId, $position, $flag);
        if ($explicit !== null) return (bool)$explicit;

        // Bootstrap-доступ руководителя/админа действует только когда явного правила нет.
        if ($isManager || self::isManager($portal, $userId, $position, $currentUser)) return true;
        if (!self::hasColumn($flag)) return false;
        return false;
    }

    public static function getAllowedAccountIds($portal, $userId, $position) {
        try {
            [$where, $params] = self::matchSql($portal, $userId, $position, 'is_manager=0');
            if (!$where) return [];
            $rows = DB::fetchAll(
                "SELECT account_id FROM account_permissions WHERE {$where}",
                $params
            );
        } catch (Throwable $e) {
            app_log('AccessControl::getAllowedAccountIds failed: ' . $e->getMessage());
            $rows = [];
        }

        if (empty($rows)) return [];
        $allowed = array_values(array_unique(array_map('intval', array_column($rows, 'account_id'))));
        if (in_array(0, $allowed, true)) return [0];
        return array_values(array_filter($allowed, static fn($id) => $id > 0));
    }

    public static function getAllowedAccounts($portal, $userId, $position, $allAccounts) {
        $allowed = self::getAllowedAccountIds($portal, $userId, $position);
        if (in_array(0, $allowed, true)) return $allAccounts;
        if (empty($allowed)) return [];
        return array_values(array_filter($allAccounts, fn($a) => in_array((int)$a['id'], $allowed, true)));
    }

    public static function canAccessAccount($portal, $userId, $position, $accountId, $isManager = false) {
        if ($isManager) return true;
        $accountId = (int)$accountId;
        if ($accountId <= 0) return false;
        $allowed = self::getAllowedAccountIds($portal, $userId, $position);
        if (in_array(0, $allowed, true)) return true;
        return in_array($accountId, $allowed, true);
    }

    public static function getPermissions($portal) {
        try {
            return DB::fetchAll(
                "SELECT ap.*, a.name as account_name
                 FROM account_permissions ap
                 LEFT JOIN accounts a ON a.id=ap.account_id
                 WHERE ap.portal=?
                 ORDER BY ap.is_manager DESC, ap.can_edit_report DESC, a.name, ap.bitrix_position, ap.bitrix_user_id, ap.id",
                [$portal]
            );
        } catch (Throwable $e) { return []; }
    }


    public static function permissionFlagColumns() {
        return [
            'can_edit_report',
            'can_settings_accounts',
            'can_settings_categories',
            'can_settings_currencies',
            'can_settings_departments',
            'can_settings_import',
            'can_view_dashboard',
            'can_view_payments',
            'can_add_payment',
            'can_view_import',
            'can_view_report',
            'can_view_settings',
            'can_view_requests',
            'can_create_request',
            'can_approve_request',
            'can_delete_request',
            'can_view_all_requests',
            'can_change_request_route',
            'can_settings_approval',
        ];
    }

    public static function addPermission($portal, $accountId, $position, $userId, $isManager, $flags = [], $userName = '') {
        $accountId = (int)$accountId;
        $position = trim((string)$position) ?: null;
        $userId = (int)$userId ?: null;
        $columns = ['portal','account_id','bitrix_position','bitrix_user_id','is_manager'];
        $values  = [$portal, $accountId, $position, $userId, $isManager ? 1 : 0];
        if (self::hasColumn('bitrix_user_name')) { $columns[] = 'bitrix_user_name'; $values[] = trim((string)$userName) ?: null; }
        foreach (self::permissionFlagColumns() as $col) {
            if (self::hasColumn($col)) {
                $columns[] = $col;
                $values[] = !empty($flags[$col]) ? 1 : 0;
            }
        }
        $place = implode(',', array_fill(0, count($columns), '?'));
        return DB::insert("INSERT INTO account_permissions (`" . implode('`,`', $columns) . "`) VALUES ({$place})", $values);
    }

    public static function updatePermission($portal, $id, $accountId, $position, $userId, $isManager, $flags = [], $userName = '') {
        $id = (int)$id;
        if ($id <= 0) return 0;
        $sets = ['account_id=?','bitrix_position=?','bitrix_user_id=?','is_manager=?'];
        $vals = [(int)$accountId, trim((string)$position) ?: null, ((int)$userId ?: null), $isManager ? 1 : 0];
        if (self::hasColumn('bitrix_user_name')) { $sets[] = 'bitrix_user_name=?'; $vals[] = trim((string)$userName) ?: null; }
        foreach (self::permissionFlagColumns() as $col) {
            if (self::hasColumn($col)) {
                $sets[] = "{$col}=?";
                $vals[] = !empty($flags[$col]) ? 1 : 0;
            }
        }
        $vals[] = $id;
        $vals[] = $portal;
        return DB::execute("UPDATE account_permissions SET " . implode(',', $sets) . " WHERE id=? AND portal=?", $vals);
    }

    public static function deletePermission($portal, $id) {
        return DB::execute(
            "DELETE FROM account_permissions WHERE id=? AND portal=?",
            [$id, $portal]
        );
    }
}
