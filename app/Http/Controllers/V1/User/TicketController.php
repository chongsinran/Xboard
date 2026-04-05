<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\TicketSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketService;
use App\Utils\Dict;
use Illuminate\Http\Request;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
    private const COMMISSION_WITHDRAW_SUBJECT = '[Commission Withdrawal Request] This ticket is opened by the system';

    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $ticket = Ticket::where('id', $request->input('id'))
                ->where('user_id', $request->user()->id)
                ->first()
                ->load('message');
            if (!$ticket) {
                return $this->fail([400, __('Ticket does not exist')]);
            }
            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->get();
            $ticket['message']->each(function ($message) use ($ticket) {
                $message['is_me'] = ($message['user_id'] == $ticket->user_id);
            });
            return $this->success(TicketResource::make($ticket)->additional(['message' => true]));
        }
        $ticket = Ticket::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'DESC')
            ->get();
        return $this->success(TicketResource::collection($ticket));
    }

    public function save(TicketSave $request)
    {
        $ticketService = new TicketService();
        $ticket = $ticketService->createTicket(
            $request->user()->id,
            $request->input('subject'),
            $request->input('level'),
            $request->input('message')
        );
        HookManager::call('ticket.create.after', $ticket);
        return $this->success(true);

    }

    public function reply(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([400, __('Invalid parameter')]);
        }
        if (empty($request->input('message'))) {
            return $this->fail([400, __('Message cannot be empty')]);
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }
        if ($ticket->status) {
            return $this->fail([400, __('The ticket is closed and cannot be replied')]);
        }
        if ((int) admin_setting('ticket_must_wait_reply', 0) && $request->user()->id == $this->getLastMessage($ticket->id)->user_id) {
            return $this->fail(codeResponse: [400, __('Please wait for the technical enginneer to reply')]);
        }
        $ticketService = new TicketService();
        if (
            !$ticketService->reply(
                $ticket,
                $request->input('message'),
                $request->user()->id
            )
        ) {
            return $this->fail([400, __('Ticket reply failed')]);
        }
        HookManager::call('ticket.reply.user.after', $ticket);
        return $this->success(true);
    }


    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([422, __('Invalid parameter')]);
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }
        $ticket->status = Ticket::STATUS_CLOSED;
        if (!$ticket->save()) {
            return $this->fail([500, __('Close failed')]);
        }
        return $this->success(true);
    }

    private function getLastMessage($ticketId)
    {
        return TicketMessage::where('ticket_id', $ticketId)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function withdraw(TicketWithdraw $request)
    {
        if ((int) admin_setting('withdraw_close_enable', 0)) {
            return $this->fail([400, 'Unsupported withdraw']);
        }
        $withdrawMethod = $this->normalizeWithdrawMethod($request->input('withdraw_method'));
        if (!$withdrawMethod || !in_array($withdrawMethod, admin_setting('commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT))) {
            return $this->fail([422, __('Unsupported withdrawal method')]);
        }
        $user = User::find($request->user()->id);
        $limit = admin_setting('commission_withdraw_limit', 100);
        if ($limit > ($user->commission_balance / 100)) {
            return $this->fail([422, __('The current required minimum withdrawal commission is :limit', ['limit' => $limit])]);
        }
        $withdrawBalance = $request->input('withdraw_balance');
        if ($withdrawBalance !== null && (float) $withdrawBalance > ($user->commission_balance / 100)) {
            return $this->fail([422, __('Insufficient commission balance')]);
        }
        try {
            $ticketService = new TicketService();
            $subject = __(self::COMMISSION_WITHDRAW_SUBJECT);
            $messageParts = [];
            if ($withdrawBalance !== null && $withdrawBalance !== '') {
                $messageParts[] = __('Withdrawal amount') . '：' . $withdrawBalance;
            }
            $messageParts[] = __('Withdrawal method') . '：' . $withdrawMethod;
            $messageParts[] = __('Withdrawal account') . '：' . $request->input('withdraw_account');
            $message = implode("\r\n", $messageParts);
            $ticket = $ticketService->createTicket(
                $request->user()->id,
                $subject,
                2,
                $message
            );
        } catch (\Exception $e) {
            throw $e;
        }
        HookManager::call('ticket.create.after', $ticket);
        return $this->success(true);
    }

    public function listCommissionWithdraw(Request $request)
    {
        $user = User::find($request->user()->id);
        if (!$user) {
            return $this->success([]);
        }

        $tickets = Ticket::with(['message' => function ($query) {
                $query->orderBy('id', 'ASC');
            }])
            ->where('user_id', $user->id)
            ->where('subject', __(self::COMMISSION_WITHDRAW_SUBJECT))
            ->orderBy('updated_at', 'DESC')
            ->get();

        $data = $tickets->map(function (Ticket $ticket) use ($user) {
            $firstMessage = $ticket->message->first();
            $parsed = $this->parseWithdrawMessage($firstMessage?->message);

            return [
                'id' => $ticket->id,
                'user_id' => $user->id,
                'commission_balance' => $user->commission_balance,
                'withdraw_balance' => $parsed['withdraw_balance'],
                'withdraw_method' => $parsed['withdraw_method_code'],
                'withdraw_account' => $parsed['withdraw_account'],
                'type' => 1,
                'status' => $ticket->status === Ticket::STATUS_CLOSED ? 3 : 1,
                'created_at' => $ticket->created_at,
                'updated_at' => $ticket->updated_at,
                'email' => $user->email,
                'typeName' => '提现申请',
                'statusName' => $ticket->status === Ticket::STATUS_CLOSED ? '已关闭' : '待审核',
                'methodName' => $parsed['withdraw_method_name'],
            ];
        })->values();

        return $this->success($data);
    }

    public function commissionWithdraw(TicketWithdraw $request)
    {
        return $this->withdraw($request);
    }

    private function normalizeWithdrawMethod(mixed $value): ?string
    {
        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return null;
        }

        return match ($stringValue) {
            '1' => '支付宝',
            '2' => 'USDT',
            '3' => 'Paypal',
            default => $stringValue,
        };
    }

    private function getWithdrawMethodCode(?string $value): int
    {
        return match ($value) {
            '支付宝' => 1,
            'USDT' => 2,
            'Paypal', 'PAYPAL' => 3,
            default => 0,
        };
    }

    private function parseWithdrawMessage(?string $message): array
    {
        $withdrawBalance = 0;
        $withdrawMethodName = null;
        $withdrawAccount = null;

        foreach (preg_split("/\r\n|\n|\r/", (string) $message) as $line) {
            if (!str_contains($line, '：')) {
                continue;
            }
            [$label, $value] = explode('：', $line, 2);
            $label = trim($label);
            $value = trim($value);

            if ($label === __('Withdrawal amount')) {
                $withdrawBalance = (int) round(((float) $value) * 100);
                continue;
            }

            if ($label === __('Withdrawal method')) {
                $withdrawMethodName = $this->normalizeWithdrawMethod($value);
                continue;
            }

            if ($label === __('Withdrawal account')) {
                $withdrawAccount = $value;
            }
        }

        return [
            'withdraw_balance' => $withdrawBalance,
            'withdraw_method_name' => $withdrawMethodName,
            'withdraw_method_code' => $this->getWithdrawMethodCode($withdrawMethodName),
            'withdraw_account' => $withdrawAccount,
        ];
    }
}
