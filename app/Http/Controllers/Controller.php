<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * @OA\Info(
 *      version="1.0.0",
 *      title="MOSAP3 Procurement API",
 *      description="Documentação oficial da API do Sistema de Procurement MOSAP3.
 * 
 *      Roles:
 *      - **Admin**: Acesso total ao sistema.
 *      - **Procurement Technician**: Gestão de cotações e fornecedores.
 *      - **Public (Fornecedor)**: Acesso via token único enviado por email.",
 *      @OA\Contact(
 *          email="admin@mosap3.ao"
 *      )
 * )
 *
 * @OA\Server(
 *      url=L5_SWAGGER_CONST_HOST,
 *      description="Servidor da API"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer"
 * )
 */
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
