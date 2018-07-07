<?php
namespace Home\Controller;
use Think\Controller;
class IndexController extends Controller {
    private $redis;

    private function init_redis() {
        $this->redis = new \Redis();
        $this->redis->connect(C('redis_host'), C('redis_port'));
    }

    /**
     * 检测登录态是否失效
     */
    public function islogin() {
        $u_id = session('u_id');
        if ($u_id == null) {
            $this->ret($result, -1, '未登录');
        } else {
            $get_user_info = M('user')->field('nick, phone_number, avatar_url, gender, balance')->where(['u_id' => $u_id])->find();
            if ($get_user_info == null) {
                $this->ret($result, 0, 'session指向的用户不存在');
            } else {
                $this->ret($get_user_info);
            }
        }
    }

    /**
     * 注册时发送验证码，重发间隔设置为60秒
     */
    public function reg_sendsms() {
        $second = 60; // 重发的时间间隔，单位为秒
        $mobile = I('get.phone_number');
        // 验证是否正确的手机号码
        if (preg_match('/^(13[0-9]|14[579]|15[0-3,5-9]|16[6]|17[0135678]|18[0-9]|19[89])\\d{8}$/', $mobile) == 0) {
            $this->ret($result, 0, '手机号码不符合要求');
        }
        // 检测手机号是否已注册
        if (!empty(M('user')->where(['phone_number' => $mobile])->find())) {
            $this->ret($result, 0, '该手机号已注册');
        }
        $this->init_redis();
        // 检测该手机号是否在 $second 秒内发送过验证码
        if ($this->redis->get($mobile) != false) {
            $this->ret($result, 0 ,'请60秒后再重发');
        }
        // 生成四位数验证码
        $code = rand(1000, 9999);
        // 拼接短信字符串并发送
        $msg = $code . '为您的登录验证码，请于' . ($second / 60) . '分钟内填写。如非本人操作，请忽略本短信。';
        $this->sendsms($msg, $mobile);
        // 在redis上存储并设置时效
        $this->redis->set($mobile, $code);
        $this->redis->EXPIRE($mobile, $second);

        $this->ret($result);
    }

    /**
     * 注册
     */
    public function register() {
        $db_user = M('user');
        $post = I('post.');
        $phone_number = trim($post['phone_number']);
        $nick = empty($post['nick']) ? '' : trim($post['nick']);
        $password = trim($post['password']);
        $gender = trim($post['gender']);
        $code = trim($post['code']);
        
        if (preg_match('/^(13[0-9]|14[579]|15[0-3,5-9]|16[6]|17[0135678]|18[0-9]|19[89])\\d{8}$/', $phone_number) == 0) {
            $this->ret($result, 0, '手机号码不符合要求');
        }
        if (!empty($db_user->where(['phone_number' => $phone_number])->find())) {
            $this->ret($result, 0, '该手机号码已被注册');
        }
        if (empty($password)) {
            $this->ret($result, 0, '密码不能为空');
        }
        if ($gender == 0) {
            $avatar_url = C('default_female_head_picture');
        } elseif ($gender == 1) {
            $avatar_url = C('default_male_head_picture');
        } else {
            $this->ret($result, 0, '性别数据错误');
        }
        
        $this->init_redis();
        // 检测验证码是否正确或过期
        if ($this->redis->get($phone_number) == false) {
            $this->ret($result, 0, '验证码已经过期');
        } elseif ($code != $this->redis->get($phone_number)) {
            $this->ret($result, 0, '验证码错误');
        }

        $user_info = [
            'nick' => $nick,
            'phone_number' => $phone_number,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'avatar_url' => $avatar_url,
            'gender' => $gender,
            'balance' => '0.00',
            'admin_weight' => 0,
        ];
        $get_u_id = $db_user->add($user_info);
        if ($get_u_id === false) {
            $this->ret($result, 0, '数据库插入出错，请重试或联系管理员');
        } else {
            session('u_id', $get_u_id);
            unset($user_info['password']);
            $this->ret($user_info);
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
        // 设置session
        session('u_id', $user_info['u_id']);
        // 返回数据
        $result['nick'] = $user_info['nick'];
        $result['avatar_url'] = $user_info['avatar_url'];
        $result['gender'] = $user_info['gender'];
        $result['balance'] = $user_info['balance'];
        $this->ret($result);
    }

    /**
     * 退出登录
     */
    public function logout() {
        session('u_id', null);
        $this->ret($result);
    }

    /**
     * 获取所有的地级市
     * 从city数据表中读取数据并按照文档接口格式整理返回给前端
     */
    public function get_city() {
        $city_list = M('city')
            ->select();
        if($city_list === false){
            $this->ret($result,0,'数据库查询出错');
        } else {
            $result['city_list'] = $city_list;
            $this->ret($result);
        }
    }

    /**
     * 获取热门场馆列表
     * 根据前端传过来city_id作为筛选条件
     * 对数据表进行必要的联表操作（如果需要）
     * 以star从高到低排序
     * 返回符合接口文档要求的格式的数据
     */
    public function get_hot_gym() {
        $city_id = I('get.city_id');
        $db_gym = M('gym');
        $gym_list = $db_gym
            ->join('city on city.city_id = gym.city_id', 'LEFT')
            ->field([
                'gym_id',
                'gym_name',
                'star',
                'cover',
                'concat(city.city_name, gym.detail_address)' => 'address'
            ])
            ->where(['gym.city_id' => $city_id])
            ->where(['gym.is_delete' => '0'])
            ->order('star desc')
            ->select();

        for ($i = 0, $len = count($gym_list); $i < $len; $i++) {
            $gym_list[$i]['star'] = (int)$gym_list[$i]['star']; 
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
        if ($gym_list === false) {
            $this->ret($result, 0, '数据库查询出错');
        } else {
            $result['gym_list'] = $gym_list;
            $this->ret($result);
        }
    }

    /**
     * 获取对应运动类型的场馆信息列表
     * 参考get_hot_gym来实现
     */
    // $city_id,$type_id = '8',$sort_type = '0'
    public function get_type_gym() {
        if(!empty(I('get.type_id'))){
            $type_id = I('get.type_id');
        } else {
            $type_id = 100;
        }
        if(!empty(I('get.sort_type'))){
            $sort_type = I('get.sort_type');
        }
        $db_gym = M('gym');
        $city_id = I('get.city_id');

        $gym_list = M('gym')
            ->join('city on city.city_id = gym.city_id', 'LEFT')
            ->field([
                'gym_id',
                'gym_name',
                'star',
                'cover',
                'concat(city.city_name, gym.detail_address)' => 'address'
            ])
            ->where(['gym.is_delete' => '0'])
            ->where([
                'gym.city_id' => $city_id,
            ]);

            //type_id 默认值是8， type_id等于8时， 返回所有场馆
            // if($type_id != 8){
            //     $gym_list = $gym_list->where([
            //         'gym.type_id' => $type_id
            //     ]);
            // }
            if($sort_type == 1){
                $gym_list = $gym_list->order('star desc')->select();
            } else {
                $gym_list = $gym_list->select();
            }




            for ($i = 0, $len = count($gym_list); $i < $len; $i++) {
                // $gym_list[$i]['key'] = $i;
                // 判断其type_id
                $gym_list[$i]['star'] = (int)$gym_list[$i]['star']; 
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


            if($type_id == 100){
                $gym_list1 = $gym_list;
            } else {
                for ($i = 0, $len = count($gym_list); $i < $len; $i++){
                    if($gym_list[$i]['type_id']==$type_id){
                        $gym_list1.push($gym_list[$i]);
                    }
                }
            }



            if ($gym_list1 === false) {
                $this->ret($result, 0, '数据库查询出错');
            } else {
                $result['gym_list'] = $gym_list1;
                $this->ret($result);
            }

    }

    /**
     * 获取我订过的场馆信息列表
     */
    public function get_my_historical_gym() {
        // 读取session里面的u_id，用作查询数据表的筛选条件
        $u_id = session('u_id');
        if ($u_id == null) {
            $this->ret($result, -1, '未登录');
        }
        $gym_list = M('book_order')
            ->join('order_site on order_site.order_id = book_order.order_id','LEFT')
            ->join('gym_site_time on gym_site_time.gym_site_time_id = order_site.gym_site_time_id','LEFT')
            ->join('gym_site on gym_site.gym_site_id = gym_site_time.gym_site_id','LEFT')
            ->join('gym on gym.gym_id = gym_site.gym_id','LEFT')
            ->join('city on city.city_id = gym.city_id','LEFT')
            ->field([
                'gym.gym_id',
                'gym_name',
                'star',
                'cover',
                'concat(city.city_name, gym.detail_address)' => 'address'
            ])
            ->where(['book_order.u_id' => $u_id])
            ->where(['gym.is_delete' => '0'])
            ->group('gym.gym_id')
            ->select();
        
        for ($i = 0, $len = count($gym_list); $i < $len; $i++) {
            // $gym_list[$i]['key'] = $i;
            // 判断其type_id
            $gym_list[$i]['star'] = (int)$gym_list[$i]['star'];
            $get_gym_site = M('gym_site')->field('type_id')->where(['gym_id' => $gym_list[$i]['gym_id']])->select();
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
        

        if ($gym_list === false) {
            $this->ret($result, 0, '数据库查询出错');
        } else {
            $result['gym_list'] = $gym_list;
            $this->ret($result);
        }
        
    }

    /**
     * 获取某个场馆的具体信息
     */
    public function get_gym_detail() {
        $gym_id	 = I('get.gym_id');
        $gym_list = M('gym')
            ->join('city on city.city_id = gym.city_id', 'LEFT')
            ->field([
                'gym_id',
                'gym_name',
                'star',
                'cover',
                'concat(city.city_name, gym.detail_address)' => 'address',
                'contact_info',
                'detail_msg'
            ])
            ->where(['gym.gym_id' => $gym_id])
            ->find();
        $gym_list['star'] = (int)$gym_list['star'];
                $get_gym_site = M('gym_site')->field('type_id')->where(['gym_id' => $gym_list['gym_id']])->select();
                $num = count($get_gym_site);
                if ($num == 0) {
                    $gym_list['type_id'] = 9;
                } else {
                    $gym_list['type_id'] = $get_gym_site[0]['type_id'];
                    if ($num > 1) {
                        for ($j = 1; $j < $num; $j++) {
                            if ($get_gym_site[$j]['type_id'] != $get_gym_site[0]['type_id']) {
                                $gym_list['type_id'] = 8;
                                break;
                            }
                        }
                    }
                }

        if ($gym_list === false) {
            $this->ret($result, 0, '数据库查询出错');
        } else {
            $result['detail'] = $gym_list;
            $this->ret($result);
        }
    }

    /**
     * 获取一个场馆近七天的场次信息
     */
    public function get_a_gym_site_feiqi() {
        $gym_id	 = I('get.gym_id');

        $time = $time != '' ? $time : time();
        $date = [];
        for ($i=0; $i<  7; $i++){
            $date[$i] = date('Y-m-d' ,strtotime( '+' . $i .' days', $time));
        }

        $map['date'] = array('in',$date);
        // dump($date);
        // $gym_id = 1;
        $gym_site_list = M('gym_site_time')
            ->join('gym_site on gym_site.gym_site_id = gym_site_time.gym_site_id','LEFT')
            ->join('gym on gym.gym_id = gym_site.gym_id')
            ->field([
                'gym_site_time_id',
                'gym_site_time.gym_site_id',
                'gym_site.name',
                'gym_site.type_id',
                'start_time',
                'end_time',
                'date',
                'price',
                'number'
            ])
            ->where([
                'gym.gym_id' => $gym_id
            ])
            ->where($map)
            ->select();
        
        for ($i = 0, $len = count($gym_site_list); $i < $len; $i++){
            $gym_site_list[$i]['key'] = $i;
            $num = count(M('order_site')->where(['gym_site_time_id'=>$gym_site_list[$i]['gym_site_time_id']])->select());
            $gym_site_list[$i]['remain'] = $gym_site_list[$i]['number']-$num;
        }

        $result = array();
        foreach($gym_site_list as $k=>$v){
            $key = $v['type_id'];
            if(!array_key_exists($key, $result)) $result[$key] =array();
            $result[$key][] = $v;

        }

        // for ($i = 0, $len = count($result); $i < $len; $i++){
        //     $result1 = array();
        //     foreach($result[$i] as $k=>$v){
        //         $key = $v['gym_site_id'];
        //         if(!array_key_exists($key, $result1)) $result1[$key] =array();
        //         $result1[$key][] = $v;
        //         $result[$i] = $result1;
        //     }
        // }
        foreach($result as $k => $v){
            // $result1 = array();
            // foreach($value as $k=>$v){
            //     $key = $v['gym_site_id'];
            //     if(!array_key_exists($key, $result1)) $result1[$key] =array();
            //     $result1[$key][] = $v;
            // }
            // $value = $result1;
            
            $result[$k] = $this->group_same_key($result[$k],'gym_site_id');
            
        }

        

        $this->ret($result);
        // if ($gym_site_list === false) {
        //     $this->ret($result, 0, '数据库查询出错');
        // } else {
        //     $result['gym_site_list'] = $gym_site_list;
        //     $this->ret($result);
        // }
        
        
        
    }

    private function group_same_key($arr,$key){
        $new_arr = array();
        foreach($arr as $k=>$v ){
            $new_arr[$v[$key]][] = $v;
        }
        return $new_arr;
    }

    public function get_a_gym_site() {
        $gym_id = I('get.gym_id');
        $gym_site = M('gym_site')
            ->join('gym on gym.gym_id = gym_site.gym_id','LEFT')
            ->field([
                'gym_site_id',
                'gym_site.name',
                'gym_site.type_id'
            ])
            ->where(['gym_site.gym_id' => $gym_id])
            ->select();
        
        $time = $time != '' ? $time : time();
        $date = [];
        for ($i=0; $i<  7; $i++){
            $date[$i] = date('Y-m-d' ,strtotime( '+' . $i .' days', $time));
        }
        $map['date'] = array('in',$date);
        
        foreach($gym_site as $k=>$v) {
            $gym_site_time = M('gym_site_time')
                ->where(['gym_site_time.gym_site_id' => $v['gym_site_id']])
                ->where($map)
                ->select();
            
            for ($i = 0, $len = count($gym_site_time); $i < $len; $i++){
                $gym_site_time[$i]['key'] = $i;
                $num = count(M('order_site')->where(['gym_site_time_id'=>$gym_site_time[$i]['gym_site_time_id']])->select());
                $gym_site_time[$i]['remain'] = $gym_site_time[$i]['number']-$num;
            }
            // dump($k);
            // dump($gym_site_time);
            $gym_site[$k]['list'] = $gym_site_time;
        }
        // 


        $new_array = $this->group_same_key($gym_site,'type_id');

        $gym_site_time_result= [];
        $i=0;
        foreach($new_array as $k=>$v) {
            
            $data['key'] = $i;
            $i++;
            $data['type_id'] = $k;
            // $data['name'] = $v[0]['name'];
            $data['list'] = $v;
            $j=0;
            foreach($data['list'] as $k=>$v){
                $data['list'][$k]['key'] = $j;
                $j++;
            }
            // dump($data);
            array_push($gym_site_time_result,$data);
        }
        // $this->ret($gym_site_time_result);

        if ($gym_site_time_result === false) {
            $this->ret($result, 0, '数据库查询出错');
        } else {
            $result['gym_site_list'] = $gym_site_time_result;
            $this->ret($result);
        }

    }


    /**
     * 订场
     */
    public function reserve_gym() {
        $id_list = I('post.id_list');
        // $id_list = [1,2,3];

        $u_id = session('u_id');
        if ($u_id == null) {
            $this->ret($result, -1, '未登录');
        }
        $u_id =1;
        $db_gym = M('gym_site_time');
        $amount = 0;
        for($i = 0, $len = count($id_list); $i < $len; $i++){
            $gym_site_time = $db_gym->where(['gym_site_time.gym_site_id' => $id_list[$i]])->find();
            $amount += $gym_site_time['price'];
        }

        // 判断是否有余量
        for($i = 0, $len = count($id_list); $i < $len; $i++){
            $order_site_count = count(M('order_site')->where(['order_site.gym_site_time_id' => $id_list[$i]])->select());
            // dump($order_site_count);
            $gym_site = M('gym_site_time')->join('gym_site on gym_site.gym_site_id = gym_site_time.gym_site_id','LEFT')->find();
            $gym_site_number = $gym_site['number'];
            // dump($gym_site_number);
            if((int)$gym_site_number - $order_site_count <1){
                $this->ret($result, 0, '没有余量');
            }
        }



        $user = M('user')->where(['user.u_id' => $u_id])->find();
        if((int)$user['balance']-(int)$amount<0){
            $this->ret($result, 0, '余额不足');
        }
        $new_balance = (int)$user['balance']-(int)$amount;
        M('user')->where(['user.u_id' => $u_id])->setField('balance',$new_balance);
        $time = date('Y-m-d H:i:s',time());
        $data['success_time'] = $time;
        $data['u_id'] = $u_id;
        $data['amount'] = $amount;
        $order_id = M('book_order')->add($data);

        
        for($i = 0, $len = count($id_list); $i < $len; $i++){
            $order[$i]['order_id'] = $order_id;
            $order[$i]['gym_site_time_id'] = $id_list[$i];
            M('order_site')->add($order[$i]);
        }

        $this->ret($result);

    }

    /**
     * 获取消息列表
     */
    public function get_message_list() {
        $u_id = session('u_id');
        if ($u_id == null) {
            $this->ret($result, -1, '未登录');
        }
        $u_id = 1;
        $is_read = I('get.is_read');

        M('message')->where(['u_id' => $u_id])->setField('is_read','1');


        $message_list = M('message')
            ->field([
                'message_id',
                'is_read',
                'content',
                'from',
                'time'
            ])
            ->where(['u_id' => $u_id]);
            // $is_read == 0 || $is_read == 1
        if($is_read == '0' || $is_read == '1'){
            $message_list = $message_list
                ->where(['message.is_read' => $is_read])
                ->select();
        } else {
            $message_list = $message_list->select();
        }
        if ($message_list === false) {
            $this->ret($result, 0, '数据库查询出错');
        } else {
            $result['message_list'] = $message_list;
            $this->ret($result);
        }
    }

    /**
     * 阅读消息
     */
    public function read_message() {
        $message_id = I('get.message_id');
        $message_detail = M('message')
            ->field([
                'message_id',
                'is_read',
                'content',
                'from',
                'time'
            ])
            ->where(['message.message_id' => $message_id])
            ->find();

        //这里把is_read置为1
        $read = M('message')
            ->where(['message.message_id' => $message_id])
            ->setField('is_read','1');

        if ($message_detail === false) {
            $this->ret($result, 0, '数据库查询出错');
        } else {
            $result['detail'] = $message_detail;
            $this->ret($result);
        }
    }

    /**
     * 获取我的订单列表
     */
    public function get_order_list() {
        // $date1 = date('y-m-d H:i:s',time());
        // $date2 = date('y-m-d H:i:s',time());
        // echo strtotime($date1);
        // echo "     ";
        // echo strtotime("+1 day");

        // $u_id = 1;
        // $order_list = 

        $u_id = session('u_id');
        if ($u_id == null) {
            $this->ret($result, -1, '未登录');
        }
        $gym_list = M('book_order')
            ->join('order_site on order_site.order_id = book_order.order_id','LEFT')
            ->join('gym_site_time on gym_site_time.gym_site_time_id = order_site.gym_site_time_id','LEFT')
            ->join('gym_site on gym_site.gym_site_id = gym_site_time.gym_site_id','LEFT')
            ->join('gym on gym.gym_id = gym_site.gym_id','LEFT')
            ->field([
                'book_order.order_id',
                'gym.gym_id',
                'gym.gym_name',
                'book_order.amount'=>'price',
                'book_order.success_time'=>'time'
            ])
            // ->group('book_order.order_id')
            ->distinct(true)
            ->where(['book_order.u_id'=>$u_id])
            ->select();

        for ($i = 0, $len = count($gym_list); $i < $len; $i++) {
            // $gym_list[$i]['key'] = $i;
            // 判断其type_id
            $get_gym_site = M('gym_site')->field('type_id')->where(['gym_id' => $gym_list[$i]['gym_id']])->select();
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
        for ($i = 0, $len = count($gym_list); $i < $len; $i++){

            $order_id = $gym_list[$i]['order_id'];
            $gym_site_time = M('gym_site_time')
            ->join('order_site on order_site.gym_site_time_id = gym_site_time.gym_site_time_id','LEFT')
            ->join('gym_site on gym_site.gym_site_id = gym_site_time.gym_site_id')
            ->where(['order_site.order_id' => $order_id])
            ->field([
                'gym_site_time.date',
                'gym_site.name',
                'gym_site_time.price',
                'gym_site.type_id',
                'gym_site_time.start_time',
                'gym_site_time.end_time'
            ])
            ->select();
            
            
            $is_over = 1;
            $now = strtotime('now');
            for ($j = 0, $len2 = count($gym_site_time); $j < $len2; $j++) {
                $date_time = strtotime($gym_site_time[$j]['date'].' '.$gym_site_time[$j]['end_time']);
                if($now<$date_time){
                    $is_over = 0;
                    break;
                }
            }
            // dump($is_over);
            $gym_list[$i]['is_over'] = $is_over;
            switch($gym_list[$i]['type_id'])
            {
                case 0:
                $gym_list[$i]['type_name'] = '羽毛球';break;
                case 1:
                $gym_list[$i]['type_name'] = '篮球';break;
                case 2:
                $gym_list[$i]['type_name'] = '足球';break;
                case 3:
                $gym_list[$i]['type_name'] = '游泳';break;
                case 4:
                $gym_list[$i]['type_name'] = '健身';break;
                case 5:
                $gym_list[$i]['type_name'] = '网球';break;
                case 6:
                $gym_list[$i]['type_name'] = '台球';break;
                case 7:
                $gym_list[$i]['type_name'] = '其他';break;
                case 8:
                $gym_list[$i]['type_name'] = '综合';break;
                case 9:
                $gym_list[$i]['type_name'] = '未知';break;
            }
        }
        // dump($gym_list);
        if ($gym_list === false) {
            $this->ret($result, 0, '数据库查询出错');
        } else {
            $result['order_list'] = $gym_list;
            $this->ret($result);
        }
        
    }

    /**
     * 查看订场订单详情
     * 未完成，缺少字段 type_name time_msg
     */
    public function get_order_detail() {
        $order_id = I('get.order_id');
        $order = M('book_order')
           ->join('order_site on order_site.order_id = book_order.order_id','LEFT')
           ->join('gym_site_time on gym_site_time.gym_site_time_id = order_site.gym_site_time_id','LEFT')
           ->join('gym_site on gym_site.gym_site_id = gym_site_time.gym_site_id','LEFT')
           ->join('gym on gym.gym_id = gym_site.gym_id','LEFT')
           ->field([
               'book_order.order_id',
               'gym.gym_id',
               'gym.gym_name',
               'book_order.amount'=>'price',
               'book_order.success_time'=>'time',
               'gym.detail_address'
           ])
           ->where(['book_order.order_id' => $order_id])
           ->find();
           
        if($order){
            
        } else {
            $this->ret($result, 0, '出错');
        }
        
        $gym_site_time = M('gym_site_time')
            ->join('order_site on order_site.gym_site_time_id = gym_site_time.gym_site_time_id','LEFT')
            ->join('gym_site on gym_site.gym_site_id = gym_site_time.gym_site_id')
            ->where(['order_site.order_id' => $order_id])
            ->field([
                'gym_site_time.date',
                'gym_site.name',
                'gym_site_time.price',
                'gym_site.type_id',
                'gym_site_time.start_time',
                'gym_site_time.end_time'
            ])
            ->select();
        
        $get_gym_site = M('gym_site')->field('type_id')->where(['gym_id' => $order['gym_id']])->select();
        $num = count($get_gym_site);
        if ($num == 0) {
            $order['type_id'] = 9;
        } else {
            $order['type_id'] = $get_gym_site[0]['type_id'];
            if ($num > 1) {
                for ($j = 1; $j < $num; $j++) {
                    if ($get_gym_site[$j]['type_id'] != $get_gym_site[0]['type_id']) {
                        $order['type_id'] = 8;
                        break;
                    }
                }
            }
        }

        




        for ($i = 0, $len = count($gym_site_time); $i < $len; $i++){
            switch($gym_site_time[$i]['type_id'])
            {
                case 0:
                $gym_site_time[$i]['type_name'] = '羽毛球';break;
                case 1:
                $gym_site_time[$i]['type_name'] = '篮球';break;
                case 2:
                $gym_site_time[$i]['type_name'] = '足球';break;
                case 3:
                $gym_site_time[$i]['type_name'] = '游泳';break;
                case 4:
                $gym_site_time[$i]['type_name'] = '健身';break;
                case 5:
                $gym_site_time[$i]['type_name'] = '网球';break;
                case 6:
                $gym_site_time[$i]['type_name'] = '台球';break;
                case 7:
                $gym_site_time[$i]['type_name'] = '其他';break;
                case 8:
                $gym_site_time[$i]['type_name'] = '综合';break;
                case 9:
                $gym_site_time[$i]['type_name'] = '未知';break;
            }
        }
        
        $is_over = 1;
        $now = strtotime('now');
        for ($i = 0, $len = count($gym_site_time); $i < $len; $i++) {
            $date_time = strtotime($gym_site_time[$i]['date'].' '.$gym_site_time[$i]['end_time']);
            
            if($now<$date_time){
                $is_over = 0;
                break;
            }
        }
        
        $order['is_over'] = $is_over;
        $order['gym_site_time'] = $gym_site_time;
        
        switch($order['type_id'])
            {
                case 0:
                $order['type_name'] = '羽毛球';break;
                case 1:
                $order['type_name'] = '篮球';break;
                case 2:
                $order['type_name'] = '足球';break;
                case 3:
                $order['type_name'] = '游泳';break;
                case 4:
                $order['type_name'] = '健身';break;
                case 5:
                $order['type_name'] = '网球';break;
                case 6:
                $order['type_name'] = '台球';break;
                case 7:
                $order['type_name'] = '其他';break;
                case 8:
                $order['type_name'] = '综合';break;
                case 9:
                $order['type_name'] = '未知';break;
            }

            if($order){
                $result['detail'] = $order;
                $this->ret($result);
            } else {
                $this->ret($result, 0, '出错');
            }
        


    }

    /**
     * 申请成为商家
     */
    public function to_be_merchant() {
        $u_id = session('u_id');
        if ($u_id == null) {
            $this->ret($result, -1, '未登录');
        }
        $data['u_id'] = $u_id;
        $data['apply_time'] = date('Y-m-d H:i:s',time());
        $data['status'] = 0;
        $data['last_time'] = date('Y-m-d H:i:s',time());

        $add_result = M('apply_list')
            ->add($data);
        
        if($add_result){
            $this->ret($result);
        } else {
            $this->ret($result, 0, '出错');
        }
    }

    /**
     * 用户评价一个订单
     */
    public function comment_order() {
        $u_id = session('u_id');
        if ($u_id == null) {
            $this->ret($result, -1, '未登录');
        }
        $u_id = 1;
        $order_id = I('post.order_id');
        $star = I('post.star');
        $content = I('post.content');
        $time = date('Y-m-d H:i:s',time());

        $data['u_id'] = $u_id;
        $data['order_id'] = $order_id;
        $data['star'] = $star;
        $data['content'] = $content;
        $data['comment_time'] = $time;

        $add_comment = M('comment')
            ->add($data);
        

        $gym = M('order_site')
            ->join('gym_site_time on gym_site_time.gym_site_time_id = order_site.gym_site_time_id','LEFT')
            ->join('gym_site on gym_site.gym_site_id = gym_site_time.gym_site_id','LEFT')
            ->where(['order_site.order_id' => $order_id])
            ->find();
        $gym_id = $gym['gym_id'];
        



        $comment_list = M('comment')
            ->join('order_site on order_site.order_id = comment.order_id','LEFT')
            ->join('gym_site_time on gym_site_time.gym_site_time_id = order_site.gym_site_time_id','LEFT')
            ->join('gym_site on gym_site.gym_site_id = gym_site_time.gym_site_id','LEFT')
            ->where(['gym_site.gym_id' => $gym_id])
            ->field([
                'comment_id',
                'comment.star'
            ])
            ->distinct(true)
            ->select();
        
        $new_star_total = 0;
        for ($i = 0, $len = count($comment_list); $i < $len; $i++) {
            $new_star_total += (int)$comment_list[$i]['star'];
        }
        $new_star = (int)$new_star_total/$len;
        
        // 更新评分
        M('gym')->where(['gym.gym_id' => $gym_id])->setField('star',$new_star);


        if($add_comment){
            $result['comment_time'] = $time;
            $this->ret($result);
        } else {
            $this->ret($result, 0, '出错');
        }
    }

    /**
     * 获取一个场馆的所有评价
     */
    public function get_gym_comment() {
        $gym_id = I('post.gym_id');
        $gym_comment_list = M('comment')
            ->join('user on user.u_id = comment.u_id','LEFT')
            ->join('book_order on book_order.order_id = comment.order_id','LEFT')
            ->join('order_site on order_site.order_id = book_order.order_id','LEFT')
            ->join('gym_site_time on gym_site_time.gym_site_time_id = order_site.gym_site_time_id','LEFT')
            ->join('gym_site on gym_site.gym_site_id = gym_site_time.gym_site_id','LEFT')
            ->join('gym on gym.gym_id = gym_site.gym_id','LEFT')
            ->field([
                'comment_id',
                'user.u_id',
                'user.phone_number',
                'user.nick',
                'user.avatar_url',
                'comment.star',
                'content',
                'comment_time'
            ])
            ->where(['gym_site.gym_id' => $gym_id])
            // ->group('comment.comment_id')
            ->distinct(true)
            ->select();
            

        if ($gym_comment_list === false) {
            $this->ret($result, 0, '数据库查询出错');
        } else {
            $result['comment_list'] = $gym_comment_list;
            $this->ret($result);
        }
        
    }

    /**
     * 充值
     */
    public function recharge(){
        $u_id = session('u_id');
        if ($u_id == null) {
            $this->ret($result, -1, '未登录');
        }
        $number = I('get.number');


        $data['u_id'] = $u_id;
        $data['amount'] = $number;
        $data['recharge_time'] = date('Y-m-d H:i:s',time());
        $user = M('user')->where(['user.u_id' => $u_id])->find();

        $new_balance = (int)$user['balance']+(int)$number;

        M('recharge_order')->add($data);
        M('user')->where(['user.u_id' => $u_id])->setField('balance',$new_balance);
        $result['balance'] = $new_balance;
        $this->ret($result);
    }

    /**
     * 获取一个场馆的场地信息
     */
    public function get_gym_site_list() {
        $gym_id = I('post.gym_id');
        $gym_site_list = M('gym_site')
            ->join('gym on gym.gym_id = gym_site.gym_id','LEFT')
            ->where(['gym.gym_id' => $gym_id])
            ->field([
                'gym_site_id',
                'gym_site.name' => 'name',
                'gym_site.number',
                'type_id'
            ])
            ->select();

        for ($i = 0, $len = count($gym_site_list); $i < $len; $i++){
            $gym_site_list[$i]['key'] = $i;
        }
        if ($gym_site_list === false) {
            $this->ret($result, 0, '数据库查询出错');
        } else {
            $result['gym_site_list'] = $gym_site_list;
            $this->ret($result);
        }
    }







    /**
     * 上传图片
     */
    public function upload_picture() {
        header('Access-Control-Allow-Headers: Content-Type, authorization, x-requested-with');
        $upload = new \Think\Upload(); // 实例化上传类
        $upload->maxSize = 0 ; // 设置附件上传大小
        $upload->exts = array('jpg', 'gif', 'png', 'jpeg'); // 设置附件上传类型
        $upload->rootPath = './Upload/picture/'; // 设置附件上传根目录
        $upload->savePath = ''; //设置附件保存目录
        $upload->saveName = 'uniqid';//设置附件文件名
        $info = $upload->upload();
        if (!$info) { // 上传错误
            $this->ret($result, 0, $upload->getError());
        } else {// 上传成功 获取上传文件信息
            foreach ($info as $file) {
                $result['url'] = C('domain_url') .'Upload/picture/' . $file['savepath'] . $file['savename'];
            }
            $this->ret($result);
        }
    }

    /**
     * 发送短信
     * @param  string  $msg         短信内容
     * @param  string  $mobile      手机号码
     * @param  string  $nationcode  国家码，默认86
     * @return boolean
     */
    private function sendsms($msg, $mobile, $nationcode = '86') {
        $sdkappid = C('sms_sdkappid');
        $appkey = C('sms_appkey');
        $random_number = rand();
        $time = time();
        $sig = hash("sha256", 'appkey=' . $appkey . '&random=' . $random_number . '&time=' . $time . '&mobile=' . $mobile);

        $post_data = [
            "ext" => '',
            "extend" => '',
            "msg" => $msg,
            "sig" => $sig,
            "tel" => [
                "mobile" => $mobile,
                "nationcode" => $nationcode
            ],
            "time" => $time,
            "type" => 0
        ];

        $url = 'https://yun.tim.qq.com/v5/tlssmssvr/sendsms?sdkappid=' . $sdkappid . '&random=' . $random_number;
        $result = $this->curl_post($url, $post_data);

        if (0 == $result['result']) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 使用curl POST数据
     * @param  string $url
     * @param  mixed  $post_data
     * @return mixed 应答的json数据 / 失败返回false
     */
    private function curl_post($url, $post_data) {
        if (!function_exists('curl_init')) {
            exit('curl extension required!');
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        $ret = curl_exec($curl);
        // if (false == $ret) {
        //     $error_msg = curl_error($curl);
        // }
        curl_close($curl);

        return json_decode($ret, true);
    }

    private function ret(&$result, $status = 1, $msg = "") {
        $result['status'] = $status;
        $result['msg'] = $msg;
        $this->ajaxReturn($result);
    }
}