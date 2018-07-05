<?php
namespace Home\Controller;
use Think\Controller;
class AdminController extends Controller {

    public function __construct(){
        // dump($_SERVER['PATH_INFO']);
        if ($_SERVER['REQUEST_METHOD'] == 'GET') {
            $request_data = json_encode(I('get.'));
        } elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $request_data = json_encode(I('post.'));
        }
        $data = array(
            'method' => $_SERVER['REQUEST_METHOD'],
            'path' => $_SERVER['PATH_INFO'],
            'data' => $request_data,
        );
        M('request_log')->add($data);
        $data = array();
    }

    /**
     * 验证是否登录态
     */
    public function islogin() {
        $u_id = session('u_id');
        if ($u_id == null) {
            $this->ret($result, -1, '未登录');
        } else {
            $is_gym_admin = session('gym_admin');
            if (!empty($is_gym_admin) && $is_gym_admin == true) {
                // 商家管理员账号
                $get_user_info = M('gym_admin')->where(['gym_admin_id' => $u_id])->find();
                if ($get_user_info == null) {
                    $this->ret($result, 0, 'session指向的用户不存在');
                } else {
                    $result['account'] = $get_user_info['account'];
                    $result['admin_weight'] = 1;
                    $this->ret($result);
                }
            } else {
                // 商家BOSS或超管
                $get_user_info = M('user')->where(['u_id' => $u_id])->find();
                if ($get_user_info == null) {
                    $this->ret($result, 0, 'session指向的用户不存在');
                } else {
                    if ($get_user_info['admin_weight'] == 0) {
                        $this->ret($result, 0, 'session指向的用户权限不足以登录后台系统');
                    }
                    $result['account'] = $get_user_info['phone_number'];
                    $result['admin_weight'] = $get_user_info['admin_weight'];
                    $this->ret($result);
                }
            }
        }
    }

    /**
     * 登录
     */
    public function login() {
        $post = I('post.');
        $account = trim($post['account']);
        $password = trim($post['password']);
        
        if (preg_match('/^(13[0-9]|14[579]|15[0-3,5-9]|16[6]|17[0135678]|18[0-9]|19[89])\\d{8}$/', $account) == 0) {
            // 非手机号登录
            $db_user = M('gym_admin');
            $user_info = $db_user->where(['account' => $account])->find();
            if (empty($user_info)) {
                $this->ret($result, 0, '该账号未注册');
            }
            // 验证密码
            if (!password_verify($password, $user_info['password'])) {
                $this->ret($result, 0, '密码错误');
            }
            // 设置session
            session('gym_admin', true);
            session('u_id', $user_info['gym_admin_id']);
            session('admin_weight', 1);
            // 返回数据
            $result['account'] = $user_info['account'];
            $result['admin_weight'] = 1;
            $this->ret($result);
        } else {
            // 手机号登录
            $db_user = M('user');
            $user_info = $db_user->where(['phone_number' => $account])->find();
            if (empty($user_info)) {
                $this->ret($result, 0, '该手机号码未注册');
            }
            // 验证密码
            if (!password_verify($password, $user_info['password'])) {
                $this->ret($result, 0, '密码错误');
            }
            // 检测其权限是否足以登录后台管理系统
            if ($user_info['admin_weight'] == 0) {
                $this->ret($result, 0, '无权限登录后台管理系统，如是商家，可在公众号页面申请成为商家');
            }
            // 设置session
            session('u_id', $user_info['u_id']);
            session('admin_weight', $user_info['admin_weight']);
            // 返回数据
            $result['account'] = $user_info['phone_number'];
            $result['admin_weight'] = $user_info['admin_weight'];
            $this->ret($result);
        }
    }

    /**
     * 获取场馆列表
     */
    public function get_gym_list() {
        $city_id = I('get.city_id');
        $type_id = I('get.type_id');

        $admin_weight = session('admin_weight');
        if (empty($admin_weight)) {
            $this->ret($result, -1, '登录态失效');
        }
        $db_gym = M('gym');
        if ($admin_weight == 10) {
            // 如果是超级管理员
            $gym_list = $db_gym
                ->join('user on gym.founder = user.u_id', 'LEFT')
                ->join('city on city.city_id = gym.city_id', 'LEFT')
                ->field([
                    'gym_id',
                    'gym_name',
                    'star',
                    'cover',
                    'contact_info',
                    'user.phone_number' => 'founder',
                    'gym.city_id',
                    'city.city_name',
                    'detail_address',
                    'detail_msg',
                ])
                ->where(['is_delete' => '0'])
                ->select();
            for ($i = 0, $len = count($gym_list); $i < $len; $i++) {
                $gym_list[$i]['key'] = $i;
                // 判断其type_id
                $get_gym_site = $db_gym->table('gym_site')->field('type_id')->where(['gym_id' => $gym_list[$i]['gym_id']])->select();
                $num = count($get_gym_site);
                if ($num == 0) {
                    $gym_list[$i]['type_id'] = 9;
                } else {
                    $gym_list[$i]['type_id'] = $get_gym_site[0]['type_id'];
                    if ($num > 1) {
                        for ($j = 1; $j < $num; $j++) {
                            if ($get_gym_site[$j]['type_id'] != $get_gym_site[0]['type_id']) {
                                $gym_list[$i]['type_id'] = 8;
                                break;
                            }
                        }
                    }
                }
            }
        } elseif ($admin_weight == 2) {
            // 如果是商家BOSS
            $u_id = session('u_id');
            $gym_list = $db_gym
                ->join('user on gym.founder = user.u_id', 'LEFT')
                ->join('city on city.city_id = gym.city_id', 'LEFT')
                ->field([
                    'gym_id',
                    'gym_name',
                    'star',
                    'cover',
                    'contact_info',
                    'user.phone_number' => 'founder',
                    'gym.city_id',
                    'city.city_name',
                    'detail_address',
                    'detail_msg',
                ])
                ->where(['is_delete' => '0'])
                ->where(['gym.founder' => $u_id]);
            if (!empty($city_id)) {
                $gym_list = $gym_list->where(['gym.city' => $city_id]);
            }
            $gym_list = $gym_list->select();
            for ($i = 0, $len = count($gym_list); $i < $len; $i++) {
                // 判断其type_id
                $get_gym_site = $db_gym->table('gym_site')->field('type_id')->where(['gym_id' => $gym_list[$i]['gym_id']])->select();
                $num = count($get_gym_site);
                if ($num == 0) {
                    $gym_list[$i]['type_id'] = 9;
                } else {
                    $gym_list[$i]['type_id'] = $get_gym_site[0]['type_id'];
                    if ($num > 1) {
                        for ($j = 1; $j < $num; $j++) {
                            if ($get_gym_site[$j]['type_id'] != $get_gym_site[0]['type_id']) {
                                $gym_list[$i]['type_id'] = 8;
                                break;
                            }
                        }
                    }
                }
            }
            if (!empty($type_id)) {
                for ($i = 0, $len = count($gym_list); $i < $len; $i++) {
                    if ($gym_list[$i]['type_id'] != $type_id) {
                        unset($gym_list[$i]);
                    }
                }
                $gym_list = array_values($gym_list);
            }
            for ($i = 0, $len = count($gym_list); $i < $len; $i++) {
                $gym_list[$i]['key'] = $i;
            }
        } elseif ($admin_weight == 1) {
            // 如果只是商家员工
            $u_id = session('u_id');
            $get_gym_id = $db_gym->table('gym_role')
                ->field('gym_id')
                ->join('gym_admin on gym_admin.role_id = gym_role.role_id', 'LEFT')
                ->where(['gym_admin_id' => $u_id])
                ->find();
            if (empty($get_gym_id)) {
                $this->ret($result, 0, '当前管理员账号不属于任何场馆');
            }
            $gym_list = $db_gym
                ->join('user on gym.founder = user.u_id', 'LEFT')
                ->join('city on city.city_id = gym.city_id', 'LEFT')
                ->field([
                    'gym_id',
                    'gym_name',
                    'star',
                    'cover',
                    'contact_info',
                    'user.phone_number' => 'founder',
                    'gym.city_id',
                    'city.city_name',
                    'detail_address',
                    'detail_msg',
                ])
                ->where(['is_delete' => '0'])
                ->where(['gym.gym_id' => $get_gym_id['gym_id']]);
            if (!empty($city_id)) {
                $gym_list = $gym_list->where(['gym.city' => $city_id]);
            }
            $gym_list = $gym_list->select();
            for ($i = 0, $len = count($gym_list); $i < $len; $i++) {
                // 判断其type_id
                $get_gym_site = $db_gym->table('gym_site')->field('type_id')->where(['gym_id' => $gym_list[$i]['gym_id']])->select();
                $num = count($get_gym_site);
                if ($num == 0) {
                    $gym_list[$i]['type_id'] = 9;
                } else {
                    $gym_list[$i]['type_id'] = $get_gym_site[0]['type_id'];
                    if ($num > 1) {
                        for ($j = 1; $j < $num; $j++) {
                            if ($get_gym_site[$j]['type_id'] != $get_gym_site[0]['type_id']) {
                                $gym_list[$i]['type_id'] = 8;
                                break;
                            }
                        }
                    }
                }
            }
            if (!empty($type_id)) {
                for ($i = 0, $len = count($gym_list); $i < $len; $i++) {
                    if ($gym_list[$i]['type_id'] != $type_id) {
                        unset($gym_list[$i]);
                    }
                }
                $gym_list = array_values($gym_list);
            }
            for ($i = 0, $len = count($gym_list); $i < $len; $i++) {
                $gym_list[$i]['key'] = $i;
            }
        } else {
            $this->ret($result, 0, '无权限');
        }
        $result['gym_list'] = $gym_list;
        $this->ret($result);
    }

    /**
     * 添加场馆
     */
    public function add_gym() {
        $u_id = session('u_id');
        $admin_weight = session('admin_weight');
        if (empty($admin_weight) || $admin_weight <= 1) {
            $this->ret($result, 0, '无权限进行操作');
        }

        $db_gym = M('gym');
        $data = [
            'gym_name' => I('post.gym_name'),
            'founder' => $u_id,
            'cover' => I('post.cover'),
            'contact_info' => I('post.contact_info'),
            'city_id' => I('post.city_id'),
            'detail_address' => I('post.detail_address'),
            'detail_msg' => I('post.detail_msg'),
        ];
        $last_id = $db_gym->add($data);
        if (!is_numeric($last_id)) {
            $this->ret($result, 0, '数据库插入出错');
        } else {
            $result['gym_id'] = $last_id;
            $result['register_time'] = ($db_gym->field('register_time')->where(['gym_id' => $last_id])->find())['register_time'];
            $this->ret($result);
        }
    }
    
    /**
     * 更新场馆信息
     */
    public function update_gym() {
        $admin_weight = session('admin_weight');
        $u_id = session('u_id');
        $post = I('post.');
        $gym_id = $post['gym_id'];

        if (!$this->can_do($u_id, $admin_weight, $gym_id, 2)) {
            $this->ret($result, 0, '无权限进行操作');
        }

        $update_data = [];
        if (isset($post['gym_name'])) {
            $update_data['gym_name'] = $post['gym_name'];
        }
        if (isset($post['cover'])) {
            $update_data['cover'] = $post['cover'];
        }
        if (isset($post['contact_info'])) {
            $update_data['contact_info'] = $post['contact_info'];
        }
        if (isset($post['city_id'])) {
            $update_data['city_id'] = $post['city_id'];
        }
        if (isset($post['detail_address'])) {
            $update_data['detail_address'] = $post['detail_address'];
        }
        if (isset($post['detail_msg'])) {
            $update_data['detail_msg'] = $post['detail_msg'];
        }
        if (empty($update_data)) {
            $this->ret($result, 1, '无需要更新的数据');
        }

        M('gym')->where(['gym_id' => $gym_id])->save($update_data);
        $this->ret($result);
    }
    
    /**
     * 删除场馆
     */
    public function delete_gym(){
        $u_id = session('u_id');
        $admin_weight = session('admin_weight');
        $gym_id = I('post.gym_id');

        if (!$this->can_do($u_id, $admin_weight, $gym_id)) {
            $this->ret($result, 0, '无权限进行操作');
        }

        M('gym')->where(['gym_id' => $gym_id])->setField('is_delete', '1');
        $this->ret($result);
    }

    /**
     * 添加场馆场地
     */
    public function add_gym_site()
    {
        $u_id = session('u_id');
        $admin_weight = session('admin_weight');
        $gym_id = I('post.gym_id');
        $site_name = I('post.name');
        $type_id = I('post.type_id');
        $number = I('post.number');
        if (empty($u_id)) {
            $this->ret($result, -1, '未登录');
        }
        if ($admin_weight < 1) {
            $this->ret($result, 0, '无权限');
        }
        if (!$this->can_do($u_id, $admin_weight, $gym_id, 5)) {
            $this->ret($result, 0, '无权限进行操作');
        }

        $db_site = M('gym_site');
        $data = [
            'gym_id' => $gym_id,
            'name' => $site_name,
            'type_id' => $type_id,
            'number' => $number,
        ];
        $last_id = $db_site->add($data);
        if (!is_numeric($last_id)) {
            $this->ret($result, 0, '数据库插入出错');
        } else {
            $result['gym_site_id'] = $last_id;
            $this->ret($result);
        }
    }

    /**
     * 修改场馆场地信息
     */
    public function update_gym_site()
    {
        $admin_weight = session('admin_weight');
        $u_id = session('u_id');
        $post = I('post.');
        $gym_site_id = $post['gym_site_id'];

        $db = M();
        $get_gym_id = $db->table('gym_site')->field('gym_id')->where(['gym_site_id' => $gym_site_id])->find();
        if (!$this->can_do($u_id, $admin_weight, $get_gym_id['gym_id'], 2)) {
            $this->ret($result, 0, '无权限进行操作');
        }

        $update_data = [];
        if (isset($post['name'])) {
            $update_data['name'] = $post['name'];
        }
        if (isset($post['type_id'])) {
            $update_data['type_id'] = $post['type_id'];
        }
        if (isset($post['number'])) {
            $update_data['number'] = $post['number'];
        }
        if (empty($update_data)) {
            $this->ret($result, 1, '无需要更新的数据');
        }

        $db->table('gym_site')->where(['gym_site_id' => $gym_site_id])->save($update_data);
        $this->ret($result);
    }

    /**
     * 添加场馆角色
     */
    public function add_gym_role() {
        $u_id = session('u_id');
        $admin_weight = session('admin_weight');
        $gym_id = I('post.gym_id');
        $role_name = I('post.name');
        $operation_list = I('post.operation_list');
        if (empty($u_id)) {
            $this->ret($result, -1, '未登录');
        }
        if ($admin_weight < 1) {
            $this->ret($result, 0, '无权限');
        }
        $db_role = M('gym_role');
        $get_gym_admin = $db_role->table('gym_admin')->field('role_id')->where(['gym_admin_id' => $u_id])->find();
        $get_gym_role = $db_role->field('gym_id')->where(['role_id' => $get_gym_admin['role_id']])->find();
        if (!$this->can_do($u_id, $admin_weight, $get_gym_role['gym_id'], 1)) {
            $this->ret($result, 0, '无权限进行操作');
        }

        // 检验$operation_list里面是否有非法的id
        $true_operation_list = $db_role->table('gym_operation')->field('operation_id')->select();
        $true_operation_list_array = [];
        for ($i = 0, $len = count($true_operation_list); $i < $len; $i++) {
            $true_operation_list_array[] = $true_operation_list[$i]['operation_id'];
        }
        for ($i = 0, $len = count($operation_list); $i < $len; $i++) {
            if (!in_array($operation_list[$i], $true_operation_list_array)) {
                $this->ret($result, 0, '权限列表有数据库中不存在的操作id');
            }
        }

        $data = [
            'gym_id' => $gym_id,
            'name' => $role_name,
            'operation_list' => implode('|', $operation_list),
        ];
        $get_id = $db_role->add($data);
        $result['role_id'] = $get_id;
        $this->ret($result);
    }

    /**
     * 添加管理员账号
     */
    public function add_gym_admin() {
        $u_id = session('u_id');
        $admin_weight = session('admin_weight');
        $role_id = I('post.role_id');
        $name = I('post.name');
        $password = I('post.password');
        if (empty($u_id)) {
            $this->ret($result, -1, '未登录');
        }
        if ($admin_weight < 1) {
            $this->ret($result, 0, '无权限');
        }
        if (empty($name)) {
            $this->ret($result, 0, '名称不能为空');
        }
        if (empty($password)) {
            $this->ret($result, 0, '密码不能为空');
        }
        $db_admin = M('gym_admin');
        $get_gym_id = $db_admin->table('gym_role')->field('gym_id')->where(['role_id' => $role_id])->find();
        if (!$this->can_do($u_id, $admin_weight, $get_gym_id['gym_id'], 1)) {
            $this->ret($result, 0, '无权限进行操作');
        }
        $data = [
            'role_id' => $role_id,
            'name' => $name,
            'password' => password_hash($password, PASSWORD_BCRYPT),
        ];
        $get_id = $db_admin->add($data);
        $account = $this->generate_string(4) . $get_id;
        $db_admin->where(['gym_admin_id' => $get_id])->save(['account' => $account]);
        $result['gym_admin_id'] = $get_id;
        $result['account'] = $account;
        $this->ret($result);
    }
    
    /**
     * 验证是否有权限操作
     * @param int $u_id 用户id
     * @param int $admin_weight 权限权重，0、1、2、10
     * @param int $gym_id 场馆id
     * @param int $operation_id 操作id，当场馆管理员一定无的权限时传入0
     * @return boolean
     */
    private function can_do($u_id, $admin_weight, $gym_id, $operation_id = 0) {
        $db = M();
        if ($admin_weight < 10) {
            if ($admin_weight == 1) {
                // 商家管理员
                $get_gym_admin = $db->table('gym_admin')->field('role_id')->where(['gym_admin_id' => $u_id])->find();
                $get_gym_role = $db->table('gym_role')->field('gym_id, operation_list')->where(['role_id' => $get_gym_admin['role_id']])->find();
                $operation_list = explode('|', $get_gym_role['operation_list']);
                if (!in_array($operation_id, $operation_list)) {
                    return false;
                }
                if ($gym_id != $get_gym_role['gym_id']) {
                    return false;
                }
            } elseif ($admin_weight == 2) {
                // 商家BOSS，判断场馆是否其创建
                $get_gym = $db->table('gym')->field('founder')->where(['gym_id' => $gym_id])->find();
                if ($get_gym['founder'] != $u_id) {
                    return false;
                }
            } else {
                return false;
            }
        }
        return true;
    }

    /**
     * 生成随机字符串
     */
    private function generate_string($length = 4) {  
        // 密码字符集，可任意添加你需要的字符
        $chars = 'abcdefghijklmnopqrstuvwxyz1234567890';
        $string = '';
        for ($i = 0; $i < $length; $i++) {
            $string .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $string;
    } 

    private function ret(&$result, $status = 1, $msg = "") {
        $result['status'] = $status;
        $result['msg'] = $msg;
        $this->ajaxReturn($result);
    }
}