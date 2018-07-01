<?php
namespace Home\Controller;
use Think\Controller;
class AdminController extends Controller {

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
     * 添加管理员账号
     */
    public function add_gym_admin() {
        $u_id = session('u_id');
        $admin_weight = session('admin_weight');
        $role_id = I('post.role_id');
        $password = I('post.password');
        if (empty($u_id)) {
            $this->ret($result, -1, '未登录');
        }
        if ($admin_weight < 1) {
            $this->ret($result, 0, '无权限');
        }
        $db_admin = M('gym_admin');
        if ($admin_weight < 10) {
            $get_gym_id = $db_admin->table('gym_role')->field('gym_id, founder')->where(['role_id' => $role_id])->find();
            if (empty($get_gym_id)) {
                $this->ret($result, 0, '数据库查询不到对应角色');
            }
            if ($admin_weight == 1) {
                // 商家管理员
                $get_gym_admin = $db_admin->field('role_id')->where(['gym_admin_id' => $u_id])->find();
                $get_role_operation = $db_admin->table('gym_role')->field('operation_list')->where(['role_id' => $get_gym_admin['role_id']])->find();
                $operation_list = explode('|', $get_role_operation['operation_list']);
                if (!in_array('1', $operation_list)) {
                    $this->ret($result, 0, '无权限添加管理员');
                }
                if ($get_gym_id['gym_id'] != ($db_admin->table('gym_role')->field('gym_id')->where(['role_id' => $get_gym_admin['role_id']])->find())['gym_id']) {
                    $this->ret($result, 0, '无权限添加其他场馆的管理员');
                }
            } else {
                // 商家BOSS，判断场馆是否其创建
                if ($get_gym_id['founder'] != $u_id) {
                    $this->ret($result, 0, '无权限添加其他场馆的管理员');
                }
            }
        }
        $data = [
            'role_id' => $role_id,
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
     * 生成随机字符串
     */
    private function generate_string($length = 4) {  
        // 密码字符集，可任意添加你需要的字符
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
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