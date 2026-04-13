<?php
/**
 * 医院管理页面
 */
$db = Database::getInstance();
$prefix = Database::getConfig()['db']['prefix'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// AJAX操作
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? intval($input['id']) : 0;
    $data = array(
        'name' => trim($input['name']),
        'address' => trim($input['address']),
        'phone' => trim($input['phone']),
        'intro' => trim($input['intro']),
        'sort' => intval($input['sort']),
        'status' => intval($input['status']),
        'updatetime' => time()
    );
    if ($id > 0) {
        $db->update('hospital', $data, array('id' => $id));
    } else {
        $data['addtime'] = time();
        $id = $db->insert('hospital', $data);
    }
    echo json_encode(array('code' => 200, 'message' => '保存成功'));
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $db->update('hospital', array('status' => 0), array('id' => intval($input['id'])));
    echo json_encode(array('code' => 200, 'message' => '删除成功'));
    exit;
}

if ($action === 'getDetail') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $item = $db->find('hospital', array('id' => $id));
    header('Content-Type: application/json');
    echo json_encode(array('code' => 200, 'data' => $item));
    exit;
}

$list = $db->query("SELECT h.*, (SELECT COUNT(*) FROM {$prefix}department WHERE hospital_id=h.id) as dept_count, (SELECT COUNT(*) FROM {$prefix}doctor WHERE hospital_id=h.id) as doctor_count FROM {$prefix}hospital h ORDER BY h.sort DESC, h.id DESC")->fetchAll();

ob_start();
?>
<div class="card">
    <div class="card-header">
        <h3>医院列表</h3>
        <button onclick="openModal('editModal');resetForm();" class="btn btn-primary btn-sm">+ 添加医院</button>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="data-table">
            <thead><tr><th>ID</th><th>医院名称</th><th>地址</th><th>电话</th><th>科室数</th><th>医生数</th><th>排序</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach($list as $h): ?>
            <tr>
                <td><?php echo $h['id']; ?></td>
                <td><strong><?php echo htmlspecialchars($h['name']); ?></strong></td>
                <td><?php echo htmlspecialchars($h['address']); ?></td>
                <td><?php echo htmlspecialchars($h['phone']); ?></td>
                <td><a href="admin.php?module=department&hospital_id=<?php echo $h['id']; ?>"><?php echo $h['dept_count']; ?></a></td>
                <td><a href="admin.php?module=doctor&hospital_id=<?php echo $h['id']; ?>"><?php echo $h['doctor_count']; ?></a></td>
                <td><?php echo $h['sort']; ?></td>
                <td><?php echo $h['status']==1?'<span class="badge badge-success">启用</span>':'<span class="badge badge-gray">禁用</span>'; ?></td>
                <td>
                    <button onclick="editItem(<?php echo $h['id']; ?>)" class="btn btn-outline btn-sm">编辑</button>
                    <button onclick="deleteItem(<?php echo $h['id']; ?>)" class="btn btn-danger btn-sm">删除</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 编辑模态框 -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header"><h3 id="modalTitle">添加医院</h3><button class="close" onclick="closeModal('editModal')">&times;</button></div>
        <div class="modal-body">
            <input type="hidden" id="editId" value="0">
            <div class="form-group"><label>医院名称 *</label><input type="text" id="editName" placeholder="请输入医院名称"></div>
            <div class="form-group"><label>地址</label><input type="text" id="editAddress" placeholder="请输入地址"></div>
            <div class="form-group"><label>联系电话</label><input type="text" id="editPhone" placeholder="请输入电话"></div>
            <div class="form-group"><label>简介</label><textarea id="editIntro" placeholder="请输入简介"></textarea></div>
            <div class="form-group"><label>排序</label><input type="number" id="editSort" value="0"></div>
            <div class="form-group"><label>状态</label><select id="editStatus"><option value="1">启用</option><option value="0">禁用</option></select></div>
        </div>
        <div class="modal-footer">
            <button onclick="closeModal('editModal')" class="btn btn-outline">取消</button>
            <button onclick="saveItem()" class="btn btn-primary">保存</button>
        </div>
    </div>
</div>

<script>
function resetForm() {
    document.getElementById('modalTitle').textContent='添加医院';
    document.getElementById('editId').value='0';
    document.getElementById('editName').value='';
    document.getElementById('editAddress').value='';
    document.getElementById('editPhone').value='';
    document.getElementById('editIntro').value='';
    document.getElementById('editSort').value='0';
    document.getElementById('editStatus').value='1';
}
function editItem(id) {
    apiGet('admin.php?module=hospital&action=getDetail&id='+id, function(d) {
        if(d.code===200 && d.data) {
            document.getElementById('modalTitle').textContent='编辑医院';
            document.getElementById('editId').value=d.data.id;
            document.getElementById('editName').value=d.data.name;
            document.getElementById('editAddress').value=d.data.address;
            document.getElementById('editPhone').value=d.data.phone;
            document.getElementById('editIntro').value=d.data.intro;
            document.getElementById('editSort').value=d.data.sort;
            document.getElementById('editStatus').value=d.data.status;
            openModal('editModal');
        }
    });
}
function saveItem() {
    var data = {
        id: document.getElementById('editId').value,
        name: document.getElementById('editName').value,
        address: document.getElementById('editAddress').value,
        phone: document.getElementById('editPhone').value,
        intro: document.getElementById('editIntro').value,
        sort: document.getElementById('editSort').value,
        status: document.getElementById('editStatus').value
    };
    if(!data.name) { showToast('请输入医院名称','error'); return; }
    apiPost('admin.php?module=hospital&action=save', data, function(d) {
        if(d.code===200) { showToast('保存成功','success'); setTimeout(function(){location.reload();},500); }
    });
}
function deleteItem(id) {
    if(!confirmAction('确定删除此医院？')) return;
    apiPost('admin.php?module=hospital&action=delete', {id:id}, function(d) {
        if(d.code===200) { showToast('已删除','success'); setTimeout(function(){location.reload();},500); }
    });
}
</script>
<?php
renderAdmin('医院管理', 'hospital', ob_get_clean());
