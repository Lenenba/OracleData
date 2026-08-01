<?php

namespace App\Domain\Resource;

/**
 * Décrit les options de requête qu'une ResourceDefinition supporte.
 *
 * Ne pas supposer que toutes les ressources Oracle supportent les mêmes options :
 * certains endpoints n'acceptent pas orderBy ou effectiveDate.
 */
final class QueryCapabilities
{
    public function __construct(
        public readonly bool $supportsFields = true,
        public readonly bool $supportsExpand = false,
        public readonly bool $supportsQ = true,
        public readonly bool $supportsOrderBy = true,
        public readonly bool $supportsLimit = true,
        public readonly bool $supportsOffset = true,
        public readonly bool $supportsEffectiveDate = false,
        public readonly bool $supportsFinder = false,
        /** @var list<string> Finders nommés disponibles (ex: 'findLatestItemByWorkerNumber') */
        public readonly array $finders = [],
    ) {}

    /** Capabilities typiques d'une collection Oracle Fusion standard. */
    public static function standard(): self
    {
        return new self(
            supportsFields: true,
            supportsExpand: false,
            supportsQ: true,
            supportsOrderBy: true,
            supportsLimit: true,
            supportsOffset: true,
        );
    }

    /** Capabilities pour une collection HCM qui supporte effectiveDate. */
    public static function hcm(): self
    {
        return new self(
            supportsFields: true,
            supportsExpand: true,
            supportsQ: true,
            supportsOrderBy: true,
            supportsLimit: true,
            supportsOffset: true,
            supportsEffectiveDate: true,
            supportsFinder: true,
        );
    }

    /** Capabilities pour un enfant sans pagination propre (liste embarquée). */
    public static function child(): self
    {
        return new self(
            supportsFields: true,
            supportsExpand: false,
            supportsQ: false,
            supportsOrderBy: false,
            supportsLimit: false,
            supportsOffset: false,
        );
    }

    /** @return array<string, bool|list<string>> */
    public function toArray(): array
    {
        return [
            'fields' => $this->supportsFields,
            'expand' => $this->supportsExpand,
            'q' => $this->supportsQ,
            'orderBy' => $this->supportsOrderBy,
            'limit' => $this->supportsLimit,
            'offset' => $this->supportsOffset,
            'effectiveDate' => $this->supportsEffectiveDate,
            'finder' => $this->supportsFinder,
            'finders' => $this->finders,
        ];
    }
}
