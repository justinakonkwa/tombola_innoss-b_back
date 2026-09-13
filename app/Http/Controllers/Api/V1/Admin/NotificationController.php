<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditAction;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Notifications du back-office (cahier des charges §24).
 *
 * Une diffusion est plafonnée à 5 000 destinataires par appel : au-delà, il
 * faut découper l'audience pour ne pas saturer la file d'envoi ni la requête.
 */
class NotificationController extends Controller
{
    /** Plafond de destinataires par diffusion (protection de la file). */
    private const MAX_RECIPIENTS = 5000;

    private const SORTABLE = ['created_at', 'sent_at', 'status'];

    /** Publics autorisés pour une diffusion. */
    private const AUDIENCES = ['all', 'campaign_participants', 'user_ids'];

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(NotificationStatus::class)],
            'channel' => ['nullable', Rule::enum(NotificationChannel::class)],
            'template' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'uuid'],
            'sort' => ['nullable', 'string', 'max:32'],
        ]);

        $query = Notification::query()
            ->with('user')
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $term = '%'.mb_strtolower((string) $request->string('search')).'%';

                $query->where(fn (Builder $q) => $q
                    ->whereRaw("LOWER(COALESCE(subject, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(body, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(destination, '')) LIKE ?", [$term]));
            })
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->string('status')))
            ->when($request->filled('channel'), fn (Builder $q) => $q->where('channel', (string) $request->string('channel')))
            ->when($request->filled('template'), fn (Builder $q) => $q->where('template', (string) $request->string('template')))
            ->when($request->filled('user_id'), fn (Builder $q) => $q->where('user_id', (string) $request->string('user_id')));

        $this->applySort($query, $request);

        return NotificationResource::collection($query->paginate($this->perPage($request)))->response();
    }

    /** Diffuse un message à un public ciblé et renvoie le nombre de notifications mises en file. */
    public function broadcast(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(self::AUDIENCES)],
            'campaign_id' => ['required_if:audience,campaign_participants', 'uuid', 'exists:campaigns,id'],
            'user_ids' => ['required_if:audience,user_ids', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'user_ids.*' => ['uuid', 'exists:users,id'],
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'channels' => ['nullable', 'array', 'min:1'],
            'channels.*' => [Rule::enum(NotificationChannel::class)],
        ]);

        $channels = $data['channels'] ?? config('tombola.notifications.channels', ['email']);
        $recipients = $this->recipients($request, $data);

        foreach ($recipients as $user) {
            $this->notifications->send(
                $user,
                'admin_broadcast',
                [],
                $channels,
                $data['subject'],
                $data['body'],
            );
        }

        $queued = $recipients->count() * max(1, count($channels));

        // Aucun cas AuditAction dédié à la diffusion : on rattache l'action au cas
        // générique le plus proche en conservant le détail de l'audience.
        $this->audit->log(AuditAction::CampaignUpdated, null, [], [
            'operation' => 'notification_broadcast',
            'audience' => $data['audience'],
            'campaign_id' => $data['campaign_id'] ?? null,
            'recipients' => $recipients->count(),
            'queued' => $queued,
            'channels' => array_values($channels),
            'subject' => $data['subject'],
        ], $request->user(), ['resource_type' => 'Notification']);

        return response()->json([
            'message' => 'Diffusion mise en file.',
            'data' => [
                'recipients' => $recipients->count(),
                'queued' => $queued,
                'capped' => $recipients->count() >= self::MAX_RECIPIENTS,
            ],
        ]);
    }

    /**
     * Résout les destinataires en appliquant le plafond de diffusion.
     *
     * @param  array<string, mixed>  $data
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function recipients(Request $request, array $data): \Illuminate\Database\Eloquent\Collection
    {
        $query = User::query()->where('status', UserStatus::Active->value);

        if ($data['audience'] === 'campaign_participants') {
            $query->whereIn('id', Ticket::query()
                ->where('campaign_id', $data['campaign_id'])
                ->select('user_id'));
        }

        if ($data['audience'] === 'user_ids') {
            $query->whereIn('id', $data['user_ids']);
        }

        return $query->orderBy('id')->limit(self::MAX_RECIPIENTS)->get();
    }

    private function perPage(Request $request): int
    {
        return max(1, min((int) $request->integer('per_page', 25), 100));
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function applySort(Builder $query, Request $request, array $allowed = self::SORTABLE, string $default = '-created_at'): void
    {
        $sort = (string) $request->string('sort', $default);
        $column = ltrim($sort, '-');

        if (! in_array($column, $allowed, true)) {
            $column = ltrim($default, '-');
            $sort = $default;
        }

        $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc');
    }
}
