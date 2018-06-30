-- 创建数据库
DROP DATABASE IF EXISTS `xcy`;
CREATE DATABASE `xcy` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
use `xcy`;

-- 用户信息
CREATE TABLE `user` (
    `u_id` int(10) PRIMARY KEY AUTO_INCREMENT COMMENT "本系统用户唯一标识id",
    `nick` varchar(200) DEFAULT "" COMMENT "昵称", 
    `phone_number` varchar(20) NOT NULL COMMENT "用户手机号码", 
    `password` varchar(256) NOT NULL COMMENT "密码",
    `avatar_url` text DEFAULT NULL COMMENT "用户头像地址", 
    `gender` ENUM('0','1') NOT NULL COMMENT "性别，0表示女，1表示男",
    `balance` DECIMAL(10, 2) DEFAULT 0 COMMENT "余额",
    `admin_weight` int(2) DEFAULT 0 COMMENT "管理员权重，0表示普通用户，1表示商家员工，2表示商家BOSS，10表示超级管理员",
    UNIQUE KEY unique_phone(`phone_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 地级市
CREATE TABLE `city` (
	`city_id` int(10) PRIMARY KEY AUTO_INCREMENT COMMENT "地级市id", 
    `city_name` varchar(20) NOT NULL COMMENT "城市名称"
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 插入地级市数据
INSERT INTO `city` (`city_name`) VALUES
('广州市'),
('深圳市');

-- 场馆信息
CREATE TABLE `gym` (
	`gym_id` int(10) PRIMARY KEY AUTO_INCREMENT COMMENT "场馆id", 
    `gym_name` varchar(100) NOT NULL COMMENT "场馆名称",
    `founder` int(10) NOT NULL COMMENT "创建人的用户ID", 
    `city_id` int(10) NOT NULL COMMENT "地级市id", 
    `star` int(1) DEFAULT 0 COMMENT "场馆星级",
    `cover` text DEFAULT NULL COMMENT "场馆封面图地址",
    `detail_address` varchar(200) DEFAULT NULL COMMENT "场馆详细地址",
    `contact_info` varchar(50) DEFAULT NULL COMMENT "场馆联系方式",
    `detail_msg` text DEFAULT NULL COMMENT "场馆详细介绍",
    `register_time` datetime DEFAULT now() COMMENT "场馆注册时间",
    FOREIGN KEY (`founder`) REFERENCES `user` (`u_id`) ON DELETE CASCADE,
    FOREIGN KEY (`city_id`) REFERENCES `city` (`city_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 场地信息
CREATE TABLE `gym_site` (
    `gym_site_id` int(10) PRIMARY KEY AUTO_INCREMENT COMMENT "场馆场地id", 
	`gym_id` int(10) COMMENT "场馆id", 
    `name` varchar(500) COMMENT "场地名字",
    `type_id` int(2) NOT NULL COMMENT "场馆运动类型",
    `number` int(10) COMMENT "该场地容纳数量",
    FOREIGN KEY (`gym_id`) REFERENCES `gym` (`gym_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 场次时间
CREATE TABLE `gym_site_time` (
    `gym_site_time_id` int(10) PRIMARY KEY AUTO_INCREMENT COMMENT "场次时间ID",
    `gym_site_id` int(10) COMMENT "场馆场地id",
    `date` varchar(20) COMMENT "日期",
    `start_time` varchar(20) COMMENT "场次开始时间，HH:ii",
    `end_time` varchar(20) COMMENT "场次结束时间，HH:ii",
    `price` DECIMAL(10, 2) DEFAULT 0 COMMENT "价格",
    FOREIGN KEY (`gym_site_id`) REFERENCES `gym_site` (`gym_site_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 充值订单
CREATE TABLE `recharge_order` (
    `order_id` int(10) PRIMARY KEY AUTO_INCREMENT COMMENT "充值订单id", 
	`u_id` int(10) COMMENT "用户id",
    `amount` DECIMAL(10, 2) COMMENT "充值金额",
    `recharge_time` datetime DEFAULT now() COMMENT "充值时间",
    FOREIGN KEY (`u_id`) REFERENCES `user` (`u_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 订场订单
CREATE TABLE `book_order` (
    `order_id` int(10) PRIMARY KEY AUTO_INCREMENT COMMENT "订单id", 
    `u_id` int(10) COMMENT "用户id",
    `amount` DECIMAL(10, 2) COMMENT "支付的金额",
    `success_time` datetime DEFAULT now() COMMENT "预定成功的时间",
    FOREIGN KEY (`u_id`) REFERENCES `user` (`u_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 订场订单和场次的关联表
CREATE TABLE `order_site` (
    `order_id` int(10) COMMENT "订单ID",
    `gym_site_time_id` int(10) COMMENT "场次时间ID",
    FOREIGN KEY (`order_id`) REFERENCES `book_order` (`order_id`) ON DELETE CASCADE,
    FOREIGN KEY (`gym_site_time_id`) REFERENCES `gym_site_time` (`gym_site_time_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 消息
CREATE TABLE `message` (
    `message_id` int(10) PRIMARY KEY AUTO_INCREMENT COMMENT "消息id", 
    `u_id` int(10) COMMENT "用户id",
    `is_read` int(1) COMMENT "是否已读，0表示未读，1表示已读",
    `content` text COMMENT "消息内容",
    `from` int(10) DEFAULT 0 COMMENT "发送者，默认0，即系统消息",
    `time` datetime DEFAULT now() COMMENT "消息发送时间",
    FOREIGN KEY (`u_id`) REFERENCES `user` (`u_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 评价
CREATE TABLE `comment` (
    `comment_id` int(10) PRIMARY KEY AUTO_INCREMENT COMMENT "评价id", 
    `order_id` int(10) COMMENT "订单ID",
    `u_id` int(10) COMMENT "用户id",
    `star` int(1) NOT NULL COMMENT "评价星级",
    `content` text DEFAULT NULL COMMENT "评价内容",
    `comment_time` datetime DEFAULT now() COMMENT "评价时间",
    FOREIGN KEY (`order_id`) REFERENCES `book_order` (`order_id`) ON DELETE CASCADE,
    FOREIGN KEY (`u_id`) REFERENCES `user` (`u_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 申请成为商家的管理清单
CREATE TABLE `apply_list` (
    `apply_id` int(10) PRIMARY KEY AUTO_INCREMENT COMMENT "申请id", 
    `u_id` int(10) COMMENT "用户ID",
    `apply_time` datetime DEFAULT now() COMMENT "申请时间",
    `status` int(1) DEFAULT 0 COMMENT "状态，0表示未处理，1表示已同意，2表示已拒绝",
    `last_time` datetime COMMENT "最后处理时间",
    FOREIGN KEY (`u_id`) REFERENCES `user` (`u_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 场馆权限表字典
CREATE TABLE `gym_operation` (
    `operation_id` int(10) PRIMARY KEY AUTO_INCREMENT COMMENT "操作id",
    `label` varchar(200) COMMENT "操作具体内容"
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 插入场馆权限表字典
INSERT INTO `gym_operation` (`label`) VALUES
('角色管理权限'),
('修改场馆信息'),
('查看场馆评论'),
('查看订单');

-- 场馆角色
CREATE TABLE `gym_role` (
    `role_id` int(10) PRIMARY KEY AUTO_INCREMENT COMMENT "角色ID",
    `gym_id` int(10) COMMENT "场馆id", 
    `name` varchar(200) COMMENT "角色名称",
    `operation_list` varchar(100) COMMENT "角色拥有的操作列表，比如拥有查看订单和查看场馆评论的操作，就是3|4",
    FOREIGN KEY (`gym_id`) REFERENCES `gym` (`gym_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 场馆下管理员账号
CREATE TABLE `gym_admin` (
    `gym_admin_id` int(10) COMMENT "管理员账号ID",
    `role_id` int(10) COMMENT "角色ID",
    `account` varchar(200) COMMENT "管理员账号名",
    `password` varchar(200) COMMENT "账号密码",
    FOREIGN KEY (`role_id`) REFERENCES `gym_role` (`role_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- 日志表，用于记录后台管理系统的各种操作
CREATE TABLE `log` (
    `log_id` int(10) PRIMARY KEY AUTO_INCREMENT COMMENT "日志ID",
    `u_id` int(10) COMMENT "操作者的用户ID",
    `operation_detail` text COMMENT "操作细节，包括但不限于登录，审批商户，商户添加修改删除场馆信息等",
    `time` datetime COMMENT "操作时间",
    FOREIGN KEY (`u_id`) REFERENCES `user` (`u_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

