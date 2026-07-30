<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Client HTTP vers l'API de monitoring Oracle Integration Cloud (OIC).
 *
 * Seuls les endpoints de lecture du monitoring sont utilisés. Les identifiants
 * (Basic Auth) sont injectés côté serveur — jamais exposés au frontend.
 *
 * Documentation OIC REST :
 *  GET /ic/api/integration/v1/integrations
 *  GET /ic/api/integration/v1/monitoring/integrations
 *  GET /ic/api/integration/v1/monitoring/integrations/{id}/instances
 *  GET /ic/api/integration/v1/monitoring/errors
 */
class OicClient
{
    private const string BASE_PATH = '/ic/api/integration/v1';

    public function __construct(
        protected string $baseUrl,
        protected string $username,
        protected string $password,
    ) {}

    /**
     * List all configured integrations (name, status, version, description).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function integrations(array $query = []): array
    {
        return $this->get('/integrations', array_merge([
            'fields' => 'id,code,name,description,status,style,lastUpdatedTime',
            'limit' => 200,
        ], $query));
    }

    /**
     * Summary stats per integration over the given time window.
     * Returns counts for COMPLETED, FAILED, ABORTED and PROCESSING instances.
     *
     * @param  array<string, mixed>  $query  e.g. ['startTime' => '...', 'endTime' => '...']
     * @return array<string, mixed>
     */
    public function monitoringIntegrations(array $query = []): array
    {
        return $this->get('/monitoring/integrations', $query);
    }

    /**
     * Execution instances for one integration, with optional status filter.
     *
     * @param  array<string, mixed>  $query  e.g. ['status' => 'FAILED', 'limit' => 50]
     * @return array<string, mixed>
     */
    public function instances(string $integrationId, array $query = []): array
    {
        return $this->get(
            '/monitoring/integrations/'.rawurlencode($integrationId).'/instances',
            array_merge(['limit' => 50, 'orderBy' => 'START_TIME:DESC'], $query),
        );
    }

    /**
     * Detail of a single instance including step trace and error payload.
     *
     * @return array<string, mixed>
     */
    public function instance(string $integrationId, string $instanceId): array
    {
        return $this->get(
            '/monitoring/integrations/'.rawurlencode($integrationId).'/instances/'.rawurlencode($instanceId),
        );
    }

    /**
     * All errors across integrations, optionally filtered by time range.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function errors(array $query = []): array
    {
        return $this->get('/monitoring/errors', array_merge(['limit' => 100], $query));
    }

    /**
     * Connectivity probe for OIC Gen2 (OCI).
     *
     * OIC Gen2 issues a 307 redirect to the shared gateway, then applies
     * Oracle IDCS Basic Auth. Acceptable outcomes after following redirects:
     *  - 2xx  → authenticated and authorised
     *  - 403  → authenticated, role restriction only
     *  - 401  → IDCS reachable but credentials rejected
     *
     * We throw a descriptive RuntimeException on 401 so the caller can surface
     * a meaningful "wrong username/password" message to the user instead of the
     * generic "unreachable" fallback.
     */
    public function testConnection(): bool
    {
        try {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->baseUrl($this->baseUrl)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->get(self::BASE_PATH.'/integrations', ['limit' => 1, 'fields' => 'id']);

            if ($response->successful() || $response->status() === 403) {
                return true;
            }

            if ($response->status() === 401) {
                throw new RuntimeException(
                    __("L'authentification OIC a été refusée. Vérifiez le nom d'utilisateur et le mot de passe Oracle Cloud (IDCS)."),
                );
            }

            return false;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $startedAt = hrtime(true);

        try {
            // OIC Gen2 issues a 307 to its shared gateway — follow it.
            // Use a tighter timeout than Fusion so error pages render quickly.
            $response = Http::withBasicAuth($this->username, $this->password)
                ->baseUrl($this->baseUrl)
                ->acceptJson()
                ->connectTimeout((float) config('fusion.http.connect_timeout', 5))
                ->timeout((float) config('fusion.oic.http_timeout', 20))
                ->get(self::BASE_PATH.$path, $query);

            // Surface auth errors as a clear message rather than a raw 401.
            if ($response->status() === 401) {
                throw new RuntimeException(
                    __("L'authentification OIC a été refusée. Vérifiez les identifiants Oracle Cloud du tenant."),
                );
            }

            $response->throw();

            Log::info('oic.request.completed', [
                'path'        => $path,
                'status'      => $response->status(),
                'duration_ms' => $this->elapsedMs($startedAt),
            ]);

            return $response->json() ?? [];

        } catch (RuntimeException $e) {
            Log::warning('oic.request.failed', [
                'path'        => $path,
                'status'      => null,
                'duration_ms' => $this->elapsedMs($startedAt),
                'message'     => $e->getMessage(),
            ]);
            throw $e;
        } catch (Throwable $e) {
            Log::warning('oic.request.failed', [
                'path'        => $path,
                'status'      => $e instanceof RequestException ? $e->response->status() : null,
                'duration_ms' => $this->elapsedMs($startedAt),
                'exception'   => $e::class,
            ]);

            throw new RuntimeException(
                $e instanceof RequestException
                    ? match ($e->response->status()) {
                        403  => __("Accès refusé : le compte OIC ne dispose pas des droits de monitoring (rôle ServiceAdministrator requis)."),
                        429  => __('OIC reçoit trop de demandes. Réessayez dans quelques instants.'),
                        default => __('Oracle Integration Cloud est temporairement indisponible.'),
                    }
                    : __('Oracle Integration Cloud ne répond pas dans le délai attendu.'),
                previous: $e,
            );
        }
    }

    private function elapsedMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }
}
