<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\PersonalNotice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PersonalNoticeController extends Controller
{
    public function fetch(Request $request)
    {
        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return $this->fail([403, '未登录或登陆已过期']);
        }
        $current = $request->input('current') ?: 1;
        $pageSize = min((int) ($request->input('page_size') ?: 50), 100);

        $model = PersonalNotice::query()
            ->where('user_id', $user->id)
            ->where('show', true)
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END ASC')
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

        $total = $model->count();
        $res = $model->forPage($current, $pageSize)->get();

        return response([
            'data' => $res,
            'total' => $total,
        ]);
    }

    public function read(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        $user = Auth::guard('sanctum')->user();
        if (!$user) {
            return $this->fail([403, '未登录或登陆已过期']);
        }
        $notice = PersonalNotice::query()
            ->where('id', (int) $request->input('id'))
            ->where('user_id', $user->id)
            ->first();

        if (!$notice) {
            return $this->fail([404, '消息不存在']);
        }

        $notice->read_at = time();
        $notice->save();

        return $this->success(true);
    }
}
