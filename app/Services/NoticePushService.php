<?php

namespace App\Services;

use App\Models\Notice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class NoticePushService
{
    public function publish(Notice $notice): void
    {
        $prefix = config('database.redis.options.prefix', '');
        $channel = 'notice:push';
        $prefixedChannel = $prefix . $channel;
        $payload = json_encode([
            'event' => 'notice.created',
            'notice' => [
                'id' => (int) $notice->id,
                'title' => (string) $notice->title,
                'content' => (string) $notice->content,
                'img_url' => $notice->img_url,
                'tags' => $notice->tags ?? [],
                'show' => (bool) $notice->show,
                'popup' => (bool) ($notice->popup ?? false),
                'created_at' => (int) $notice->created_at,
                'updated_at' => (int) $notice->updated_at,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $subscriberCount = (int) Redis::publish($channel, $payload);

        if ($subscriberCount === 0 && $prefix !== '') {
            $subscriberCount = (int) Redis::publish($prefixedChannel, $payload);
        }

        Log::info('[NoticePush] Published', [
            'notice_id' => (int) $notice->id,
            'channel' => $channel,
            'prefixed_channel' => $prefixedChannel,
            'subscribers' => $subscriberCount,
        ]);
    }
}
