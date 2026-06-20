<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    public static function bootAuditable()
    {
        static::created(function ($model) {
            static::logAudit('created', $model);
        });

        static::updated(function ($model) {
            static::logAudit('updated', $model);
        });

        static::deleted(function ($model) {
            static::logAudit('deleted', $model);
        });
    }

    protected static function logAudit($action, $model)
    {
        $modelName = class_basename($model);
        $user = Auth::user();

        $details = [
            'model_id' => $model->id,
        ];

        $description = null;

        if ($action === 'created') {
            $attributes = $model->toArray();
            unset($attributes['password'], $attributes['remember_token']);
            $details['new_values'] = $attributes;

            $name = $model->name ?? $model->legal_name ?? $model->commercial_name ?? $model->title ?? $model->email ?? "#{$model->id}";
            $description = "{$modelName} '{$name}' foi criado(a)";
        }

        if ($action === 'updated') {
            $changes = $model->getChanges();
            unset($changes['updated_at']);

            if (empty($changes)) {
                return;
            }

            $original = $model->getOriginal();
            $changedFields = [];
            foreach ($changes as $field => $newValue) {
                $oldValue = $original[$field] ?? null;
                $changedFields[$field] = ['old' => $oldValue, 'new' => $newValue];
            }
            $details['changes'] = $changedFields;

            $name = $model->name ?? $model->legal_name ?? $model->commercial_name ?? $model->title ?? $model->email ?? "#{$model->id}";
            $fields = implode(', ', array_keys($changedFields));
            $description = "{$modelName} '{$name}' foi atualizado(a): {$fields}";
        }

        if ($action === 'deleted') {
            $attributes = $model->toArray();
            unset($attributes['password'], $attributes['remember_token']);
            $details['deleted_values'] = $attributes;

            $name = $model->name ?? $model->legal_name ?? $model->commercial_name ?? $model->title ?? $model->email ?? "#{$model->id}";
            $description = "{$modelName} '{$name}' foi excluído(a)";
        }

        AuditLog::log(
            ucfirst($action === 'created' ? "Cadastro de {$modelName}" : ($action === 'updated' ? "Alteração de {$modelName}" : "Exclusão de {$modelName}")),
            $description,
            $details,
            $user
        );
    }
}
