<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PersonalNoticeSave;
use App\Models\PersonalNotice;
use App\Services\PersonalNoticePushService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PersonalNoticeController extends Controller
{
    public function __construct(
        private readonly PersonalNoticePushService $pushService,
    ) {
    }

    public function fetch(Request $request)
    {
        $query = PersonalNotice::query()
            ->with('user:id,email')
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('email')) {
            $email = strtolower(trim((string) $request->input('email')));
            $query->whereHas('user', static function ($q) use ($email) {
                $q->where('email', 'like', '%' . $email . '%');
            });
        }

        if ($request->filled('tag')) {
            $tag = trim((string) $request->input('tag'));
            $query->whereJsonContains('tags', $tag);
        }

        return $this->success($query->get()->map(function (PersonalNotice $notice) {
            $data = $notice->toArray();
            $data['user_email'] = $notice->user?->email;
            return $data;
        }));
    }

    public function searchUsers(Request $request)
    {
        $keyword = trim((string) $request->input('keyword', ''));
        $limit = min(max((int) $request->input('limit', 20), 1), 50);

        $query = User::query()->select(['id', 'email'])->orderBy('id', 'DESC');

        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->whereRaw('LOWER(email) LIKE ?', ['%' . strtolower($keyword) . '%']);
                if (is_numeric($keyword)) {
                    $builder->orWhere('id', (int) $keyword);
                }
            });
        }

        $results = $query->limit($limit)->get();

        Log::info('personalNotice:searchUsers', [
            'keyword' => $keyword,
            'limit' => $limit,
            'count' => $results->count(),
        ]);

        return $this->success($results);
    }

    public function save(PersonalNoticeSave $request)
    {
        $validated = $request->validated();
        $recipientIds = collect($validated['recipient_ids'] ?? [])
            ->map(static fn ($id) => (int) $id)
            ->filter(static fn ($id) => $id > 0)
            ->values();

        $recipientEmails = collect($validated['recipient_emails'] ?? [])
            ->map(static fn ($email) => strtolower(trim((string) $email)))
            ->filter()
            ->values();

        if ($recipientEmails->isNotEmpty()) {
            $emailMatchedIds = User::query()
                ->whereIn('email', $recipientEmails->all())
                ->pluck('id');
            $recipientIds = $recipientIds->merge($emailMatchedIds);
        }

        $recipientIds = $recipientIds->unique()->values();

        if ($recipientIds->isEmpty()) {
            return $this->fail([422, '至少选择一个接收用户']);
        }

        $users = User::query()
            ->select(['id'])
            ->whereIn('id', $recipientIds->all())
            ->pluck('id');

        if ($users->isEmpty()) {
            return $this->fail([404, '未找到有效的接收用户']);
        }

        $payload = [
            'title' => $validated['title'],
            'content' => $validated['content'],
            'content_format' => $validated['content_format'] ?? 'markdown',
            'img_url' => $validated['img_url'] ?? null,
            'tags' => $validated['tags'] ?? [],
            'show' => array_key_exists('show', $validated) ? (int) (bool) $validated['show'] : 1,
        ];

        $createdNotices = collect();

        DB::beginTransaction();
        try {
            foreach ($users as $userId) {
                $createdNotices->push(
                    PersonalNotice::create($payload + ['user_id' => $userId])
                );
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->fail([500, '保存失败']);
        }

        $createdNotices->each(function (PersonalNotice $notice) {
            $this->pushService->publish($notice);
        });

        return $this->success([
            'count' => $users->count(),
        ]);
    }

    public function show(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([500, '消息ID不能为空']);
        }

        $notice = PersonalNotice::find($request->input('id'));
        if (!$notice) {
            return $this->fail([400202, '消息不存在']);
        }

        $notice->show = $notice->show ? 0 : 1;
        if (!$notice->save()) {
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function drop(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([422, '消息ID不能为空']);
        }

        $notice = PersonalNotice::find($request->input('id'));
        if (!$notice) {
            return $this->fail([400202, '消息不存在']);
        }

        if (!$notice->delete()) {
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }
}
