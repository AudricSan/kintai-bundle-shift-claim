<?php

declare(strict_types=1);

namespace kintai\Tests\Bundles\ShiftClaim;

use kintai\Tests\Support\ContractUsageScanner;
use PHPUnit\Framework\TestCase;

/**
 * Le bundle n'utilise du Core que le contrat de bundle (kintai\Contract\…, voir docs/creating-a-bundle.md du Core).
 * Seules exceptions : les classes propres à ce bundle qui vivent encore dans le Core, listées ici ; elles rejoindront
 * ce dépôt à l'étape 4 du plan du contrat (extraction), et la liste devra alors être vide.
 */
final class ContractUsageTest extends TestCase
{
    private const PENDING_EXTRACTION = [
        'kintai\\Core\\Repositories\\DatabaseShiftClaimRepository',
        'kintai\\Core\\Repositories\\ShiftClaimRepositoryInterface',
    ];

    public function testOnlyTheBundleContractIsUsed(): void
    {
        $root = dirname(__DIR__);

        $this->assertSame([], ContractUsageScanner::violations(
            [$root . '/src', $root . '/Views', $root . '/routes.php', $root . '/database'],
            self::PENDING_EXTRACTION,
        ));
    }
}
