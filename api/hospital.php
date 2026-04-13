<?php
/**
 * 医院管理API
 */
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Auth.php';

class HospitalController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * 获取医院列表
     * GET /api/hospital/list
     */
    public function getList() {
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $pageSize = isset($_GET['pageSize']) ? intval($_GET['pageSize']) : 10;
        $name = isset($_GET['name']) ? trim($_GET['name']) : '';
        
        // 确保分页参数有效
        if ($page < 1) $page = 1;
        if ($pageSize < 1 || $pageSize > 100) $pageSize = 10;
        
        $where = array('status' => 1);
        if ($name) {
            $where['name'] = array('LIKE', "%{$name}%");
        }
        
        $offset = ($page - 1) * $pageSize;
        $limit = "{$offset}, {$pageSize}";
        
        $total = $this->db->count('hospital', $where);
        $list = $this->db->select('hospital', $where, '*', 'sort DESC, id DESC', $limit);
        
        Response::page($list, $total, $page, $pageSize);
    }
    
    /**
     * 获取医院详情
     * GET /api/hospital/detail
     */
    public function getDetail() {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if (!$id) {
            Response::error('缺少医院ID', 400);
        }
        
        $hospital = $this->db->find('hospital', array('id' => $id));
        
        if (!$hospital) {
            Response::error('医院不存在', 404);
        }
        
        Response::success($hospital);
    }
    
    /**
     * 添加医院(管理员)
     * POST /api/hospital/add
     */
    public function add() {
        Auth::verifyAdmin();
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $data = array(
            'name' => isset($input['name']) ? trim($input['name']) : '',
            'address' => isset($input['address']) ? trim($input['address']) : '',
            'phone' => isset($input['phone']) ? trim($input['phone']) : '',
            'images' => isset($input['images']) ? json_encode($input['images']) : '',
            'intro' => isset($input['intro']) ? trim($input['intro']) : '',
            'sort' => isset($input['sort']) ? intval($input['sort']) : 0,
            'status' => isset($input['status']) ? intval($input['status']) : 1,
            'addtime' => time(),
            'updatetime' => time()
        );
        
        if (empty($data['name'])) {
            Response::error('医院名称不能为空', 400);
        }
        
        $id = $this->db->insert('hospital', $data);
        
        Response::success(array('id' => $id), '添加成功');
    }
    
    /**
     * 更新医院(管理员)
     * POST /api/hospital/update
     */
    public function update() {
        Auth::verifyAdmin();
        
        $input = json_decode(file_get_contents('php://input'), true);
        $id = isset($input['id']) ? intval($input['id']) : 0;
        
        if (!$id) {
            Response::error('缺少医院ID', 400);
        }
        
        $hospital = $this->db->find('hospital', array('id' => $id));
        if (!$hospital) {
            Response::error('医院不存在', 404);
        }
        
        $data = array();
        
        if (isset($input['name'])) $data['name'] = trim($input['name']);
        if (isset($input['address'])) $data['address'] = trim($input['address']);
        if (isset($input['phone'])) $data['phone'] = trim($input['phone']);
        if (isset($input['images'])) $data['images'] = json_encode($input['images']);
        if (isset($input['intro'])) $data['intro'] = trim($input['intro']);
        if (isset($input['sort'])) $data['sort'] = intval($input['sort']);
        if (isset($input['status'])) $data['status'] = intval($input['status']);
        
        $data['updatetime'] = time();
        
        $this->db->update('hospital', $data, array('id' => $id));
        
        Response::success(null, '更新成功');
    }
    
    /**
     * 删除医院(管理员)
     * POST /api/hospital/delete
     */
    public function delete() {
        Auth::verifyAdmin();
        
        $input = json_decode(file_get_contents('php://input'), true);
        $id = isset($input['id']) ? intval($input['id']) : 0;
        
        if (!$id) {
            Response::error('缺少医院ID', 400);
        }
        
        $this->db->update('hospital', array('status' => 0), array('id' => $id));
        
        Response::success(null, '删除成功');
    }
}

// 处理请求
$controller = new HospitalController();
$action = isset($_GET['action']) ? $_GET['action'] : 'getList';

if (method_exists($controller, $action)) {
    $controller->$action();
} else {
    Response::error('接口不存在', 404);
}
