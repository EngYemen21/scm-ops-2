<?php

namespace App\Credit\Services;

use App\Credit\Models\CreditNotification;
use App\Credit\Models\CreditRequest;
use App\Credit\Notifications\EmailGateway;
use App\Credit\Notifications\Gateway;
use App\Credit\Notifications\NoProvider;
use App\Credit\Notifications\WhatsAppGateway;
use App\Models\Notification;
use App\Services\Core\NotifyService;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Telling people about a credit request — and writing every attempt to the notification log
 * (`credit_notifications`, Odoo's `b2b.credit.notification.log`).
 *
 *   in the application  the activity feed and the bell of a role go through NotifyService (the system's own entry);
 *                       the bell of one person is written the way the approvals and CRM sections write it, because
 *                       NotifyService has no entry for a single user.
 *   e-mail, WhatsApp    go to a Gateway. No provider is configured in this installation, so each message is logged as
 *                       `skipped` with the reason. With a provider bound, a message is handed over AFTER the change is
 *                       committed (a message is never sent for a change that was rolled back) and its line says `sent`
 *                       or `failed` — whatever the provider answered.
 *
 * A message carries the request's number, the company's name, the stage and the amounts. It never carries an identity
 * number, a bank account or the name of a paper.
 */
class Notifier
{
    public function __construct(
        private readonly NotifyService $notify,
        private readonly EmailGateway $email,
        private readonly WhatsAppGateway $whatsapp,
    ) {}

    /**
     * @param  array{roles?:array<int, ?string>, users?:array<int, ?string>, customer?:bool, feed?:bool}  $to
     *                                                                                                         roles: OPS role keys (their bell) · users: user ids (their own bell) ·
     *                                                                                                         customer: the contact of the request, by e-mail and WhatsApp · feed: a line in the activity feed
     */
    public function tell(CreditRequest $r, string $template, string $ar, string $en, ?AuthUser $by, array $to): void
    {
        $roles = array_values(array_unique(array_filter($to['roles'] ?? [])));
        $users = array_values(array_unique(array_filter($to['users'] ?? [], fn ($id) => $id !== null && $id !== '' && ! ($by && strcasecmp((string) $id, $by->id) === 0))));
        if ($roles || ! empty($to['feed'])) {
            $this->notify->activity($by, CreditLog::ENTITY, $r->id, $r->number, $ar, $en, $roles);
            foreach ($roles as $role) {
                $this->log($r, 'in_app', $template, 'sent', $ar, $en, ['recipient_role' => $role]);
            }
        }
        foreach ($users as $userId) {
            Notification::create(['user_id' => $userId, 'text_ar' => $ar, 'text_en' => $en, 'entity_type' => CreditLog::ENTITY, 'entity_id' => $r->id, 'entity_number' => $r->number, 'at' => now()]);
            $this->log($r, 'in_app', $template, 'sent', $ar, $en, ['recipient_user_id' => $userId]);
        }
        foreach ($this->addresses($roles, $users, $by) as $userId => $address) {
            $this->outbound($r, 'email', $this->email, $address, $template, $ar, $en, ['recipient_user_id' => $userId]);
        }
        if (! empty($to['customer'])) {
            $who = ['recipient_partner_ref' => $r->partner_ref, 'recipient_name' => mb_substr((string) ($r->company_name ?: $r->partner_name), 0, 190) ?: null];
            if (($address = trim((string) $r->email)) !== '') {
                $this->outbound($r, 'email', $this->email, $address, $template, $ar, $en, $who);
            }
            if (($number = trim((string) ($r->mobile ?: $r->phone))) !== '') {
                $this->outbound($r, 'whatsapp', $this->whatsapp, $number, $template, $ar, $en, $who);
            }
        }
    }

    /** One message of a channel that leaves the system: skipped while nobody is connected, otherwise handed over after the commit. */
    private function outbound(CreditRequest $r, string $channel, Gateway $gateway, string $to, string $template, string $ar, string $en, array $who): void
    {
        $who['recipient_value'] = mb_substr($to, 0, 190);
        if (! $gateway->configured()) {
            $this->log($r, $channel, $template, 'skipped', $ar, $en, $who, NoProvider::REASON);

            return;
        }
        $row = $this->log($r, $channel, $template, 'pending', $ar, $en, $who);
        $number = $r->number;
        DB::afterCommit(function () use ($row, $gateway, $to, $template, $ar, $en, $number) {
            try {
                $out = $gateway->send($to, $template, $ar, $en, ['request' => $number]);
            } catch (Throwable $e) {
                report($e);
                $out = ['ok' => false, 'error' => 'the provider did not answer ('.class_basename($e).')'];
            }
            $ok = (bool) ($out['ok'] ?? false);
            $row->forceFill(['status' => $ok ? 'sent' : 'failed', 'sent_at' => $ok ? now() : null, 'error_message' => $ok ? null : mb_substr((string) ($out['error'] ?? 'refused by the provider'), 0, 500)])->save();
        });
    }

    private function log(CreditRequest $r, string $channel, string $template, string $status, string $ar, string $en, array $who, ?string $error = null): CreditNotification
    {
        return CreditNotification::create($who + [
            'request_id' => $r->id, 'channel' => $channel, 'template_code' => $template, 'status' => $status, 'sent_at' => $status === 'sent' ? now() : null,
            'error_message' => $error, 'payload' => json_encode(['ar' => $ar, 'en' => $en], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * The e-mail addresses of the people told inside the application: the users named, and the active users of the
     * roles named — each address once, never the person who did the thing.
     *
     * @param  string[]  $roles
     * @param  string[]  $users
     * @return array<string, string> user id → address
     */
    private function addresses(array $roles, array $users, ?AuthUser $by): array
    {
        if (! $roles && ! $users) {
            return [];
        }
        $q = DB::table('users as u')->where('u.active', true)->whereNotNull('u.email')->where('u.email', '!=', '')->where(function ($w) use ($roles, $users) {
            if ($users) {
                $w->orWhereIn('u.id', $users);
            }
            if ($roles) {
                $w->orWhereExists(fn ($x) => $x->select(DB::raw('1'))->from('user_roles as ur')->join('roles as ro', 'ro.id', '=', 'ur.role_id')
                    ->whereColumn('ur.user_id', 'u.id')->whereIn('ro.key', $roles));
            }
        });
        $out = [];
        foreach ($q->orderBy('u.id')->get(['u.id', 'u.email']) as $u) {
            if ($by && strcasecmp((string) $u->id, $by->id) === 0) {
                continue;
            }
            $out[(string) $u->id] = (string) $u->email;
        }

        return $out;
    }
}
