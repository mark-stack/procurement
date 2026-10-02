<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationDeliveryResource;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything this application has sent, bell and email in one list.
 *
 * There was no way to answer "did that customer get the email" short of reading the mail provider's
 * logs. The bell had its own table and nothing else had anything at all - see the
 * notification_deliveries migration - so this screen and the log behind it arrived together.
 *
 * Both channels in one list, rather than a page each, because the question an admin brings here is
 * about a person and not about a channel: a notification sent by both channels is two rows with one
 * timestamp, and seeing them side by side is how "we told them twice" is distinguishable from "the
 * bell lit up and no mail went". The pill says which, and the channel filter narrows to one -
 * /admin/users links straight to the email half.
 */
class AdminNotificationIndexController extends Controller
{
    /**
     * This table only grows - a row per notification per channel, forever - so the page is never
     * allowed to be "all of them". 50 matches the users list it is linked from.
     */
    private const PER_PAGE = 50;

    public function __invoke(Request $request): Response
    {
        $filters = [
            /*
             * Validated against the channels the log labels rather than taken as typed. The column
             * stores whatever the channel called itself, so an unrecognised ?channel= would quietly
             * return an empty list and read as "nothing was ever sent by email" on a page that had
             * simply been linked to wrongly.
             */
            'channel' => $this->channelFilter($request),
            //A plain id: the link from the users list carries one, and nothing else sets it
            'user' => $request->integer('user') ?: null,
        ];

        $deliveries = NotificationDelivery::query()
            /*
             * Relation, not Builder: an eager load closure is handed the relation itself. Columns
             * spelled out because the page renders a name, an address and a domain - the users list
             * next door is the cautionary tale for what loading the whole row costs here.
             */
            ->with(['notifiable' => fn (Relation $query) => $query
                ->select(['id', 'name', 'email', 'business_id'])
                ->with(['business' => fn (Relation $business) => $business->select(['id', 'domain'])]),
            ])
            ->when($filters['channel'], fn (Builder $query, string $channel) => $query->channel($channel))
            /*
             * Recipients are Users today and morphs() does not make them so. Without the type the id
             * alone would match a delivery to anything else that ever becomes notifiable.
             */
            ->when($filters['user'], fn (Builder $query, int $userId) => $query
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $userId))
            /*
             * Newest first: a notification is looked up because it just went out, or just didn't.
             * id breaks the tie because the two rows of a both-channels send share a timestamp to
             * the second and would otherwise come back in whichever order the database chose.
             */
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('AdminNotificationsIndex', [
            'deliveries' => NotificationDeliveryResource::collection($deliveries),
            'filters' => $filters,
            'channels' => NotificationDelivery::channelLabels(),
            //Counts for the filter pills, over the whole log and not the filtered page: the point of
            //them is to say how much is on the other side of the filter you are not looking at
            'totals' => $this->totals($filters['user']),
            /*
             * Who the list is narrowed to, so the heading can name them and offer a way out. An id
             * with no user behind it - a stale link, a deleted account - leaves this null and the
             * page says the filter found nobody rather than showing an unexplained empty table.
             */
            'filteredUser' => $this->filteredUser($filters['user']),
        ]);
    }

    /**
     * ?channel=, if it names a channel this log labels. Empty string for "all", which is what the
     * page and the pills treat as no filter.
     */
    private function channelFilter(Request $request): string
    {
        $channel = (string) $request->query('channel', '');

        return array_key_exists($channel, NotificationDelivery::channelLabels()) ? $channel : '';
    }

    /**
     * How many deliveries there are per channel, counted under the user filter but not the channel
     * one - so the Email pill says how many emails this person was sent while the Bell pill is
     * selected.
     *
     * @return array<string, int>
     */
    private function totals(?int $userId): array
    {
        $counts = NotificationDelivery::query()
            ->when($userId, fn (Builder $query, int $id) => $query
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $id))
            ->selectRaw('channel, count(*) as aggregate')
            ->groupBy('channel')
            ->pluck('aggregate', 'channel');

        //Keyed for every labelled channel, present or not: a pill reading nothing is worse than one
        //reading 0, and a channel with no rows yet still has to be clickable to prove it has none
        $totals = ['' => (int) $counts->sum()];

        foreach (array_keys(NotificationDelivery::channelLabels()) as $channel) {
            $totals[$channel] = (int) ($counts[$channel] ?? 0);
        }

        return $totals;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function filteredUser(?int $userId): ?array
    {
        if (! $userId) {
            return null;
        }

        $user = User::query()->select(['id', 'name', 'email'])->find($userId);

        return $user ? [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ] : null;
    }
}
