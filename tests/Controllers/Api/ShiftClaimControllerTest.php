<?php

declare(strict_types=1);

namespace kintai\Tests\Bundles\ShiftClaim\Controllers\Api;


use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Bundles\Installed\ShiftClaim\Controllers\Api\ShiftClaimController;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Exceptions\ForbiddenException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftClaimRepositoryInterface;
use kintai\Core\Request;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Régression (audit RBAC du 11/09/2026) : show/update/destroy ne re-vérifiaient
 * jamais que la candidature chargée appartenait à un store géré par l'appelant,
 * et index() sans filtre renvoyait TOUTES les candidatures de TOUS les stores à
 * n'importe quel porteur de token (findAll() sans restriction) — voir le
 * docblock de ShiftClaimController.
 */
final class ShiftClaimControllerTest extends TestCase
{
    private ShiftClaimRepositoryInterface&MockObject $claims;
    private RoleAssignmentRepositoryInterface&MockObject $assignments;
    private RoleRepositoryInterface&MockObject $roles;
    private ShiftClaimController $controller;
    private StoreUserRepositoryInterface&MockObject $storeUsers;

    protected function setUp(): void
    {
        $this->claims      = $this->createMock(ShiftClaimRepositoryInterface::class);
        $this->assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $this->roles       = $this->createMock(RoleRepositoryInterface::class);
        $this->controller  = new ShiftClaimController(
            $this->claims,
            new PermissionService($this->assignments, $this->roles),
            $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class)
        );
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
    }

    private function requestAsStoreManager(int $userId, int $storeId): Request
    {
        $this->assignments->method('findByUser')->with($userId)->willReturn([
            ['id' => 1, 'user_id' => $userId, 'role_id' => 20, 'scope_type' => 'store', 'scope_id' => $storeId],
        ]);
        $this->roles->method('findById')->with(20)->willReturn(['id' => 20, 'is_system' => 0]);
        $this->roles->method('getPermissions')->with(20)->willReturn(['open_shifts.view', 'open_shifts.approve']);

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => $userId]);
        return $req;
    }

    public function testIndexWithoutFilterExcludesOtherStoresForScopedManager(): void
    {
        $this->claims->method('findAll')->willReturn([
            ['id' => 1, 'store_id' => 5],
            ['id' => 2, 'store_id' => 9],
        ]);

        $response = $this->controller->index($this->requestAsStoreManager(2, 5));
        $body = json_decode($response->body(), true);

        $this->assertCount(1, $body['data']);
        $this->assertSame(5, $body['data'][0]['store_id']);
    }

    public function testShowThrowsNotFoundForMissingClaim(): void
    {
        $this->claims->method('findById')->with(99)->willReturn(null);

        $req = $this->requestAsStoreManager(2, 5);
        $req->setRouteParams(['id' => '99']);

        $this->expectException(NotFoundException::class);
        $this->controller->show($req);
    }

    public function testShowRejectsClaimFromAnotherStore(): void
    {
        $this->claims->method('findById')->with(5)->willReturn(['id' => 5, 'store_id' => 9]);

        $req = $this->requestAsStoreManager(2, 5);
        $req->setRouteParams(['id' => '5']);

        $this->expectException(ForbiddenException::class);
        $this->controller->show($req);
    }

    public function testDestroyAllowsClaimInManagedStore(): void
    {
        $this->claims->method('findById')->with(5)->willReturn(['id' => 5, 'store_id' => 5]);
        $this->claims->expects($this->once())->method('delete')->with(5);

        $req = $this->requestAsStoreManager(2, 5);
        $req->setRouteParams(['id' => '5']);

        $response = $this->controller->destroy($req);

        $this->assertSame(204, $response->status());
    }

    public function testDestroyRejectsClaimFromAnotherStore(): void
    {
        $this->claims->method('findById')->with(5)->willReturn(['id' => 5, 'store_id' => 9]);
        $this->claims->expects($this->never())->method('delete');

        $req = $this->requestAsStoreManager(2, 5);
        $req->setRouteParams(['id' => '5']);

        $this->expectException(ForbiddenException::class);
        $this->controller->destroy($req);
    }
}
