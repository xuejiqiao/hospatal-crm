<?php
/**
 * CRM后台管理系统 - 公共模板
 * 提供页面框架、侧边栏、顶栏
 */

// 防止直接访问
if (!defined('CRM_ADMIN')) {
    exit('非法访问');
}

/**
 * 渲染后台页面
 * @param string $pageTitle 页面标题
 * @param string $activeMenu 当前激活菜单
 * @param string $content 页面内容HTML
 */
function renderAdmin($pageTitle, $activeMenu, $content) {
    $adminUser = isset($_SESSION['admin_user']) ? $_SESSION['admin_user'] : array();
    $adminName = isset($adminUser['nickname']) ? $adminUser['nickname'] : '管理员';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - CRM后台管理</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif; background:#f0f2f5; color:#333; font-size:14px; }

        /* 顶栏 */
        .topbar { position:fixed; top:0; left:0; right:0; height:56px; background:#1d4ed8; color:#fff; display:flex; align-items:center; justify-content:space-between; padding:0 24px; z-index:100; box-shadow:0 2px 8px rgba(0,0,0,.15); }
        .topbar .logo { font-size:18px; font-weight:700; letter-spacing:1px; }
        .topbar .logo span { color:#93c5fd; font-weight:400; font-size:13px; margin-left:8px; }
        .topbar .user-info { display:flex; align-items:center; gap:12px; }
        .topbar .user-info .avatar { width:32px; height:32px; background:#3b82f6; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700; }
        .topbar .user-info a { color:#bfdbfe; text-decoration:none; font-size:13px; }
        .topbar .user-info a:hover { color:#fff; }

        /* 侧边栏 */
        .sidebar { position:fixed; top:56px; left:0; bottom:0; width:220px; background:#fff; border-right:1px solid #e5e7eb; overflow-y:auto; z-index:90; }
        .sidebar .menu-group { padding:8px 0; border-bottom:1px solid #f3f4f6; }
        .sidebar .menu-group-title { padding:8px 20px; font-size:12px; color:#9ca3af; text-transform:uppercase; letter-spacing:1px; }
        .sidebar a { display:flex; align-items:center; padding:10px 20px; color:#4b5563; text-decoration:none; transition:all .15s; border-left:3px solid transparent; }
        .sidebar a:hover { background:#eff6ff; color:#1d4ed8; }
        .sidebar a.active { background:#eff6ff; color:#1d4ed8; border-left-color:#1d4ed8; font-weight:600; }
        .sidebar a .icon { width:20px; margin-right:10px; text-align:center; font-size:16px; }

        /* 主内容 */
        .main { margin-left:220px; margin-top:56px; padding:24px; min-height:calc(100vh - 56px); }

        /* 卡片 */
        .card { background:#fff; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:20px; }
        .card-header { padding:16px 20px; border-bottom:1px solid #f3f4f6; display:flex; align-items:center; justify-content:space-between; }
        .card-header h3 { font-size:16px; font-weight:600; }
        .card-body { padding:20px; }

        /* 统计卡片 */
        .stat-cards { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; }
        .stat-card { background:#fff; border-radius:8px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .stat-card .label { font-size:13px; color:#6b7280; margin-bottom:8px; }
        .stat-card .value { font-size:28px; font-weight:700; color:#111827; }
        .stat-card .sub { font-size:12px; color:#9ca3af; margin-top:4px; }
        .stat-card.blue { border-left:4px solid #3b82f6; }
        .stat-card.green { border-left:4px solid #10b981; }
        .stat-card.orange { border-left:4px solid #f59e0b; }
        .stat-card.red { border-left:4px solid #ef4444; }

        /* 表格 */
        .data-table { width:100%; border-collapse:collapse; }
        .data-table th { background:#f9fafb; padding:10px 12px; text-align:left; font-weight:600; color:#6b7280; font-size:13px; border-bottom:2px solid #e5e7eb; white-space:nowrap; }
        .data-table td { padding:10px 12px; border-bottom:1px solid #f3f4f6; font-size:13px; }
        .data-table tr:hover td { background:#f9fafb; }

        /* 状态标签 */
        .badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:12px; font-weight:500; }
        .badge-success { background:#d1fae5; color:#065f46; }
        .badge-warning { background:#fef3c7; color:#92400e; }
        .badge-danger { background:#fee2e2; color:#991b1b; }
        .badge-info { background:#dbeafe; color:#1e40af; }
        .badge-gray { background:#f3f4f6; color:#6b7280; }

        /* 按钮 */
        .btn { display:inline-flex; align-items:center; padding:6px 14px; border-radius:6px; font-size:13px; font-weight:500; border:none; cursor:pointer; text-decoration:none; transition:all .15s; gap:4px; }
        .btn-primary { background:#1d4ed8; color:#fff; } .btn-primary:hover { background:#1e40af; }
        .btn-success { background:#10b981; color:#fff; } .btn-success:hover { background:#059669; }
        .btn-warning { background:#f59e0b; color:#fff; } .btn-warning:hover { background:#d97706; }
        .btn-danger { background:#ef4444; color:#fff; } .btn-danger:hover { background:#dc2626; }
        .btn-outline { background:#fff; color:#4b5563; border:1px solid #d1d5db; } .btn-outline:hover { background:#f9fafb; border-color:#9ca3af; }
        .btn-sm { padding:4px 10px; font-size:12px; }

        /* 表单 */
        .form-group { margin-bottom:16px; }
        .form-group label { display:block; margin-bottom:4px; font-weight:500; color:#374151; font-size:13px; }
        .form-group input, .form-group select, .form-group textarea { width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; transition:border-color .15s; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.15); }
        .form-group textarea { min-height:80px; resize:vertical; }

        /* 搜索栏 */
        .toolbar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
        .toolbar input, .toolbar select { padding:7px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; }
        .toolbar input:focus, .toolbar select:focus { outline:none; border-color:#3b82f6; }

        /* 分页 */
        .pagination { display:flex; align-items:center; justify-content:space-between; padding:12px 0; }
        .pagination .info { color:#6b7280; font-size:13px; }
        .pagination .pages { display:flex; gap:4px; }
        .pagination .pages a, .pagination .pages span { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 8px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; text-decoration:none; color:#4b5563; }
        .pagination .pages span.current { background:#1d4ed8; color:#fff; border-color:#1d4ed8; }
        .pagination .pages a:hover { background:#f3f4f6; }

        /* 模态框 */
        .modal-overlay { display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.4); z-index:200; align-items:center; justify-content:center; }
        .modal-overlay.show, .modal-overlay.active { display:flex; }
        .modal { background:#fff; border-radius:8px; width:560px; max-width:90vw; max-height:85vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.3); }
        .modal-header { padding:16px 20px; border-bottom:1px solid #f3f4f6; display:flex; align-items:center; justify-content:space-between; }
        .modal-header h3 { font-size:16px; font-weight:600; }
        .modal-header .close { width:28px; height:28px; display:flex; align-items:center; justify-content:center; border:none; background:none; cursor:pointer; font-size:18px; color:#9ca3af; border-radius:4px; }
        .modal-header .close:hover { background:#f3f4f6; color:#374151; }
        .modal-body { padding:20px; }
        .modal-footer { padding:12px 20px; border-top:1px solid #f3f4f6; display:flex; justify-content:flex-end; gap:8px; }

        /* Toast提示 */
        .toast-container { position:fixed; top:70px; right:20px; z-index:300; }
        .toast { padding:12px 20px; border-radius:6px; margin-bottom:8px; color:#fff; font-size:13px; box-shadow:0 4px 12px rgba(0,0,0,.15); animation:slideIn .3s; }
        .toast-success { background:#10b981; }
        .toast-error { background:#ef4444; }
        .toast-info { background:#3b82f6; }
        @keyframes slideIn { from{transform:translateX(100px);opacity:0} to{transform:translateX(0);opacity:1} }

        /* 登录页 */
        .login-page { display:flex; align-items:center; justify-content:center; min-height:100vh; background:linear-gradient(135deg,#1d4ed8 0%,#3b82f6 50%,#60a5fa 100%); }
        .login-box { background:#fff; border-radius:12px; padding:40px; width:400px; max-width:90vw; box-shadow:0 20px 60px rgba(0,0,0,.3); }
        .login-box h1 { text-align:center; font-size:24px; color:#1d4ed8; margin-bottom:8px; }
        .login-box p { text-align:center; color:#6b7280; margin-bottom:30px; font-size:14px; }
        .login-box .form-group { margin-bottom:20px; }
        .login-box .btn-primary { width:100%; padding:12px; font-size:15px; justify-content:center; }
        .login-error { background:#fee2e2; color:#991b1b; padding:10px; border-radius:6px; margin-bottom:16px; font-size:13px; display:none; }

        /* 响应式 */
        @media(max-width:768px) {
            .sidebar { display:none; }
            .main { margin-left:0; }
            .stat-cards { grid-template-columns:repeat(2,1fr); }
        }
    </style>
</head>
<body>
    <!-- 顶栏 -->
    <div class="topbar">
        <div class="logo">CRM管理系统 <span>后台管理</span></div>
        <div class="user-info">
            <div class="avatar"><?php echo mb_substr($adminName, 0, 1); ?></div>
            <span><?php echo htmlspecialchars($adminName); ?></span>
            <a href="admin.php?module=auth&action=logout">退出登录</a>
        </div>
    </div>

    <!-- 侧边栏 -->
    <div class="sidebar">
        <div class="menu-group">
            <div class="menu-group-title">概览</div>
            <a href="admin.php?module=index" class="<?php echo $activeMenu=='index'?'active':''; ?>">
                <span class="icon">📊</span> 仪表盘
            </a>
        </div>
        <div class="menu-group">
            <div class="menu-group-title">业务管理</div>
            <a href="admin.php?module=reservation" class="<?php echo $activeMenu=='reservation'?'active':''; ?>">
                <span class="icon">📅</span> 预约管理
            </a>
            <a href="admin.php?module=patient" class="<?php echo $activeMenu=='patient'?'active':''; ?>">
                <span class="icon">🏥</span> 患者管理
            </a>
            <a href="admin.php?module=follow_up" class="<?php echo $activeMenu=='follow_up'?'active':''; ?>">
                <span class="icon">📞</span> 随访管理
            </a>
            <a href="admin.php?module=hospital" class="<?php echo $activeMenu=='hospital'?'active':''; ?>">
                <span class="icon">🏨</span> 医院管理
            </a>
            <a href="admin.php?module=department" class="<?php echo $activeMenu=='department'?'active':''; ?>">
                <span class="icon">📋</span> 科室管理
            </a>
            <a href="admin.php?module=doctor" class="<?php echo $activeMenu=='doctor'?'active':''; ?>">
                <span class="icon">👨‍⚕️</span> 医生管理
            </a>
        </div>
        <div class="menu-group">
            <div class="menu-group-title">系统管理</div>
            <a href="admin.php?module=custom_field" class="<?php echo $activeMenu=='custom_field'?'active':''; ?>">
                <span class="icon">&#x1F527;</span> 自定义字段
            </a>
            <a href="admin.php?module=import" class="<?php echo $activeMenu=='import'?'active':''; ?>">
                <span class="icon">&#x1F4E5;</span> 数据导入
            </a>
            <a href="admin.php?module=export" class="<?php echo $activeMenu=='export'?'active':''; ?>">
                <span class="icon">&#x1F4E4;</span> 数据导出
            </a>
            <a href="admin.php?module=user" class="<?php echo $activeMenu=='user'?'active':''; ?>">
                <span class="icon">&#x1F465;</span> 用户管理
            </a>
            <a href="admin.php?module=admin_user" class="<?php echo $activeMenu=='admin_user'?'active':''; ?>">
                <span class="icon">&#x1F464;</span> 管理员管理
            </a>
            <a href="admin.php?module=stats" class="<?php echo $activeMenu=='stats'?'active':''; ?>">
                <span class="icon">&#x1F4C8;</span> 数据统计
            </a>
            <a href="admin.php?module=log" class="<?php echo $activeMenu=='log'?'active':''; ?>">
                <span class="icon">&#x1F4DD;</span> 操作日志
            </a>
            <a href="admin.php?module=settings" class="<?php echo $activeMenu=='settings'?'active':''; ?>">
                <span class="icon">&#x2699;</span> 系统设置
            </a>
        </div>
    </div>

    <!-- 主内容区 -->
    <div class="main">
        <?php echo $content; ?>
    </div>

    <!-- Toast容器 -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
    // Toast提示
    function showToast(msg, type) {
        type = type || 'info';
        var t = document.createElement('div');
        t.className = 'toast toast-' + type;
        t.textContent = msg;
        document.getElementById('toastContainer').appendChild(t);
        setTimeout(function(){ t.remove(); }, 3000);
    }

    // 确认对话框
    function confirmAction(msg) {
        return confirm(msg);
    }

    // 模态框
    function openModal(id) {
        document.getElementById(id).classList.add('show');
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove('show');
    }

    // AJAX请求
    function apiPost(url, data, callback) {
        fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        }).then(function(r){ 
            if (!r.ok) {
                return r.text().then(function(t){
                    // 尝试解析JSON错误响应
                    try { var j = JSON.parse(t); if(j.message) throw new Error(j.message); } catch(pe) {}
                    throw new Error('HTTP ' + r.status + ': ' + t.substring(0, 200));
                });
            }
            return r.json(); 
        }).then(function(d){ callback(d); }).catch(function(e){ showToast('请求失败: '+e.message, 'error'); console.error('apiPost error:', e); });
    }

    function apiGet(url, callback) {
        fetch(url).then(function(r){ 
            if (!r.ok) {
                return r.text().then(function(t){
                    try { var j = JSON.parse(t); if(j.message) throw new Error(j.message); } catch(pe) {}
                    throw new Error('HTTP ' + r.status + ': ' + t.substring(0, 200));
                });
            }
            return r.json(); 
        }).then(function(d){ callback(d); }).catch(function(e){ showToast('请求失败: '+e.message, 'error'); console.error('apiGet error:', e); });
    }
    </script>
</body>
</html>
<?php
}
?>
