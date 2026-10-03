<?php

namespace App\Models\Concerns;

use App\Models\AdminAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records every create, update and delete made by a signed-in super admin (FR-ADM-014).
 * Changes made by the system, seeders or the console are not audited here.
 */
trait AuditsAdminChanges
{
    public static function bootAuditsAdminChanges(): void
    {
        static::created(fn (Model $model) => self::auditAdminChange($model, 'created', null, $model->getAttributes()));

        static::updated(function (Model $model): void {
            $changes = collect($model->getChanges())->except('updated_at')->all();

            if ($changes !== []) {
                self::auditAdminChange($model, 'updated', array_intersect_key($model->getRawOriginal(), $changes), $changes);
            }
        });

        static::deleted(fn (Model $model) => self::auditAdminChange($model, 'deleted', $model->getRawOriginal(), null));
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private static function auditAdminChange(Model $model, string $event, ?array $old, ?array $new): void
    {
        $adminId = auth('admin')->id();

        if ($adminId === null) {
            return;
        }

        AdminAuditLog::query()->create([
            'admin_user_id' => $adminId,
            'auditable_type' => $model::class,
            'auditable_id' => (string) $model->getKey(),
            'event' => $event,
            'old' => $old,
            'new' => $new,
        ]);
    }
}
