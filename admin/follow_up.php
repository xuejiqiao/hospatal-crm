<?php
/**
 * 随访管理 - 随访记录列表 + 添加/删除
 */
$db = Database::getInstance();
$prefix = Database::getConfig()['db']['prefix'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// 保存随访记录
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $data = array(
        'reservation_id' => isset($input['reservation_id']) ? intval($input['reservation_id']) : 0,
        'patient_phone' => trim($input['patient_phone']),
        'patient_name' => isset($input['patient_name']) ? trim($input['patient_name']) : '',
        'follow_type' => isset($input['follow_type']) ? trim($input['follow_type']) : 'phone',
        'follow_result' => isset($input['follow_result']) ? trim($input['follow_result']) : 'normal',
        'content' => trim($input['content']),
        'next_date' => isset($input['next_date']) ? trim($input['next_date']) : '',
        'admin_id' => 0,
        'addtime' => time()
    );
    $admin = AdminAuth::getAdmin();
    if ($admin) $data['admin_id'] = intval($admin['id']);
    
    if (empty($data['patient_phone'])) { echo json_encode(array('code'=>400,'message'=>'缺少患者手机号')); exit; }
    if (empty($data['content'])) { echo json_encode(array('code'=>400,'message'=>'请填写随访内容')); exit; }
    
    $id = $db->insert('follow_up', $data);
    addLog('create', 'follow_up', $id, "添加随访记录:{$data['patient_name']}({$data['patient_phone']})");
    echo json_encode(array('code'=>200,'message'=>'随访记录已添加','data'=>array('id'=>$id)));
    exit;
}

// 删除随访记录
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id']);
    if ($id > 0) {
        $db->delete('follow_up', array('id' => $id));
        addLog('delete', 'follow_up', $id, "删除随访记录#{$id}");
        echo json_encode(array('code'=>200,'message'=>'已删除'));
    } else {
        echo json_encode(array('code'=>400,'message'=>'参数错误'));
    }
    exit;
}

// ===== 随访列表 =====
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$followType = isset($_GET['follow_type']) ? trim($_GET['follow_type']) : '';
$followResult = isset($_GET['follow_result']) ? trim($_GET['follow_result']) : '';
$nextDate = isset($_GET['next_date']) ? trim($_GET['next_date']) : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$pageSize = 15;

$sql = "SELECT f.*, a.nickname as admin_name FROM {$prefix}follow_up f LEFT JOIN {$prefix}admin a ON f.admin_id=a.id";
$params = array();
$conditions = array();

if ($keyword) { $conditions[] = "(f.patient_name LIKE ? OR f.patient_phone LIKE ?)"; $params[] = "%{$keyword}%"; $params[] = "%{$keyword}%"; }
if ($followType) { $conditions[] = "f.follow_type = ?"; $params[] = $followType; }
if ($followResult) { $conditions[] = "f.follow_result = ?"; $params[] = $followResult; }
if ($nextDate) { $conditions[] = "f.next_date = ?"; $params[] = $nextDate; }
if (!empty($conditions)) { $sql .= " WHERE " . implode(' AND ', $conditions); }

$countSql = str_replace("SELECT f.*, a.nickname as admin_name", "SELECT COUNT(*) as total", $sql);
try { $total = $db->query($countSql, $params)->fetch()['total']; } catch(PDOException $e) { $total = 0; }

$offset = ($page - 1) * $pageSize;
$totalPages = $total > 0 ? ceil($total / $pageSize) : 1;
$sql .= " ORDER BY f.addtime DESC LIMIT {$offset}, {$pageSize}";
try { $list = $db->query($sql, $params)->fetchAll(); } catch(PDOException $e) { $list = array(); }

$followTypeMap = array('phone'=>'电话','wechat'=>'微信','visit'=>'到访');
$followResultMap = array('normal'=>'正常','abnormal'=>'异常','no_answer'=>'未接听','cancelled'=>'取消');
$followResultBadge = array('normal'=>'badge-success','abnormal'=>'badge-danger','no_answer'=>'badge-warning','cancelled'=>'badge-gray');

ob_start();
?>
<!-- 搜索栏 -->
<div class="card">
    <div class="card-body">
        <form method="get" action="admin.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <input type="hidden" name="module" value="follow_up">
            <input type="text" name="keyword" placeholder="搜索患者姓名/手机号" value="<?php echo htmlspecialchars($keyword); ?>" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;width:180px;">
            <select name="follow_type" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;">
                <option value="">全部方式</option>
                <option value="phone" <?php echo $followType==='phone'?'selected':''; ?>>电话</option>
                <option value="wechat" <?php echo $followType==='wechat'?'selected':''; ?>>微信</option>
                <option value="visit" <?php echo $followType==='visit'?'selected':''; ?>>到访</option>
            </select>
            <select name="follow_result" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;">
                <option value="">全部结果</option>
                <option value="normal" <?php echo $followResult==='normal'?'selected':''; ?>>正常</option>
                <option value="abnormal" <?php echo $followResult==='abnormal'?'selected':''; ?>>异常</option>
                <option value="no_answer" <?php echo $followResult==='no_answer'?'selected':''; ?>>未接听</option>
                <option value="cancelled" <?php echo $followResult==='cancelled'?'selected':''; ?>>取消</option>
            </select>
            <label style="font-size:13px;color:#6b7280;">待随访日:</label>
            <input type="date" name="next_date" value="<?php echo $nextDate; ?>" style="padding:7px 12px;border:1px solid #d1d5db;border-radius:6px;">
            <button type="submit" class="btn btn-primary btn-sm">搜索</button>
            <a href="admin.php?module=follow_up" class="btn btn-outline btn-sm">重置</a>
        </form>
    </div>
</div>

<!-- 随访列表 -->
<div class="card">
    <div class="card-header">
        <h3>随访记录 (共<?php echo $total; ?>条)</h3>
        <button onclick="openAddModal()" class="btn btn-primary btn-sm">+ 新建随访</button>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="data-table">
            <thead><tr><th>ID</th><th>患者</th><th>手机号</th><th>方式</th><th>结果</th><th>随访内容</th><th>下次随访</th><th>操作人</th><th>时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php if(empty($list)): ?>
                <tr><td colspan="10" style="text-align:center;padding:40px;color:#9ca3af;">暂无随访记录</td></tr>
            <?php else: ?>
            <?php foreach($list as $f): ?>
            <tr>
                <td><?php echo $f['id']; ?></td>
                <td><strong><?php echo htmlspecialchars($f['patient_name']); ?></strong></td>
                <td><?php echo htmlspecialchars($f['patient_phone']); ?></td>
                <td><?php echo isset($followTypeMap[$f['follow_type']]) ? $followTypeMap[$f['follow_type']] : $f['follow_type']; ?></td>
                <td><span class="badge <?php echo isset($followResultBadge[$f['follow_result']]) ? $followResultBadge[$f['follow_result']] : 'badge-gray'; ?>"><?php echo isset($followResultMap[$f['follow_result']]) ? $followResultMap[$f['follow_result']] : $f['follow_result']; ?></span></td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($f['content']); ?>"><?php echo htmlspecialchars($f['content']); ?></td>
                <td><?php echo $f['next_date'] ? '<span style="color:#f59e0b;">'.$f['next_date'].'</span>' : '-'; ?></td>
                <td><?php echo htmlspecialchars($f['admin_name'] ?: '系统'); ?></td>
                <td><?php echo date('Y-m-d H:i', $f['addtime']); ?></td>
                <td>
                    <a href="admin.php?module=patient&action=detail&phone=<?php echo urlencode($f['patient_phone']); ?>" class="btn btn-outline btn-sm">患者</a>
                    <button onclick="deleteFollowUp(<?php echo $f['id']; ?>)" class="btn btn-danger btn-sm">删除</button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if($totalPages > 1): ?>
    <div class="card-body" style="border-top:1px solid #f3f4f6;">
        <div class="pagination">
            <div class="info">共 <?php echo $total; ?> 条，第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</div>
            <div class="pages">
                <?php if($page > 1): ?><a href="?module=follow_up&page=<?php echo $page-1; ?>">上页</a><?php endif; ?>
                <span class="current"><?php echo $page; ?></span>
                <?php if($page < $totalPages): ?><a href="?module=follow_up&page=<?php echo $page+1; ?>">下页</a><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 新建随访 模态框 -->
<div class="modal-overlay" id="addModal">
    <div class="modal" style="width:500px;">
        <div class="modal-header"><h3>新建随访记录</h3><button class="close" onclick="closeModal('addModal')">&times;</button></div>
        <div class="modal-body">
            <div class="form-group"><label>患者手机号 *</label><input type="text" id="addPhone" placeholder="输入手机号搜索患者"></div>
            <div class="form-group"><label>患者姓名</label><input type="text" id="addName" placeholder="可自动填充"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;">
                <div class="form-group"><label>随访方式</label>
                    <select id="addType"><option value="phone">电话随访</option><option value="wechat">微信随访</option><option value="visit">到访随访</option></select>
                </div>
                <div class="form-group"><label>随访结果</label>
                    <select id="addResult"><option value="normal">正常</option><option value="abnormal">异常</option><option value="no_answer">未接听</option><option value="cancelled">取消</option></select>
                </div>
            </div>
            <div class="form-group"><label>随访内容 *</label><textarea id="addContent" rows="3" placeholder="记录随访详情"></textarea></div>
            <div class="form-group"><label>下次随访日期</label><input type="date" id="addNextDate"></div>
        </div>
        <div class="modal-footer">
            <button onclick="closeModal('addModal')" class="btn btn-outline">取消</button>
            <button onclick="saveNewFollowUp()" class="btn btn-primary">保存</button>
        </div>
    </div>
</div>

<script>
document.getElementById('addPhone').addEventListener('blur', function() {
    var phone = this.value.trim();
    if (phone.length >= 11) {
        apiGet('admin.php?module=api_search_patient&phone=' + encodeURIComponent(phone), function(r) {
            if (r.code === 200 && r.data && r.data.patient_name) {
                document.getElementById('addName').value = r.data.patient_name;
            }
        });
    }
});

function openAddModal() {
    document.getElementById('addPhone').value = '';
    document.getElementById('addName').value = '';
    document.getElementById('addType').value = 'phone';
    document.getElementById('addResult').value = 'normal';
    document.getElementById('addContent').value = '';
    document.getElementById('addNextDate').value = '';
    openModal('addModal');
}

function saveNewFollowUp() {
    var phone = document.getElementById('addPhone').value.trim();
    var content = document.getElementById('addContent').value.trim();
    if (!phone) { showToast('请输入患者手机号','error'); return; }
    if (!content) { showToast('请填写随访内容','error'); return; }
    apiPost('admin.php?module=follow_up&action=save', {
        patient_phone: phone,
        patient_name: document.getElementById('addName').value,
        follow_type: document.getElementById('addType').value,
        follow_result: document.getElementById('addResult').value,
        content: content,
        next_date: document.getElementById('addNextDate').value
    }, function(r) {
        if (r.code === 200) { showToast('随访记录已添加','success'); setTimeout(function(){location.reload();},500); }
        else { showToast(r.message,'error'); }
    });
}

function deleteFollowUp(id) {
    if (!confirmAction('确定删除此随访记录？')) return;
    apiPost('admin.php?module=follow_up&action=delete', {id:id}, function(r) {
        if (r.code === 200) { showToast('已删除','success'); setTimeout(function(){location.reload();},500); }
        else { showToast(r.message,'error'); }
    });
}
</script>
<?php renderAdmin('随访管理','follow_up',ob_get_clean());
