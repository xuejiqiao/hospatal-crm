<?php
/**
 * 操作日志页面
 */
$db = Database::getInstance();
$prefix = Database::getConfig()['db']['prefix'];

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$pageSize = 20;

$total = $db->count('admin_log');
$offset = ($page - 1) * $pageSize;
$totalPages = ceil($total / $pageSize);

$list = $db->query(
    "SELECT l.*, a.username, a.nickname FROM {$prefix}admin_log l LEFT JOIN {$prefix}admin a ON l.admin_id=a.id ORDER BY l.addtime DESC LIMIT {$offset}, {$pageSize}"
)->fetchAll();

ob_start();
?>
<div class="card">
    <div class="card-header"><h3>操作日志 (共<?php echo $total; ?>条)</h3></div>
    <div class="card-body" style="padding:0;">
        <table class="data-table">
            <thead><tr><th>ID</th><th>操作人</th><th>动作</th><th>目标</th><th>内容</th><th>IP</th><th>时间</th></tr></thead>
            <tbody>
            <?php foreach($list as $l): ?>
            <tr>
                <td><?php echo $l['id']; ?></td>
                <td><?php echo htmlspecialchars(!empty($l['nickname']) ? $l['nickname'] : (!empty($l['username']) ? $l['username'] : '未知')); ?></td>
                <td><span class="badge badge-info"><?php echo htmlspecialchars($l['action']); ?></span></td>
                <td><?php echo $l['target_type'] . '#' . $l['target_id']; ?></td>
                <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($l['content']); ?>"><?php echo htmlspecialchars($l['content']); ?></td>
                <td><?php echo $l['ip']; ?></td>
                <td><?php echo date('Y-m-d H:i:s', $l['addtime']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if($totalPages > 1): ?>
    <div class="card-body" style="border-top:1px solid #f3f4f6;">
        <div class="pagination">
            <div class="info">第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</div>
            <div class="pages">
                <?php if($page > 1): ?><a href="?module=log&page=<?php echo $page-1; ?>">上页</a><?php endif; ?>
                <span class="current"><?php echo $page; ?></span>
                <?php if($page < $totalPages): ?><a href="?module=log&page=<?php echo $page+1; ?>">下页</a><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php renderAdmin('操作日志','log',ob_get_clean());