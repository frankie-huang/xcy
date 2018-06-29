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
        $gym_list = M('gym')
            ->join('city on city.city_id = gym.city_id', 'LEFT')
            ->field([
                'gym_id',
                'gym_name',
                'star',
                'cover',
                'type_id',
                'concat(city.city_name, gym.detail_address)' => 'address'
            ])
            ->where(['gym.city_id' => $city_id])
            ->order('star desc')
            ->select();
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
    public function get_type_gym($city_id,$type_id = '8',$sort_type = '0') {
        if(empty(I('get.type_id')!==true)){
            $type_id = I('get.type_id');
        }
        if(empty(I('get.sort_type')!==true)){
            $sort_type = I('get.sort_type');
        }
        $gym_list = M('gym')
            ->join('city on city.city_id = gym.city_id', 'LEFT')
            ->field([
                'gym_id',
                'gym_name',
                'star',
                'cover',
                'type_id',
                'concat(city.city_name, gym.detail_address)' => 'address'
            ])
            ->where([
                'gym.city_id' => $city_id,
            ]);

            //type_id 默认值是8， type_id等于8时， 返回所有场馆
            if($type_id != 8){
                $gym_list = $gym_list->where([
                    'gym.type_id' => $type_id
                ]);
            }
            if($sort_type == 1){
                $gym_list = $gym_list->order('star desc')->select();
            } else {
                $gym_list = $gym_list->select();
            }
            if ($gym_list === false) {
                $this->ret($result, 0, '数据库查询出错');
            } else {
                $result['gym_list'] = $gym_list;
                $this->ret($result);
            }

    }

    /**
     * 获取我订过的场馆信息列表  
     */
    public function get_my_historical_gym() {
        // 读取session里面的u_id，用作查询数据表的筛选条件
        $u_id = 1;
        // 请继续实现代码
        // $gym_list = M('gym')
        //     ->join('gym_site on gym_site.gym_id = gym.gym_id','LEFT')
        //     ->join('book_order on book_order.gym_site_id = gym_site.gym_site_id','LEFT')
        //     ->join('city on city.city_id = gym.city_id', 'LEFT')
            // ->field([
            //     'gym_id',
            //     'u_id',
            //     'gym_name',
            //     'star',
            //     'cover',
            //     'type_id',
            //     'concat(city.city_name, gym.detail_address)' => 'address'
            // ])
        //     ->where(['book_order.u_id' => $u_id])
        //     ->select();
        //     if ($gym_list === false) {
        //         $this->ret($result, 0, '数据库查询出错');
        //     } else {
        //         $result['gym_list'] = $gym_list;
        //         $this->ret($result);
        //     }
        // $gym_list = M('gym')
        //     ->join('gym_site on gym_site.gym_id = gym.gym_id','LEFT')
        //     ->join('book_order on book_order.gym_site_id = gym_site.gym_site_id','LEFT')
        //     ->join('city on city.city_id = gym.city_id', 'LEFT')
        //     ->field([
        //         'gym.gym_id',
        //         'u_id',
        //         'gym_name',
        //         'star',
        //         'cover',
        //         'type_id',
        //         'concat(city.city_name, gym.detail_address)' => 'address'
        //     ])
        //     ->where(['book_order.u_id' => $u_id])
        //     ->select();



        $gym_list = M('book_order')
            ->join('gym_site on gym_site.gym_site_id = book_order.gym_site_id','LEFT')
            ->join('gym on gym.gym_id = gym_site.gym_id')
            ->join('city on city.city_id = gym.city_id','LEFT')
            ->field([
                'gym.gym_id',
                'u_id',
                'gym_name',
                'star',
                'cover',
                'type_id',
                'concat(city.city_name, gym.detail_address)' => 'address'
            ])
            ->group('gym.gym_id')
            ->where(['book_order.u_id' => $u_id])
            ->select();


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
                'type_id',
                'concat(city.city_name, gym.detail_address)' => 'address',
                'contact_info',
                'detail_msg'
            ])
            ->where(['gym.gym_id' => $gym_id])
            ->find();
        if ($gym_list === false) {
            $this->ret($result, 0, '数据库查询出错');
        } else {
            $result['gym_list'] = $gym_list;
            $this->ret($result);
        }
    }

    /**
     * 获取一个场馆近五天的场次信息
     * 未完成，选择5天,剩余数量
     */
    public function get_a_gym_site() {
        $gym_id	 = I('get.gym_id');
        $gym_site_list = M('gym_site')
            ->field([
                'gym_site_id',
                'start_time',
                'end_time',
                'date',
                'price',
            ]);
    }

    /**
     * 订场
     */
    public function reserve_gym() {
        $this->islogin();
        $gym_site_id = I('post.gym_site_id');
        $u_id = session('u_id');
        // $u_id = 1;
        


        $gym_book_list = M('book_order')
            ->where(['book_order.gym_site_id' => $gym_site_id])
            ->select();
        
        $gym_site = M('gym_site')
            ->where(['gym_site.gym_site_id' => $gym_site_id])
            ->find();
            
        $number = $gym_site['number'];

        $book_number = count($gym_book_list);
        if($number > $book_number){
            $time = date('y-m-d H:i:s',time());
            // echo $time;
            $data['u_id'] = $u_id;
            $data['gym_site_id'] = $gym_site_id;
            $data['success_time'] = $time;

            $book = M('book_order')
                ->add($data);
                $this->ret($result);
        } else {
            $this->ret($result, 0, '数据库查询出错');
        }
    }

    /**
     * 获取消息列表
     */
    public function get_message_list() {
        // $this->islogin();
        // $u_id = session('u_id');
        $u_id = 1;
        $is_read = I('get.is_read');
        $message_list = M('message')
            ->field([
                'message_id',
                'is_read',
                'content',
                'from',
                'time'
            ])
            ->where(['u_id' => $u_id]);
        if($is_read == 0 || $is_read == 1){
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
        $this->islogin();
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
        $date1 = date('y-m-d H:i:s',time());
        $date2 = date('y-m-d H:i:s',time());
        echo strtotime($date1);
        echo "     ";
        echo strtotime("+1 day");
    }

    /**
     * 查看订场订单详情
     * 未完成，缺少字段 type_name time_msg
     */
    public function get_order_detail() {
        $order_id = I('get.order_id');
        $order_detail = M('book_order')
            ->join('gym_site on gym_site.gym_site_id = book_order.gym_site_id','LEFT')
            ->join('gym on gym.gym_id = gym_site.gym_id','LEFT')
            ->field([
                'order_id',
                'gym.gym_id',
                'gym.gym_name',
                'gym.type_id',
                'gym_site.price',
                'success_time'=>'time'
            ])
            ->where(['book_order.order_id' => $order_id])
            ->select();
        if ($order_detail === false) {
            $this->ret($result, 0, '数据库查询出错');
        } else {
            $result['detail'] = $order_detail;
            $this->ret($result);
        }
    }

    /**
     * 申请成为商家
     */
    public function to_be_merchant() {
        $this->islogin();
        $u_id = session('u_id');
        $data['u_id'] = $u_id;
        $data['apply_time'] = date('y-m-d H:i:s',time());
        $data['status'] = 0;
        $data['last_time'] = date('y-m-d H:i:s',time());

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
        $this->islogin();
        $u_id = session('u_id');
        // $u_id = 1;
        $order_id = I('post.order_id');
        $star = I('post.star');
        $content = I('post.content');
        $time = date('y-m-d H:i:s',time());

        $data['u_id'] = $u_id;
        $data['order_id'] = $order_id;
        $data['star'] = $star;
        $data['content'] = $content;
        $data['comment_time'] = $time;

        $add_comment = M('comment')
            ->add($data);
        
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
        $gym_id = I('get.gym_id');
        $gym_comment_list = M('comment')
            ->join('user on user.u_id = comment.u_id','LEFT')
            ->join('book_order on book_order.order_id = comment.order_id','LEFT')
            ->join('gym_site on gym_site.gym_site_id = book_order.gym_site_id','LEFT')
            ->field([
                'user.u_id',
                'user.phone_number',
                'user.avatar_url',
                'star',
                'content',
                'comment_time'
            ])
            ->where(['gym_site.gym_id' => $gym_id])
            ->select();
            

        if ($gym_comment_list === false) {
            $this->ret($result, 0, '数据库查询出错');
        } else {
            $result['comment_list'] = $gym_comment_list;
            $this->ret($result);
        }
        
    }

    /**
     * 上传图片
     */
    public function upload_picture() {
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