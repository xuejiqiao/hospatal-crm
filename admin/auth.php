<?php
/**
 * CRM后台 - 管理员认证模块
 */

// 安全启动session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

class AdminAuth {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * 管理员登录
     */
    public function login($username, $password) {
        // 查管理员表
        $admin = $this->db->find('admin', array('username' => $username));

        if (!$admin) {
            return array('success' => false, 'message' => '用户名不存在');
        }

        if ($admin['status'] != 1) {
            return array('success' => false, 'message' => '账号已被禁用');
        }

        // 验证密码 (兼容 md5 和 password_hash)
        $passwordOk = false;
        if (password_verify($password, $admin['password'])) {
            $passwordOk = true;
        } elseif (md5($password) === $admin['password']) {
            $passwordOk = true;
        }

        if ($passwordOk) {
            $_SESSION['admin_user'] = array(
                'id' => $admin['id'],
                'username' => $admin['username'],
                'nickname' => $admin['nickname'],
                'role' => $admin['role']
            );

            // 更新最后登录时间
            try {
                $this->db->update('admin', array(
                    'last_login_time' => time(),
                    'last_login_ip' => $_SERVER['REMOTE_ADDR']
                ), array('id' => $admin['id']));
            } catch (Exception $e) {
                // 忽略更新失败
            }

            // 记录日志
            try {
                $this->log($admin['id'], 'login', 'admin', $admin['id'], '管理员登录');
            } catch (Exception $e) {
                // 忽略日志写入失败
            }

            return array('success' => true, 'message' => '登录成功');
        }

        return array('success' => false, 'message' => '密码错误');
    }

    /**
     * 退出登录
     */
    public function logout() {
        if (isset($_SESSION['admin_user'])) {
            try {
                $this->log($_SESSION['admin_user']['id'], 'logout', 'admin', $_SESSION['admin_user']['id'], '管理员退出');
            } catch (Exception $e) {}
        }
        $_SESSION = array();
        session_destroy();
    }

    /**
     * 检查是否已登录
     */
    public static function check() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['admin_user']) && !empty($_SESSION['admin_user']);
    }

    /**
     * 要求登录（未登录则跳转）
     */
    public static function requireLogin() {
        if (!self::check()) {
            // 输出JS跳转，避免header已发送的问题
            echo '<script>window.location.href="admin.php?module=auth&action=login";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=admin.php?module=auth&action=login"></noscript>';
            exit;
        }
    }

    /**
     * 获取当前管理员信息
     */
    public static function getAdmin() {
        return isset($_SESSION['admin_user']) ? $_SESSION['admin_user'] : null;
    }

    /**
     * 记录操作日志
     */
    public function log($adminId, $action, $targetType, $targetId, $content) {
        $this->db->insert('admin_log', array(
            'admin_id' => $adminId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'content' => $content,
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
            'addtime' => time()
        ));
    }
}
