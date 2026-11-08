<?php

namespace App\Services\Email;

use App\Models\EmailPayment;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Services\OrderService;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns FamApp "money received" e-mails into paid orders.
 *
 * Safety rules, in order:
 *  1. The mail must come from FamApp's sender address AND carry the mailbox
 *     provider's own SPF/DKIM/DMARC pass for that domain (forged mail fails).
 *  2. It must be a received-money mail with an amount and a UTR.
 *  3. Each message and each UTR is processed once (database unique keys).
 *  4. Only orders created before the payment, with exactly the same amount,
 *     can be paid. The payer's note (our order number) picks the order;
 *     otherwise a single open order with that amount is used. If more than
 *     one order fits, nothing is guessed and the payment is left unmatched.
 */
class EmailPaymentService
{
    public function __construct(
        private readonly FamAppEmailParser $parser,
        private readonly OrderService $orders,
    ) {}

    /**
     * @param  array{message_id: string, from: string, subject: string, body: string, received_at: string, auth_results?: ?string}  $mail
     * @return array{status: string, reason?: string, order_id?: string}
     */
    public function ingest(PaymentAccount $account, array $mail): array
    {
        $sender = strtolower((string) config('gateway.fam_email.sender'));

        if ($this->senderAddress($mail['from']) !== $sender) {
            return ['status' => 'ignored', 'reason' => 'sender_not_allowed'];
        }

        if (! $this->isAuthentic((string) ($mail['auth_results'] ?? ''))) {
            return ['status' => 'rejected', 'reason' => 'sender_not_authenticated'];
        }

        $parsed = $this->parser->parse($mail['subject'], $mail['body']);

        if ($parsed === null) {
            return ['status' => 'ignored', 'reason' => 'not_a_received_payment'];
        }

        $receivedAt = Carbon::parse($mail['received_at']);

        if ($receivedAt->lt(now()->subHours((int) config('gateway.fam_email.max_age_hours', 72)))) {
            return ['status' => 'ignored', 'reason' => 'too_old'];
        }

        $paidAt = $parsed->paidAt ?? $receivedAt;

        $duplicate = EmailPayment::where('payment_account_id', $account->id)->where('message_id', $mail['message_id'])->first()
            ?? EmailPayment::where('user_id', $account->user_id)->where('utr', $parsed->utr)->first();

        if ($duplicate) {
            return $this->result($duplicate, 'duplicate');
        }

        try {
            $payment = DB::transaction(fn () => EmailPayment::create([
                'user_id' => $account->user_id,
                'payment_account_id' => $account->id,
                'message_id' => $mail['message_id'],
                'utr' => $parsed->utr,
                'txn_id' => $parsed->txnId,
                'amount' => $parsed->amount,
                'payer_name' => $parsed->payer,
                'purpose' => $parsed->purpose,
                'paid_at' => $paidAt,
                'status' => EmailPayment::UNMATCHED,
            ]));
        } catch (UniqueConstraintViolationException) {
            return ['status' => 'duplicate'];
        }

        [$order, $outcome] = $this->findOrder($account, $payment, $paidAt);

        if ($order !== null && $this->settle($order, $payment)) {
            $payment->update(['order_id' => $order->id, 'status' => EmailPayment::MATCHED]);

            return $this->result($payment->refresh(), EmailPayment::MATCHED);
        }

        $payment->update(['status' => $outcome === EmailPayment::AMBIGUOUS ? EmailPayment::AMBIGUOUS : EmailPayment::UNMATCHED]);

        return $this->result($payment->refresh(), $payment->status);
    }

    /**
     * A customer typed their UTR on the checkout page. If that payment's mail has
     * already arrived (and was not matched), approve the order straight away.
     */
    public function reconcileSubmittedUtr(Order $order): Order
    {
        $order->refresh();

        if ($order->status !== Order::REVIEW || ! $order->customer_utr || ! $order->payment_account_id) {
            return $order;
        }

        $payment = EmailPayment::where('payment_account_id', $order->payment_account_id)
            ->where('utr', $order->customer_utr)
            ->where('amount', $order->amount)
            ->whereIn('status', [EmailPayment::UNMATCHED, EmailPayment::AMBIGUOUS])
            ->first();

        if (! $payment || ! $this->paidAfterOrderCreated($order, $payment->paid_at)) {
            return $order;
        }

        try {
            $this->orders->approveCustomerUtr($order, $payment->utr);
            $payment->update(['order_id' => $order->id, 'status' => EmailPayment::MATCHED]);
        } catch (ValidationException) {
            // UTR already used elsewhere: leave the order for the merchant to review.
        }

        return $order->refresh();
    }

    /**
     * Gmail's own verdict: DKIM must pass for the allowed domain and DMARC must pass
     * for a From address on that domain. Read from the receiving server's header.
     */
    public function isAuthentic(string $authResults): bool
    {
        $domain = preg_quote((string) config('gateway.fam_email.domain'), '/');
        $end = '(?![a-z0-9.\-])';

        $dkim = preg_match('/dkim=pass\b[^;]*?header\.(?:i|d)=@?(?:[a-z0-9\-]+\.)*'.$domain.$end.'/i', $authResults);
        $dmarc = preg_match('/dmarc=pass\b[^;]*?header\.from='.$domain.$end.'/i', $authResults);

        return $dkim === 1 && $dmarc === 1;
    }

    private function senderAddress(string $from): string
    {
        if (preg_match('/<([^<>\s]+)>\s*$/', trim($from), $m)) {
            return strtolower($m[1]);
        }

        return strtolower(trim($from));
    }

    /**
     * @return array{0: ?Order, 1: string}
     */
    private function findOrder(PaymentAccount $account, EmailPayment $payment, CarbonInterface $paidAt): array
    {
        $base = fn () => Order::where('payment_account_id', $account->id)->where('environment', Order::LIVE);

        // The customer already typed this very UTR on the checkout page.
        $reviewed = $base()->where('status', Order::REVIEW)
            ->where('customer_utr', $payment->utr)
            ->where('amount', $payment->amount)
            ->first();

        if ($reviewed && $this->paidAfterOrderCreated($reviewed, $paidAt)) {
            return [$reviewed, EmailPayment::MATCHED];
        }

        $grace = now()->subMinutes((int) config('gateway.fam_email.late_grace_minutes', 60));

        $candidates = $base()
            ->where('amount', $payment->amount)
            ->where('created_at', '<=', $paidAt->copy()->addMinutes($this->skew()))
            ->where(fn ($q) => $q->where('status', Order::PENDING)
                ->orWhere(fn ($q) => $q->where('status', Order::EXPIRED)->where('expires_at', '>', $grace)))
            ->get();

        $purpose = (string) $payment->purpose;
        $named = $candidates->filter(fn (Order $o) => $this->referencedIn($o, $purpose));

        if ($named->count() === 1) {
            return [$named->first(), EmailPayment::MATCHED];
        }

        if ($named->count() > 1) {
            return [null, EmailPayment::AMBIGUOUS];
        }

        // The payer named a different order (e.g. paid the wrong amount): never guess.
        if (preg_match('/ORD[A-Z0-9]{16}/i', $purpose)) {
            return [null, EmailPayment::UNMATCHED];
        }

        if ($candidates->count() === 1) {
            return [$candidates->first(), EmailPayment::MATCHED];
        }

        return [null, $candidates->isEmpty() ? EmailPayment::UNMATCHED : EmailPayment::AMBIGUOUS];
    }

    private function referencedIn(Order $order, string $purpose): bool
    {
        if ($purpose === '') {
            return false;
        }

        if (stripos($purpose, $order->order_no) !== false) {
            return true;
        }

        return $order->note !== null && trim($order->note) !== ''
            && strcasecmp(trim($order->note), trim($purpose)) === 0;
    }

    private function paidAfterOrderCreated(Order $order, ?CarbonInterface $paidAt): bool
    {
        return $paidAt === null || $order->created_at->lte($paidAt->copy()->addMinutes($this->skew()));
    }

    private function skew(): int
    {
        return (int) config('gateway.fam_email.clock_skew_minutes', 2);
    }

    private function settle(Order $order, EmailPayment $payment): bool
    {
        try {
            if ($order->status === Order::REVIEW) {
                $this->orders->approveCustomerUtr($order, $payment->utr);
            } else {
                $this->orders->markPaid($order, $payment->utr, $payment->txn_id, [
                    'source' => 'famapp_email',
                    'utr' => $payment->utr,
                    'txn_id' => $payment->txn_id,
                    'payer' => $payment->payer_name,
                    'purpose' => $payment->purpose,
                ], 'email');
            }
        } catch (ValidationException) {
            return false;
        }

        return $order->refresh()->status === Order::PAID;
    }

    /**
     * @return array{status: string, order_id?: string}
     */
    private function result(EmailPayment $payment, string $status): array
    {
        $result = ['status' => $status, 'payment_status' => $payment->status];

        if ($payment->order_id) {
            $result['order_id'] = Order::whereKey($payment->order_id)->value('order_no');
        }

        return $result;
    }
}
