<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

//Route::middleware('auth:api')->get('/user', function () {
//    return 1;
//});
$api = app('Dingo\Api\Routing\Router');

$api->version('v1',function($api){
    $api->get('test',function (){
        $data = \App\Model\Users::all();
        foreach ($data as $v){
            \Illuminate\Support\Facades\Redis::del($v->openid . '_catch');
            \Illuminate\Support\Facades\Redis::del($v->openid . '_catched');
            \Illuminate\Support\Facades\Redis::del($v->openid . '_point');
            \Illuminate\Support\Facades\Redis::del($v->openid . '_charge');
            \Illuminate\Support\Facades\Redis::del($v->openid . '_mission');
            $keys = \Illuminate\Support\Facades\Redis::keys($v->openid . '_*_lucky');
            foreach ($keys as $s){
                \Illuminate\Support\Facades\Redis::del($s);
            }
            $keyss = \Illuminate\Support\Facades\Redis::keys($v->openid . '_*_mission');
            foreach ($keyss as $ss){
                \Illuminate\Support\Facades\Redis::del($ss);
            }
        }
        return ['d' => 1];

    });
    $api->group(['namespace' => 'App\Http\Controllers\Api'],function($api){
        $api->post('login','LoginController@login');
        // 刷新token
        $api->get('refresh','LoginController@refreshToken');


    });

    $api->group(['middleware' => ['api.cpy'],'namespace' => 'App\Http\Controllers\Api'],function($api){
//    $api->group(['namespace' => 'App\Http\Controllers\Api'],function($api){
        // 公告
        $api->get('notice','NoticeController@qnotice');

        // 玩家秀
        $api->get('usershow','UserShowController@userShow');
        $api->post('addusershow','UserShowController@addUserShow');

        // 充值额度
        $api->get('rechargeamount','RechargeAmountController@rechargeAmount');
        // 支付
        $api->get('pay/{id}','PayController@doPay');
        $api->post('notify','PayController@pay_notify');

        // 抓娃娃
        $api->get('selectdm/{id}','CatchDollController@selectDollMachine'); // 选择了一个娃娃机
        $api->get('dollmachine','CatchDollController@getRandDollMachine');
        $api->post('catchdoll/{id}/{gid}','CatchDollController@catchDoll');
        // 用户背包
        $api->get('rucksack','UserRucksackController@rucksack');
        $api->post('withdrawdoll','UserRucksackController@withdrawDoll');
        $api->post('withdrawlog','UserRucksackController@withdrawLog');
        $api->get('address','UserRucksackController@getAddress');
        // 任务
        $api->get('mission','MissionController@dayMission');
        $api->get('invite','MissionController@inviteMission');
        $api->get('daymission','MissionController@loginInMission');
        $api->post('finishmission/{id}','MissionController@finishMission');
    });

//
//    $api->group(['middleware' => ['api', 'wechat.oauth'],'namespace' => 'App\Http\Controllers\Api'], function ($api) {
//        // 用户授权
//        $api->get('user','UserController@oauthUser');
//        // 充值
//        $api->post('wxpay/{id}','WxpayController@Wxpay');
//        $api->post('wxnotify','WxpayController@wxNotify');
//        // 分享
//        $api->get('jssdk','UserController@getJsConfig');
//        // 刷新token
//        $api->get('refresh','UserController@refreshToken');
//    });
});