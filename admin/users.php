<?php
/**
 * 用户管理页面
 */
$db = Database::getInstance();
$prefix = Database::getConfig()['db']['prefix'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// AJAX: 更新用户状态
if ($action === 'updateStatus' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id']);
    $status = intval($input['status']);
    $db->update('user', array('status' => $status, 'updatetime' => time()), array('id' => $id));
    echo json_encode(array('code' => 200, 'message' => '操作成功'));
    exit;
}

// AJAX: 更新用户角色
if ($action === 'updateRole' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id']);
    $role = trim($input['role']);
    $db->update('user', array('role' => $role, 'updatetime' => time()), array('id' => $id));
    echo json_encode(array('code' => 200, 'message' => '操作成功'));
    exit;
}

// AJAX: 删除用户
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id']);
    $db->delete('user', array('id' => $id));
    echo json_encode(array('code' => 200, 'message' => '删除成功'));
    exit;
}

// 搜索参数
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$pageSize = 15;
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$role = isset($_GET['role']) ? trim($_GET['role']) : '';
$status = isset($_GET['status']) ? intval($_GET['status']) : -1;

$conditions = array();
$params = array();
if ($keyword) {
    $conditions[] = "(nickname LIKE ? OR phone LIKE ? OR name LIKE ?)";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
}
if ($role) {
    $conditions[] = "role = ?";
    $params[] = $role;
}
if ($status >= 0) {
    $conditions[] = "status = ?";
    $params[] = $status;
}

$where = !empty($conditions) ? "WHERE " . implode(' AND ', $conditions) : "";

$total = $db->query("SELECT COUNT(*) as total FROM {$prefix}user {$where}", $params)->fetch()['total'];
$offset = ($page - 1) * $pageSize;
$totalPages = ceil($total / $pageSize);
$list = $db->query("SELECT * FROM {$prefix}user {$where} ORDER BY addtime DESC LIMIT {$offset}, {$pageSize}", $params)->fetchAll();

$roleMap = array('user' => '普通用户', 'admin' => '管理员', 'manager' => '经理');

ob_start();
?>
<div class="card">
    <div class="card-body">
        <form method="get" action="admin.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <input type="hidden" name="module" value="user">
            <input type="text" name="keyword" placeholder="搜索昵称/手机号/姓名" value="<?php echo htmlspecialchars($keyword); ?>" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;width:200px;">
            <select name="role" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;">
                <option value="">全部角色</option>
                <option value="user" <?php echo $role==='user'?'selected':''; ?>>普通用户</option>
                <option value="admin" <?php echo $role==='admin'?'selected':''; ?>>管理员</option>
                <option value="manager" <?php echo $role==='manager'?'selected':''; ?>>经理</option>
            </select>
            <select name="status" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;">
                <option value="-1">全部状态</option>
                <option value="1" <?php echo $status===1?'selected':''; ?>>启用</option>
                <option value="0" <?php echo $status===0?'selected':''; ?>>禁用</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">搜索</button>
            <a href="admin.php?module=user" class="btn btn-outline btn-sm">重置</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>用户列表 (共<?php echo $total; ?>人)</h3>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr><th>ID</th><th>头像</th><th>昵称</th><th>姓名</th><th>手机号</th><th>角色</th><th>状态</th><th>预约数</th><th>注册时间</th><th>操作</th></tr>
            </thead>
            <tbody>
            <?php foreach($list as $u): ?>
            <?php $resCount = $db->count('reservation', array('user_id' => $u['id'])); ?>
            <tr>
                <td><?php echo $u['id']; ?></td>
                <td><div style="width:32px;height:32px;background:#e0e7ff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;color:#4338ca;"><?php echo mb_substr($u['nickname'],0,1); ?></div></td>
                <td><?php echo htmlspecialchars($u['nickname']); ?></td>
                <td><?php echo htmlspecialchars($u['name']); ?></td>
                <td><?php echo htmlspecialchars($u['phone']); ?></td>
                <td>
                    <select onchange="changeRole(<?php echo $u['id']; ?>, this.value)" style="padding:2px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;">
                        <?php foreach($roleMap as $k => $v): ?>
                        <option value="<?php echo $k; ?>" <?php echo $u['role']===$k?'selected':''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <?php if($u['status'] == 1): ?>
                    <span class="badge badge-success">启用</span>
                    <button onclick="toggleStatus(<?php echo $u['id']; ?>, 0)" class="btn btn-outline btn-sm" style="font-size:11px;padding:1px 6px;">禁用</button>
                    <?php else: ?>
                    <span class="badge badge-danger">禁用</span>
                    <button onclick="toggleStatus(<?php echo $u['id']; ?>, 1)" class="btn btn-success btn-sm" style="font-size:11px;padding:1px 6px;">启用</button>
                    <?php endif; ?>
                </td>
                <td><a href="admin.php?module=reservation&keyword=<?php echo urlencode($u['phone']); ?>"><?php echo $resCount; ?></a></td>
                <td><?php echo date('Y-m-d H:i', $u['addtime']); ?></td>
                <td>
                    <button onclick="if(confirmAction('确定删除此用户？'))deleteUser(<?php echo $u['id']; ?>)" class="btn btn-danger btn-sm">删除</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if($totalPages > 1): ?>
    <div class="card-body" style="border-top:1px solid #f3f4f6;">
        <div class="pagination">
            <div class="info">共 <?php echo $total; ?> 条，第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</div>
            <div class="pages">
                <?php if($page > 1): ?><a href="?module=user&page=<?php echo $page-1; ?>&keyword=<?php echo urlencode($keyword); ?>&role=<?php echo $role; ?>">上页</a><?php endif; ?>
                <span class="current"><?php echo $page; ?></span>
                <?php if($page < $totalPages): ?><a href="?module=user&page=<?php echo $page+1; ?>&keyword=<?php echo urlencode($keyword); ?>&role=<?php echo $role; ?>">下页</a><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleStatus(id, status) {
    apiPost('admin.php?module=user&action=updateStatus', {id:id, status:status}, function(d) {
        if(d.code===200) { showToast('操作成功','success'); setTimeout(function(){location.reload();},500); }
    });
}
function changeRole(id, role) {
    apiPost('admin.php?module=user&action=updateRole', {id:id, role:role}, function(d) {
        if(d.code===200) { showToast('角色已更新','success'); }
    });
}
function deleteUser(id) {
    apiPost('admin.php?module=user&action=delete', {id:id}, function(d) {
        if(d.code===200) { showToast('已删除','success'); setTimeout(function(){location.reload();},500); }
    });
}
</script>
<?php
renderAdmin('用户管理', 'user', ob_get_clean());
