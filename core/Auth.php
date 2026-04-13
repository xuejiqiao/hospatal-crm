<?php
/**
 * 认证类
 */
class Auth {
    /**
     * 验证Token
     */
    public static function verify() {
        $headers = @getallheaders();
        $token = null;
        
        // 从Authorization头获取
        if (isset($headers['Authorization'])) {
            $token = str_replace('Bearer ', '', $headers['Authorization']);
        }
        
        // 尝试从query参数获取
        if (!$token && isset($_GET['token'])) {
            $token = trim($_GET['token']);
        }
        
        if (!$token) {
            Response::error('未提供认证令牌', 401);
        }
        
        $db = Database::getInstance();
        $session = $db->find('user_session', array('token' => $token));
        
        if (!$session) {
            Response::error('认证令牌无效', 401);
        }
        
        if ($session['expire_time'] < time()) {
            Response::error('认证令牌已过期', 401);
        }
        
        // 获取用户角色（从user表获取，确保角色是最新的）
        $user = $db->find('user', array('id' => $session['user_id']));
        if ($user) {
            $session['role'] = $user['role'];
        }
        
        return $session;
    }
    
    /**
     * 生成Token
     */
    public static function generateToken($userId) {
        $token = md5(uniqid(rand(), true) . time());
        $config = Database::getConfig();
        $expireTime = time() + $config['api']['token_expire'];
        
        return array(
            'token' => $token,
            'expire_time' => $expireTime
        );
    }
    
    /**
     * 微信小程序登录
     */
    public static function wechatLogin($code) {
        $config = Database::getConfig();
        $wechatConfig = $config['wechat'];
        
        // 调用微信接口获取openid和session_key
        $url = "https://api.weixin.qq.com/sns/jscode2session";
        $params = array(
            'appid' => $wechatConfig['appid'],
            'secret' => $wechatConfig['secret'],
            'js_code' => $code,
            'grant_type' => 'authorization_code'
        );
        
        $response = @file_get_contents($url . '?' . http_build_query($params));
        if ($response === false) {
            Response::error('微信接口请求失败', 500);
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['errcode'])) {
            Log::error('微信登录失败: ' . $response);
            Response::error('登录失败: ' . $result['errmsg'], 400);
        }
        
        return $result;
    }
    
    /**
     * 验证管理员权限
     */
    public static function verifyAdmin() {
        $session = self::verify();
        
        if ($session['role'] !== 'admin' && $session['role'] !== 'manager') {
            Response::error('权限不足', 403);
        }
        
        return $session;
    }
}
