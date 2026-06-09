<?php
class DB {
    private static $pdo   = null;
    private static $tries = 0;

    public static function connect() {
        if (self::$pdo !== null) return self::$pdo;

        $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';
        $opt = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT         => false,
            PDO::ATTR_TIMEOUT            => 10,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone='+05:00'",
        ];

        $maxTries = 3;
        $lastErr  = null;
        for ($i = 0; $i < $maxTries; $i++) {
            try {
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $opt);
                self::$tries = 0;
                return self::$pdo;
            } catch (PDOException $e) {
                $lastErr = $e;
                self::$pdo = null;
                if ($i < $maxTries - 1) usleep(200000); // 200ms retry
            }
        }
        // Вернуть понятную ошибку без раскрытия деталей
        error_log('DB connect failed: ' . $lastErr->getMessage());
        throw new RuntimeException('Ошибка подключения к базе данных. Попробуйте позже.');
    }

    public static function query($sql, $params = []) {
        try {
            $stmt = self::connect()->prepare($sql);
            $stmt->execute($params ?: []);
            return $stmt;
        } catch (PDOException $e) {
            // Попытка переподключения при потере соединения
            if (in_array($e->getCode(), ['HY000', '2006', '2013'])) {
                self::$pdo = null;
                $stmt = self::connect()->prepare($sql);
                $stmt->execute($params ?: []);
                return $stmt;
            }
            error_log('DB query error: ' . $e->getMessage() . ' SQL: ' . $sql);
            throw $e;
        }
    }

    public static function fetchAll($sql, $params = []) {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchOne($sql, $params = []) {
        return self::query($sql, $params)->fetch();
    }

    public static function insert($sql, $params = []) {
        self::query($sql, $params);
        return self::connect()->lastInsertId();
    }

    public static function execute($sql, $params = []) {
        return self::query($sql, $params)->rowCount();
    }

    private static $afterCommit = [];

    public static function afterCommit(callable $fn) {
        $pdo = self::connect();
        if ($pdo->inTransaction()) {
            self::$afterCommit[] = $fn;
            return;
        }
        $fn();
    }

    private static function flushAfterCommit() {
        $callbacks = self::$afterCommit;
        self::$afterCommit = [];
        foreach ($callbacks as $cb) {
            try { $cb(); }
            catch (Throwable $e) { error_log('afterCommit callback failed: ' . $e->getMessage()); }
        }
    }

    // Транзакция с защитой от вложенных транзакций и двойного rollback/commit.
    public static function transaction(callable $fn) {
        $pdo = self::connect();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            if ($pdo->inTransaction()) $pdo->commit();
            self::flushAfterCommit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            self::$afterCommit = [];
            throw $e;
        }
    }
}
