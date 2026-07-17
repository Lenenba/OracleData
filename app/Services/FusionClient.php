<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Connexion à UN environnement (tenant) Oracle Fusion en Basic Auth.
 *
 * Lecture seule (GET) pour le moment. Point unique de communication HTTP
 * vers Fusion : aucune autre classe ne doit instancier de client HTTP vers Fusion.
 */
class FusionClient
{
    /**
     * Chemins sondés par testConnection(). Les racines publiques de Fusion
     * (`/`, catalogue REST) répondent 200/302 sans identifiants : seules les
     * ressources métier exigent le Basic Auth. Le second chemin sert de repli
     * si le premier module est absent de l'instance (404).
     *
     * @var list<string>
     */
    private const array CONNECTION_PROBE_PATHS = [
        '/hcmRestApi/resources/11.13.18.05/workers',
        '/fscmRestApi/resources/11.13.18.05/invoices',
    ];

    public function __construct(
        protected string $baseUrl,
        protected string $username,
        protected string $password,
    ) {}

    /**
     * GET générique : renvoie le JSON décodé (enveloppe Oracle complète).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        if ($this->baseUrl === '') {
            throw new RuntimeException("L'URL Oracle Fusion de cet environnement n'est pas configurée.");
        }

        $startedAt = hrtime(true);

        try {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->baseUrl($this->baseUrl)
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout((float) config('fusion.http.connect_timeout', 5))
                ->timeout((float) config('fusion.http.timeout', 30))
                ->retry(
                    (array) config('fusion.http.retry_delays', [200, 500]),
                    when: fn (Throwable $exception): bool => $this->isRetryable($exception),
                )
                ->get($path, $query)
                ->throw();

            Log::info('oracle.request.completed', [
                'path' => $path,
                'status' => $response->status(),
                'duration_ms' => $this->elapsedMilliseconds($startedAt),
            ]);

            return $response->json() ?? [];
        } catch (Throwable $e) {
            Log::warning('oracle.request.failed', [
                'path' => $path,
                'status' => $e instanceof RequestException ? $e->response->status() : null,
                'duration_ms' => $this->elapsedMilliseconds($startedAt),
                'exception' => $e::class,
            ]);

            throw new RuntimeException(
                $this->safeFailureMessage($e),
                previous: $e,
            );
        }
    }

    /**
     * Renvoie uniquement les lignes (`items`) de la réponse Fusion.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, mixed>
     */
    public function list(string $path, array $query = []): array
    {
        return $this->get($path, $query)['items'] ?? [];
    }

    /**
     * Ping de connectivité authentifié : true si l'environnement répond et
     * accepte les identifiants. Un 403 vaut succès (identifiants valides mais
     * privilège manquant sur la ressource sondée), un 401 vaut échec.
     */
    public function testConnection(): bool
    {
        foreach (self::CONNECTION_PROBE_PATHS as $path) {
            try {
                $response = Http::withBasicAuth($this->username, $this->password)
                    ->baseUrl($this->baseUrl)
                    ->acceptJson()
                    ->withoutRedirecting()
                    ->connectTimeout((float) config('fusion.http.connect_timeout', 5))
                    ->timeout((float) config('fusion.http.timeout', 30))
                    ->get($path, ['limit' => 1, 'onlyData' => 'true']);
            } catch (Throwable) {
                return false;
            }

            if ($response->status() === 404) {
                continue;
            }

            return $response->successful() || $response->status() === 403;
        }

        return false;
    }

    private function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        return $exception->response->status() === 429
            || $exception->response->serverError();
    }

    private function safeFailureMessage(Throwable $exception): string
    {
        if (! $exception instanceof RequestException) {
            return __('Oracle Fusion ne répond pas dans le délai attendu. Réessayez plus tard.');
        }

        return match ($exception->response->status()) {
            401, 403 => __("L'authentification Oracle de cet environnement a été refusée."),
            429 => __('Oracle Fusion reçoit trop de demandes. Réessayez dans quelques instants.'),
            default => __('Oracle Fusion est temporairement indisponible. Réessayez plus tard.'),
        };
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }
}
