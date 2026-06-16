<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
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
        try {
            return Http::withBasicAuth($this->username, $this->password)
                ->baseUrl($this->baseUrl)
                ->acceptJson()
                ->get($path, $query)
                ->throw()
                ->json() ?? [];
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Échec de la requête Oracle Fusion : {$e->getMessage()}",
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
     * Ping léger de connectivité : true si l'environnement répond avec succès.
     */
    public function testConnection(): bool
    {
        try {
            return Http::withBasicAuth($this->username, $this->password)
                ->baseUrl($this->baseUrl)
                ->acceptJson()
                ->get('/')
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
