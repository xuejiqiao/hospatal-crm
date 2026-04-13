<?php
/**
 * 错误诊断工具
 * 访问: http://127.0.0.3/debug.php
 */
header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo '<h2>API错误诊断</h2>';
echo '<pre>';

// 1. PHP版本
echo "1. PHP版本: " . PHP_VERSION . "\n";
echo "   最低要求: 7.0\n";
echo "   状态: " . (version_compare(PHP_VERSION, '7.0.0', '>=') ? '✓ 符合' : '✗ 不符合') . "\n\n";

// 2. 必要扩展
echo "2. PHP扩展检查:\n";
$exts = ['pdo', 'pdo_mysql', 'json', 'curl'];
foreach ($exts as $ext) {
    echo "   {$ext}: " . (extension_loaded($ext) ? '✓' : '✗ 缺失!') . "\n";
}
echo "\n";

// 3. 文件检查
echo "3. 文件检查:\n";
$files = [
    'config/database.php',
    'core/Database.php',
    'core/Response.php',
    'core/Auth.php',
    'core/Log.php',
    'api/hospital.php',
    'api/wechat.php',
    'api/reservation.php',
    'api/stats.php',
    'api.php'
];
foreach ($files as $f) {
    echo "   {$f}: " . (file_exists(__DIR__ . '/' . $f) ? '✓' : '✗ 缺失!') . "\n";
}
echo "\n";

// 4. 配置文件加载
echo "4. 加载配置文件:\n";
try {
    $config = require __DIR__ . '/config/database.php';
    if (!is_array($config)) {
        echo "   ✗ 配置文件返回类型错误: " . gettype($config) . " (应为array)\n";
    } else {
        echo "   ✓ 配置文件加载成功\n";
        echo "   数据库: {$config['db']['database']}\n";
        echo "   主机: {$config['db']['host']}:{$config['db']['port']}\n";
        echo "   用户: {$config['db']['username']}\n";
        echo "   前缀: {$config['db']['prefix']}\n";
    }
} catch (Exception $e) {
    echo "   ✗ 配置加载失败: " . $e->getMessage() . "\n";
}
echo "\n";

// 5. 数据库连接
echo "5. 数据库连接测试:\n";
try {
    $db = $config['db'];
    $dsn = "mysql:host={$db['host']};port={$db['port']};charset={$db['charset']}";
    $pdo = new PDO($dsn, $db['username'], $db['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "   ✓ MySQL连接成功\n";
    
    // 检查/创建数据库
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['database']}` DEFAULT CHARACTER SET {$db['charset']} COLLATE {$db['charset']}_unicode_ci");
    echo "   ✓ 数据库 `{$db['database']}` 已就绪\n";
    
    $pdo->exec("USE `{$db['database']}`");
    
    // 检查表
    echo "\n6. 数据表检查:\n";
    $prefix = $db['prefix'];
    $tables = ['user', 'user_session', 'hospital', 'reservation', 'admin_log'];
    foreach ($tables as $t) {
        $fullTable = $prefix . $t;
        $result = $pdo->query("SHOW TABLES LIKE '{$fullTable}'");
        $exists = $result->rowCount() > 0;
        echo "   {$fullTable}: " . ($exists ? '✓ 存在' : '✗ 不存在 (将自动创建)') . "\n";
    }
    
    // 测试查询
    echo "\n7. 医院数据测试:\n";
    $fullTable = $prefix . 'hospital';
    $result = $pdo->query("SELECT COUNT(*) as count FROM {$fullTable}");
    if ($result) {
        $count = $result->fetch(PDO::FETCH_ASSOC);
        echo "   医院记录数: " . $count['count'] . "\n";
        
        if ($count['count'] > 0) {
            $result = $pdo->query("SELECT id, name, status FROM {$fullTable} LIMIT 5");
            $rows = $result->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                echo "   - [{$row['id']}] {$row['name']} (status={$row['status']})\n";
            }
        } else {
            echo "   (无数据，API启动时会自动插入示例数据)\n";
        }
    }
    
} catch (PDOException $e) {
    echo "   ✗ 数据库错误: " . $e->getMessage() . "\n";
    echo "\n   可能的原因:\n";
    echo "   1. MySQL服务未启动\n";
    echo "   2. 用户名/密码不正确 (当前: {$db['username']}/{$db['password']})\n";
    echo "   3. MySQL端口不正确 (当前: {$db['port']})\n";
}

echo "\n8. API测试链接:\n";
$host = $_SERVER['HTTP_HOST'];
echo "   医院列表: http://{$host}/api.php?module=hospital&action=getList\n";
echo "   医院详情: http://{$host}/api.php?module=hospital&action=getDetail&id=1\n";

echo '</pre>';
