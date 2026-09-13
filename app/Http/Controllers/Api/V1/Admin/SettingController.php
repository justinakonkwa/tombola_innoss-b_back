<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paramètres applicatifs (cahier des charges §29).
 *
 * Les paramètres sont exposés groupés pour l'écran de configuration. La mise à
 * jour est un upsert par clé : les clés inconnues sont créées, jamais les
 * colonnes sensibles (`is_public`, `group`) qui restent pilotées par le seed.
 */
class SettingController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->groupedSettings()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array', 'min:1'],
        ]);

        $map = $data['settings'];

        foreach (array_keys($map) as $key) {
            // Notation pointée autorisée (ex. notifications.channels) mais aucun
            // caractère permettant une injection ou une clé vide.
            if (! is_string($key) || ! preg_match('/^[a-zA-Z0-9_.]+$/', $key)) {
                throw ValidationException::withMessages([
                    'settings' => 'Clé de paramètre invalide.',
                ]);
            }
        }

        $keys = array_keys($map);
        $before = $this->valuesFor($keys);

        DB::transaction(function () use ($map, $request) {
            foreach ($map as $key => $value) {
                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $value, 'updated_by' => $request->user()->id],
                );
            }
        });

        $after = $this->valuesFor($keys);

        $this->audit->log(AuditAction::SettingsUpdated, null, ['settings' => $before], [
            'settings' => $after,
        ], $request->user(), ['resource_type' => 'Setting']);

        return response()->json([
            'message' => 'Paramètres mis à jour.',
            'data' => $this->groupedSettings(),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private function valuesFor(array $keys): array
    {
        return Setting::query()
            ->whereIn('key', $keys)
            ->get()
            ->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->value])
            ->all();
    }

    /**
     * Regroupe les paramètres par domaine fonctionnel.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupedSettings(): array
    {
        return Setting::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->groupBy(fn (Setting $setting) => $setting->group ?: 'general')
            ->map(fn ($items) => $items->map(fn (Setting $setting) => [
                'key' => $setting->key,
                'value' => $setting->value,
                'group' => $setting->group,
                'description' => $setting->description,
                'is_public' => (bool) $setting->is_public,
                'updated_at' => $setting->updated_at?->toIso8601String(),
            ])->values()->all())
            ->all();
    }
}
