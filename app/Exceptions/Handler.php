<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (Throwable $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $this->handleContractException($e);
            }
        });
    }

    protected function handleContractException(Throwable $e)
    {
        if ($e instanceof BusinessException) {
            return response()->json([
                'code' => $e->getErrorCode(),
                'message' => $e->getMessage(),
                'details' => $e->getDetails(),
            ], $e->getStatusCode());
        }

        if ($e instanceof ValidationException) {
            $details = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $msg) {
                    $details[] = [
                        'field' => $field,
                        'issue' => $msg,
                    ];
                }
            }
            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'Los datos proporcionados no son válidos.',
                'details' => $details,
            ], 422);
        }

        if ($e instanceof AuthenticationException) {
            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'No autenticado o token inválido.',
                'details' => [],
            ], 401);
        }

        if ($e instanceof AccessDeniedHttpException) {
            return response()->json([
                'code' => 'FORBIDDEN_ROLE',
                'message' => 'No tiene permisos para realizar esta acción.',
                'details' => [],
            ], 403);
        }

        if ($e instanceof NotFoundHttpException) {
            return response()->json([
                'code' => 'NOT_FOUND',
                'message' => 'El recurso solicitado no fue encontrado.',
                'details' => [],
            ], 404);
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return response()->json([
                'code' => 'METHOD_NOT_ALLOWED',
                'message' => 'Método no permitido para esta ruta.',
                'details' => [],
            ], 405);
        }

        if ($e instanceof HttpException) {
            $statusCode = $e->getStatusCode();
            $code = match ($statusCode) {
                401 => 'INVALID_CREDENTIALS',
                403 => 'FORBIDDEN_ROLE',
                404 => 'NOT_FOUND',
                409 => 'CONFLICT',
                422 => 'VALIDATION_ERROR',
                default => 'HTTP_ERROR',
            };
            return response()->json([
                'code' => $code,
                'message' => $e->getMessage() ?: 'Error en la petición.',
                'details' => [],
            ], $statusCode);
        }

        // Generic 500 without leaking secrets, SQL or server paths
        return response()->json([
            'code' => 'INTERNAL_ERROR',
            'message' => 'Ocurrió un error interno en el servidor.',
            'details' => [],
        ], 500);
    }
}
