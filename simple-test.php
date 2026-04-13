<?php
/**
 * 简单API测试 - 直接显示错误
 */
header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>API测试</h1>";

echo "<h2>1. 加载核心文件</h2>";
try {
    require_once __DIR__ . '/core/Database.php';
    require_once __DIR__ . '/core/Response.php';
    echo "✓ 核心文件加载成功<br>";
} catch (Exception $e) {
    echo "✗ 错误: " . $e->getMessage() . "<br>";
    exit;
}

echo "<h2>2. 测试数据库连接</h2>";
try {
    $db = Database::getInstance();
    echo "✓ 数据库连接成功<br>";
} catch (Exception $e) {
    echo "✗ 数据库错误: " . $e->getMessage() . "<br>";
    exit;
}

echo "<h2>3. 查询医院数据</h2>";
try {
    $result = $db->select('hospital', ['status' => 1], '*', 'sort DESC, id DESC', '0, 10');
    echo "✓ 查询成功，共 " . count($result) . " 条记录<br>";
    echo "<pre>";
    print_r($result);
    echo "</pre>";
} catch (Exception $e) {
    echo "✗ 查询错误: " . $e->getMessage() . "<br>";
    echo "<pre>堆栈跟踪:\n";
    echo $e->getTraceAsString();
    echo "</pre>";
}

echo "<h2>4. 测试完整API</h2>";
echo "<p><a href='api.php?module=hospital&action=getList' target='_blank'>点击测试API接口</a></p>";
