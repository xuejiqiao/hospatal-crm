<?php
/**
 * 管理员账号管理
 */
$db = Database::getInstance();
$prefix = Database::getConfig()['db']['prefix'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? intval($input['id']) : 0;
    $data = array('username'=>trim($input['username']),'nickname'=>trim($input['nickname']),'role'=>trim($input['role']),'status'=>intval($input['status']),'updatetime'=>time());
    $pwd = isset($input['password']) ? trim($input['password']) : '';
    if ($id > 0) {
        // 编辑时：只在提供了密码时才更新
        if ($pwd) $data['password'] = md5($pwd);
        $db->update('admin', $data, array('id' => $id));
    } else {
        // 新建时：必须有密码
        if (!$pwd) { echo json_encode(array('code'=>400,'message'=>'请输入密码')); exit; }
        $data['password'] = md5($pwd);
        $data['addtime'] = time();
        $db->insert('admin', $data);
    }
    echo json_encode(array('code'=>200,'message'=>'保存成功')); exit;
}
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id']);
    $admin = AdminAuth::getAdmin();
    if ($id == $admin['id']) { echo json_encode(array('code'=>400,'message'=>'不能删除自己')); exit; }
    $db->delete('admin', array('id' => $id));
    echo json_encode(array('code'=>200,'message'=>'删除成功')); exit;
}

$list = $db->select('admin', array(), '*', 'id ASC');
$roleMap = array('super_admin'=>'超级管理员','admin'=>'管理员','operator'=>'操作员');

ob_start();
?>
<div class="card">
    <div class="card-header">
        <h3>管理员列表</h3>
        <button onclick="openModal('editModal');resetForm();" class="btn btn-primary btn-sm">+ 添加管理员</button>
    </div>
    <div class="card-body" style="padding:0;">
        <table class="data-table">
            <thead><tr><th>ID</th><th>用户名</th><th>昵称</th><th>角色</th><th>状态</th><th>最后登录</th><th>登录IP</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach($list as $a): ?>
            <tr>
                <td><?php echo $a['id']; ?></td>
                <td><strong><?php echo htmlspecialchars($a['username']); ?></strong></td>
                <td><?php echo htmlspecialchars($a['nickname']); ?></td>
                <td><span class="badge <?php echo $a['role']=='super_admin'?'badge-danger':'badge-info'; ?>"><?php echo isset($roleMap[$a['role']])?$roleMap[$a['role']]:$a['role']; ?></span></td>
                <td><?php echo $a['status']==1?'<span class="badge badge-success">启用</span>':'<span class="badge badge-gray">禁用</span>'; ?></td>
                <td><?php echo $a['last_login_time']?date('Y-m-d H:i',$a['last_login_time']):'-'; ?></td>
                <td><?php echo $a['last_login_ip']?:'-'; ?></td>
                <td>
                    <button onclick="editItem(<?php echo $a['id']; ?>)" class="btn btn-outline btn-sm">编辑</button>
                    <button onclick="deleteItem(<?php echo $a['id']; ?>)" class="btn btn-danger btn-sm">删除</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header"><h3 id="modalTitle">添加管理员</h3><button class="close" onclick="closeModal('editModal')">&times;</button></div>
        <div class="modal-body">
            <input type="hidden" id="editId" value="0">
            <div class="form-group"><label>用户名 *</label><input type="text" id="editUsername"></div>
            <div class="form-group"><label>密码 (留空则不修改)</label><input type="password" id="editPassword" placeholder="新密码"></div>
            <div class="form-group"><label>昵称</label><input type="text" id="editNickname"></div>
            <div class="form-group"><label>角色</label><select id="editRole"><option value="super_admin">超级管理员</option><option value="admin">管理员</option><option value="operator">操作员</option></select></div>
            <div class="form-group"><label>状态</label><select id="editStatus"><option value="1">启用</option><option value="0">禁用</option></select></div>
        </div>
        <div class="modal-footer"><button onclick="closeModal('editModal')" class="btn btn-outline">取消</button><button onclick="saveItem()" class="btn btn-primary">保存</button></div>
    </div>
</div>

<script>
var adminList=<?php echo json_encode($list); ?>;
function resetForm(){document.getElementById('modalTitle').textContent='添加管理员';document.getElementById('editId').value='0';document.getElementById('editUsername').value='';document.getElementById('editPassword').value='';document.getElementById('editNickname').value='';document.getElementById('editRole').value='operator';document.getElementById('editStatus').value='1';}
function editItem(id){var a=adminList.find(function(x){return x.id==id;});if(a){document.getElementById('modalTitle').textContent='编辑管理员';document.getElementById('editId').value=a.id;document.getElementById('editUsername').value=a.username;document.getElementById('editPassword').value='';document.getElementById('editNickname').value=a.nickname;document.getElementById('editRole').value=a.role;document.getElementById('editStatus').value=a.status;openModal('editModal');}}
function saveItem(){var d={id:document.getElementById('editId').value,username:document.getElementById('editUsername').value,password:document.getElementById('editPassword').value,nickname:document.getElementById('editNickname').value,role:document.getElementById('editRole').value,status:document.getElementById('editStatus').value};if(!d.username){showToast('请输入用户名','error');return;}if(!d.id&&!d.password){showToast('新建管理员请输入密码','error');return;}apiPost('admin.php?module=admin_user&action=save',d,function(r){if(r.code===200){showToast('保存成功','success');setTimeout(function(){location.reload();},500);}else{showToast(r.message,'error');}});}
function deleteItem(id){if(!confirmAction('确定删除此管理员？'))return;apiPost('admin.php?module=admin_user&action=delete',{id:id},function(r){if(r.code===200){showToast('已删除','success');setTimeout(function(){location.reload();},500);}else{showToast(r.message,'error');}});}
</script>
<?php renderAdmin('管理员管理','admin_user',ob_get_clean());