<?php

declare(strict_types=1);

namespace kintai\Tests\Bundles\ShiftClaim\Controllers\Web;


use kintai\Bundles\Installed\ShiftClaim\Controllers\Web\EmployeeShiftClaimController;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftClaimRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\NotificationService;
use kintai\UI\ViewRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class EmployeeShiftClaimControllerTest extends TestCase
{
    private ShiftRepositoryInterface&MockObject $shifts;
    private StoreRepositoryInterface&MockObject $stores;
    private StoreUserRepositoryInterface&MockObject $storeUsers;
    private ShiftClaimRepositoryInterface&MockObject $shiftClaims;
    private NotificationService&MockObject $notifs;
    private EmployeeShiftClaimController $controller;

    protected function setUp(): void
    {
        $viewDir = sys_get_temp_dir() . '/kintai-shift-claim-views';
        $this->ensureViewFile($viewDir, 'open-shifts');
        $this->ensureViewFile(sys_get_temp_dir(), 'layout.app');

        $view = new ViewRenderer(sys_get_temp_dir());
        $view->addNamespace('shift-claim', $viewDir);

        $this->shifts = $this->createMock(ShiftRepositoryInterface::class);
        $this->stores = $this->createMock(StoreRepositoryInterface::class);
        $this->storeUsers = $this->createMock(StoreUserRepositoryInterface::class);
        $this->shiftClaims = $this->createMock(ShiftClaimRepositoryInterface::class);
        $this->notifs = $this->createMock(NotificationService::class);

        $this->controller = new EmployeeShiftClaimController(
            $view,
            $this->shifts,
            $this->createMock(ShiftTypeRepositoryInterface::class),
            $this->stores,
            $this->storeUsers,
            $this->createMock(UserRepositoryInterface::class),
            $this->shiftClaims,
            new AuditLogger(),
            $this->notifs,
            new PermissionService(
                $this->createMock(RoleAssignmentRepositoryInterface::class),
                $this->createMock(RoleRepositoryInterface::class),
            ),
        );
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
    }

    public function testOpenShiftsListsShiftsFromMyStoresWithMyClaimMarked(): void
    {
        $this->storeUsers->method('findByUser')->with(9)->willReturn([['store_id' => 1, 'user_id' => 9]]);
        $this->stores->method('getFeatures')->with(1)->willReturn([]);
        $this->shifts->method('findOpen')->with(1)->willReturn([
            ['id' => 100, 'shift_date' => '2026-08-01', 'store_id' => 1],
        ]);
        $this->stores->method('findAll')->willReturn([['id' => 1, 'name' => 'Store A']]);
        $this->shiftClaims->method('findByUser')->with(9)->willReturn([
            ['id' => 5, 'shift_id' => 100, 'status' => 'pending'],
        ]);

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);

        $response = $this->controller->openShifts($req);

        $this->assertSame(200, $response->status());
    }

    public function testOpenShiftsRedirectsWhenFeatureDisabledForStore(): void
    {
        $this->storeUsers->method('findByUser')->with(9)->willReturn([['store_id' => 1, 'user_id' => 9]]);
        $this->stores->method('getFeatures')->with(1)->willReturn(['shifts']);

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);

        $response = $this->controller->openShifts($req);

        $this->assertSame(302, $response->status());
    }

    public function testClaimShiftCreatesPendingClaim(): void
    {
        $this->storeUsers->method('findByUser')->with(9)->willReturn([['store_id' => 1, 'user_id' => 9]]);
        $this->stores->method('getFeatures')->with(1)->willReturn([]);
        $this->shifts->method('findById')->with(50)->willReturn(['id' => 50, 'is_open' => 1, 'user_id' => null, 'store_id' => 1]);
        $this->shiftClaims->method('findByUserAndShift')->with(9, 50)->willReturn(null);

        $captured = null;
        $this->shiftClaims->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d + ['id' => 77];
        });

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);
        $req->setRouteParams(['id' => '50']);

        $response = $this->controller->claimShift($req);

        $this->assertSame(302, $response->status());
        $this->assertSame('pending', $captured['status']);
        $this->assertSame(9, $captured['user_id']);
    }

    public function testClaimShiftNotifiesManagersWithApprovePermission(): void
    {
        $this->storeUsers->method('findByUser')->with(9)->willReturn([['store_id' => 1, 'user_id' => 9]]);
        $this->stores->method('getFeatures')->with(1)->willReturn([]);
        $this->shifts->method('findById')->with(50)->willReturn(['id' => 50, 'is_open' => 1, 'user_id' => null, 'store_id' => 1]);
        $this->shiftClaims->method('findByUserAndShift')->with(9, 50)->willReturn(null);
        $this->shiftClaims->method('save')->willReturnCallback(fn(array $d) => $d + ['id' => 77]);

        $this->storeUsers->method('findByStore')->with(1)->willReturn([
            ['user_id' => 9],  // le candidat lui-même, sans droit particulier : ne doit pas se notifier lui-même
            ['user_id' => 20], // manager avec open_shifts.approve
        ]);

        $usersRepo = $this->createMock(UserRepositoryInterface::class);
        $usersRepo->method('findById')->willReturnMap([
            [9, ['id' => 9]],
            [20, ['id' => 20]],
        ]);
        $assignments = $this->createMock(RoleAssignmentRepositoryInterface::class);
        $assignments->method('findByUser')->willReturnMap([
            [9, []],
            [20, [['id' => 1, 'user_id' => 20, 'role_id' => 2, 'scope_type' => 'store', 'scope_id' => 1]]],
        ]);
        $roles = $this->createMock(RoleRepositoryInterface::class);
        $roles->method('findById')->with(2)->willReturn(['id' => 2, 'is_system' => 0]);
        $roles->method('getPermissions')->with(2)->willReturn(['open_shifts.approve']);

        $view = new ViewRenderer(sys_get_temp_dir());
        $view->addNamespace('shift-claim', sys_get_temp_dir() . '/kintai-shift-claim-views');

        $controller = new EmployeeShiftClaimController(
            $view,
            $this->shifts,
            $this->createMock(ShiftTypeRepositoryInterface::class),
            $this->stores,
            $this->storeUsers,
            $usersRepo,
            $this->shiftClaims,
            new AuditLogger(),
            $this->notifs,
            new PermissionService($assignments, $roles),
        );

        $this->notifs->expects($this->once())->method('notifyMany')
            ->with([20], 'shift_claim_submitted', $this->anything(), $this->anything(), 50);

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);
        $req->setRouteParams(['id' => '50']);

        $controller->claimShift($req);
    }

    public function testClaimShiftRejectsAlreadyClaimedShift(): void
    {
        $this->storeUsers->method('findByUser')->with(9)->willReturn([['store_id' => 1, 'user_id' => 9]]);
        $this->stores->method('getFeatures')->with(1)->willReturn([]);
        $this->shifts->method('findById')->with(50)->willReturn(['id' => 50, 'is_open' => 1, 'user_id' => null, 'store_id' => 1]);
        $this->shiftClaims->method('findByUserAndShift')->with(9, 50)->willReturn(['id' => 1, 'status' => 'pending']);
        $this->shiftClaims->expects($this->never())->method('save');

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);
        $req->setRouteParams(['id' => '50']);

        $response = $this->controller->claimShift($req);

        $this->assertSame(302, $response->status());
    }

    public function testWithdrawShiftClaimSetsWithdrawnStatus(): void
    {
        $this->shiftClaims->method('findByUserAndShift')->with(9, 50)->willReturn(['id' => 1, 'status' => 'pending', 'shift_id' => 50]);

        $captured = null;
        $this->shiftClaims->expects($this->once())->method('save')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $d;
        });

        $req = new Request();
        $req->setAttribute('auth_user', ['id' => 9]);
        $req->setRouteParams(['id' => '50']);

        $response = $this->controller->withdrawShiftClaim($req);

        $this->assertSame(302, $response->status());
        $this->assertSame('withdrawn', $captured['status']);
    }

    private function ensureViewFile(string $dir, string $view): void
    {
        $file = $dir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';
        $parent = dirname($file);
        if (!is_dir($parent)) {
            mkdir($parent, 0777, true);
        }
        touch($file);
    }
}
