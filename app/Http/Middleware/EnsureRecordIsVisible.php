<?php

namespace App\Http\Middleware;

use App\Contracts\VisibilityScoped;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Impede o acesso a um registo de um processo de aquisição que o utilizador não
 * pode ver, qualquer que seja a rota.
 *
 * Aplica-se a GRUPOS de rotas em routes/api.php, de propósito: assim uma rota
 * nova acrescentada ao grupo herda a guarda, em vez de depender de alguém se
 * lembrar de a escrever no método do controller. O teste
 * tests/Feature/ScopedSurfaceTest.php assere que nenhuma rota destes prefixos
 * fica sem guarda.
 *
 * Parâmetros de rota que não implementem VisibilityScoped são ignorados — é o
 * que permite partilhar o grupo `menu:documents,read` entre o documento da
 * proposta (restrito ao processo) e os documentos do fornecedor (que todo o
 * staff de procurement vê legitimamente).
 */
class EnsureRecordIsVisible
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        foreach ($request->route()->parameters() as $parameter) {
            if (! $parameter instanceof VisibilityScoped) {
                continue;
            }

            if (! $parameter->isVisibleTo($user)) {
                abort(403, 'Não tem acesso a este processo de aquisição.');
            }
        }

        return $next($request);
    }
}
