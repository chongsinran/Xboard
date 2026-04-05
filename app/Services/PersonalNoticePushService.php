<?php

namespace App\Services;

use App\Models\PersonalNotice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PersonalNoticePushService
{
    public function publish(PersonalNotice $notice): void
    {
        $prefix = config('database.redis.options.prefix', '');
        $channel = 'personal_notice:push';
        $prefixedChannel = $prefix . $channel;
        $payload = json_encode([
            'event' => 'personal_notice.created',
            'user_id' => (int) $notice->user_id,
            'notice' => [
                'id' => (int) $notice->id,
                'user_id' => (int) $notice->user_id,
                'title' => (string) $notice->title,
                'content' => (string) $notice->content,
                'content_format' => (string) ($notice->content_format ?: 'markdown'),
                'img_url' => $notice->img_url,
                'tags' => $notice->tags ?? [],
                'show' => (bool) $notice->show,
                'read_at' => $notice->read_at,
                'created_at' => (int) $notice->created_at,
                'updated_at' => (int) $notice->updated_at,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $subscriberCount = (int) Redis::publish($channel, $payload);

        if ($subscriberCount === 0 && $prefix !== '') {
            $subscriberCount = (int) Redis::publish($prefixedChannel, $payload);
        }

        Log::info('[PersonalNoticePush] Published', [
            'notice_id' => (int) $notice->id,
            'user_id' => (int) $notice->user_id,
            'channel' => $channel,
            'prefixed_channel' => $prefixedChannel,
            'subscribers' => $subscriberCount,
        ]);
    }
}
