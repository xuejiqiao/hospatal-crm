<?php
/**
 * 环境检查文件
 * 访问: http://127.0.0.3/check.php
 */

header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>系统环境检查</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        .check-item {
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .check-item.success {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        .check-item.error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
        }
        .check-item.warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        .label {
            font-weight: bold;
            color: #333;
        }
        .value {
            color: #666;
            margin-left: 10px;
        }
        .status {
            float: right;
            font-weight: bold;
        }
        .status.ok {
            color: #28a745;
        }
        .status.fail {
            color: #dc3545;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        table th, table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        table th {
            background: #f8f9fa;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 系统环境检查报告</h1>
        
        <h2>1. PHP环境检查</h2>
        <?php
        $phpVersion = phpversion();
        $phpOk = version_compare($phpVersion, '7.0.0', '>=');
        ?>
        <div class="check-item <?php echo $phpOk ? 'success' : 'error'; ?>">
            <span class="label">PHP版本:</span>
            <span class="value"><?php echo $phpVersion; ?></span>
            <span class="status <?php echo $phpOk ? 'ok' : 'fail'; ?>">
                <?php echo $phpOk ? '✓ 符合要求(>=7.0)' : '✗ 需要PHP 7.0+'; ?>
            </span>
        </div>

        <?php
        $requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'curl'];
        foreach ($requiredExtensions as $ext):
            $loaded = extension_loaded($ext);
        ?>
        <div class="check-item <?php echo $loaded ? 'success' : 'error'; ?>">
            <span class="label">扩展 <?php echo $ext; ?>:</span>
            <span class="status <?php echo $loaded ? 'ok' : 'fail'; ?>">
                <?php echo $loaded ? '✓ 已加载' : '✗ 未加载'; ?>
            </span>
        </div>
        <?php endforeach; ?>

        <h2>2. 目录权限检查</h2>
        <?php
        $dirs = [
            'core' => '核心类库目录',
            'api' => 'API接口目录',
            'config' => '配置目录'
        ];
        
        foreach ($dirs as $dir => $desc):
            $exists = is_dir(__DIR__ . '/' . $dir);
            $readable = is_readable(__DIR__ . '/' . $dir);
        ?>
        <div class="check-item <?php echo ($exists && $readable) ? 'success' : 'error'; ?>">
            <span class="label"><?php echo $desc; ?> (<?php echo $dir; ?>/):</span>
            <span class="status <?php echo ($exists && $readable) ? 'ok' : 'fail'; ?>">
                <?php echo ($exists && $readable) ? '✓ 存在且可读' : '✗ 不存在或不可读'; ?>
            </span>
        </div>
        <?php endforeach; ?>

        <?php
        $writableDirs = ['uploads', 'logs'];
        foreach ($writableDirs as $dir):
            $exists = is_dir(__DIR__ . '/' . $dir);
            if (!$exists) {
                @mkdir(__DIR__ . '/' . $dir, 0755, true);
            }
            $writable = is_writable(__DIR__ . '/' . $dir);
        ?>
        <div class="check-item <?php echo $writable ? 'success' : 'warning'; ?>">
            <span class="label"><?php echo ucfirst($dir); ?>目录 (<?php echo $dir; ?>/):</span>
            <span class="status <?php echo $writable ? 'ok' : 'fail'; ?>">
                <?php echo $writable ? '✓ 可写' : '✗ 不可写'; ?>
            </span>
        </div>
        <?php endforeach; ?>

        <h2>3. 配置文件检查</h2>
        <?php
        $configFile = __DIR__ . '/config/database.php';
        $configExists = file_exists($configFile);
        ?>
        <div class="check-item <?php echo $configExists ? 'success' : 'error'; ?>">
            <span class="label">数据库配置文件:</span>
            <span class="status <?php echo $configExists ? 'ok' : 'fail'; ?>">
                <?php echo $configExists ? '✓ 存在' : '✗ 不存在'; ?>
            </span>
        </div>

        <?php
        if ($configExists) {
            $config = require_once $configFile;
            $dbConfig = $config['db'];
            
            // 测试数据库连接
            try {
                $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}";
                $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password']);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $dbOk = true;
                $dbError = '';
            } catch (Exception $e) {
                $dbOk = false;
                $dbError = $e->getMessage();
            }
        ?>
        <div class="check-item <?php echo $dbOk ? 'success' : 'error'; ?>">
            <span class="label">数据库连接:</span>
            <span class="value"><?php echo $dbConfig['host'] . '/' . $dbConfig['database']; ?></span>
            <span class="status <?php echo $dbOk ? 'ok' : 'fail'; ?>">
                <?php echo $dbOk ? '✓ 连接成功' : '✗ 连接失败: ' . $dbError; ?>
            </span>
        </div>

        <?php
        if ($dbOk) {
            // 检查表是否存在
            $tables = ['weapp_user', 'weapp_hospital', 'weapp_reservation', 'weapp_user_session'];
            foreach ($tables as $table):
                $result = $pdo->query("SHOW TABLES LIKE '{$dbConfig['prefix']}{$table}'");
                $tableExists = $result->rowCount() > 0;
            ?>
            <div class="check-item <?php echo $tableExists ? 'success' : 'warning'; ?>">
                <span class="label">数据表 <?php echo $dbConfig['prefix'] . $table; ?>:</span>
                <span class="status <?php echo $tableExists ? 'ok' : 'fail'; ?>">
                    <?php echo $tableExists ? '✓ 存在' : '✗ 不存在 (请运行install.sql)'; ?>
                </span>
            </div>
            <?php
            endforeach;
        }
        ?>

        <h2>4. API接口检查</h2>
        <table>
            <tr>
                <th>接口</th>
                <th>URL</th>
                <th>状态</th>
            </tr>
            <tr>
                <td>医院列表</td>
                <td>/api.php?module=hospital&action=getList</td>
                <td><a href="api.php?module=hospital&action=getList" target="_blank">测试</a></td>
            </tr>
            <tr>
                <td>微信登录</td>
                <td>/api.php?module=wechat&action=login</td>
                <td><a href="api.php?module=wechat&action=login" target="_blank">测试</a></td>
            </tr>
            <tr>
                <td>预约列表</td>
                <td>/api.php?module=reservation&action=getList</td>
                <td><a href="api.php?module=reservation&action=getList" target="_blank">测试</a></td>
            </tr>
            <tr>
                <td>统计概览</td>
                <td>/api.php?module=stats&action=getOverview</td>
                <td><a href="api.php?module=stats&action=getOverview" target="_blank">测试</a></td>
            </tr>
        </table>

        <h2>5. 快速测试链接</h2>
        <ul>
            <li><a href="quick-test.html" target="_blank">快速测试工具</a></li>
            <li><a href="test.html" target="_blank">完整测试工具</a></li>
        </ul>

        <?php
        } else {
            echo '<div class="check-item error">数据库未连接，无法继续检查</div>';
        }
        ?>

        <div style="margin-top: 30px; padding: 20px; background: #e7f3ff; border-radius: 6px;">
            <h3>📝 使用说明</h3>
            <ol>
                <li>确保所有检查项都通过(显示✓)</li>
                <li>如果数据库表不存在，请导入 <code>install.sql</code></li>
                <li>访问 <code>quick-test.html</code> 进行API测试</li>
                <li>API调用示例: <code>GET /api.php?module=hospital&action=getList&page=1&pageSize=10</code></li>
            </ol>
        </div>
    </div>
</body>
</html>
