<?php

namespace App\Http\Controllers\Api;

use App\Model\catchLog;
use App\Model\ChargeConfig;
use App\Model\DollMachineLog;
use App\Model\Goods;
use App\Model\GoodsCategory;
use App\Model\Mission;
use App\Model\Player;
use App\Model\TalkExpression;
use App\Model\UserRucksack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CatchDollController extends BaseController
{
    // 娃娃机选择 // 需开启redis
    public function selectDollMachine($id)
    {
        try{
            $lucky = $this->getLuckyRedis($id);
            if(!($lucky >= 0))
            {
                $this->setLuckyRedis($id,0);
                $lucky = $this->getLuckyRedis($id);
            }
            $data = Goods::where('goods_cate_id',intval($id))
                ->where('status','<>','-1')
                ->get([
                    'id',
                    'goods_cate_id',
                    'add_num',
                    'name',
                    'pic',
                    'sc_pic',
                    'width',
                    'height',
                    'xdheight'
                ]);
            if(empty($data)) return ['code' => -1,'msg' => '该娃娃机不存在...'];
            $list = [];
            foreach ($data as $d) {
                for ($i = $d->add_num;$i > 0 ;$i-- ){
                    $list[] = [
                        'id' => $d->id,
                        'goods_cate_id' => $d->goods_cate_id,
                        'name' => $d->name,
                        'pic' => env('APP_URL').'/uploads/'.$d->pic,
                        'sc_pic' => env('APP_URL').'/uploads/'.$d->sc_pic,
                        'width' => $d->width,
                        'height' => $d->height,
                        'xdheight' => $d->xdheight
                    ];
                }
            }
            $coin = GoodsCategory::where('id',$id)->value('coin');
        }catch (\Exception $e){
            return ['code' => -1,'msg' => $e->getMessage(),'ss'=>$e->getLine()];
        }
        return ['code' => 1,'msg' => '查询成功','coin' => $coin,'lucky' => $lucky,'data' => $list];
    }


    /**
     * 随机返回娃娃机
     *  notice ：要开redis
     * @return array
     */
    public function getRandDollMachine()
    {
        $key = 'doll_machine';
        if(Redis::scard($key) > 0){
            return $this->randDollMachine($key);
        }else {
            $cate_id = Goods::where('status','<>','-1')->distinct()->get(['goods_cate_id'])->pluck('goods_cate_id');
            $data = GoodsCategory::join('goods_tags_cate','goods_tags_cate.id','=','goods_category.tag_id')
                ->whereIn('goods_category.id',$cate_id)
                ->where('goods_category.status','<>','-1')
                ->get([
                    'goods_category.id as id',
                    'goods_category.cate_name as name',
                    'goods_category.spec as spec',
                    'goods_category.coin as coin',
                    'goods_category.pic as pic',
                    'goods_tags_cate.tag_icon as tag_icon'
                ]);
            foreach ($data as $d){
                $d->pic = env('APP_URL').'/uploads/'.$d->pic;
                $d->tag_icon = env('APP_URL').'/uploads/'.$d->tag_icon;
            }
            foreach ($data as $item) {
                Redis::sadd($key, $item);
            }
            return $this->randDollMachine($key);
        }
    }

    // 返回 $num 个随机 娃娃机
    protected function randDollMachine($key,$num = 4)
    {
        return ['code' => 1,'msg' => '查询成功','data' => json_decode('['.implode(',',Redis::srandmember($key, $num)).']',true)];
    }

    /**
     * 抓取娃娃
     * @param $id        @娃娃机id
     * @param $gid       @娃娃id
     * @return array
     */
    public function catchDoll($id,$gid,Request $request)
    {
        $this->validate($request,[
            'iscatch' => 'required'
        ]);
        try{
            $uid = $this->getUserid();
            $gcoin = GoodsCategory::where('id',intval($id))->value('coin');
            $ucoin = Player::where('user_id',$uid)->value('coin');
            if($ucoin < $gcoin) {return ['code' => -1,'msg' => '金币不足！'];}
            $this->setCatchNum(1);
            $this->machineLog($id,'catch');
        }catch (\Exception $e){
            return ['code' => -1,'msg' => $e->getMessage()];
        }

//        $udata = Player::find($uid);
//        $this->setRebate($udata,$gcoin); // 返佣

        $lucky = $this->getLuckyRedis($id);

        if($request->iscatch == 'false' || intval($gid) == 0){
            $this->finishMission('catch');
            Player::where('user_id',$uid)->update(['coin' => $ucoin - $gcoin]);
            if($lucky >= 100){
                $add_lucky = 0;
            }else{
                $add_lucky = $this->reLucky($lucky);
                $this->setLuckyRedis($id,$add_lucky);
            }
            return ['code' => 1,'data' => 'lost','lucky' => $add_lucky];
        }
        $rate = GoodsCategory::where('id',intval($id))->value('win_rate');
        $arr = ['get' => $rate,'lost'=>1000];
        if((($res = $this->getRand($arr)) == 'get' || $lucky == 100) && $request->iscatch == 'true' && $rate > 0)
        {
            try{
                $res = UserRucksack::where('user_id',$uid)->where('goods_id',$gid)->first();
                DB::transaction(function () use ($uid,$gid,$gcoin,$ucoin,$res){
                    if(!empty($res) && $res->goods_id == $gid) {
                        UserRucksack::where('user_id',$uid)->where('goods_id',$gid)->update([
                            'num' => $res->num + 1
                        ]);
                    }else {
                        UserRucksack::create([
                            'user_id'   => $uid,
                            'goods_id'  => $gid,
                            'num'       => 1,
                            'gain_time' => date('Y-m-d H:i:s', time())
                        ]);
                    }
                    catchLog::create([
                        'user_id'  => $uid,
                        'goods_id' => $gid,
                    ]);
                    $this->changeUserCoin($gcoin);
                });
                $this->setLuckyRedis($id,0);
                $this->setCatchedNum(1);
                $this->finishMission('catched');
                $this->finishMission('catch');
                $this->machineLog($id,'catched');
                return ['code' => 1,'data' => 'get','lucky' => 'clear'];
            }catch (\Exception $e){
                return ['code' => -1,'msg' => $e->getMessage()];
            }
        }else{
            try{
                $this->finishMission('catch');
                $this->changeUserCoin($gcoin);
                if($lucky >= 100){
                    $add_lucky = 0;
                }else{
                    $add_lucky = $this->reLucky($lucky);
                    $this->setLuckyRedis($id,$add_lucky);
                }
                return ['code' => 1,'data' => $res,'lucky' => $add_lucky];
            }catch (\Exception $e){
                return ['code' => -1,'msg' => $e->getMessage()];
            }
        }
    }
    // 扣除用户金币 ，增加用户总消费
    protected function changeUserCoin($gcoin)
    {
        $user = Player::where('user_id',$this->getUserid())->first();
        $user->coin = $user->coin - $gcoin;
        $user->used_coin = $user->used_coin + $gcoin;
        $user->save();
    }

    // 返回随机键
    private function getRand($arr)
    {
	 	$sum = array_sum($arr);
        $res = 'lost';
	 	foreach($arr as $k => $v)
	 	{
	 		$current = mt_rand(1,$sum);

	 		if($current <= $v)
	 		{
	 			$res = $k;
	 			break;
	 		}else{
	 			$sum -= $current;
	 		}
	 	}
	 	return $res;
    }

    // 返回增加的幸运值
    protected function reLucky($lucky)
    {
        if( $lucky < 60 && $lucky >=0) return mt_rand(5,10);
        if( $lucky >= 60  && $lucky < 80) return mt_rand(3,5);
        if( $lucky >= 80  && $lucky < 95) return  mt_rand(2,3);
        if( $lucky >= 95 && $lucky <=100) return 1;
    }

    // 完成 抓取 抓到 任务
    protected function finishMission($action)
    {
        if($action == 'catch'){
            $num = $this->getCatchNum();
            $res = Mission::where('type',2)->get(['id','need_num']);
            foreach ($res as $v){
                if($v->need_num == $num && $this->getMissionRedis($v->id) != 1){
                    $this->setMissionRedis($v->id,1);
                }
            }
        }elseif($action == 'catched'){
            $num = $this->getCatchedNum();
            $res = Mission::where('type',5)->get(['id','need_num']);
            foreach ($res as $v){
                if($v->need_num == $num && $this->getMissionRedis($v->id) != 1){
                    $this->setMissionRedis($v->id,1);
                }
            }
        }
    }

    // 抓取到后分享
    public function getShare($id)
    {
        $goods = Goods::find($id);
        $user = Player::where('user_id','<>',$this->getUserid())->orderBy(DB::raw('RAND()'))->take(2)->get(['user_name'])->pluck('user_name')->toArray();
        $catch = $this->getCatchNum();
        $data = [
            'goods_name' => $goods->name,
            'pic' => env('APP_URL').'/uploads/'.$goods->pic,
            'user_name' => $user,
            'catchnum' => $catch
        ];
        return ['code' => 1,'msg' =>'查询成功','data' => $data];
    }

    // 返佣 （作废）
    protected function setRebate($data,$gcoin,$rate=1)
    {
        if(!empty($data->parent_id)){
            $pdata = Player::find($data->parent_id);
            $cc = ChargeConfig::where('identity','rebate_ratio_'.$rate.'v')->first();
            $pdata->coin = $pdata->coin + $gcoin * $cc->rebate_ratio;
            $pdata->save();

            if(!empty($pdata->parent_id)) {
                $this->setRebate($pdata,$gcoin,++$rate);
            }
//                $ddata = Player::find($pdata->parent_id);
//                $cc = ChargeConfig::where('identity','rebate_ratio_2v')->first();
//                $ddata->coin = $ddata->coin + $gcoin * $cc->rebate_ratio;
//                $ddata->save();
//                if(!empty($ddata->parent_id)){
//                    $sdata = Player::find($ddata->parent_id);
//                    $cc = ChargeConfig::where('identity','rebate_ratio_3v')->first();
//                    $sdata->coin = $sdata->coin + $gcoin * $cc->rebate_ratio;
//                    $sdata->save();
//                }
//            }
        }else{
            return;
        }
    }


    // 返回 娃娃互动信息
    public function getDollInteraction(Request $request)
    {
        $this->validate($request,[
            'machine_id' => 'required|integer'
        ]);
        if(in_array($request->type,[1,2,3])){
            $te = TalkExpression::where('dollmachine_id',$request->machine_id)
                ->where('type',$request->type)
                ->first();
            if(!empty($te->talk_doll)){
                $talk = explode(',',$te->talk_doll);
                $talk = $talk[array_rand($talk)];
            }else{
                $talk = '';
            }

            if(!empty($te->small_expression)){
                $se = $te->small_expression;
                foreach ($se as $k=>$v){
                    $se[$k] = env('APP_URL') . '/uploads/' . $v;
                }
                $expression = $se[array_rand($se)];
            }else {
                $expression = '';
            }
            return ['code' => 1,'msg' => '查询成功','talk' => $talk,'expression' => $expression];
        }else {
            $te = TalkExpression::where('dollmachine_id',$request->machine_id)
                ->where('type',3)
                ->first();

            if(!empty($te->talk_doll)){
                $talk = explode(',',$te->talk_doll);
                $talk = $talk[array_rand($talk)];
            }else{
                $talk = '';
            }

            if(!empty($te->small_expression)){
                $se = $te->small_expression;
                foreach ($se as $k=>$v){
                    $se[$k] = env('APP_URL') . '/uploads/' . $v;
                }
                $expression = $se[array_rand($se)];
            }else {
                $expression = '';
            }
            return ['code' => 1,'msg' => '查询成功','talk' => $talk,'expression' => $expression];
        }
    }

    // 娃娃机抓取日志
    protected function machineLog($mid,$ac)
    {
        try{
            $dml = DollMachineLog::where('doll_machine_id',$mid)->first();
            $gc = GoodsCategory::where('id',$mid)->first();
            if(empty($dml)){
                $uid = $this->getUserid();
                $user = Player::where('user_id',$uid)->first();
                if($ac == 'catch'){
                    DollMachineLog::create([
                        'sdk_id' => $user->sdk_id,
                        'doll_machine_id' => $mid,
                        'doll_machine_name' => $gc->cate_name,
                        'catch_num' => 1,
                    ]);
                }elseif($ac == 'catched'){
                    DollMachineLog::create([
                        'sdk_id' => $user->sdk_id,
                        'doll_machine_id' => $mid,
                        'doll_machine_name' => $gc->cate_name,
                        'catched_num' => 1,
                    ]);
                }elseif ($ac == 'lucky_model'){
                    DollMachineLog::create([
                        'sdk_id' => $user->sdk_id,
                        'doll_machine_id' => $mid,
                        'doll_machine_name' => $gc->cate_name,
                        'lucky_model_catch_num' => 1,
                    ]);
                }else{
                    DollMachineLog::create([
                        'sdk_id' => $user->sdk_id,
                        'doll_machine_id' => $mid,
                        'doll_machine_name' => $gc->cate_name,
                        'catch_num' => 1,
                    ]);
                }
            }else{
                if($ac == 'catch'){
                    $dml->catch_num = $dml->catch_num + 1;
                    $dml->save();
                }elseif ($ac == 'catched'){
                    $dml->catched_num = $dml->catched_num + 1;
                    $dml->save();
                }elseif($ac == 'lucky_model'){
                    $dml->lucky_model_catch_num = $dml->lucky_model_catch_num + 1;
                    $dml->save();
                }else{
                    $dml->catch_num = $dml->catch_num + 1;
                    $dml->save();
                }
            }

        }catch (\Exception $e){
            Log::info('error place ; machineLog msg:  ' .$e->getMessage() .' line: ' .$e->getLine());
        }
    }
}
