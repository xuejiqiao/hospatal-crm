<?php
/**
 * 科室管理API
 */
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Auth.php';

class DepartmentController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * 获取科室列表(公开，按医院筛选)
     * GET /api/department/getList?hospital_id=1
     */
    public function getList() {
        $hospitalId = isset($_GET['hospital_id']) ? intval($_GET['hospital_id']) : 0;
        
        $where = array('status' => 1);
        if ($hospitalId) {
            $where['hospital_id'] = $hospitalId;
        }
        
        $list = $this->db->select('department', $where, '*', 'sort DESC, id DESC');
        
        Response::success($list);
    }
    
    /**
     * 获取科室详情
     * GET /api/department/getDetail?id=1
     */
    public function getDetail() {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        if (!$id) Response::error('缺少科室ID', 400);
        
        $department = $this->db->find('department', array('id' => $id));
        if (!$department) Response::error('科室不存在', 404);
        
        Response::success($department);
    }
}

// 处理请求
$controller = new DepartmentController();
$action = isset($_GET['action']) ? $_GET['action'] : 'getList';

if (method_exists($controller, $action)) {
    $controller->$action();
} else {
    Response::error('接口不存在', 404);
}
