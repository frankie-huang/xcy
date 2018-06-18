<?php
namespace Home\Controller;
use Think\Controller;
class IndexController extends Controller {
    public function index(){
        $this->show('<style type="text/css">*{ padding: 0; margin: 0; } div{ padding: 4px 48px;} body{ background: #fff; font-family: "微软雅黑"; color: #333;font-size:24px} h1{ font-size: 100px; font-weight: normal; margin-bottom: 12px; } p{ line-height: 1.8em; font-size: 36px } a,a:hover{color:blue;}</style><div style="padding: 24px 48px;"> <h1>:)</h1><p>欢迎使用 <b>ThinkPHP</b>！</p><br/>版本 V{$Think.version}</div><script type="text/javascript" src="http://ad.topthink.com/Public/static/client.js"></script><thinkad id="ad_55e75dfae343f5a1"></thinkad><script type="text/javascript" src="http://tajs.qq.com/stats?sId=9347272" charset="UTF-8"></script>','utf-8');
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
     * 注册
     */
    public function register() {
        $db_user = M('user');
        $post = I('post.');
        $phone_number = trim($post['phone_number']);
        $password = trim($post['password']);
        $gender = trim($post['gender']);
        
        if (preg_match('/^(13[0-9]|14[579]|15[0-3,5-9]|16[6]|17[0135678]|18[0-9]|19[89])\\d{8}$/', $phone_number) == 0) {
            $this->ret($result, 0, '手机号码不符合要求');
        }
        if ($db_user->where(['phone_number' => $phone_number])->find() != null) {
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
            unset($user_info['password']);
            $this->ret($user_info);
        }
    }

    public function login() {

    }

    public function logout() {

    }

    private function ret(&$result, $status = 1, $msg = "") {
        $result['status'] = $status;
        $result['msg'] = $msg;
        $this->ajaxReturn($result);
    }
}