<?php

namespace Omnilab\StringDb;

use Omnilab\Model\Identifier;
use Omnilab\Model\Scheme;

/**
 * One interaction of STRING: two proteins, their combined score (0 to 1)
 * and the score of each evidence channel that supports it (neighborhood,
 * fusion, cooccurrence, coexpression, experiments, databases, textmining;
 * StringSource::CHANNELS).
 */
final readonly class Interaction
{
    /**
     * @param array<string, float> $channels the evidence channels' scores, by name
     */
    public function __construct(
        public Identifier $a,
        public Identifier $b,
        public float $score,
        public ?string $nameA = null,
        public ?string $nameB = null,
        public array $channels = [],
    ) {
    }

    /** @param array<string, mixed> $row a row of interaction_partners or network */
    public static function fromRow(array $row): ?self
    {
        $a = Identifier::tryOf(Scheme::STRING, $row['stringId_A'] ?? null);
        $b = Identifier::tryOf(Scheme::STRING, $row['stringId_B'] ?? null);
        if (null === $a || null === $b || !is_numeric($row['score'] ?? null)) {
            return null;
        }
        $channels = [];
        foreach (StringSource::CHANNELS as $key => $name) {
            if (is_numeric($row[$key] ?? null)) {
                $channels[$name] = (float) $row[$key];
            }
        }

        return new self($a, $b, (float) $row['score'], self::name($row['preferredName_A'] ?? null), self::name($row['preferredName_B'] ?? null), $channels);
    }

    /** The channels that support it, the strongest first: ['experiments' => 0.999, 'coexpression' => 0.978...]; a zero left out. */
    public function evidence(): array
    {
        $channels = array_filter($this->channels, static fn (float $score) => $score > 0);
        arsort($channels);

        return $channels;
    }

    private static function name(mixed $name): ?string
    {
        return \is_string($name) && '' !== trim($name) ? trim($name) : null;
    }
}
