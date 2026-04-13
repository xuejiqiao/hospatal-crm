# CRM微信小程序后端API系统

基于PHP 7.x + MySQL 5.4+开发的医院预约CRM系统后端API服务

## 系统要求

- PHP >= 7.0
- MySQL >= 5.4
- Apache/Nginx
- PHP扩展: PDO, PDO_MySQL, JSON, CURL

## 目录结构

```
crm-weapp-api/
├── api/                    # API接口目录
│   ├── wechat.php         # 微信小程序接口
│   ├── hospital.php       # 医院管理接口
│   ├── reservation.php    # 预约管理接口
│   └── stats.php          # 统计分析接口
├── config/                 # 配置目录
│   └── database.php       # 数据库配置
├── core/                   # 核心类库
│   ├── Database.php       # 数据库类
│   ├── Response.php       # 响应类
│   ├── Auth.php           # 认证类
│   └── Log.php            # 日志类
├── uploads/               # 上传目录(需创建)
├── logs/                  # 日志目录(需创建)
├── index.php              # API入口文件
├── .htaccess              # Apache重写规则
├── nginx.conf             # Nginx配置示例
└── install.sql            # 数据库安装脚本
```

## 安装步骤

### 1. 环境准备

确保服务器已安装:
- PHP 7.0+
- MySQL 5.4+
- Apache或Nginx

### 2. 创建数据库

```bash
mysql -u root -p < install.sql
```

或在phpMyAdmin中导入`install.sql`文件

### 3. 配置数据库连接

编辑 `config/database.php`:

```php
return array(
    'db' => array(
        'host' => 'localhost',
        'port' => '3306',
        'database' => 'crm_weapp',
        'username' => 'your_username',
        'password' => 'your_password',
        'charset' => 'utf8mb4',
        'prefix' => 'weapp_'
    ),
    
    'wechat' => array(
        'appid' => 'your_wechat_appid',
        'secret' => 'your_wechat_secret',
    ),
    // ...
);
```

### 4. 设置目录权限

```bash
chmod 755 uploads/
chmod 755 logs/
```

### 5. 配置Web服务器

#### Apache配置

确保已启用mod_rewrite模块，`.htaccess`文件已包含在项目中。

#### Nginx配置

使用提供的`nginx.conf`作为参考，修改为您的实际路径。

### 6. 测试API

访问: `http://your-domain/api/wechat/login`

## API接口文档

### 基础信息

- 基础URL: `http://your-domain/api`
- 数据格式: JSON
- 字符编码: UTF-8
- 认证方式: Bearer Token

### 接口列表

#### 1. 微信小程序接口 (`/api/wechat`)

##### 1.1 登录
- **URL**: `POST /api/wechat/login`
- **参数**: 
  ```json
  {
    "code": "微信登录code"
  }
  ```
- **响应**:
  ```json
  {
    "code": 200,
    "message": "登录成功",
    "data": {
      "token": "登录令牌",
      "userInfo": {
        "id": 1,
        "nickname": "微信用户",
        "role": "user"
      }
    }
  }
  ```

##### 1.2 更新用户信息
- **URL**: `POST /api/wechat/updateUserInfo`
- **Headers**: `Authorization: Bearer {token}`
- **参数**:
  ```json
  {
    "nickname": "昵称",
    "avatar": "头像URL",
    "phone": "手机号",
    "name": "真实姓名"
  }
  ```

##### 1.3 获取用户信息
- **URL**: `GET /api/wechat/getUserInfo`
- **Headers**: `Authorization: Bearer {token}`

#### 2. 医院管理接口 (`/api/hospital`)

##### 2.1 获取医院列表
- **URL**: `GET /api/hospital/list?page=1&pageSize=10&name=医院名称`

##### 2.2 获取医院详情
- **URL**: `GET /api/hospital/detail?id=1`

##### 2.3 添加医院 (管理员)
- **URL**: `POST /api/hospital/add`
- **Headers**: `Authorization: Bearer {token}`
- **权限**: admin/manager

##### 2.4 更新医院 (管理员)
- **URL**: `POST /api/hospital/update`

##### 2.5 删除医院 (管理员)
- **URL**: `POST /api/hospital/delete`

#### 3. 预约管理接口 (`/api/reservation`)

##### 3.1 创建预约
- **URL**: `POST /api/reservation/create`
- **Headers**: `Authorization: Bearer {token}`
- **参数**:
  ```json
  {
    "hospital_id": 1,
    "patient_name": "张三",
    "patient_phone": "13800138000",
    "patient_idcard": "身份证号",
    "department": "内科",
    "doctor": "医生姓名",
    "reservation_date": "2026-04-10",
    "time_period": "上午",
    "remark": "备注信息"
  }
  ```

##### 3.2 获取我的预约列表
- **URL**: `GET /api/reservation/myList?page=1&pageSize=10&status=0`
- **Headers**: `Authorization: Bearer {token}`

##### 3.3 取消预约
- **URL**: `POST /api/reservation/cancel`
- **Headers**: `Authorization: Bearer {token}`

##### 3.4 获取预约列表 (管理员)
- **URL**: `GET /api/reservation/list`
- **Headers**: `Authorization: Bearer {token}`

##### 3.5 更新预约状态 (管理员)
- **URL**: `POST /api/reservation/updateStatus`
- **参数**:
  ```json
  {
    "id": 1,
    "status": 1,
    "remark": "管理员备注"
  }
  ```

#### 4. 统计分析接口 (`/api/stats`)

所有统计接口需要管理员权限

##### 4.1 获取概览统计
- **URL**: `GET /api/stats/overview`

##### 4.2 获取预约趋势
- **URL**: `GET /api/stats/reservationTrend`

##### 4.3 获取医院排名
- **URL**: `GET /api/stats/hospitalRank`

##### 4.4 获取状态分布
- **URL**: `GET /api/stats/statusDistribution`

## 安全建议

1. **修改默认配置**
   - 修改数据库密码
   - 修改微信小程序AppSecret
   - 定期更新token

2. **启用HTTPS**
   - 强烈建议在生产环境启用SSL证书
   - 配置nginx.conf中的HTTPS部分

3. **数据备份**
   - 定期备份数据库
   - 备份上传文件

4. **日志监控**
   - 定期检查logs/目录下的日志文件
   - 监控异常访问

5. **权限控制**
   - 严格区分用户和管理员权限
   - 敏感操作记录日志

## 常见问题

### 1. 数据库连接失败
检查`config/database.php`中的数据库配置是否正确

### 2. 接口返回404
检查Web服务器重写规则是否配置正确

### 3. 微信登录失败
- 检查AppID和AppSecret是否正确
- 检查服务器是否能访问微信API
- 查看logs/目录下的错误日志

### 4. Token过期
Token默认有效期为2小时，过期后需要重新登录

## 技术支持

如有问题，请查看:
- 错误日志: `logs/` 目录
- 数据库日志: MySQL错误日志

## 版本历史

- v1.0.0 (2026-04-03)
  - 初始版本
  - 支持微信小程序登录
  - 医院管理
  - 预约管理
  - 统计分析

## 许可证

本项目仅供学习和内部使用。
