<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h3>Тест подключения к БД</h3>";

try {
    $pdo = new PDO(
        'mysql:host=sql111.infinityfree.com;dbname=if0_41975153_finance;charset=utf8mb4',
        'if0_41975153',
        'EaDhhTCDFeVxUjY'
    );
    echo "<p style='color:green'>✅ БД подключена успешно!</p>";
    
    $tables = $pdo->query("SHOW TABLES")->fetchAll();
    echo "<p>Таблицы:</p><ul>";
    foreach ($tables as $t) echo "<li>" . array_values($t)[0] . "</li>";
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<p style='color:red'>❌ Ошибка: " . $e->getMessage() . "</p>";
}

phpinfo();