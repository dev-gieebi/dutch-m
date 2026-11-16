<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BaseModel;
use App\Support\Paginated;
use App\Support\RequestMeta;
use Illuminate\Support\Carbon;

/** Journal d'audit horodaté (avant / après, IP, agent). */
class AuditService
{
    public function log(?int $adminUserId, string $action, string $entityType, string|int|null $entityId = null, mixed $before = null, mixed $after = null, ?RequestMeta $meta = null): void
    {
        AuditLog::create([
            'admin_user_id' => $adminUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId !== null ? (string) $entityId : null,
            'before' => $before !== null ? self::plain($before) : null,
            'after' => $after !== null ? self::plain($after) : null,
            'ip' => $meta?->ip,
            'user_agent' => $meta?->userAgent,
        ]);
    }

    /** Convertit modèles / collections en tableau JSON-compatible. */
    public static function plain(mixed $value): mixed
    {
        if ($value instanceof BaseModel) {
            return $value->attributesToArrayCamel();
        }
        if ($value instanceof \JsonSerializable || is_object($value)) {
            return json_decode(json_encode($value), true);
        }

        return $value;
    }

    public function list(array $q): array
    {
        $query = AuditLog::query()->with('adminUser:id,full_name');
        foreach (['adminUserId' => 'admin_user_id', 'entityType' => 'entity_type', 'entityId' => 'entity_id', 'action' => 'action'] as $k => $col) {
            if (! empty($q[$k])) {
                $query->where($col, $q[$k]);
            }
        }
        if (! empty($q['from'])) {
            $query->where('created_at', '>=', Carbon::parse($q['from']));
        }
        if (! empty($q['to'])) {
            $query->where('created_at', '<=', Carbon::parse($q['to']));
        }
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->forPage($q['page'], $q['limit'])->get();

        return Paginated::make($rows->map(function (AuditLog $r) {
            $arr = $r->toArray();
            $arr['adminUser'] = $r->adminUser ? ['id' => $r->adminUser->id, 'fullName' => $r->adminUser->full_name] : null;

            return $arr;
        })->all(), $total, $q);
    }
}
