<?php

namespace App\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Modelo cuja visibilidade depende do processo de aquisição a que pertence.
 *
 * Admin vê tudo. Um técnico vê os processos que iniciou e aqueles em que tem uma
 * atribuição activa.
 *
 * Implementar este contrato tem um efeito imediato: o middleware
 * `App\Http\Middleware\EnsureRecordIsVisible` passa a verificar automaticamente
 * qualquer parâmetro de rota deste tipo nos grupos onde está aplicado.
 */
interface VisibilityScoped
{
    /** Este utilizador pode ver este registo concreto? */
    public function isVisibleTo(?User $user): bool;

    /** Restringe a query aos registos visíveis para este utilizador. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder;
}
