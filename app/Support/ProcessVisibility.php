<?php

namespace App\Support;

use App\Models\QuotationRequestAssignment;
use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * Filtro de visibilidade para queries CRUAS (DB::table).
 *
 * Algumas agregações do sistema são construídas com o query builder cru por
 * razões de desempenho e não passam por scopes Eloquent — por isso o filtro
 * tem de ser escrito à mão. É exactamente a razão por que este projecto não usa
 * um global scope: daria a falsa impressão de cobrir estes casos.
 *
 * Mantém a mesma regra que QuotationRequest::scopeVisibleTo().
 */
class ProcessVisibility
{
    /**
     * @param  Builder  $query
     * @param  string  $requestIdColumn  coluna que guarda o quotation_request_id
     *                                   (ex.: 'acquisitions.quotation_request_id')
     */
    public static function applyToRaw(
        Builder $query,
        ?User $user,
        string $requestIdColumn,
        string $alias = 'vqr'
    ): Builder {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        // Admin fica intocado de propósito: assim os números que já vê não mudam
        // por causa desta alteração.
        if ($user->role === 'admin') {
            return $query;
        }

        return $query
            ->join("quotation_requests as {$alias}", $requestIdColumn, '=', "{$alias}.id")
            ->whereNull("{$alias}.deleted_at")
            ->where(function ($q) use ($user, $alias) {
                $q->where("{$alias}.user_id", $user->id)
                    ->orWhereExists(function ($sub) use ($user, $alias) {
                        $sub->selectRaw('1')
                            ->from('quotation_request_assignments as qra')
                            ->whereColumn('qra.quotation_request_id', "{$alias}.id")
                            ->where('qra.user_id', $user->id)
                            ->where('qra.status', QuotationRequestAssignment::ACTIVE);
                    });
            });
    }
}
