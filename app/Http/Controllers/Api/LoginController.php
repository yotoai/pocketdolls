<?php

namespace App\Http\Controllers\Api;

use App\Model\Goods;
use App\Model\GoodsCategory;
use App\Model\Player;
use App\Model\Users;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class LoginController extends BaseController
{
    // 用户授权
    public function login(Request $request)
    {
        $this->validate($request,[
            'sdkId'  => 'required',
            'userId' => 'required',
            'timestamp' => 'required',
           // 'sign'   => 'required'
        ]);

//        if($request->sign != strtolower( md5($request->sdkId.$request->userId.$request->userName.$request->userImg.$request->timestamp.env('GAMEKEY')))){
//            return ['code' => -1,'msg' => '验证失败'];
//        }
        $res = $this->addUser($request);
        if(!$res){
            return ['code' => -1,'msg' => '获取数据异常...'];
        }
        if($res['code'] == -1){
            return $res;
        }
        $this->setUserId($request->userId);
        $goods_id = Goods::where('status','<>','-1')->distinct()->get(['goods_cate_id'])->pluck('goods_cate_id');
        $cid = GoodsCategory::whereIn('id',$goods_id)->where('status','<>','-1')->get(['id'])->pluck('id')->toArray();
        $data = $this->selectDollMachine($cid[array_rand($cid)]);
        return array_merge($res,['data' => $data]);
    }

    public function index(Request $request)
    {
        $this->validate($request,[
            'username' => 'required',
            'password' => 'required'
        ]);

        try{
            $user = Users::where('phone',$request->username)->first();
            if(empty($user)){
                return ['code' => -1,'msg' => '账号或者密码错误'];
            } elseif(!(new BcryptHasher())->check($request->password,$user->password)){
                return ['code' => -1,'msg' => '账号或者密码错误！'];
            }else{
                if(date('Y-m-d') != date('Y-m-d',$user->login_time)){
                    $day = $user->login_day + 1;
                }else{
                    $day = $user->login_day;
                }
                Users::where('phone',$request->username)
                    ->update([
                        'login_day' => $day,
                        'login_time' => date('Y-m-d H:i:s',time())
                    ]);
            }

            $token = JWTAuth::fromUser(Users::where('phone',$request->username)->first());
            $user = [
                'username' => $user->phone,
                'icon'     => $user->icon,
                'coin'     => $user->coin,
                'token'    => $token
            ];
            $this->setUserId($request->userId);
            $goods_id = Goods::where('status','<>','-1')->distinct()->get(['goods_cate_id'])->pluck('goods_cate_id');
            $cid = GoodsCategory::whereIn('id',$goods_id)->where('status','<>','-1')->get(['id'])->pluck('id')->toArray();
            $data = $this->selectDollMachine($cid[array_rand($cid)]);
            return array_merge($user,['data' => $data]);
        }catch (\Exception $e){
            return ['code' => -1,'msg' => $e->getMessage()];
        }
    }

    //添加用户
    protected function addUser(Request $request)
    {
        if( empty($request) ) return false;
        $data = Player::where('user_id',$request->userId)->first();
        if(!empty($data))
        {
            try{
                Player::where('user_id',$request->userId)->update([
                    'sdk_id'     => $request->sdkId,
                    'user_name'  => empty($request->userName) ? '' : $request->userName,
                    'user_img'   => empty($request->userImg) ? '' : $request->userImg,
                    'login_time' => date('Y-m-d H:i:s',time())
                ]);
                $token = JWTAuth::fromUser(Player::where('user_id',$request->userId)->first());
                $user = [
                    'username' => $data->user_name,
                    'icon'     => $data->user_img,
                    'coin'     => $data->coin
                ];
            }catch (\Exception $e){
                return ['code' => -1,'msg' => $e->getMessage()];
            }
            return ['code' => 1,'token' => $token,'user' => $user];
        }else{
            try{
                $data = Player::create([
                    'sdk_id'    => $request->sdkId,
                    'user_id'   => $request->userId,
                    'user_name' => $request->userName,
                    'user_img'  => $request->userImg,
                    'coin'      => 0,
                    'login_time' => date('Y-m-d H:i:s',time())
                ]);
                $token = JWTAuth::fromUser($data);
                $user = [
                    'username' => $data->userName,
                    'icon'     => $data->userImg,
                    'coin'     => $data->coin
                ];
            }catch (\Exception $e){
                return ['code' => -1,'msg' => $e->getMessage()];
            }
            return ['code' => 1,'token' => $token,'user' => $user];
        }
    }

    // 娃娃机选择 // 需开启redis
    public function selectDollMachine($id)
    {
        try{
            $lucky = $this->getLuckyRedis($id);
            if(intval($lucky) <= 0 )
            {
                $this->setLuckyRedis($id,0);
                $lucky = $this->getLuckyRedis($id);
            }
            $data = Goods::where('goods_cate_id',intval($id))->where('status','<>','-1')->get([
                'id',
                'goods_cate_id',
                'name',
                'pic',
                'sc_pic',
                'width',
                'height'
            ]);
            if(empty($data->toArray())) return ['code' => -1,'msg' => '该娃娃机没有放入娃娃...'];
            foreach ($data as $d) {
                $d->pic = env('APP_URL') .'/uploads/'.$d->pic;
                $d->sc_pic = env('APP_URL') .'/uploads/'.$d->sc_pic;
            }
            $coin = GoodsCategory::where('id',$id)->value('coin');
        }catch (\Exception $e){
            return ['code' => -1,'msg' => $e->getMessage()];
        }
        return ['code' => 1,'coin' => $coin,'lucky' => $lucky,'data' => $data];
    }

    // 刷新 token
    public function refreshToken()
    {
        $old_token = JWTAuth::getToken();
        $token = JWTAuth::refresh($old_token);
        JWTAuth::invalidate($old_token);
        return $token;
    }
}
