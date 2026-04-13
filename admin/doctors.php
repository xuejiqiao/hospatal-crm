<?php
/**
 * 医生管理页面 - 支持多科室关联
 */
$db = Database::getInstance();
$prefix = Database::getConfig()['db']['prefix'];
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// ===== API处理 =====
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? intval($input['id']) : 0;
    $departmentIds = isset($input['department_ids']) ? $input['department_ids'] : array();
    // 兼容：如果传的是单个department_id
    if (empty($departmentIds) && !empty($input['department_id'])) {
        $departmentIds = array(intval($input['department_id']));
    }
    
    $data = array(
        'name' => trim($input['name']),
        'hospital_id' => intval($input['hospital_id']),
        'department_id' => !empty($departmentIds) ? intval($departmentIds[0]) : 0, // 主科室（第一个）
        'title' => trim($input['title']),
        'specialty' => trim($input['specialty']),
        'intro' => trim($input['intro']),
        'sort' => intval($input['sort']),
        'status' => intval($input['status']),
        'updatetime' => time()
    );
    
    if (empty($data['name'])) { echo json_encode(array('code'=>400,'message'=>'请输入医生姓名')); exit; }
    
    try {
        if ($id > 0) {
            $db->update('doctor', $data, array('id' => $id));
        } else {
            $data['addtime'] = time();
            $id = $db->insert('doctor', $data);
        }
        
        // 更新医生-科室关联（先删后插）
        $db->query("DELETE FROM {$prefix}doctor_dept WHERE doctor_id=?", array($id));
        if (!empty($departmentIds)) {
            foreach ($departmentIds as $deptId) {
                $deptId = intval($deptId);
                if ($deptId > 0) {
                    $db->query(
                        "INSERT IGNORE INTO {$prefix}doctor_dept (doctor_id, department_id, addtime) VALUES (?, ?, ?)",
                        array($id, $deptId, time())
                    );
                }
            }
        }
        
        echo json_encode(array('code'=>200,'message'=>'保存成功'));
    } catch(PDOException $e) {
        echo json_encode(array('code'=>500,'message'=>'保存失败: '.$e->getMessage()));
    }
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id']);
    $db->update('doctor', array('status'=>0), array('id'=>$id));
    echo json_encode(array('code'=>200,'message'=>'删除成功')); exit;
}

if ($action === 'getDetail') {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $doctor = $db->find('doctor', array('id' => $id));
    if ($doctor) {
        // 获取该医生关联的所有科室ID
        $deptLinks = $db->query(
            "SELECT department_id FROM {$prefix}doctor_dept WHERE doctor_id=?",
            array($id)
        )->fetchAll(PDO::FETCH_COLUMN);
        $doctor['department_ids'] = $deptLinks;
    }
    echo json_encode(array('code'=>200,'data'=>$doctor)); exit;
}

if ($action === 'getDepartments') {
    header('Content-Type: application/json');
    $hid = intval($_GET['hospital_id']);
    $depts = $db->query(
        "SELECT d.id, d.name FROM {$prefix}department d INNER JOIN {$prefix}dept_hospital dh ON d.id=dh.department_id WHERE dh.hospital_id=? AND d.status=1 ORDER BY d.sort DESC",
        array($hid)
    )->fetchAll();
    echo json_encode($depts); exit;
}

// ===== 列表页 =====
$hospitalId = isset($_GET['hospital_id']) ? intval($_GET['hospital_id']) : 0;
$where = $hospitalId ? "WHERE d.hospital_id={$hospitalId}" : "";

// 查询医生列表（带主科室名）
$list = $db->query(
    "SELECT d.*, h.name as hospital_name, dp.name as dept_name 
     FROM {$prefix}doctor d 
     LEFT JOIN {$prefix}hospital h ON d.hospital_id=h.id 
     LEFT JOIN {$prefix}department dp ON d.department_id=dp.id 
     {$where} ORDER BY d.sort DESC"
)->fetchAll();

// 批量获取每个医生的所有关联科室
$doctorIds = array_column($list, 'id');
$doctorDepts = array(); // doctor_id => [{id, name}, ...]
if (!empty($doctorIds)) {
    $idList = implode(',', array_map('intval', $doctorIds));
    try {
        $deptLinks = $db->query(
            "SELECT dd.doctor_id, dp.id as dept_id, dp.name as dept_name 
             FROM {$prefix}doctor_dept dd 
             INNER JOIN {$prefix}department dp ON dd.department_id=dp.id 
             WHERE dd.doctor_id IN ({$idList})
             ORDER BY dd.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($deptLinks as $dl) {
            if (!isset($doctorDepts[$dl['doctor_id']])) {
                $doctorDepts[$dl['doctor_id']] = array();
            }
            $doctorDepts[$dl['doctor_id']][] = $dl;
        }
    } catch(PDOException $e) {}
}

$hospitals = $db->select('hospital', array('status'=>1), 'id, name', 'sort DESC');

ob_start();
?>
<div class="card">
    <div class="card-header">
        <h3>医生列表</h3>
        <div style="display:flex;gap:8px;">
            <select onchange="location.href='admin.php?module=doctor&hospital_id='+this.value" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                <option value="0">全部医院</option>
                <?php foreach($hospitals as $h): ?>
                <option value="<?php echo $h['id']; ?>" <?php echo $hospitalId==$h['id']?'selected':''; ?>><?php echo htmlspecialchars($h['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button onclick="openModal('editModal');resetForm();" class="btn btn-primary btn-sm">+ 添加医生</button>
        </div>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="data-table">
            <thead><tr><th>ID</th><th>姓名</th><th>医院</th><th>关联科室</th><th>职称</th><th>专长</th><th>排序</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach($list as $d): 
                $depts = isset($doctorDepts[$d['id']]) ? $doctorDepts[$d['id']] : array();
            ?>
            <tr>
                <td><?php echo $d['id']; ?></td>
                <td><strong><?php echo htmlspecialchars($d['name']); ?></strong></td>
                <td><?php echo htmlspecialchars($d['hospital_name']); ?></td>
                <td>
                    <?php if(!empty($depts)): ?>
                        <?php foreach($depts as $i => $dept): ?>
                        <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;<?php echo $i===0?'background:#dbeafe;color:#1d4ed8;font-weight:500;':'background:#f3f4f6;color:#6b7280;'; ?>margin:1px 2px;"><?php echo htmlspecialchars($dept['dept_name']); ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <span style="color:#9ca3af;">-</span>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($d['title']); ?></td>
                <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($d['specialty']); ?></td>
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
        <div class="modal-header"><h3 id="modalTitle">添加医生</h3><button class="close" onclick="closeModal('editModal')">&times;</button></div>
        <div class="modal-body">
            <input type="hidden" id="editId" value="0">
            <div class="form-group"><label>所属医院 *</label><select id="editHospitalId" onchange="loadDepts()"><?php foreach($hospitals as $h): ?><option value="<?php echo $h['id']; ?>"><?php echo htmlspecialchars($h['name']); ?></option><?php endforeach; ?></select></div>
            <div class="form-group">
                <label>关联科室（可多选）</label>
                <div id="deptCheckboxes" style="max-height:180px;overflow-y:auto;border:1px solid #d1d5db;border-radius:6px;padding:8px;background:#fff;">
                    <div style="color:#9ca3af;font-size:13px;">请先选择医院</div>
                </div>
            </div>
            <div class="form-group"><label>医生姓名 *</label><input type="text" id="editName" placeholder="请输入姓名"></div>
            <div class="form-group"><label>职称</label><input type="text" id="editTitle" placeholder="如：主任医师"></div>
            <div class="form-group"><label>专长</label><input type="text" id="editSpecialty" placeholder="如：心血管内科"></div>
            <div class="form-group"><label>简介</label><textarea id="editIntro" rows="3" placeholder="请输入简介"></textarea></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px;">
                <div class="form-group"><label>排序</label><input type="number" id="editSort" value="0"></div>
                <div class="form-group"><label>状态</label><select id="editStatus"><option value="1">启用</option><option value="0">禁用</option></select></div>
            </div>
        </div>
        <div class="modal-footer"><button onclick="closeModal('editModal')" class="btn btn-outline">取消</button><button onclick="saveItem()" class="btn btn-primary">保存</button></div>
    </div>
</div>

<script>
var allDepts = []; // 缓存当前医院的科室列表

function loadDepts(selectedIds) {
    var hid = document.getElementById('editHospitalId').value;
    var box = document.getElementById('deptCheckboxes');
    box.innerHTML = '<div style="color:#9ca3af;font-size:13px;">加载中...</div>';
    
    apiGet('admin.php?module=doctor&action=getDepartments&hospital_id=' + hid, function(d) {
        allDepts = (d && d.length) ? d : [];
        if (allDepts.length === 0) {
            box.innerHTML = '<div style="color:#9ca3af;font-size:13px;">该医院暂无科室</div>';
            return;
        }
        var html = '<label style="display:flex;align-items:center;padding:4px 0;border-bottom:1px solid #f3f4f6;margin-bottom:4px;cursor:pointer;font-weight:500;font-size:12px;color:#6b7280;">'
            + '<input type="checkbox" id="deptSelectAll" onchange="toggleAllDepts(this.checked)" style="margin-right:6px;"> 全选/取消全选</label>';
        for (var i = 0; i < allDepts.length; i++) {
            var checked = (selectedIds && selectedIds.indexOf(String(allDepts[i].id)) !== -1) ? ' checked' : '';
            html += '<label style="display:flex;align-items:center;padding:4px 0;cursor:pointer;font-size:13px;">'
                + '<input type="checkbox" name="dept_checkbox" value="' + allDepts[i].id + '"' + checked + ' style="margin-right:6px;">'
                + allDepts[i].name + '</label>';
        }
        box.innerHTML = html;
    });
}

function toggleAllDepts(checked) {
    var cbs = document.querySelectorAll('input[name="dept_checkbox"]');
    for (var i = 0; i < cbs.length; i++) { cbs[i].checked = checked; }
}

function getSelectedDeptIds() {
    var ids = [];
    var cbs = document.querySelectorAll('input[name="dept_checkbox"]:checked');
    for (var i = 0; i < cbs.length; i++) { ids.push(cbs[i].value); }
    return ids;
}

function resetForm() {
    document.getElementById('modalTitle').textContent = '添加医生';
    document.getElementById('editId').value = '0';
    document.getElementById('editName').value = '';
    document.getElementById('editTitle').value = '';
    document.getElementById('editSpecialty').value = '';
    document.getElementById('editIntro').value = '';
    document.getElementById('editSort').value = '0';
    document.getElementById('editStatus').value = '1';
    document.getElementById('deptCheckboxes').innerHTML = '<div style="color:#9ca3af;font-size:13px;">请先选择医院</div>';
    loadDepts();
}

function editItem(id) {
    apiGet('admin.php?module=doctor&action=getDetail&id=' + id, function(r) {
        if (r.code === 200 && r.data) {
            var d = r.data;
            document.getElementById('modalTitle').textContent = '编辑医生';
            document.getElementById('editId').value = d.id;
            document.getElementById('editHospitalId').value = d.hospital_id;
            document.getElementById('editName').value = d.name;
            document.getElementById('editTitle').value = d.title;
            document.getElementById('editSpecialty').value = d.specialty;
            document.getElementById('editIntro').value = d.intro;
            document.getElementById('editSort').value = d.sort;
            document.getElementById('editStatus').value = d.status;
            // 加载科室并选中已有的
            var selectedIds = d.department_ids || [];
            loadDepts(selectedIds);
            openModal('editModal');
        }
    });
}

function saveItem() {
    var deptIds = getSelectedDeptIds();
    var d = {
        id: document.getElementById('editId').value,
        hospital_id: document.getElementById('editHospitalId').value,
        department_ids: deptIds,
        name: document.getElementById('editName').value,
        title: document.getElementById('editTitle').value,
        specialty: document.getElementById('editSpecialty').value,
        intro: document.getElementById('editIntro').value,
        sort: document.getElementById('editSort').value,
        status: document.getElementById('editStatus').value
    };
    if (!d.name) { showToast('请输入医生姓名','error'); return; }
    apiPost('admin.php?module=doctor&action=save', d, function(r) {
        if (r.code === 200) { showToast('保存成功','success'); setTimeout(function(){location.reload();},500); }
        else { showToast(r.message,'error'); }
    });
}

function deleteItem(id) {
    if (!confirmAction('确定删除？')) return;
    apiPost('admin.php?module=doctor&action=delete', {id:id}, function(r) {
        if (r.code === 200) { showToast('已删除','success'); setTimeout(function(){location.reload();},500); }
    });
}
</script>
<?php renderAdmin('医生管理','doctor',ob_get_clean());
