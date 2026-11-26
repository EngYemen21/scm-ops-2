<?php

namespace App\Workspace\Support;

use App\Support\AuthUser;
use App\Workspace\Models\Ticket;
use App\Workspace\Models\TicketTeam;
use App\Workspace\Models\TicketTeamMember;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees a ticket and who works it.
 *
 *   SEES   the help-desk manager (`ticket.manage`); everybody who opens the section when the ticket's team is open
 *          (`visibility = all`); otherwise the members of the team — and always its assignee, its requester and
 *          whoever entered it.
 *   WORKS  (edit, assign, move, close, archive, write notes) the manager, the members of the ticket's team and its
 *          assignee. The requester answers on his own ticket and may reopen it; he does not work it.
 */
final class TicketAccess
{
    /** @var string[]|null */
    private ?array $memberTeams = null;

    /** @var string[]|null */
    private ?array $readableTeams = null;

    public function __construct(public readonly AuthUser $user) {}

    public function manages(): bool
    {
        return $this->user->can('ticket.manage');
    }

    /** @return string[] the teams the user is a member of */
    public function memberTeamIds(): array
    {
        return $this->memberTeams ??= TicketTeamMember::where('user_id', $this->user->id)->pluck('team_id')->map(fn ($id) => (string) $id)->unique()->values()->all();
    }

    /** @return string[] the teams whose tickets the user reads as a matter of course */
    public function readableTeamIds(): array
    {
        return $this->readableTeams ??= array_values(array_unique(array_merge(
            TicketTeam::where('visibility', 'all')->pluck('id')->map(fn ($id) => (string) $id)->all(), $this->memberTeamIds())));
    }

    /** Narrows a query of tickets to the ones the user may see. */
    public function scope(Builder $query): Builder
    {
        if ($this->manages()) {
            return $query;
        }
        $me = $this->user->id;

        return $query->where(fn (Builder $w) => $w->whereIn('team_id', $this->readableTeamIds())
            ->orWhere('assignee_id', $me)->orWhere('requester_user_id', $me)->orWhere('created_by_id', $me));
    }

    public function sees(Ticket $t): bool
    {
        return $this->manages() || in_array((string) $t->team_id, $this->readableTeamIds(), true) || $this->named($t);
    }

    public function works(Ticket $t): bool
    {
        return $this->manages() || in_array((string) $t->team_id, $this->memberTeamIds(), true) || $this->same($t->assignee_id);
    }

    public function requested(Ticket $t): bool
    {
        return $this->same($t->requester_user_id) || $this->same($t->created_by_id);
    }

    private function named(Ticket $t): bool
    {
        return $this->same($t->assignee_id) || $this->requested($t);
    }

    private function same(?string $userId): bool
    {
        return $userId !== null && strcasecmp($userId, $this->user->id) === 0;
    }
}
