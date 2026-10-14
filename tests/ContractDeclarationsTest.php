<?php

declare(strict_types=1);

namespace kintai\Tests\Bundles\ShiftClaim;

use kintai\Contract\Extension\Action;
use kintai\Contract\Extension\NavItem;
use kintai\Core\Auth\PermissionCatalog;
use kintai\Core\Container;
use kintai\Core\Extension\Actions;
use kintai\Core\Extension\Legacy\LegacyPendingRequests;
use kintai\Core\Extension\Navigation;
use kintai\Core\Extension\StoreFeatures;
use kintai\Bundles\Installed\ShiftClaim\ShiftClaimBundle;
use PHPUnit\Framework\TestCase;

/**
 * Les déclarations du bundle (contrat 1.11) reproduisent exactement ce que le Core affichait pour lui en repli : les
 * déclarer ne change rien à l'écran. Quand le repli du Core disparaîtra (étape 5 du plan du contrat), ce test pourra
 * être retiré.
 */
final class ContractDeclarationsTest extends TestCase
{
    private const SLUG = 'shift-claim';

    private function bundle(): ShiftClaimBundle
    {
        return (new \ReflectionClass(ShiftClaimBundle::class))->newInstanceWithoutConstructor();
    }

    /** @param list<NavItem> $items */
    private static function navShape(array $items): array
    {
        return array_map(static function (NavItem $i): array {
            $v = get_object_vars($i);
            $v['links'] = $v['links'] !== null;
            return $v;
        }, $items);
    }

    /** @param list<Action> $actions */
    private static function actionShape(array $actions): array
    {
        $context = ['user_id' => 3, 'store_id' => 5, 'store_name' => 'S', 'period' => 30];
        return array_map(static function (Action $a) use ($context): array {
            $v = get_object_vars($a);
            try {
                $v['url'] = ($a->url)($context);
            } catch (\Throwable $e) {
                $v['url'] = $e::class;
            }
            return $v;
        }, $actions);
    }

    public function testNavigationMatchesTheCoreFallback(): void
    {
        $this->assertEquals(self::navShape(Navigation::legacyBundleItems()[self::SLUG] ?? []), self::navShape($this->bundle()->navigation()));
    }

    public function testPermissionsMatchTheCoreFallback(): void
    {
        $expected = array_intersect_key(PermissionCatalog::CATEGORIES, array_flip(array_keys(PermissionCatalog::CATEGORY_BUNDLES, self::SLUG, true)));
        $this->assertSame($expected, $this->bundle()->permissions());
    }

    public function testStoreFeaturesMatchTheCoreFallback(): void
    {
        $this->assertEquals(StoreFeatures::legacyBundleFeatures()[self::SLUG] ?? [], $this->bundle()->storeFeatures());
    }

    public function testActionsMatchTheCoreFallback(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $this->assertEquals(self::actionShape(Actions::legacyBundleActions()[self::SLUG] ?? []), self::actionShape($this->bundle()->actions()));
    }

    public function testPendingRequestsProvidersMatchTheCoreFallback(): void
    {
        $legacy = LegacyPendingRequests::forBundles(Container::getInstance())[self::SLUG] ?? null;
        $declared = $this->bundle()->pendingRequestsProviders();

        if ($legacy === null) {
            $this->assertSame([], $declared);
            return;
        }
        $this->assertCount(1, $declared);
        foreach (['key', 'label', 'emptyLabel', 'permission', 'storeFeature', 'order'] as $method) {
            $this->assertSame($legacy->{$method}(), $declared[0]->{$method}(), $method);
        }
    }
}
