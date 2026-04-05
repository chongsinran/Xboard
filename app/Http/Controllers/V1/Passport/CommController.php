<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Models\InviteCode;
use App\Models\User;
use App\Services\CaptchaService;
use App\Services\MailService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CommController extends Controller
{

    public function sendEmailVerify(CommSendEmailVerify $request)
    {
        $email = (string) $request->input('email');

        Log::info('sendEmailVerify:start', [
            'email' => $email,
            'ip' => $request->ip(),
            'route' => $request->path(),
        ]);

        // 验证人机验证码
        $captchaService = app(CaptchaService::class);
        [$captchaValid, $captchaError] = $captchaService->verify($request);
        if (!$captchaValid) {
            Log::warning('sendEmailVerify:captcha_failed', [
                'email' => $email,
                'ip' => $request->ip(),
                'error' => $captchaError,
            ]);
            return $this->fail($captchaError);
        }

        // 检查白名单后缀限制
        if ((int) admin_setting('email_whitelist_enable', 0)) {
            $isRegisteredEmail = User::byEmail($email)->exists();
            if (!$isRegisteredEmail) {
                $allowedSuffixes = Helper::getEmailSuffix();
                $emailSuffix = substr(strrchr($email, '@'), 1);

                if (!in_array($emailSuffix, $allowedSuffixes)) {
                    Log::warning('sendEmailVerify:whitelist_rejected', [
                        'email' => $email,
                        'ip' => $request->ip(),
                        'suffix' => $emailSuffix,
                        'allowed_suffixes' => $allowedSuffixes,
                    ]);
                    return $this->fail([400, __('Email suffix is not in whitelist')]);
                }
            }
        }

        if (Cache::get(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email))) {
            Log::warning('sendEmailVerify:cooldown_blocked', [
                'email' => $email,
                'ip' => $request->ip(),
            ]);
            return $this->fail([400, __('Email verification code has been sent, please request again later')]);
        }
        $code = rand(100000, 999999);
        $subject = admin_setting('app_name', 'XBoard') . __('Email verification code');

        $mailLog = MailService::sendEmail([
            'email' => $email,
            'subject' => $subject,
            'template_name' => 'verify',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'code' => $code,
                'url' => admin_setting('app_url')
            ]
        ]);
        Log::info('sendEmailVerify:mail_sent', [
            'email' => $email,
            'ip' => $request->ip(),
            'subject' => $subject,
            'error' => $mailLog['error'] ?? null,
        ]);

        if (!empty($mailLog['error'])) {
            Log::warning('sendEmailVerify:mail_failed', [
                'email' => $email,
                'ip' => $request->ip(),
                'error' => $mailLog['error'],
            ]);
            return $this->fail([500, $mailLog['error']]);
        }

        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), $code, 300);
        Cache::put(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email), time(), 60);
        Log::info('sendEmailVerify:cache_written', [
            'email' => $email,
            'ip' => $request->ip(),
            'code_cache_key' => CacheKey::get('EMAIL_VERIFY_CODE', $email),
            'cooldown_cache_key' => CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email),
        ]);
        return $this->success(true);
    }

    public function pv(Request $request)
    {
        $inviteCode = InviteCode::where('code', $request->input('invite_code'))->first();
        if ($inviteCode) {
            $inviteCode->pv = $inviteCode->pv + 1;
            $inviteCode->save();
        }

        return $this->success(true);
    }

}
