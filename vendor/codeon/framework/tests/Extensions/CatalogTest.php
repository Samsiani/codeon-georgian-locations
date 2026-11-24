<?php

declare(strict_types=1);

namespace CodeOn\Framework\Tests\Extensions;

use CodeOn\Framework\Extensions\Catalog;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    public function testFreeFlagIsReadAndSurvivesTheTransientRoundTrip(): void
    {
        $catalog = Catalog::fromArray([
            'version' => '1',
            'plugins' => [
                ['pluginSlug' => 'codeon-shipping-rules', 'pluginId' => 'shipping-rules', 'free' => true, 'products' => []],
                ['pluginSlug' => 'fina-sync', 'pluginId' => 'fina-sync', 'products' => [['id' => 'fina-sync', 'priceTetri' => 30000]]],
            ],
        ]);

        $free = $catalog->findByPluginId('shipping-rules');
        $paid = $catalog->findByPluginId('fina-sync');
        self::assertNotNull($free);
        self::assertNotNull($paid);
        self::assertTrue($free->free);
        self::assertFalse($paid->free, 'Entries without the flag stay paid.');

        $restored = Catalog::fromArray($catalog->toArray());
        self::assertTrue($restored->findByPluginId('shipping-rules')?->free);
        self::assertFalse($restored->findByPluginId('fina-sync')?->free);
    }
}
