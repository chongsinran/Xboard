<?php

namespace App\Http\Controllers\V1\Passport;

use App\Helpers\ResponseEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Models\User;
use App\Utils\CacheKey;
use App\Services\Auth\LoginService;
use App\Services\Auth\MailLinkService;
use App\Services\Auth\RegisterService;
use App\Services\AuthService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AuthController extends Controller
{
    protected MailLinkService $mailLinkService;
    protected RegisterService $registerService;
    protected LoginService $loginService;

    public function __construct(
        MailLinkService $mailLinkService,
        RegisterService $registerService,
        LoginService $loginService
    ) {
        $this->mailLinkService = $mailLinkService;
        $this->registerService = $registerService;
        $this->loginService = $loginService;
    }

    /**
     * 通过邮件链接登录
     */
    public function loginWithMailLink(Request $request)
    {
        $params = $request->validate([
            'email' => 'required|email:strict',
            'redirect' => 'nullable'
        ]);

        [$success, $result] = $this->mailLinkService->handleMailLink(
            $params['email'],
            $request->input('redirect')
        );

        if (!$success) {
            return $this->fail($result);
        }

        return $this->success($result);
    }

    /**
     * 用户注册
     */
    public function register(AuthRegister $request)
    {
        [$success, $result] = $this->registerService->register($request);

        if (!$success) {
            return $this->fail($result);
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
    }

    /**
     * 用户登录
     */
    public function login(AuthLogin $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        [$success, $result] = $this->loginService->login($email, $password);

        if (!$success) {
            return $this->fail($result);
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
    }

    /**
     * 通过token登录
     */
    public function token2Login(Request $request)
    {
        // 处理直接通过token重定向
        if ($token = $request->input('token')) {
            $redirect = '/#/login?verify=' . $token . '&redirect=' . ($request->input('redirect', 'dashboard'));

            return redirect()->to(
                admin_setting('app_url')
                ? admin_setting('app_url') . $redirect
                : url($redirect)
            );
        }

        // 处理通过验证码登录
        if ($verify = $request->input('verify')) {
            $userId = $this->mailLinkService->handleTokenLogin($verify);

            if (!$userId) {
                return response()->json([
                    'message' => __('Token error')
                ], 400);
            }

            $user = \App\Models\User::find($userId);

            if (!$user) {
                return response()->json([
                    'message' => __('User not found')
                ], 400);
            }

            $authService = new AuthService($user);

            return response()->json([
                'data' => $authService->generateAuthData()
            ]);
        }

        return response()->json([
            'message' => __('Invalid request')
        ], 400);
    }

    /**
     * 获取快速登录URL
     */
    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');

        if (!$authorization) {
            return response()->json([
                'message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED
            ], 401);
        }

        $user = AuthService::findUserByBearerToken($authorization);

        if (!$user) {
            return response()->json([
                'message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED_EXPIRED
            ], 401);
        }

        $url = $this->loginService->generateQuickLoginUrl($user, $request->input('redirect'));
        return $this->success($url);
    }

    /**
     * 忘记密码处理
     */
    public function forget(AuthForget $request)
    {
        [$success, $result] = $this->loginService->resetPassword(
            $request->input('email'),
            $request->input('email_code'),
            $request->input('password')
        );

        if (!$success) {
            return $this->fail($result);
        }

        return $this->success(true);
    }

    /**
     * Easylink compatibility: convert a guest/example.com account into a
     * normal email account and optionally set a new password.
     */
    public function updateEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email:strict',
            'new_password' => 'nullable|min:8',
            'email_code' => 'nullable',
            'invite_code' => 'nullable',
            'device_id' => 'nullable|string|max:191',
        ]);

        if ((int) admin_setting('email_verify', 0)) {
            if (empty($request->input('email_code'))) {
                return $this->fail([422, __('Email verification code cannot be empty')]);
            }
            if ((string) Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email'))) !== (string) $request->input('email_code')) {
                return $this->fail([400, __('Incorrect email verification code')]);
            }
        }

        $user = $request->user();
        if (!$user) {
            return $this->fail([403, __('Unauthorized')]);
        }

        $email = $this->normalizeEmail($request->input('email'));
        $existingUser = User::byEmail($email)->first();
        if ($existingUser && $existingUser->id !== $user->id) {
            return $this->fail([400, __('Email already exists')]);
        }

        $deviceId = trim((string) $request->input('device_id', ''));
        if ($deviceId !== '') {
            $deviceOwner = User::where('device_id', $deviceId)->first();
            if ($deviceOwner && $deviceOwner->id !== $user->id) {
                return $this->fail([400, __('This device is already linked to another account')]);
            }
            $user->device_id = $deviceId;
        }

        $inviteCode = trim((string) $request->input('invite_code', ''));
        if ($inviteCode !== '' && !$user->invite_user_id) {
            try {
                $user->invite_user_id = $this->registerService->handleInviteCode($inviteCode);
            } catch (\Throwable $e) {
                return $this->fail([400, $e->getMessage()]);
            }
        }

        $user->email = $email;
        if ($request->filled('new_password')) {
            $user->password = password_hash($request->input('new_password'), PASSWORD_DEFAULT);
            $user->password_algo = null;
            $user->password_salt = null;
        }
        $user->last_login_at = time();

        if (!$user->save()) {
            return $this->fail([500, __('Save failed')]);
        }

        if ((int) admin_setting('email_verify', 0)) {
            Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email')));
        }

        $authService = new AuthService($user);
        return $this->success($authService->generateAuthData());
    }

    /**
     * Compatibility guest login for the Easylink app.
     * Reuses a deterministic guest email derived from the device id so the
     * same device can recover the same trial account without a dedicated column.
     */
    public function loginWithDeviceId(Request $request)
    {
        $deviceId = trim((string) $request->input('device_id', ''));
        if ($deviceId === '') {
            return $this->fail([400, __('Device ID cannot be empty')]);
        }

        $user = User::where('device_id', $deviceId)->first();

        if (!$user) {
            $guestEmail = $this->buildGuestEmail($deviceId);
            $user = User::byEmail($guestEmail)->first();

            if ($user && empty($user->device_id)) {
                $user->device_id = $deviceId;
                $user->save();
            }
        }

        if (!$user) {
            $inviteCode = trim((string) ($request->input('invite_code') ?: $request->input('invite_token') ?: ''));
            $inviteUserId = null;

            if ($inviteCode !== '') {
                try {
                    $inviteUserId = $this->registerService->handleInviteCode($inviteCode);
                } catch (\Throwable $e) {
                    $inviteUserId = null;
                }
            }

            $userService = app(UserService::class);
            $user = $userService->createUser([
                'email' => $this->buildGuestEmail($deviceId),
                'password' => $deviceId,
                'invite_user_id' => $inviteUserId,
                'device_id' => $deviceId,
            ]);
            $user->last_login_at = time();

            if (!$user->save()) {
                return $this->fail([500, __('Register failed')]);
            }

            $userService->applyInviteRegistrationRewards($user);
        } else {
            $user->last_login_at = time();
            $user->save();
        }

        $authService = new AuthService($user);
        return $this->success($authService->generateAuthData());
    }

    private function buildGuestEmail(string $deviceId): string
    {
        $localPart = strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '', $deviceId) ?? '');
        if ($localPart === '') {
            $localPart = substr(sha1($deviceId), 0, 24);
        }

        return $localPart . '@example.com';
    }

    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        [$local, $domain] = explode('@', $email);

        if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
            $local = explode('+', str_replace('.', '', $local))[0];
            $domain = 'gmail.com';
        }

        return $local . '@' . rtrim($domain, '.');
    }
}
