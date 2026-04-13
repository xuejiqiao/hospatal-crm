<?php
/**
 * 科室管理页面 - 支持多医院关联
 */
$db = Database::getInstance();
$prefix = Database::getConfig()['db']['prefix'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// 保存科室（含多医院关联）
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? intval($input['id']) : 0;
    $hospitalIds = isset($input['hospital_ids']) ? $input['hospital_ids'] : array();
    $data = array(
        'name' => trim($input['name']),
        'hospital_id' => !empty($hospitalIds) ? intval($hospitalIds[0]) : 0, // 兼容旧字段，取第一个医院
        'intro' => trim($input['intro']),
        'sort' => intval($input['sort']),
        'status' => intval($input['status']),
        'updatetime' => time()
    );
    
    if (empty($data['name'])) { echo json_encode(array('code'=>400,'message'=>'请输入科室名称')); exit; }
    
    if ($id > 0) {
        $db->update('department', $data, array('id' => $id));
        // 更新关联：先删后增
        $db->delete('dept_hospital', array('department_id' => $id));
    } else {
        $data['addtime'] = time();
        $id = $db->insert('department', $data);
    }
    
    // 写入关联表
    foreach ($hospitalIds as $hid) {
        $db->insert('dept_hospital', array(
            'department_id' => $id,
            'hospital_id' => intval($hid),
            'addtime' => time()
        ));
    }
    
    addLog($id > 0 && isset($input['id']) ? 'edit' : 'create', 'department', $id, "保存科室:{$data['name']}");
    echo json_encode(array('code'=>200,'message'=>'保存成功')); exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $did = intval($input['id']);
    $db->update('department', array('status'=>0), array('id'=>$did));
    addLog('delete', 'department', $did, "删除科室#{$did}");
    echo json_encode(array('code'=>200,'message'=>'删除成功')); exit;
}

if ($action === 'getDetail') {
    header('Content-Type: application/json');
    $dept = $db->find('department', array('id'=>intval($_GET['id'])));
    if ($dept) {
        // 获取关联的医院ID列表
        $links = $db->select('dept_hospital', array('department_id'=>$dept['id']), 'hospital_id');
        $dept['hospital_ids'] = array_column($links, 'hospital_id');
    }
    echo json_encode(array('code'=>200,'data'=>$dept));
    exit;
}

// 列表查询 - 通过关联表获取医院名称
$hospitalId = isset($_GET['hospital_id']) ? intval($_GET['hospital_id']) : 0;

if ($hospitalId) {
    // 按医院筛选：通过关联表查找
    $list = $db->query(
        "SELECT d.* FROM {$prefix}department d INNER JOIN {$prefix}dept_hospital dh ON d.id=dh.department_id WHERE dh.hospital_id=? ORDER BY d.sort DESC",
        array($hospitalId)
    )->fetchAll();
} else {
    $list = $db->select('department', array(), '*', 'sort DESC');
}

// 获取每个科室关联的医院名称
$deptHospitals = array();
if (!empty($list)) {
    $deptIds = array_column($list, 'id');
    $idList = implode(',', $deptIds);
    $links = $db->query(
        "SELECT dh.department_id, dh.hospital_id, h.name as hospital_name FROM {$prefix}dept_hospital dh LEFT JOIN {$prefix}hospital h ON dh.hospital_id=h.id WHERE dh.department_id IN ({$idList})"
    )->fetchAll();
    foreach ($links as $link) {
        $deptHospitals[$link['department_id']][] = $link['hospital_name'];
    }
}

$hospitals = $db->select('hospital', array('status'=>1), 'id, name', 'sort DESC');

ob_start();
?>
<div class="card">
    <div class="card-header">
        <h3>科室列表</h3>
        <div style="display:flex;gap:8px;">
            <select onchange="location.href='admin.php?module=department&hospital_id='+this.value" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                <option value="0">全部医院</option>
                <?php foreach($hospitals as $h): ?>
                <option value="<?php echo $h['id']; ?>" <?php echo $hospitalId==$h['id']?'selected':''; ?>><?php echo htmlspecialchars($h['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button onclick="openModal('editModal');resetForm();" class="btn btn-primary btn-sm">+ 添加科室</button>
        </div>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="data-table">
            <thead><tr><th>ID</th><th>科室名称</th><th>关联医院</th><th>简介</th><th>排序</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach($list as $d): ?>
            <tr>
                <td><?php echo $d['id']; ?></td>
                <td><strong><?php echo htmlspecialchars($d['name']); ?></strong></td>
                <td>
                    <?php if(isset($deptHospitals[$d['id']])): ?>
                        <?php foreach($deptHospitals[$d['id']] as $i => $hname): ?>
                            <?php if($i > 0) echo ', '; ?>
                            <span class="badge badge-info" style="margin:1px;"><?php echo htmlspecialchars($hname); ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span style="color:#9ca3af;">未关联</span>
                    <?php endif; ?>
                </td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($d['intro']); ?></td>
                <td><?php echo $d['sort']; ?></td>
                <td><?php echo $d['status']==1?'<span class="badge badge-success">启用</span>':'<span class="badge badge-gray">禁用</span>'; ?></td>
                <td>
                    <button onclick="editItem(<?php echo $d['id']; ?>)" class="btn btn-outline btn-sm">编辑</button>
                    <button onclick="deleteItem(<?php echo $d['id']; ?>)" class="btn btn-danger btn-sm">删除</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header"><h3 id="modalTitle">添加科室</h3><button class="close" onclick="closeModal('editModal')">&times;</button></div>
        <div class="modal-body">
            <input type="hidden" id="editId" value="0">
            <div class="form-group">
                <label>关联医院 *（可多选）</label>
                <div id="hospitalCheckboxes" style="max-height:200px;overflow-y:auto;border:1px solid #d1d5db;border-radius:6px;padding:10px;">
                    <?php foreach($hospitals as $h): ?>
                    <label style="display:flex;align-items:center;padding:4px 0;cursor:pointer;font-weight:400;">
                        <input type="checkbox" name="hospital_ids" value="<?php echo $h['id']; ?>" style="margin-right:8px;width:16px;height:16px;">
                        <?php echo htmlspecialchars($h['name']); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:4px;font-size:12px;color:#9ca3af;">按住 Ctrl/Cmd 可多选医院</div>
            </div>
            <div class="form-group"><label>科室名称 *</label><input type="text" id="editName" placeholder="请输入科室名称"></div>
            <div class="form-group"><label>简介</label><textarea id="editIntro" placeholder="请输入简介"></textarea></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;">
                <div class="form-group"><label>排序</label><input type="number" id="editSort" value="0"></div>
                <div class="form-group"><label>状态</label><select id="editStatus"><option value="1">启用</option><option value="0">禁用</option></select></div>
            </div>
        </div>
        <div class="modal-footer"><button onclick="closeModal('editModal')" class="btn btn-outline">取消</button><button onclick="saveItem()" class="btn btn-primary">保存</button></div>
    </div>
</div>

<script>
function resetForm() {
    document.getElementById('modalTitle').textContent = '添加科室';
    document.getElementById('editId').value = '0';
    document.getElementById('editName').value = '';
    document.getElementById('editIntro').value = '';
    document.getElementById('editSort').value = '0';
    document.getElementById('editStatus').value = '1';
    var cbs = document.querySelectorAll('input[name="hospital_ids"]');
    for (var i = 0; i < cbs.length; i++) cbs[i].checked = false;
}

function editItem(id) {
    apiGet('admin.php?module=department&action=getDetail&id='+id, function(d) {
        if (d.code === 200 && d.data) {
            document.getElementById('modalTitle').textContent = '编辑科室';
            document.getElementById('editId').value = d.data.id;
            document.getElementById('editName').value = d.data.name;
            document.getElementById('editIntro').value = d.data.intro;
            document.getElementById('editSort').value = d.data.sort;
            document.getElementById('editStatus').value = d.data.status;
            // 勾选关联的医院
            var cbs = document.querySelectorAll('input[name="hospital_ids"]');
            var ids = d.data.hospital_ids || [];
            for (var i = 0; i < cbs.length; i++) {
                cbs[i].checked = ids.indexOf(parseInt(cbs[i].value)) >= 0;
            }
            openModal('editModal');
        }
    });
}

function saveItem() {
    var cbs = document.querySelectorAll('input[name="hospital_ids"]:checked');
    var hospitalIds = [];
    for (var i = 0; i < cbs.length; i++) hospitalIds.push(cbs[i].value);
    
    var d = {
        id: document.getElementById('editId').value,
        hospital_ids: hospitalIds,
        name: document.getElementById('editName').value,
        intro: document.getElementById('editIntro').value,
        sort: document.getElementById('editSort').value,
        status: document.getElementById('editStatus').value
    };
    if (!d.name) { showToast('请输入科室名称','error'); return; }
    if (hospitalIds.length === 0) { showToast('请至少选择一家医院','error'); return; }
    apiPost('admin.php?module=department&action=save', d, function(r) {
        if (r.code === 200) { showToast('保存成功','success'); setTimeout(function(){location.reload();},500); }
        else { showToast(r.message,'error'); }
    });
}

function deleteItem(id) {
    if (!confirmAction('确定删除此科室？')) return;
    apiPost('admin.php?module=department&action=delete', {id:id}, function(r) {
        if (r.code === 200) { showToast('已删除','success'); setTimeout(function(){location.reload();},500); }
    });
}
</script>
<?php renderAdmin('科室管理','department',ob_get_clean());
