<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\ShiftClaim\Controllers\Api;

use kintai\Core\Api\Paginator;
use kintai\Core\Auth\PermissionService;
use kintai\Core\Repositories\ShiftClaimRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;

/**
 * Régression (audit RBAC du 11/09/2026) : mêmes correctifs que
 * ShiftSwapRequestController — voir son docblock pour le détail de la faille.
 * index() sans filtre renvoyait en plus TOUTES les candidatures de TOUS les
 * stores à n'importe quel porteur de token (findAll() sans restriction).
 */
final class ShiftClaimController
{
    public function __construct(
        private readonly ShiftClaimRepositoryInterface $claims,
        private readonly PermissionService $permissions,
    ) {}

    /** GET /api/v1/shift-claims?store_id=X&user_id=Y&shift_id=Z&status=W&page=1&limit=20 */
    public function index(Request $request): Response
    {
        [$page, $limit] = Paginator::params($request);
        $storeId = $request->query('store_id');
        $userId  = $request->query('user_id');
        $shiftId = $request->query('shift_id');
        $status  = $request->query('status');

        if ($shiftId !== null) {
            $items = $this->claims->findByShift((int) $shiftId);
        } elseif ($userId !== null) {
            $items = $this->claims->findByUser((int) $userId);
        } elseif ($storeId !== null && $status === 'pending') {
            $items = $this->claims->findPendingByStore((int) $storeId);
        } elseif ($storeId !== null) {
            $items = $this->claims->findByStore((int) $storeId);
        } else {
            $items = $this->claims->findAll();
        }

        $items = $this->permissions->restrictToScope($this->authUser($request), 'open_shifts.view', $items);

        return Response::json(Paginator::paginate($items, $page, $limit));
    }

    /** GET /api/v1/shift-claims/{id} */
    public function show(Request $request): Response
    {
        $claim = $this->requireClaim($request, 'open_shifts.view');
        return Response::json($claim);
    }

    /** POST /api/v1/shift-claims */
    public function store(Request $request): Response
    {
        $data = array_merge($request->json() ?? [], ['claimed_at' => date('Y-m-d H:i:s')]);
        return Response::json($this->claims->save($data), 201);
    }

    /** PUT /api/v1/shift-claims/{id} */
    public function update(Request $request): Response
    {
        $claim = $this->requireClaim($request, 'open_shifts.approve');
        $id    = (int) $claim['id'];
        return Response::json($this->claims->save(array_merge($request->json() ?? [], ['id' => $id])));
    }

    /** DELETE /api/v1/shift-claims/{id} */
    public function destroy(Request $request): Response
    {
        $claim = $this->requireClaim($request, 'open_shifts.approve');
        $this->claims->delete((int) $claim['id']);
        return Response::empty();
    }

    private function authUser(Request $request): array
    {
        return $request->getAttribute('auth_user') ?? [];
    }

    /** Charge la candidature par id et vérifie $permissionKey sur le store réel du shift. */
    private function requireClaim(Request $request, string $permissionKey): array
    {
        return $this->permissions->requireOwnedResource(
            $this->authUser($request),
            fn(int $id) => $this->claims->findById($id),
            (int) $request->param('id'),
            $permissionKey,
            notFoundMessage: __('error_claim_not_found'),
        );
    }
}
