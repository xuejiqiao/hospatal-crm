<?php
/**
 * 微信小程序API控制器
 */
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Log.php';

class WechatController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * 小程序登录
     * POST /api/wechat/login
     */
    public function login() {
        $input = json_decode(file_get_contents('php://input'), true);
        $code = isset($input['code']) ? trim($input['code']) : '';
        
        if (!$code) {
            Response::error('缺少登录凭证', 400);
        }
        
        // 调用微信接口获取openid
        $wechatResult = Auth::wechatLogin($code);
        $openid = $wechatResult['openid'];
        $sessionKey = $wechatResult['session_key'];
        
        // 查询用户是否存在
        $user = $this->db->find('user', array('openid' => $openid));
        
        if (!$user) {
            // 新用户，创建账号
            $userId = $this->db->insert('user', array(
                'openid' => $openid,
                'nickname' => '微信用户',
                'role' => 'user',
                'status' => 1,
                'addtime' => time(),
                'updatetime' => time()
            ));
        } else {
            $userId = $user['id'];
        }
        
        // 生成token
        $tokenData = Auth::generateToken($userId);
        
        // 保存session
        $this->db->insert('user_session', array(
            'user_id' => $userId,
            'token' => $tokenData['token'],
            'session_key' => $sessionKey,
            'expire_time' => $tokenData['expire_time'],
            'addtime' => time()
        ));
        
        // 获取用户信息
        $userInfo = $this->db->find('user', array('id' => $userId));
        
        Response::success(array(
            'token' => $tokenData['token'],
            'userInfo' => array(
                'id' => $userInfo['id'],
                'openid' => $userInfo['openid'],
                'nickname' => $userInfo['nickname'],
                'avatar' => $userInfo['avatar'],
                'role' => $userInfo['role']
            )
        ), '登录成功');
    }
    
    /**
     * 更新用户信息
     * POST /api/wechat/updateUserInfo
     */
    public function updateUserInfo() {
        $session = Auth::verify();
        $input = json_decode(file_get_contents('php://input'), true);
        
        $data = array();
        
        if (isset($input['nickname'])) {
            $data['nickname'] = trim($input['nickname']);
        }
        if (isset($input['avatar'])) {
            $data['avatar'] = trim($input['avatar']);
        }
        if (isset($input['phone'])) {
            $data['phone'] = trim($input['phone']);
        }
        if (isset($input['name'])) {
            $data['name'] = trim($input['name']);
        }
        
        if (empty($data)) {
            Response::error('没有需要更新的数据');
        }
        
        $data['updatetime'] = time();
        
        $this->db->update('user', $data, array('id' => $session['user_id']));
        
        Response::success(null, '更新成功');
    }
    
    /**
     * 获取用户信息
     * GET /api/wechat/getUserInfo
     */
    public function getUserInfo() {
        $session = Auth::verify();
        
        $user = $this->db->find('user', array('id' => $session['user_id']));
        
        if (!$user) {
            Response::error('用户不存在', 404);
        }
        
        Response::success(array(
            'id' => $user['id'],
            'openid' => $user['openid'],
            'nickname' => $user['nickname'],
            'avatar' => $user['avatar'],
            'phone' => $user['phone'],
            'name' => $user['name'],
            'role' => $user['role'],
            'addtime' => $user['addtime']
        ));
    }
}

// 处理请求
$controller = new WechatController();
$action = isset($_GET['action']) ? $_GET['action'] : 'login';

if (method_exists($controller, $action)) {
    $controller->$action();
} else {
    Response::error('接口不存在', 404);
}
