<?php

namespace Omnilab\StringDb;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * STRING, the database of known and predicted protein-protein interactions
 * (SIB, CPR, EMBL), through its API: a protein by its STRING id
 * ("9606.ENSP00000269305", TP53), a UniProt accession, an Ensembl protein
 * or a name in a species - its preferred name, annotation, species, its
 * Ensembl protein as a cross-reference, and its interaction partners as
 * related things, each partner's combined score a property; the network
 * among several proteins and their functional enrichment (network(),
 * enrichment(), outside the contract).
 *
 * No key, no account. STRING asks for one call a second at most and for
 * the caller's identity in every call: a 1 s throttle by default and the
 * caller_identity parameter. Two calls an entity (the mapping, the
 * partners).
 */
final class StringSource extends HttpSource
{
    public const BASE_URI = 'https://string-db.org/api/json/';

    /** STRING's evidence channels, as its API names their scores. */
    public const CHANNELS = [
        'nscore' => 'neighborhood',
        'fscore' => 'fusion',
        'pscore' => 'cooccurrence',
        'ascore' => 'coexpression',
        'escore' => 'experiments',
        'dscore' => 'databases',
        'tscore' => 'textmining',
    ];

    /**
     * @param string|null $species       the NCBI taxon a name or an accession is looked up in ("9606"); a STRING id says its own
     * @param int         $partners      how many partners entity() asks for, the best first (0: none, one call)
     * @param int         $requiredScore the lowest combined score of an interaction, in thousandths (400: STRING's medium confidence)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly ?string $species = '9606',
        private readonly int $partners = 10,
        private readonly int $requiredScore = 400,
        private readonly string $callerIdentity = 'omnilab',
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 1.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'string';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::CROSS_REFERENCES];
    }

    /**
     * A protein by its STRING id ("9606.ENSP00000269305", "string:...",
     * its page), by a UniProt accession or an Ensembl protein (in the
     * configured species), or by a name STRING maps ("TP53", in the
     * configured species); another base's identifier is refused before
     * any call; null when STRING maps nothing.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $identifier = self::identify($id, Scheme::STRING, Scheme::UNIPROT, Scheme::ENSEMBL);
        if (null === $identifier) {
            $name = \is_string($id) ? trim($id) : '';
            if ($id instanceof Identifier || '' === $name || null !== Identifier::parse($name) || null === $this->species) {
                throw NotSupportedException::identifier('string', (string) $id);
            }
        }
        $query = $identifier?->value ?? $name;
        $species = Scheme::STRING === $identifier?->scheme ? explode('.', $identifier->value, 2)[0] : $this->species;
        $found = $this->call('get_string_ids', ['identifiers' => $query, 'species' => $species, 'limit' => 1])[0] ?? null;
        if (!\is_array($found) || !isset($found['stringId'])) {
            return null;
        }
        $partners = $this->partners > 0 ? $this->partners((string) $found['stringId'], $this->partners) : null;

        return $this->toEntity($found, $partners, Scheme::UNIPROT === $identifier?->scheme ? $identifier : null);
    }

    /**
     * A protein's interaction partners, the best first, at the configured
     * score or more: a STRING id, or what entity() reads. Outside the
     * contract (an interaction is two things and a score, not an entity).
     *
     * @return list<Interaction>
     */
    public function partners(Identifier|string $protein, int $limit = 10): array
    {
        $id = $this->stringId($protein);
        if (null === $id) {
            return [];
        }
        $rows = $this->call('interaction_partners', ['identifiers' => $id, 'species' => explode('.', $id, 2)[0], 'limit' => max(1, $limit), 'required_score' => $this->requiredScore]);

        return array_values(array_filter(array_map(static fn (mixed $row) => \is_array($row) ? Interaction::fromRow($row) : null, $rows)));
    }

    /**
     * The interactions among proteins (two or more, of one species: STRING
     * ids, or names in the configured species), at the configured score or
     * more. Outside the contract.
     *
     * @param list<Identifier|string> $proteins
     *
     * @return list<Interaction>
     */
    public function network(array $proteins): array
    {
        [$ids, $species] = $this->list($proteins);
        if (!$ids) {
            return [];
        }
        $rows = $this->call('network', ['identifiers' => implode("\r", $ids), 'species' => $species, 'required_score' => $this->requiredScore]);

        return array_values(array_filter(array_map(static fn (mixed $row) => \is_array($row) ? Interaction::fromRow($row) : null, $rows)));
    }

    /**
     * The functional enrichment of a set of proteins against their genome
     * (GO processes, functions and components, KEGG and Reactome pathways,
     * Pfam and InterPro domains, diseases, tissues...): every term STRING
     * finds at a false discovery rate under 5 %, the most significant first
     * in each category. Outside the contract.
     *
     * @param list<Identifier|string> $proteins
     *
     * @return list<Enrichment>
     */
    public function enrichment(array $proteins): array
    {
        [$ids, $species] = $this->list($proteins);
        if (!$ids) {
            return [];
        }
        $rows = $this->call('enrichment', ['identifiers' => implode("\r", $ids), 'species' => $species]);

        return array_values(array_filter(array_map(static fn (mixed $row) => \is_array($row) ? Enrichment::fromRow($row) : null, $rows)));
    }

    /**
     * @param array<string, mixed>   $found    get_string_ids' row
     * @param list<Interaction>|null $partners
     */
    private function toEntity(array $found, ?array $partners, ?Identifier $asked): Entity
    {
        $id = Identifier::of(Scheme::STRING, (string) $found['stringId']);
        [$taxon, $protein] = explode('.', $id->value, 2);
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'string');
            }
        };
        $name = self::text($found['preferredName'] ?? null);
        $add('preferred_name', 'Preferred name', $name);
        $add('species', 'Species', self::text($found['taxonName'] ?? null));
        $related = ['organism' => Identifiers::of(Identifier::tryOf(Scheme::NCBI_TAXON, $taxon))];
        if (null !== $partners) {
            foreach ($partners as $partner) {
                $add('interaction', 'Interaction with '.($partner->nameB ?? $partner->b->value), $partner->score);
            }
            $related['interactor'] = new Identifiers(array_map(static fn (Interaction $i) => $i->b, $partners));
        }
        // "Name; description": the annotation is UniProt's protein name, then its function.
        $annotation = self::text($found['annotation'] ?? null);
        $annotation = null === $annotation ? null : (string) preg_replace('~\s*\[\.\.\.\]\s*$~', '…', $annotation);
        $fullName = null;
        if (null !== $annotation && preg_match('~^([^;]{1,200});\s*(.*)$~s', $annotation, $m)) {
            [$fullName, $annotation] = [$m[1], '' === trim($m[2]) ? null : $m[2]];
        }
        $add('protein_name', 'Protein name', $fullName);

        return new Entity(
            id: $id,
            label: $name ?? $id->value,
            domain: Domain::BIOLOGY,
            kind: 'protein',
            synonyms: null === $fullName ? [] : [$fullName],
            description: $annotation,
            properties: $properties,
            identifiers: Identifiers::of(Identifier::tryOf(Scheme::ENSEMBL, $protein), $asked),
            url: $id->url(),
            image: 'https://string-db.org/api/image/network?'.http_build_query(['identifiers' => $id->value, 'species' => $taxon, 'caller_identity' => $this->callerIdentity]),
            source: 'string',
            related: array_filter($related, static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }

    /** The STRING id of a protein: a STRING id as given, anything else mapped (one call); null when STRING maps nothing. */
    private function stringId(Identifier|string $protein): ?string
    {
        $identifier = self::identify($protein, Scheme::STRING);
        if (null !== $identifier) {
            return $identifier->value;
        }
        if ($protein instanceof Identifier && !\in_array($protein->scheme, [Scheme::UNIPROT, Scheme::ENSEMBL], true)) {
            throw NotSupportedException::identifier('string', (string) $protein);
        }
        $value = $protein instanceof Identifier ? $protein->value : trim($protein);
        $found = '' === $value ? null : ($this->call('get_string_ids', ['identifiers' => $value, 'species' => $this->species, 'limit' => 1])[0] ?? null);

        return \is_array($found) && isset($found['stringId']) ? (string) $found['stringId'] : null;
    }

    /**
     * The proteins as STRING takes a list of them: STRING ids or names, and
     * the species (the STRING ids', else the configured one).
     *
     * @param list<Identifier|string> $proteins
     *
     * @return array{0: list<string>, 1: ?string}
     */
    private function list(array $proteins): array
    {
        $ids = [];
        $species = null;
        foreach ($proteins as $protein) {
            $identifier = self::identify($protein, Scheme::STRING, Scheme::UNIPROT, Scheme::ENSEMBL);
            if (null === $identifier && $protein instanceof Identifier) {
                throw NotSupportedException::identifier('string', (string) $protein);
            }
            if (Scheme::STRING === $identifier?->scheme) {
                $species ??= explode('.', $identifier->value, 2)[0];
            }
            $value = $identifier?->value ?? trim((string) $protein);
            if ('' !== $value) {
                $ids[] = $value;
            }
        }

        return [array_values(array_unique($ids)), $species ?? $this->species];
    }

    /**
     * One call of the API, the caller's identity added.
     *
     * @param array<string, mixed> $query
     *
     * @return list<mixed>
     */
    private function call(string $method, array $query): array
    {
        return array_values($this->getJson($method, $query + ['caller_identity' => $this->callerIdentity]) ?? []);
    }
}
