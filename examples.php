<?php
/**
 * API使用示例
 */
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>API使用示例</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
        }
        .example {
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-left: 4px solid #007bff;
            border-radius: 4px;
        }
        .example h3 {
            margin-top: 0;
            color: #007bff;
        }
        code {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
        pre {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
        a {
            color: #007bff;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📖 CRM API 使用示例</h1>
        
        <div class="example">
            <h3>✅ 第一步：环境检查</h3>
            <p>访问环境检查页面，确保所有配置正确：</p>
            <p><a href="check.php" target="_blank">http://127.0.0.3/check.php</a></p>
        </div>

        <div class="example">
            <h3>✅ 第二步：快速测试</h3>
            <p>使用可视化测试工具测试API：</p>
            <p><a href="quick-test.html" target="_blank">http://127.0.0.3/quick-test.html</a></p>
        </div>

        <h2>📡 API调用示例</h2>

        <div class="example">
            <h3>1. 获取医院列表（无需登录）</h3>
            <p><strong>URL:</strong> <code>GET /api.php?module=hospital&action=getList&page=1&pageSize=10</code></p>
            <p><strong>完整示例:</strong></p>
            <pre><a href="api.php?module=hospital&action=getList&page=1&pageSize=10" target="_blank">http://127.0.0.3/api.php?module=hospital&action=getList&page=1&pageSize=10</a></pre>
            
            <p><strong>JavaScript示例:</strong></p>
            <pre>fetch('http://127.0.0.3/api.php?module=hospital&action=getList&page=1&pageSize=10')
  .then(response => response.json())
  .then(data => console.log(data));</pre>
        </div>

        <div class="example">
            <h3>2. 微信小程序登录</h3>
            <p><strong>URL:</strong> <code>POST /api.php?module=wechat&action=login</code></p>
            <p><strong>请求体:</strong></p>
            <pre>{
  "code": "微信登录code"
}</pre>
            <p><strong>JavaScript示例:</strong></p>
            <pre>fetch('http://127.0.0.3/api.php?module=wechat&action=login', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({code: 'your_code'})
})
  .then(response => response.json())
  .then(data => {
    console.log(data);
    // 保存token
    localStorage.setItem('token', data.data.token);
  });</pre>
        </div>

        <div class="example">
            <h3>3. 创建预约（需要登录）</h3>
            <p><strong>URL:</strong> <code>POST /api.php?module=reservation&action=create</code></p>
            <p><strong>Headers:</strong> <code>Authorization: Bearer {token}</code></p>
            <p><strong>请求体:</strong></p>
            <pre>{
  "hospital_id": 1,
  "patient_name": "张三",
  "patient_phone": "13800138000",
  "reservation_date": "2026-04-10",
  "time_period": "上午",
  "department": "内科",
  "remark": "测试预约"
}</pre>
            <p><strong>JavaScript示例:</strong></p>
            <pre>const token = localStorage.getItem('token');

fetch('http://127.0.0.3/api.php?module=reservation&action=create', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer ' + token
  },
  body: JSON.stringify({
    hospital_id: 1,
    patient_name: '张三',
    patient_phone: '13800138000',
    reservation_date: '2026-04-10',
    time_period: '上午'
  })
})
  .then(response => response.json())
  .then(data => console.log(data));</pre>
        </div>

        <div class="example">
            <h3>4. 获取我的预约列表</h3>
            <p><strong>URL:</strong> <code>GET /api.php?module=reservation&action=myList&page=1&pageSize=10</code></p>
            <p><strong>Headers:</strong> <code>Authorization: Bearer {token}</code></p>
            <pre>const token = localStorage.getItem('token');

fetch('http://127.0.0.3/api.php?module=reservation&action=myList&page=1&pageSize=10', {
  headers: {
    'Authorization': 'Bearer ' + token
  }
})
  .then(response => response.json())
  .then(data => console.log(data));</pre>
        </div>

        <h2>🔧 两种访问方式</h2>

        <div class="example">
            <h3>方式1：简化版（推荐，已测试）</h3>
            <p><code>/api.php?module={模块}&action={动作}</code></p>
            <p>示例: <a href="api.php?module=hospital&action=getList" target="_blank">/api.php?module=hospital&action=getList</a></p>
        </div>

        <div class="example">
            <h3>方式2：RESTful风格（需要URL重写）</h3>
            <p><code>/api/{模块}/{动作}</code></p>
            <p>示例: /api/hospital/getList</p>
            <p>⚠️ 需要配置Apache的.htaccess或Nginx的URL重写规则</p>
        </div>

        <h2>📋 可用模块和动作</h2>
        <table border="1" cellpadding="10" style="width:100%; border-collapse: collapse;">
            <tr style="background: #007bff; color: white;">
                <th>模块</th>
                <th>动作</th>
                <th>说明</th>
                <th>需要登录</th>
            </tr>
            <tr>
                <td>wechat</td>
                <td>login</td>
                <td>微信登录</td>
                <td>否</td>
            </tr>
            <tr>
                <td>wechat</td>
                <td>getUserInfo</td>
                <td>获取用户信息</td>
                <td>是</td>
            </tr>
            <tr>
                <td>wechat</td>
                <td>updateUserInfo</td>
                <td>更新用户信息</td>
                <td>是</td>
            </tr>
            <tr>
                <td>hospital</td>
                <td>getList</td>
                <td>获取医院列表</td>
                <td>否</td>
            </tr>
            <tr>
                <td>hospital</td>
                <td>getDetail</td>
                <td>获取医院详情</td>
                <td>否</td>
            </tr>
            <tr>
                <td>reservation</td>
                <td>create</td>
                <td>创建预约</td>
                <td>是</td>
            </tr>
            <tr>
                <td>reservation</td>
                <td>myList</td>
                <td>我的预约列表</td>
                <td>是</td>
            </tr>
            <tr>
                <td>reservation</td>
                <td>cancel</td>
                <td>取消预约</td>
                <td>是</td>
            </tr>
            <tr>
                <td>stats</td>
                <td>getOverview</td>
                <td>统计概览</td>
                <td>是(管理员)</td>
            </tr>
        </table>

        <div style="margin-top: 30px; padding: 20px; background: #fff3cd; border-radius: 6px;">
            <h3>⚠️ 注意事项</h3>
            <ul>
                <li>确保已导入 <code>install.sql</code> 数据库文件</li>
                <li>修改 <code>config/database.php</code> 中的数据库配置</li>
                <li>修改 <code>config/database.php</code> 中的微信小程序AppID和AppSecret</li>
                <li>需要登录的接口必须在Header中携带Token</li>
                <li>管理员接口需要admin或manager角色的账号</li>
            </ul>
        </div>
    </div>
</body>
</html>
