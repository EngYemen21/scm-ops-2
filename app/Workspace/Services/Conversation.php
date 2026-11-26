<?php

namespace App\Workspace\Services;

use App\Support\AuthUser;
use App\Workspace\Models\Message;
use App\Workspace\Models\WsFile;
use App\Workspace\Support\People;
use App\Workspace\Support\Shape;
use App\Workspace\Support\Ws;
use DateTimeInterface;

/**
 * The thread of a ticket or a task (`ws_messages`): what people wrote — replies and internal notes on a ticket, comments
 * on a task, each with its files — and what happened to the record, told as events at the moment it happened. Lines are
 * only ever added, so the history of a record is never rewritten. Every method here joins the caller's transaction.
 */
class Conversation
{
    /** How many lines of a thread are read (the latest ones). */
    private const LIMIT = 500;

    public function __construct(private readonly FilesService $files) {}

    /**
     * One line telling what happened to the record.
     *
     * @param  array<string, mixed>  $data  the facts: from / to (a name, or `{ ar, en }`), reason, fields
     */
    public function event(string $thread, string $threadId, string $event, ?AuthUser $user, array $data = [], ?string $body = null, ?DateTimeInterface $at = null): Message
    {
        return Message::create([
            'thread_type' => $thread, 'thread_id' => $threadId, 'kind' => 'event', 'event' => $event, 'body' => Ws::text($body),
            'data' => array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []) ?: null,
            'author_id' => $user?->id, 'author_name' => $user?->nameAr, 'at' => $at ?? now(),
        ]);
    }

    /**
     * What somebody wrote, with its files (already decoded by FilesService::decode).
     *
     * @param  array<int, array>  $files
     */
    public function post(AuthUser $user, string $thread, string $threadId, string $kind, ?string $body, array $files = []): Message
    {
        $message = Message::create(['thread_type' => $thread, 'thread_id' => $threadId, 'kind' => $kind, 'body' => Ws::text($body), 'author_id' => $user->id, 'author_name' => $user->nameAr, 'at' => now()]);
        $this->files->keepAll($user, 'message', $message->id, $files);

        return $message;
    }

    /**
     * The thread, the oldest line first, as the client reads it.
     *
     * @param  bool  $manages  the caller manages the tool the thread belongs to (he may take any file off)
     * @return array<int, array>
     */
    public function thread(AuthUser $user, string $thread, string $threadId, bool $manages): array
    {
        $messages = Message::with('author:'.People::COLUMNS)->where('thread_type', $thread)->where('thread_id', $threadId)->whereNull('removed_at')
            ->orderByDesc('at')->orderByDesc('id')->limit(self::LIMIT)->get()->reverse()->values();
        $files = $this->files->of('message', $messages->pluck('id')->all(), fn (WsFile $f) => FilesService::removableBy($user, $f, $manages));

        return $messages->map(fn (Message $m) => Shape::message($m, $files[(string) $m->id] ?? []))->all();
    }
}
