<?php
namespace Home\Controller;
use Think\Controller;
class IndexController extends Controller {
    private $redis;

    private function init_redis() {
        $this->redis = new Redis();
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
            $get_user_info = M('user')->field('phone_number, avatar_url, gender, balance')->where(['u_id' => $u_id])->find();
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
        curl_close($ch);

        return json_decode($ret, true);
    }

    private function ret(&$result, $status = 1, $msg = "") {
        $result['status'] = $status;
        $result['msg'] = $msg;
        $this->ajaxReturn($result);
    }
}