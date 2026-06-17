<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Client HTTP brut vers l'API Messages d'Anthropic (Claude).
 *
 * Point unique d'appel au LLM : aucune autre classe ne doit instancier de
 * client HTTP vers api.anthropic.com. Les secrets (clé API) restent côté
 * serveur et ne transitent jamais vers Oracle ni vers le front.
 */
class ClaudeClient
{
    /**
     * Appelle POST /v1/messages et renvoie la réponse JSON décodée.
     *
     * @param  array<string, mixed>  $payload  Corps Messages API (sans model/headers, ajoutés ici).
     * @return array<string, mixed>
     */
    public function messages(array $payload): array
    {
        $apiKey = (string) config('services.anthropic.api_key');

        if ($apiKey === '') {
            throw new RuntimeException("La clé API Anthropic n'est pas configurée (ANTHROPIC_API_KEY).");
        }

        $payload['model'] ??= (string) config('services.anthropic.model', 'claude-opus-4-8');

        try {
            return Http::baseUrl((string) config('services.anthropic.base_url', 'https://api.anthropic.com'))
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
                ])
                ->acceptJson()
                ->asJson()
                ->timeout(120)
                ->post('/v1/messages', $payload)
                ->throw()
                ->json() ?? [];
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Échec de l'appel au modèle Claude : {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    /**
     * Concatène les blocs de texte d'une réponse Messages API.
     *
     * @param  array<string, mixed>  $response
     */
    public static function textFrom(array $response): string
    {
        $text = '';

        /** @var array<int, array<string, mixed>> $content */
        $content = $response['content'] ?? [];

        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return $text;
    }
}
