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
            $get_user_info = M('user')->field('phone_number, avatar_url, gender, admin_weight')->where(['u_id' => $u_id])->find();
            if ($get_user_info == null) {
                $this->ret($result, 0, 'session指向的用户不存在');
            } else {
                if ($get_user_info['admin_weight'] == 0) {
                    $this->ret($result, 0, 'session指向的用户权限不足以登录后台系统');
                }
                $this->ret($get_user_info);
            }
        }
    }

    /**
     * 登录
     */
    public function login() {
        $db_user = M('user');
        $post = I('post.');
        $phone_number = trim($post['phone_number']);
        $password = trim($post['password']);
        
        if (preg_match('/^(13[0-9]|14[579]|15[0-3,5-9]|16[6]|17[0135678]|18[0-9]|19[89])\\d{8}$/', $phone_number) == 0) {
            $this->ret($result, 0, '手机号码不符合要求');
        }

        $user_info = $db_user->where(['phone_number' => $phone_number])->find();
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
        $result['phone_number'] = $user_info['phone_number'];
        $result['avatar_url'] = $user_info['avatar_url'];
        $result['gender'] = $user_info['gender'];
        $result['admin_weight'] = $user_info['admin_weight'];
        $this->ret($result);
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
        if ($admin_weight == 10) {
            // 如果是超级管理员
            $gym_list = M('gym')
                ->join('user on gym.founder = user.u_id', 'LEFT')
                ->join('city on city.city_id = gym.city_id', 'LEFT')
                ->field([
                    'gym_id',
                    'gym_name',
                    'star',
                    'cover',
                    'type_id',
                    'contact_info',
                    'user.phone_number' => 'founder',
                    'city_id',
                    'city.city_name',
                    'detail_address',
                    'detail_msg',
                ])
                ->select();
            for ($i = 0, $len = count($gym_list); $i < $len; $i++) {
                $gym_list[$i] = array_merge($gym_list[$i], [
                    'can_edit' => 1,
                    'can_delete' => 1,
                ]);
            }
        } elseif ($admin_weight == 2) {
            // 如果是商家BOSS
            
        } elseif ($admin_weight == 1) {
            // 如果只是商家员工
        }
    }
}