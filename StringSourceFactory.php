<?php

namespace Omnilab\StringDb;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * STRING: a protein and its interaction partners, the network among
 * proteins and their enrichment - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     caller_identity: 'omnilab'       # sent with every call, as STRING asks
 *     species: '9606'                  # the NCBI taxon a name or an accession is looked up in
 *     partners: 10                     # how many partners entity() asks for (0: none, one call)
 *     required_score: 400              # the lowest combined score, in thousandths (400 medium, 700 high, 900 highest)
 *     throttle: 1                      # seconds between two calls: STRING asks for one a second
 *     base_uri: 'https://string-db.org/api/json/'
 */
final class StringSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'string',
            'omnilab.required_options' => [],
            'caller_identity' => 'omnilab',
            'species' => '9606',
            'partners' => 10,
            'required_score' => 400,
            'throttle' => 1.0,
            'base_uri' => StringSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $species = $c->get('species');

        return new StringSource(
            $this->http ?? HttpClient::create(),
            \in_array($species, [null, ''], true) ? null : (string) $species,
            (int) $c->get('partners'),
            (int) $c->get('required_score'),
            (string) ($c->get('caller_identity') ?: 'omnilab'),
            (string) $c->get('base_uri', StringSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
