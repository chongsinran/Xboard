<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ComissionLogResource;
use App\Http\Resources\InviteCodeResource;
use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use App\Services\InviteRewardService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class InviteController extends Controller
{
    public function save(Request $request)
    {
        if (InviteCode::where('user_id', $request->user()->id)->where('status', 0)->count() >= admin_setting('invite_gen_limit', 5)) {
            return $this->fail([400,__('The maximum number of creations has been reached')]);
        }
        $inviteCode = new InviteCode();
        $inviteCode->user_id = $request->user()->id;
        $inviteCode->code = Helper::randomChar(8);
        return $this->success($inviteCode->save());
    }

    public function details(Request $request)
    {
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('page_size') >= 10 ? $request->input('page_size') : 10;
        $builder = CommissionLog::with('inviter:id,email')
            ->where('invite_user_id', $request->user()->id)
            ->where('get_amount', '>', 0)
            ->orderBy('created_at', 'DESC');
        $total = $builder->count();
        $details = $builder->forPage($current, $pageSize)
            ->get();

        foreach ($details as $detail) {
            if ($detail->inviter) {
                $detail->inviter_email = $detail->inviter->email;
            }
        }

        return response([
            'data' => ComissionLogResource::collection($details),
            'total' => $total
        ]);
    }

    public function fetch(Request $request)
    {
        $commission_rate = admin_setting('invite_commission', 10);
        $user = User::find($request->user()->id)
                ->load(['codes' => fn($query) => $query->where('status', 0)]);

        // Easylink assumes an invite code already exists and reads codes[0].
        if ($user && $user->codes->isEmpty()) {
            $inviteCode = new InviteCode();
            $inviteCode->user_id = $user->id;
            $inviteCode->code = Helper::randomChar(8);
            $inviteCode->save();
            $user->load(['codes' => fn($query) => $query->where('status', 0)]);
        }

        if ($user->commission_rate) {
            $commission_rate = $user->commission_rate;
        }
        $uncheck_commission_balance = (int)Order::where('status', 3)
            ->where('commission_status', 0)
            ->where('invite_user_id', $user->id)
            ->sum('commission_balance');
        if (admin_setting('commission_distribution_enable', 0)) {
            $uncheck_commission_balance = $uncheck_commission_balance * (admin_setting('commission_distribution_l1') / 100);
        }
        $stat = [
            //已注册用户数
            (int)User::where('invite_user_id', $user->id)->count(),
            //有效的佣金
            (int)CommissionLog::where('invite_user_id', $user->id)
                ->sum('get_amount'),
            //确认中的佣金
            $uncheck_commission_balance,
            //佣金比例
            (int)$commission_rate,
            //可用佣金
            (int)$user->commission_balance
        ];
        $registeredUsers = User::where('invite_user_id', $user->id)
            ->orderBy('created_at', 'DESC')
            ->get(['id', 'email', 'created_at']);
        $inviteProgress = app(InviteRewardService::class)->getInviteProgress($user);
        $data = [
            'codes' => InviteCodeResource::collection($user->codes),
            'stat' => $stat,
            'registered_users' => $registeredUsers,
            'commission_freeze_balance' => 0,
            'invite_progress' => $inviteProgress,
        ];
        return $this->success($data);
    }
}
