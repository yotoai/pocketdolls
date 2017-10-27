<?php

namespace App\Http\Controllers\Api;

use App\Model\Goods;
use App\Model\GoodsCategory;
use App\Model\Users;
use EasyWeChat\Foundation\Application;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Redis;
use Tymon\JWTAuth\Facades\JWTAuth;

class UserController extends BaseController
{
    // 用户授权
    public function oauthUser()
    {
        $user = session('wechat.oauth_user')->toArray(); // 拿到授权用户资料
        $gc = GoodsCategory::orderBy(\DB::raw('RAND()'))->take(1)->get(['id'])[0];
        $res = $this->addUser($user);
        if(!$res) return ['code' => -1,'msg' => '获取数据异常...'];
        if($res['code'] == -1) return $res;
        $data = $this->selectDollMachine($gc->id);
        return array_merge($res,['user'=>$user['original']],['data'=>$data]);
    }

    //添加用户
    protected function addUser($user)
    {
        if(!is_array($user) && empty($user)) return false;
        $data = Users::where('openid',$user['id'])->first();
        if(!empty($data))
        {
            $token = JWTAuth::fromUser($data);
            try{
                Users::where('openid',$user['id'])->update([
                    'login_time' => date('Y-m-d H:i:s',time())
                ]);
            }catch (\Exception $e){
                return ['code' => -1,'msg' => $e->getMessage()];
            }
            return ['code' => 1,'token' => $token];
        }else{
            try{
                $data = Users::create([
                    'openid'   => $user['id'],
                    'nickname' => $user['nickname'],
                    'icon'     => $user['avatar'],
                    'coin'     => 0,
                    'point'    => 0,
                    'login_time' => date('Y-m-d H:i:s',time())
                ]);
                $token = JWTAuth::fromUser( Users::find($data->id) );
            }catch (\Exception $e){
                return ['code' => -1,'msg' => $e->getMessage()];
            }
            return ['code' => 1,'token' => $token];
        }
    }

    // 娃娃机选择 // 需开启redis
    public function selectDollMachine($id)
    {
        try{
            $lucky = $this->getLuckyRedis($id);
            if($lucky < 0 )
            {
                $this->setLuckyRedis($id,0);
                $lucky = $this->getLuckyRedis($id);
            }
            $data = Goods::where('goods_cate_id',intval($id))->get()->toArray();
            if(empty($data)) return ['code' => -1,'msg' => '该娃娃机没有放入娃娃...'];
            foreach ($data as $d) {
                $d->pic = '/public/uploads/'.$d->pic;
                $d->sc_pic = '/public/uploads/'.$d->sc_pic;
            }
        }catch (\Exception $e){
            return ['code' => -1,'msg' => $e->getMessage()];
        }
        return ['code' => 1,'data' => $data,'lucky' => $lucky];
    }

    // 返回 微信分享 需要的 配置
    public function getJsConfig()
    {
        $app = new Application(config('wechat'));

        $js = $app->js;

        return $js->config(['onMenuShareQQ', 'onMenuShareWeibo'],true);
    }
}
