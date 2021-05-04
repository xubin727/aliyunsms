<?php
namespace Xubin\Aliyun;

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;

class Sms {
    
    static protected $name = '';
    static protected $accessKeyId = '';
    static protected $accessSecret = '';
    static protected $ddosLimit = 16;
    static protected $ddosExpireTime = 3600*30;
    static protected $smsExpireTime = 5*3600;
    
    /**
     * 配置短信接入密钥信息
     * @param String $accessKeyId
     * @param String $accessSecret
     * @param String $smsExpireTime 可选
     * @param String $ddosLimit 可选
     * @param String $ddosExpireTime 可选
     * @return self
     */
    public static function config($accessKeyId, $accessSecret, $smsExpireTime=5*3600, $ddosLimit=16, $ddosExpireTime = 3600*30)
    {
        self::$accessKeyId = $accessKeyId;
        self::$accessSecret = $accessSecret;
        self::$smsExpireTime = $smsExpireTime;
        self::$ddosLimit = $ddosLimit;
        self::$ddosExpireTime = $ddosExpireTime;
        
        return Sms;
    }
    
    
    /**
     * 防DDOS攻击
     * @return true|array 如：array('code'=>'5003','msg' => '勿频繁发送验证码。')
     */
    protected static function checkDdos()
    {
        // 启动会话
        if (PHP_SESSION_DISABLED == session_status()) { // 会话被禁用了
            return array(
                'code' => '5001',
                'msg' => '请启用会话功能'
            );
        } else if (PHP_SESSION_NONE == session_status()) { // 会话未启动
            session_start();
        }
        
        $name = self::$name;
        if (empty($_COOKIE)) { // cookie未启用，说明访问为机器行为，不输出任何内容直接退出
            exit;
            
        } else if (empty($_SESSION[$name . 'num'])) {
            $_SESSION[$name . 'num'] = 1;
            
        } else if ($_SESSION[$name . 'num'] > self::$ddosLimit) { // 限制当前名称短信最多重复发16次
            $_SESSION[$name . 'forbid'] = true; // 禁止当前名称短信发送
            $_SESSION[$name . 'forbid_expire_time'] = time() + 3600*30; // 禁止时长为半小时
            
            return array (
                'code' => 5003,
                'msg' => '勿频繁发送验证码。'
            );
            
        } else {
            $_SESSION[$name . 'num'] += 1;
            return true;
            
        }
        
    }
    
    /**
     * 检查手机号码是否正确
     * @param String $phoneNum
     * @return boolean
     */
    public static function checkMobileNum($phoneNum)
    {
        if (preg_match("/^1[3-9][0-9]{9}$/", trim($phoneNum))) {
            return true;
        } else {
            return false;
        }
    }
    
    /**
     *
     * @param string $name 短信名称标示
     * @param number $mobile 目标手机号码
     * @return array 如：array('code'=>2000, 'msg'=>'验证码已发送，将填写已接收到的验证码。')
     */
    public static function send($name, $mobile)
    {
        self::$name = $name;
        
        $ddos = self::checkDdos();
        
        if (true !== $ddos) {
            return $ddos;
            
        } else { // 发送验证吗短信
            
            try {
                $accessKeyId = self::$accessKeyId;
                $accessSecret = self::$accessSecret;
                AlibabaCloud::accessKeyClient($accessKeyId, $accessSecret)->regionId('cn-hangzhou')->asDefaultClient();
                
                $smsCode  = rand(100000, 999999);
                
                $result = AlibabaCloud::rpc()
                ->product('Dysmsapi')
//                 ->scheme('https') // https | http
                ->version('2017-05-25')
                ->action('SendSms')
                ->method('POST')
                ->host('dysmsapi.aliyuncs.com')
                ->options([
                    'query' => [
                        'RegionId' => "cn-hangzhou",
                        'PhoneNumbers' => $mobile,
                        'SignName' => "说明书在线验证码",
                        'TemplateCode' => "SMS_203716891",
                        'TemplateParam' => "{\"code\":\"{$smsCode}\"}",
                        ],
                    ])
                ->request();
                
                $result = $result->toArray(); // var_dump($accessKeyId, $accessSecret,$result);exit;
                
                if ('OK' == $result['Code']) {
                    $_SESSION[$name . 'sms_code'] = $smsCode;
                    $_SESSION[$name . 'sms_code_expire_time'] = time() + 5*3600;
                    return array (
                        'code' => 2000,
                        'msg' => '验证码已发送，将填写已接收到的验证码。' //.json_encode($result->toArray())."{\"code\":\"{$code}\"}"
                    );
                    
                } else {
                    return array(
                        'code' =>$result['Code'],
                        'msg' => $result['Message'],
                    );
                }
                        
            } catch (ClientException $e) {
//                 echo $e->getErrorMessage() . PHP_EOL;
                return array (
                    'code' => 2004,
                    'msg' => $e->getErrorMessage() . PHP_EOL
                );
            } catch (ServerException $e) {
//                 echo $e->getErrorMessage() . PHP_EOL;
                return array (
                    'code' => 5005,
                    'msg' => $e->getErrorMessage() . PHP_EOL
                );
            }
        }
        
    }
    
    
    /**
     *
     * @param string $name
     * @param string $code
     * @return array|true 返回为true里代表验证通过。否则返回数组信息，如：array('code' => '2003', 'msg' => '验证码已过期。')
     */
    public static function check($name, $code)
    {
         if(empty($_SESSION[$name . 'sms_code'])) {
            return array(
                'code' => '2002',
                'msg' => '未发送过验证码。',
            );
            
         } else if(time() >= $_SESSION[$name . 'sms_code_expire_time']) {
             return array(
                 'code' => '2003',
                 'msg' => '验证码已过期。',
             );
             
        } else {
            self::clean($name);
            return true;
        }
    }
    
    protected static function clean($name)
    {
        unset($_SESSION[$name . 'sms_code']);
        unset($_SESSION[$name . 'sms_code_expire_time']);
    }
    
}

