<?php
namespace Home\Controller;

use Think\Controller;

class AdminController extends Controller
{

    public function __construct()
    {
        if ($_SERVER['REQUEST_METHOD'] != 'OPTIONS') {
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
    }

    /**
     * 验证是否登录态
     */
    public function islogin()
    {
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
    public function login()
    {
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
        }
        $this->record_log(session('u_id'), session('admin_weight'), '登录后台管理系统');
        $this->ret($result);
    }

    /**
     * 退出登录
     */
    public function logout()
    {
        session('u_id', null);
        session('admin_weight', null);
        $this->ret($result);
    }

    /**
     * 获取全部用户列表
     */
    public function get_user_list()
    {
        $u_id = session('u_id');
        $admin_weight = session('admin_weight');
        $post = I('get.');

        if ($admin_weight < 10) {
            $this->ret($result, 0, '只有超管有权限查看用户列表');
        }

        $user_list = M('user')->field('u_id, nick, phone_number, gender, admin_weight');
        if (isset($post['is_admin'])) {
            if ($post['is_admin'] == '0') {
                $user_list = $user_list->where(['admin_weight' => 0]);
            } elseif ($post['is_admin'] == '1') {
                $user_list = $user_list->where(['admin_weight' => ['EGT', 2]]);
            }
        }
        if (isset($post['gender'])) {
            $user_list = $user_list->where(['gender' => $post['gender']]);
        }
        $user_list = $user_list->select();
        for ($i = 0, $len = count($user_list); $i < $len; $i++) {
            $user_list[$i]['key'] = $i;
        }
        $result['list'] = $user_list;
        $this->ret($result);
    }

    /**
     * 将用户升级为商家BOSS或者超管，或者降级为普通用户
     */
    public function change_user_auth()
    {
        $u_id = session('u_id');
        $admin_weight = session('admin_weight');
        $post = I('post.');

        $admin_weight_map = [
            '0' => '普通用户',
            '2' => '商家BOSS',
            '10' => '超级管理员',
        ];

        if ($admin_weight < 10) {
            $this->ret($result, 0, '只有超管有权限改变用户权限');
        }

        $db = M('user');
        $user_info = $db->where(['u_id' => $post['u_id']])->find();
        if (empty($user_info)) {
            $this->ret($result, 0, '查询不到用户信息');
        }
        if (!in_array($post['admin_weight'], ['0', '2', '10'])) {
            $this->ret($result, 0, 'admin_weight数据须是0、2或10');
        }
        if ($user_info['admin_weight'] == $post['admin_weight']) {
            $this->ret($result, 0, '该用户已经是当前权限');
        }
        $db->where(['u_id' => $post['u_id']])->setField('admin_weight', $post['admin_weight']);

        $this->record_log($u_id, $admin_weight, '将u_id为' . $post['u_id'] . '的用户改变为' . $admin_weight_map[$post['admin_weight']]);
        $this->ret($result);
    }

    /**
     * 获取场馆列表
     */
    public function get_gym_list()
    {
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
                ->order('gym_id desc')
                ->where(['is_delete' => '0']);
            if (!empty($city_id)) {
                $gym_list = $gym_list->where(['gym.city_id' => $city_id]);
            }
            $gym_list = $gym_list->select();
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
            if ($type_id == '0' || !empty($type_id)) {
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
                ->order('gym_id desc')
                ->where(['is_delete' => '0'])
                ->where(['gym.founder' => $u_id]);
            if (!empty($city_id)) {
                $gym_list = $gym_list->where(['gym.city_id' => $city_id]);
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
            if ($type_id == '0' || !empty($type_id)) {
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
                ->order('gym_id desc')
                ->where(['is_delete' => '0'])
                ->where(['gym.gym_id' => $get_gym_id['gym_id']]);
            if (!empty($city_id)) {
                $gym_list = $gym_list->where(['gym.city_id' => $city_id]);
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
            if ($type_id == '0' || !empty($type_id)) {
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
    public function add_gym()
    {
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
            $this->record_log($u_id, $admin_weight, '新建了场馆，场馆id为' . $last_id);
            $this->ret($result);
        }
    }

    /**
     * 更新场馆信息
     */
    public function update_gym()
    {
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
        $this->record_log($u_id, $admin_weight, '更新了场馆信息，场馆id为' . $gym_id);
        $this->ret($result);
    }

    /**
     * 删除场馆
     */
    public function delete_gym()
    {
        $u_id = session('u_id');
        $admin_weight = session('admin_weight');
        $gym_id = I('post.gym_id');

        if (!$this->can_do($u_id, $admin_weight, $gym_id)) {
            $this->ret($result, 0, '无权限进行操作');
        }

        M('gym')->where(['gym_id' => $gym_id])->setField('is_delete', '1');
        $this->record_log($u_id, $admin_weight, '删除了场馆，场馆id为' . $gym_id);
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
        $site_name = trim(I('post.name'));
        $type_id = I('post.type_id');
        $number = I('post.number');
        if (empty($u_id)) {
            $this->ret($result, -1, '未登录');
        }
        if ($admin_weight < 1) {
            $this->ret($result, 0, '无权限');
        }
        if (!$this->can_do($u_id, $admin_weight, $gym_id, 3)) {
            $this->ret($result, 0, '无权限进行操作');
        }

        if (!in_array($type_id, ['0', '1', '2', '3', '4', '5', '6', '7'])) {
            $this->ret($result, 0, '添加场地时type_id范围只能为0-7');
        }
        if (empty($site_name)) {
            $this->ret($result, 0, '场地名称不能留空');
        }
        if (!is_numeric($number) || $number < 1) {
            $this->ret($result, 0, '容纳数量必须是大于0的整数');
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
            $this->record_log($u_id, $admin_weight, '为场馆id为' . $gym_id . '的场馆新建了场地，场地id为' . $last_id);
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
        if (!$this->can_do($u_id, $admin_weight, $get_gym_id['gym_id'], 3)) {
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
        $this->record_log($u_id, $admin_weight, '更新了场馆id为' . $get_gym_id['gym_id'] . '的场馆的某个场地信息，场地id为' . $gym_site_id);
        $this->ret($result);
    }

    /**
     * 删除场馆场地
     */
    public function delete_gym_site()
    {
        $gym_site_id = I('post.gym_site_id');
        $admin_weight = session('admin_weight');
        $u_id = session('u_id');

        $db = M();
        $get_gym_id = $db->table('gym_site')->field('gym_id')->where(['gym_site_id' => $gym_site_id])->find();
        if (!$this->can_do($u_id, $admin_weight, $get_gym_id['gym_id'], 3)) {
            $this->ret($result, 0, '无权限进行操作');
        }

        // 判断场地是否关联了订单，关联了则暂不允许删除
        $get_site_time = $db->table('gym_site_time')->field('gym_site_time_id')->where(['gym_site_id' => $gym_site_id])->select();
        if (!empty($get_site_time)) {
            $temp_array = [];
            for ($i = 0, $len = count($get_site_time); $i < $len; $i++) {
                $temp_array[] = $get_site_time[$i]['gym_site_time_id'];
            }
            $get_order = $db->table('order_site')->where(['gym_site_time_id' => ['in', $temp_array]])->select();
            if (!empty($get_order)) {
                $this->ret($result, 0, '该场地已有部分订单信息与其绑定，暂不支持删除');
            }
        }

        $db->table('gym_site')->where(['gym_site_id' => $gym_site_id])->delete();
        $this->record_log($u_id, $admin_weight, '删除了场馆id为' . $get_gym_id['gym_id'] . '的场馆的一个场地，被删场地id为' . $gym_site_id);
        $this->ret($result);
    }

    /**
     * 获取一个场地的近七天的所有场次
     */
    public function get_a_site()
    {
        $gym_site_id = I('post.gym_site_id');
        $admin_weight = session('admin_weight');
        $u_id = session('u_id');

        $db = M();
        $get_gym_id = $db->table('gym_site')->field('gym_id')->where(['gym_site_id' => $gym_site_id])->find();
        if (!$this->can_do($u_id, $admin_weight, $get_gym_id['gym_id'], true)) {
            $this->ret($result, 0, '无法查看不属于自己权限范围的场馆的信息');
        }

        // 获取近七天的日期列表
        $time = time();
        $date = [];
        for ($i = 0; $i < 7; $i++) {
            $date[$i] = date('Y-m-d', strtotime('+' . $i . ' days', $time));
        }

        $get_list = $db->table('gym_site_time')
            ->field([
                'gym_site_time_id',
                'date',
                'start_time',
                'end_time',
                'price',
            ])
            ->where(['gym_site_id' => $gym_site_id])
            ->where(['date' => ['in', $date]])
            ->select();
        for ($i = 0, $len = count($get_list); $i < $len; $i++) {
            $get_list[$i]['key'] = $i;
            $get_list[$i]['date'] = strtotime($get_list[$i]['date']);
        }
        $result['list'] = $get_list;
        $this->ret($result);
    }

    /**
     * 添加场馆场地的场次信息
     */
    public function add_gym_site_time()
    {
        $u_id = session('u_id');
        $admin_weight = session('admin_weight');
        $gym_site_id = I('post.gym_site_id');
        $price = I('post.price');
        $date = I('post.date');
        $start_time = I('post.start_time');
        $end_time = I('post.end_time');
        if (empty($u_id)) {
            $this->ret($result, -1, '未登录');
        }
        if ($admin_weight < 1) {
            $this->ret($result, 0, '无权限');
        }
        $db = M();
        $get_gym_id = $db->table('gym_site')->field('gym_id')->where(['gym_site_id' => $gym_site_id])->find();
        if (!$this->can_do($u_id, $admin_weight, $get_gym_id['gym_id'], 3)) {
            $this->ret($result, 0, '无权限进行操作');
        }

        if (empty($date)) {
            $this->ret($result, 0, '日期不能为空');
        }
        $date = date('Y-m-d', $date);
        if (preg_match('/^\d{2}:\d{2}$/', $start_time) == 0 || preg_match('/^\d{2}:\d{2}$/', $end_time) == 0) {
            $this->ret($result, 0, '时间格式须为 xx:xx');
        }

        $data = [
            'gym_site_id' => $gym_site_id,
            'price' => $price,
            'date' => $date,
            'start_time' => $start_time,
            'end_time' => $end_time,
        ];
        $last_id = $db->table('gym_site_time')->add($data);
        if (!is_numeric($last_id)) {
            $this->ret($result, 0, '数据库插入出错');
        } else {
            $result['gym_site_time_id'] = $last_id;
            $this->record_log($u_id, $admin_weight, '为场地id为' . $gym_site_id . '的场地新增了场次信息，场次id为' . $last_id);
            $this->ret($result);
        }
    }

    /**
     * 更新场馆场地的场次信息
     */
    public function update_gym_site_time()
    {
        $admin_weight = session('admin_weight');
        $u_id = session('u_id');
        $post = I('post.');
        $gym_site_time_id = $post['gym_site_time_id'];

        $db = M();
        $get_gym_site = $db->table('gym_site_time')->field('gym_site_id')->where(['gym_site_time_id' => $gym_site_time_id])->find();
        $get_gym_id = $db->table('gym_site')->field('gym_id')->where(['gym_site_id' => $get_gym_site['gym_site_id']])->find();
        if (!$this->can_do($u_id, $admin_weight, $get_gym_id['gym_id'], 3)) {
            $this->ret($result, 0, '无权限进行操作');
        }

        $update_data = [];
        if (isset($post['price'])) {
            $update_data['price'] = $post['price'];
        }
        if (isset($post['date'])) {
            $post['date'] = date('Y-m-d', $post['date']);
            $update_data['date'] = $post['date'];
        }
        if (isset($post['start_time'])) {
            $update_data['start_time'] = $post['start_time'];
        }
        if (isset($post['end_time'])) {
            $update_data['end_time'] = $post['end_time'];
        }
        if (empty($update_data)) {
            $this->ret($result, 1, '无需要更新的数据');
        }

        $db->table('gym_site_time')->where(['gym_site_time_id' => $gym_site_time_id])->save($update_data);
        $this->record_log($u_id, $admin_weight, '更新了场地id为' . $get_gym_site['gym_site_id'] . '的场地的某个场次信息，场次id为' . $gym_site_time_id);
        $this->ret($result);
    }

    /**
     * 删除场馆场地的场次信息
     */
    public function delete_gym_site_time()
    {
        $gym_site_time_id = I('post.gym_site_time_id');
        $admin_weight = session('admin_weight');
        $u_id = session('u_id');

        $db = M();
        $get_gym_site = $db->table('gym_site_time')->field('gym_site_id')->where(['gym_site_time_id' => $gym_site_time_id])->find();
        $get_gym_id = $db->table('gym_site')->field('gym_id')->where(['gym_site_id' => $get_gym_site['gym_site_id']])->find();
        if (!$this->can_do($u_id, $admin_weight, $get_gym_id['gym_id'], 3)) {
            $this->ret($result, 0, '无权限进行操作');
        }

        // 判断场地场次是否关联了订单，关联了则暂不允许删除
        $get_order = $db->table('order_site')->where(['gym_site_time_id' => $gym_site_time_id])->select();
        if (!empty($get_order)) {
            $this->ret($result, 0, '该场地场次已有部分订单信息与其绑定，暂不支持删除');
        }

        $db->table('gym_site_time')->where(['gym_site_time_id' => $gym_site_time_id])->delete();
        $this->record_log($u_id, $admin_weight, '删除了场地id为' . $get_gym_site['gym_site_id'] . '的场地的一个场次信息，场次id为' . $gym_site_time_id);
        $this->ret($result);
    }

    /**
     * 获取角色可选的权限操作列表
     */
    public function get_gym_operation()
    {
        $result['gym_operation_list'] = M('gym_operation')->select();
        for ($i = 0, $len = count($result['gym_operation_list']); $i < $len; $i++) {
            $result['gym_operation_list'][$i]['key'] = $i;
        }
        $this->ret($result);
    }

    /**
     * 获取场馆角色列表
     */
    public function get_gym_role_list()
    {
        $admin_weight = session('admin_weight');
        $u_id = session('u_id');
        $gym_id = I('get.gym_id');

        if (!$this->can_do($u_id, $admin_weight, $gym_id, 1)) {
            $this->ret($result, 0, '当前登录者无权限查看角色列表');
        }

        $db = M();
        $get_operation_list = $db->table('gym_operation')->select();
        $get_role_list = $db->table('gym_role')->field('role_id, name, operation_list')->where(['gym_id' => $gym_id])->select();
        for ($i = 0, $len = count($get_role_list); $i < $len; $i++) {
            $get_role_list[$i]['key'] = $i;
            $operation_list = explode('|', $get_role_list[$i]['operation_list']);
            $get_role_list[$i]['operation_list'] = [];
            for ($j = 0, $len_j = count($operation_list); $j < $len_j; $j++) {
                $get_role_list[$i]['operation_list'][] = [
                    'key' => $j,
                    'operation_id' => $get_operation_list[$operation_list[$j] - 1]['operation_id'],
                    'label' => $get_operation_list[$operation_list[$j] - 1]['label'],
                ];
            }
        }

        $result['gym_role_list'] = $get_role_list;
        $this->ret($result);
    }

    /**
     * 添加场馆角色
     */
    public function add_gym_role()
    {
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
        if (empty($operation_list)) {
            $this->ret($result, 0, '请为角色分配至少一个权限');
        }
        $db_role = M('gym_role');
        if (!$this->can_do($u_id, $admin_weight, $gym_id, 1)) {
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
        $this->record_log($u_id, $admin_weight, '为场馆id为' . $gym_id . '场馆新增一个角色，角色id为' . $get_id);
        $result['role_id'] = $get_id;
        $this->ret($result);
    }

    /**
     * 更新场馆角色信息
     */
    public function update_gym_role()
    {
        $admin_weight = session('admin_weight');
        $u_id = session('u_id');
        $post = I('post.');
        $role_id = $post['role_id'];

        $db = M();
        $get_gym_id = $db->table('gym_role')->field('gym_id')->where(['role_id' => $role_id])->find();
        if (!$this->can_do($u_id, $admin_weight, $get_gym_id['gym_id'], 1)) {
            $this->ret($result, 0, '无权限进行操作');
        }

        $update_data = [];
        if (isset($post['name'])) {
            $update_data['name'] = $post['name'];
        }
        if (isset($post['operation_list'])) {
            $update_data['operation_list'] = implode('|', $post['operation_list']);
        }
        if (empty($update_data)) {
            $this->ret($result, 1, '无需要更新的数据');
        }

        $db->table('gym_role')->where(['role_id' => $role_id])->save($update_data);
        $this->record_log($u_id, $admin_weight, '更新场馆id为' . $get_gym_id['gym_id'] . '的场馆的某个角色信息，角色id为' . $role_id);
        $this->ret($result);
    }

    /**
     * 删除场馆角色
     */
    public function delete_gym_role()
    {
        $role_id = I('post.role_id');
        $admin_weight = session('admin_weight');
        $u_id = session('u_id');

        $db = M();
        $get_gym_id = $db->table('gym_role')->field('gym_id')->where(['role_id' => $role_id])->find();
        if (!$this->can_do($u_id, $admin_weight, $get_gym_id['gym_id'], 1)) {
            $this->ret($result, 0, '无权限进行操作');
        }

        $db->table('gym_role')->where(['role_id' => $role_id])->delete();
        $this->record_log($u_id, $admin_weight, '删除了场馆id为' . $get_gym_id['gym_id'] . '的场馆的一个角色信息，角色id为' . $role_id);
        $this->ret($result);
    }

    /**
     * 获取场馆管理员列表
     */
    public function get_gym_admin_list()
    {
        $admin_weight = session('admin_weight');
        $u_id = session('u_id');
        $gym_id = I('get.gym_id');

        if (!$this->can_do($u_id, $admin_weight, $gym_id, 1)) {
            $this->ret($result, 0, '当前登录者无权限查看管理员列表');
        }

        $db = M();
        $gym_admin_list = $db->table('gym_admin')
            ->field('gym_admin_id, account, gym_admin.name, gym_role.role_id, gym_role.name AS role_name')
            ->join('gym_role on gym_role.role_id = gym_admin.role_id', 'LEFT')
            ->where(['gym_id' => $gym_id])
            ->select();
        for ($i = 0, $len = count($gym_admin_list); $i < $len; $i++) {
            $gym_admin_list[$i]['key'] = $i;
            $gym_admin_list[$i]['role'] = [
                'role_id' => $gym_admin_list[$i]['role_id'],
                'name' => $gym_admin_list[$i]['role_name'],
            ];
            unset($gym_admin_list[$i]['role_id']);
            unset($gym_admin_list[$i]['role_name']);
        }
        $result['gym_admin_list'] = $gym_admin_list;
        $this->ret($result);
    }

    /**
     * 添加管理员账号
     */
    public function add_gym_admin()
    {
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
        $this->record_log($u_id, $admin_weight, '为场馆id为' . $get_gym_id['gym_id'] . '的场馆新增一个管理员，管理员id为' . $get_id);
        $this->ret($result);
    }

    /**
     * 更新场馆管理员信息
     */
    public function update_gym_admin()
    {
        $admin_weight = session('admin_weight');
        $u_id = session('u_id');
        $post = I('post.');
        $gym_admin_id = $post['gym_admin_id'];

        $db = M();
        $get_role = $db->table('gym_admin')->field('role_id')->where(['gym_admin_id' => $gym_admin_id])->find();
        $get_gym_id = $db->table('gym_role')->field('gym_id')->where(['role_id' => $get_role['role_id']])->find();
        if (!$this->can_do($u_id, $admin_weight, $get_gym_id['gym_id'], 1)) {
            $this->ret($result, 0, '无权限进行操作');
        }

        $update_data = [];
        if (isset($post['role_id'])) {
            $update_data['role_id'] = $post['role_id'];
        }
        if (isset($post['name'])) {
            $update_data['name'] = $post['name'];
        }
        if (isset($post['password'])) {
            $update_data['password'] = password_hash($post['password'], PASSWORD_BCRYPT);
        }
        if (empty($update_data)) {
            $this->ret($result, 1, '无需要更新的数据');
        }

        $db->table('gym_admin')->where(['gym_admin_id' => $gym_admin_id])->save($update_data);
        $this->record_log($u_id, $admin_weight, '更新了场馆id为' . $get_gym_id['gym_id'] . '的场馆的某个管理员信息，管理员id为' . $gym_admin_id);
        $this->ret($result);
    }

    /**
     * 删除场馆管理员
     */
    public function delete_gym_admin()
    {
        $gym_admin_id = I('post.gym_admin_id');
        $admin_weight = session('admin_weight');
        $u_id = session('u_id');

        $db = M();
        $get_role = $db->table('gym_admin')->field('role_id')->where(['gym_admin_id' => $gym_admin_id])->find();
        $get_gym_id = $db->table('gym_role')->field('gym_id')->where(['role_id' => $get_role['role_id']])->find();
        if (!$this->can_do($u_id, $admin_weight, $get_gym_id['gym_id'], 1)) {
            $this->ret($result, 0, '无权限进行操作');
        }

        $db->table('gym_admin')->where(['gym_admin_id' => $gym_admin_id])->delete();
        $this->record_log($u_id, $admin_weight, '删除了场馆id为' . $get_gym_id['gym_id'] . '的场地的一个管理员，管理员id为' . $gym_admin_id);
        $this->ret($result);
    }

    /**
     * 获取订场的订单列表
     */
    public function get_order_list()
    {
        $admin_weight = session('admin_weight');
        $u_id = session('u_id');
        $post = I('post.');

        $db = M();

        if (empty($admin_weight)) {
            $this->ret($result, -1, '未登录');
        } elseif ($admin_weight < 1) {
            $this->ret($result, 0, '无权限');
        } elseif ($admin_weight < 10) {
            if ($admin_weight == 1) {
                // 商家管理员
                $get_gym_id = $db->table('gym_role')
                    ->field('gym_id')
                    ->join('gym_admin on gym_admin.role_id = gym_role.role_id', 'LEFT')
                    ->where(['gym_admin_id' => $u_id])
                    ->find();
                if (empty($get_gym_id)) {
                    $this->ret($result, 0, '当前管理员账号不属于任何场馆');
                }
                if (empty($post['gym_id'])) {
                    $post['gym_id'] = $get_gym_id['gym_id'];
                } else {
                    if ($post['gym_id'] != $get_gym_id['gym_id']) {
                        $result = ['order_list' => []];
                        $this->ret($result);
                    }
                }
            } elseif ($admin_weight == 2) {
                // 商家BOSS
                $get_gym_list = $db->table('gym')->field('gym_id')->where(['founder' => $u_id])->select();
                if (empty($get_gym_list)) {
                    $result = ['order_list' => []];
                    $this->ret($result);
                }

                $gym_list = [];
                for ($i = 0, $len = count($get_gym_list); $i < $len; $i++) {
                    $gym_list[] = $get_gym_list[$i]['gym_id'];
                }
                if (!empty($post['gym_id'])) {
                    if (!in_array($post['gym_id'], $gym_list)) {
                        $result = ['order_list' => []];
                        $this->ret($result);
                    } else {
                        $post['gym_id'] = null;
                    }
                }
            }
        }

        $get_order_list = $db->table('book_order')
            ->field([
                'book_order.order_id',
                'phone_number' => 'user',
                'gym.gym_id',
                'gym.gym_name',
                'gym_site.gym_site_id' => 'gym_site_id',
                'gym_site.type_id',
                'gym_site.name' => 'gym_site_name',
                'date',
                'start_time',
                'end_time',
                'amount',
                'success_time',
            ])
            ->join('order_site on order_site.order_id = book_order.order_id')
            ->join('gym_site_time on gym_site_time.gym_site_time_id = order_site.gym_site_time_id')
            ->join('gym_site on gym_site.gym_site_id = gym_site_time.gym_site_id')
            ->join('gym on gym.gym_id = gym_site.gym_id')
            ->join('user on user.u_id = book_order.u_id')
            ->order('success_time desc');
        if (!empty($post['gym_id'])) {
            $get_order_list = $get_order_list->where(['gym.gym_id' => $post['gym_id']]);
        }
        if ($post['type_id'] == '0' || !empty($post['type_id'])) {
            $get_order_list = $get_order_list->where(['gym_site.type_id' => $post['type_id']]);
        }
        if (!empty($post['city_id'])) {
            $get_order_list = $get_order_list->where(['gym.city_id' => $post['city_id']]);
        }
        if ($admin_weight == 2) {
            $get_order_list = $get_order_list->where(['gym.gym_id' => ['in', $gym_list]]);
        }
        $get_order_list = $get_order_list->select();

        $gym_list = [];
        $order_list = [];
        $type_map = [
            '羽毛球',
            '篮球',
            '足球',
            '游泳',
            '健身',
            '网球',
            '台球',
            '其他',
        ];
        for ($i = 0, $len = count($get_order_list); $i < $len; $i++) {
            if (!isset($gym_list[$get_order_list[$i]['order_id']])) {
                $gym_list[$get_order_list[$i]['order_id']] = count($order_list);
                $order_list[] = [
                    'key' => $gym_list[$get_order_list[$i]['order_id']],
                    'order_id' => $get_order_list[$i]['order_id'],
                    'user' => $get_order_list[$i]['user'],
                    'gym_id' => $get_order_list[$i]['gym_id'],
                    'gym_name' => $get_order_list[$i]['gym_name'],
                    'amount' => $get_order_list[$i]['amount'],
                    'success_time' => $get_order_list[$i]['success_time'],
                    'detail' => '',
                ];
            }
            $order_index = $gym_list[$get_order_list[$i]['order_id']];
            $order_list[$order_index]['detail'] .= $get_order_list[$i]['gym_site_name'] . '(' . $type_map[$get_order_list[$i]['type_id']] . ') ' . $get_order_list[$i]['date'] . ' ' . $get_order_list[$i]['start_time'] . '-' . $get_order_list[$i]['end_time'] . ' ';
        }

        $result['order_list'] = $order_list;
        $this->ret($result);
    }

    /**
     * 申请管理下显示申请列表
     */
    public function get_apply_list()
    {
        $admin_weight = session('admin_weight');
        if ($admin_weight < 10) {
            $this->ret($result, 0, '只有超管可以查看申请列表');
        }

        $apply_list = M('apply_list')
            ->field([
                'apply_id',
                'user.phone_number' => 'user',
                'nick',
                'apply_time',
                'status',
                'last_time',
            ])
            ->join('user on user.u_id = apply_list.u_id')
            ->order('apply_time desc')
            ->select();
        for ($i = 0, $len = count($apply_list); $i < $len; $i++) {
            $apply_list[$i]['key'] = $i;
        }
        $result['apply_list'] = $apply_list;
        $this->ret($result);
    }

    /**
     * 同意用户申请成为商户
     */
    public function agree()
    {
        $apply_id = I('post.apply_id');
        $u_id = session('u_id');
        $admin_weight = session('admin_weight');
        if ($admin_weight < 10) {
            $this->ret($result, 0, '只有超管可以处理用户的申请');
        }

        $db = M('apply_list');
        $get_apply = $db->where(['apply_id' => $apply_id])->find();
        if ($get_apply['status'] == 1) {
            $this->ret($result, 0, '该用户之前已被批准成为商家');
        }
        $update['status'] = 1;
        $update['last_time'] = date('Y-m-d H:i:s');
        $db->where(['apply_id' => $apply_id])->save($update);
        $db->table('user')->where(['u_id' => $get_apply['u_id']])->setField('admin_weight', 2);
        $result['last_time'] = $update['last_time'];
        $this->record_log($u_id, $admin_weight, '同意了用户id为' . $get_apply['u_id'] . '的用户申请成为商户的请求');
        $this->ret($result);
    }

    /**
     * 拒绝用户申请成为商户
     */
    public function refuse()
    {
        $apply_id = I('post.apply_id');
        $u_id = session('u_id');
        $admin_weight = session('admin_weight');
        if ($admin_weight < 10) {
            $this->ret($result, 0, '只有超管可以处理用户的申请');
        }

        $db = M('apply_list');
        $get_apply = $db->where(['apply_id' => $apply_id])->find();
        if ($get_apply['status'] == 2) {
            $this->ret($result, 0, '该用户之前已被拒绝成为商家');
        }
        $update['status'] = 2;
        $update['last_time'] = date('Y-m-d H:i:s');
        $db->where(['apply_id' => $apply_id])->save($update);
        $result['last_time'] = $update['last_time'];
        $this->record_log($u_id, $admin_weight, '拒绝了用户id为' . $get_apply['u_id'] . '的用户申请成为商户的请求');
        $this->ret($result);
    }

    /**
     * 获取后台系统操作日志列表
     */
    public function get_log_list()
    {
        $u_id = session('u_id');
        $admin_weight = session('admin_weight');
        $get = I('get.');
        if ($admin_weight < 10) {
            $this->ret($result, 0, '只有超管可以查看操作日志');
        }

        $get_log_list = M('log')->field('log_id, u_id, account, operation_detail, time')->order('time desc');
        if (!empty($get['u_id'])) {
            $get_log_list = $get_log_list->where(['u_id' => $get['u_id']]);
        }
        $get_log_list = $get_log_list->select();
        for ($i = 0, $len = count($get_log_list); $i < $len; $i++) {
            $get_log_list[$i]['key'] = $i;
        }
        $result['list'] = $get_log_list;
        $this->ret($result);
    }

    /**
     * 验证是否有权限操作
     * @param int $u_id 用户id
     * @param int $admin_weight 权限权重，0、1、2、10
     * @param int $gym_id 场馆id
     * @param int $operation_id 操作id，当场馆管理员一定无的权限时传入0，场馆管理员一定可以有的操作传入true
     * @return boolean
     */
    private function can_do($u_id, $admin_weight, $gym_id, $operation_id = 0)
    {
        $db = M();
        if (empty($admin_weight)) {
            return false;
        }
        if ($admin_weight < 10) {
            if ($admin_weight == 1) {
                // 商家管理员
                $get_gym_admin = $db->table('gym_admin')->field('role_id')->where(['gym_admin_id' => $u_id])->find();
                $get_gym_role = $db->table('gym_role')->field('gym_id, operation_list')->where(['role_id' => $get_gym_admin['role_id']])->find();
                $operation_list = explode('|', $get_gym_role['operation_list']);
                if ($operation_id !== true && !in_array($operation_id, $operation_list)) {
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
    private function generate_string($length = 4)
    {
        // 密码字符集，可任意添加你需要的字符
        $chars = 'abcdefghijklmnopqrstuvwxyz1234567890';
        $string = '';
        for ($i = 0; $i < $length; $i++) {
            $string .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $string;
    }

    /**
     * 用于记录后台管理系统的各种操作日志
     */
    private function record_log($u_id, $admin_weight, $detail)
    {
        $db = M();
        if ($admin_weight == 1) {
            $get_admin = $db->table('gym_admin')->field('account')->where(['gym_admin_id' => $u_id])->find();
            $is_admin = 1;
            $account = $get_admin['account'];
        } else {
            $get_user = $db->table('user')->field('phone_number')->where(['u_id' => $u_id])->find();
            $is_admin = 0;
            $account = $get_user['phone_number'];
        }
        $db->table('log')->add([
            'u_id' => $u_id,
            'is_admin' => $is_admin,
            'account' => $account,
            'operation_detail' => $detail,
            'time' => date('Y-m-d H:i:s'),
        ]);
    }

    private function ret(&$result, $status = 1, $msg = "")
    {
        $result['status'] = $status;
        $result['msg'] = $msg;
        $this->ajaxReturn($result);
    }
}
