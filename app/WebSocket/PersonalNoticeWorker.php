<?php

namespace App\WebSocket;

use App\Services\AuthService;
use Illuminate\Support\Facades\Log;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

class PersonalNoticeWorker
{
    private const AUTH_TIMEOUT = 10;
    private const PING_INTERVAL = 45;

    private Worker $worker;

    /** @var array<int, TcpConnection> */
    private static array $connections = [];

    /** @var array<int, array<int, TcpConnection>> */
    private static array $connectionsByUser = [];

    public function __construct(string $host, int $port)
    {
        $this->worker = new Worker("websocket://{$host}:{$port}");
        $this->worker->count = 1;
        $this->worker->name = 'xboard-personal-notice-ws';
    }

    public function run(): void
    {
        $this->setupLogging();
        $this->setupCallbacks();
        Worker::runAll();
    }

    private function setupLogging(): void
    {
        $logPath = storage_path('logs');
        if (!is_dir($logPath)) {
            mkdir($logPath, 0777, true);
        }

        Worker::$logFile = $logPath . '/xboard-personal-notice-ws.log';
        Worker::$pidFile = $logPath . '/xboard-personal-notice-ws.pid';
    }

    private function setupCallbacks(): void
    {
        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
    }

    public function onWorkerStart(): void
    {
        Log::info('[PersonalNoticeWS] Worker started');
        $this->subscribeRedis();
        $this->setupTimers();
    }

    private function setupTimers(): void
    {
        Timer::add(self::PING_INTERVAL, function () {
            foreach (self::$connectionsByUser as $connections) {
                foreach ($connections as $connection) {
                    $connection->send(json_encode([
                        'event' => 'ping',
                        'data' => ['ts' => time()],
                    ]));
                }
            }
        });
    }

    public function onConnect(TcpConnection $conn): void
    {
        self::$connections[$conn->id] = $conn;
        $conn->authTimer = Timer::add(self::AUTH_TIMEOUT, function () use ($conn) {
            if (empty($conn->userId)) {
                $conn->close(json_encode([
                    'event' => 'error',
                    'data' => ['message' => 'auth timeout'],
                ]));
            }
        }, [], false);
    }

    public function onWebSocketConnect(TcpConnection $conn, $httpMessage): void
    {
        $queryString = '';
        if (is_string($httpMessage)) {
            $queryString = parse_url($httpMessage, PHP_URL_QUERY) ?? '';
        } elseif ($httpMessage instanceof \Workerman\Protocols\Http\Request) {
            $queryString = $httpMessage->queryString();
        }

        parse_str($queryString, $params);

        $authData = trim((string) ($params['auth_data'] ?? $params['token'] ?? ''));
        if ($authData === '') {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'missing auth'],
            ]));
            return;
        }

        if (stripos($authData, 'Bearer ') !== 0) {
            $authData = 'Bearer ' . $authData;
        }

        $user = AuthService::findUserByBearerToken($authData);
        if (!$user) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'invalid auth'],
            ]));
            return;
        }

        if (isset($conn->authTimer)) {
            Timer::del($conn->authTimer);
        }

        $conn->userId = (int) $user->id;
        self::$connectionsByUser[$conn->userId][$conn->id] = $conn;

        Log::info('[PersonalNoticeWS] User connected', [
            'user_id' => $conn->userId,
            'connection_id' => $conn->id,
            'total' => count(self::$connectionsByUser[$conn->userId]),
        ]);

        $conn->send(json_encode([
            'event' => 'auth.success',
            'data' => ['user_id' => $conn->userId],
        ]));
    }

    public function onMessage(TcpConnection $conn, $message): void
    {
        $payload = json_decode((string) $message, true);
        if (($payload['event'] ?? null) === 'pong') {
            return;
        }

        if (($payload['event'] ?? null) === 'ping') {
            $conn->send(json_encode([
                'event' => 'pong',
                'data' => ['ts' => time()],
            ]));
        }
    }

    public function onClose(TcpConnection $conn): void
    {
        unset(self::$connections[$conn->id]);
        $userId = $conn->userId ?? null;
        if (!$userId) {
            return;
        }

        unset(self::$connectionsByUser[$userId][$conn->id]);
        if (empty(self::$connectionsByUser[$userId])) {
            unset(self::$connectionsByUser[$userId]);
        }

        Log::info('[PersonalNoticeWS] User disconnected', [
            'user_id' => $userId,
            'connection_id' => $conn->id,
        ]);
    }

    private function subscribeRedis(): void
    {
        $host = config('database.redis.default.host', '127.0.0.1');
        $port = config('database.redis.default.port', 6379);

        if (str_starts_with($host, '/')) {
            $redisUri = "unix://{$host}";
        } else {
            $redisUri = "redis://{$host}:{$port}";
        }

        $redis = new \Workerman\Redis\Client($redisUri);

        $password = config('database.redis.default.password');
        if ($password) {
            $redis->auth($password);
        }

        $prefix = config('database.redis.options.prefix', '');
        $personalChannel = $prefix . 'personal_notice:push';
        $noticeChannel = $prefix . 'notice:push';

        $redis->subscribe([$personalChannel, $noticeChannel], function ($chan, $message) use ($personalChannel, $noticeChannel) {
            $payload = json_decode($message, true);
            if (!is_array($payload)) {
                return;
            }

            $event = (string) ($payload['event'] ?? '');
            $data = $payload['notice'] ?? null;

            if ($event === '' || !is_array($data)) {
                return;
            }

            if ($chan === $personalChannel) {
                $userId = (int) ($payload['user_id'] ?? 0);
                if ($userId <= 0) {
                    return;
                }

                foreach (self::$connectionsByUser[$userId] ?? [] as $connection) {
                    $connection->send(json_encode([
                        'event' => $event,
                        'data' => $data,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }

                Log::info('[PersonalNoticeWS] Notice pushed', [
                    'user_id' => $userId,
                    'notice_id' => $data['id'] ?? null,
                    'connections' => count(self::$connectionsByUser[$userId] ?? []),
                ]);
                return;
            }

            if ($chan === $noticeChannel) {
                foreach (self::$connections as $connection) {
                    if (empty($connection->userId)) {
                        continue;
                    }

                    $connection->send(json_encode([
                        'event' => $event,
                        'data' => $data,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }

                Log::info('[PersonalNoticeWS] Global notice pushed', [
                    'notice_id' => $data['id'] ?? null,
                    'connections' => count(self::$connections),
                ]);
            }
        });

        Log::info("[PersonalNoticeWS] Subscribed to Redis channels: {$personalChannel}, {$noticeChannel}");
    }
}
