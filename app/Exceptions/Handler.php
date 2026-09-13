<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException as LaravelAuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Traduction uniforme des erreurs en JSON (cahier des charges §14).
 * Aucune trace d'exécution n'est exposée en production.
 */
class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'refresh_token',
    ];

    /**
     * Toute route `/api/*` répond en JSON, même si le client n'envoie pas
     * d'en-tête `Accept`.
     *
     * Le middleware `ForceJsonResponse` positionne bien l'en-tête, mais trop
     * tard : Symfony met en cache les types de contenu acceptés dès qu'un
     * middleware antérieur les consulte, si bien que `expectsJson()` peut
     * rester faux et qu'une requête non authentifiée finissait en page HTML
     * « Route [login] not defined » (HTTP 500) au lieu d'un 401 JSON.
     */
    protected function shouldReturnJson($request, Throwable $e): bool
    {
        return $request->is('api/*') || parent::shouldReturnJson($request, $e);
    }

    private function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public function register(): void
    {
        $this->renderable(function (AuthenticationException $e, Request $request) {
            if (! $this->wantsJson($request)) {
                return null;
            }

            return response()->json(['message' => $e->getMessage()], 401);
        });

        $this->renderable(function (OrderException|TicketIssuanceException|DrawException $e, Request $request) {
            if (! $this->wantsJson($request)) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => class_basename($e),
            ], 422);
        });

        $this->renderable(function (PaymentProviderException $e, Request $request) {
            if (! $this->wantsJson($request)) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'payment_provider_error',
            ], 502);
        });

        $this->renderable(function (LaravelAuthenticationException $e, Request $request) {
            if (! $this->wantsJson($request)) {
                return null;
            }

            return response()->json(['message' => 'Authentification requise.'], 401);
        });

        $this->renderable(function (AuthorizationException $e, Request $request) {
            if (! $this->wantsJson($request)) {
                return null;
            }

            return response()->json(['message' => 'Action non autorisée.'], 403);
        });

        $this->renderable(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if (! $this->wantsJson($request)) {
                return null;
            }

            return response()->json(['message' => 'Ressource introuvable.'], 404);
        });

        $this->renderable(function (ValidationException $e, Request $request) {
            if (! $this->wantsJson($request)) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->status);
        });

        $this->renderable(function (Throwable $e, Request $request) {
            if (! $this->wantsJson($request)) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface) {
                return null;
            }

            // Aucun détail technique n'est renvoyé au client en production.
            return response()->json([
                'message' => app()->hasDebugModeEnabled()
                    ? $e->getMessage()
                    : 'Une erreur interne est survenue. L’incident a été journalisé.',
                'code' => 'server_error',
            ], 500);
        });
    }
}
