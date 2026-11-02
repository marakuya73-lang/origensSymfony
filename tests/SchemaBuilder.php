<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

final class SchemaBuilder
{
    public static function rebuild(EntityManagerInterface $em): void
    {
        $connection = $em->getConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS artefacts');
        $connection->executeStatement('DROP TABLE IF EXISTS artefact_counters');
        $connection->executeStatement('DROP TABLE IF EXISTS messenger_messages');

        $tool = new SchemaTool($em);
        $metadata = array_values(array_filter(
            $em->getMetadataFactory()->getAllMetadata(),
            static fn (object $meta): bool => str_starts_with($meta->getName(), 'App\\Entity\\'),
        ));
        if ($metadata !== []) {
            $tool->createSchema($metadata);
        }
    }
}
