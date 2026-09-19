<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\ShiftClaim\Controllers\Web;

use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\ShiftClaimRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\NotificationService;
use kintai\Core\Services\ShiftServiceInterface;
use kintai\UI\Controller\Web\HasAdminAccess;
use kintai\UI\ViewRenderer;

final class AdminShiftClaimController
{
    use HasAdminAccess;

    public function __construct(
        private readonly ViewRenderer $view,
        private readonly UserRepositoryInterface $users,
        private readonly StoreRepositoryInterface $stores,
        private readonly StoreUserRepositoryInterface $storeUsers,
        private readonly ShiftRepositoryInterface $shifts,
        private readonly ShiftTypeRepositoryInterface $shiftTypes,
        private readonly ShiftClaimRepositoryInterface $shiftClaims,
        private readonly ShiftServiceInterface $shiftService,
        private readonly AuditLogger $auditLogger,
        private readonly NotificationService $notifs,
    ) {}

    public function openShifts(Request $request): Response
    {
        $managedIds = $this->managedIds($request);

        $openShifts = $managedIds !== null
            ? array_merge(...array_map(fn($sid) => $this->shifts->findOpen($sid), $managedIds))
            : $this->shifts->findOpen();

        $usersMap  = $this->buildUsersMap();
        $typesMap  = [];
        foreach ($this->shiftTypes->findAll() as $t) {
            $typesMap[(int) $t['id']] = $t;
        }
        $storesMap = [];
        foreach ($this->availableStores($managedIds) as $s) {
            $storesMap[(int) $s['id']] = $s['name'] ?? ('#' . $s['id']);
        }

        // Enrichir chaque shift avec ses candidatures
        $shiftsWithClaims = [];
        foreach ($openShifts as $shift) {
            $claims = $this->shiftClaims->findByShift((int) $shift['id']);
            foreach ($claims as &$claim) {
                $claim['_user_name'] = $usersMap[(int) ($claim['user_id'] ?? 0)] ?? '—';
            }
            unset($claim);
            $shiftsWithClaims[] = array_merge($shift, ['_claims' => $claims]);
        }

        $sort = (string) ($request->query('sort') ?? 'date_asc');
        usort($shiftsWithClaims, function ($a, $b) use ($sort, $storesMap) {
            $dateA  = $a['shift_date'] ?? '';
            $dateB  = $b['shift_date'] ?? '';
            $storeA = strtolower($storesMap[(int) ($a['store_id'] ?? 0)] ?? '');
            $storeB = strtolower($storesMap[(int) ($b['store_id'] ?? 0)] ?? '');
            return match ($sort) {
                'date_desc'  => strcmp($dateB, $dateA),
                'store_asc'  => strcmp($storeA, $storeB) ?: strcmp($dateA, $dateB),
                'store_desc' => strcmp($storeB, $storeA) ?: strcmp($dateA, $dateB),
                default      => strcmp($dateA, $dateB),
            };
        });

        // Candidatures : agrégées depuis tous les shifts ouverts (avant filtrage par type,
        // pour que le filtre "type" du tableau des shifts n'affecte pas celui des candidatures).
        $claimStatusFilter = (string) ($request->query('claim_status') ?? '');
        $claims = [];
        foreach ($shiftsWithClaims as $shift) {
            foreach ($shift['_claims'] ?? [] as $claim) {
                if ($claimStatusFilter !== '' && ($claim['status'] ?? 'pending') !== $claimStatusFilter) {
                    continue;
                }
                $claims[] = array_merge($claim, ['_shift' => $shift]);
            }
        }

        $claimSort = (string) ($request->query('claim_sort') ?? 'date_asc');
        usort($claims, function ($a, $b) use ($claimSort, $storesMap) {
            $dateA  = $a['claimed_at'] ?? '';
            $dateB  = $b['claimed_at'] ?? '';
            $storeA = strtolower($storesMap[(int) ($a['_shift']['store_id'] ?? 0)] ?? '');
            $storeB = strtolower($storesMap[(int) ($b['_shift']['store_id'] ?? 0)] ?? '');
            return match ($claimSort) {
                'date_desc'  => strcmp($dateB, $dateA),
                'store_asc'  => strcmp($storeA, $storeB) ?: strcmp($dateA, $dateB),
                'store_desc' => strcmp($storeB, $storeA) ?: strcmp($dateA, $dateB),
                default      => strcmp($dateA, $dateB),
            };
        });

        $typeFilter = (string) ($request->query('type') ?? '');
        if ($typeFilter !== '') {
            $shiftsWithClaims = array_values(array_filter(
                $shiftsWithClaims,
                fn($s) => (string) ($s['shift_type_id'] ?? '') === $typeFilter
            ));
        }

        return Response::html($this->view->render('shift-claim::open-shifts', [
            'title'              => __('open_shifts'),
            'shifts'             => $shiftsWithClaims,
            'claims'             => $claims,
            'users_map'          => $usersMap,
            'types_map'          => $typesMap,
            'stores_map'         => $storesMap,
            'type_filter'        => $typeFilter,
            'claim_status_filter'=> $claimStatusFilter,
            'sort'               => $sort,
            'claim_sort'         => $claimSort,
        ], 'layout.app'));
    }

    /**
     * GET /admin/open-shifts/publish — liste les shifts à venir, déjà assignés
     * et non publiés, pour permettre à l'admin d'en publier un à la bourse
     * sans repasser par le formulaire complet de création/édition.
     */
    public function selectShiftToPublish(Request $request): Response
    {
        $managedIds = $this->managedIds($request);
        $today      = date('Y-m-d');

        $shifts = array_values(array_filter($this->shifts->findAll(), function ($s) use ($managedIds, $today) {
            if (($s['shift_date'] ?? '') < $today) return false;
            if ((int) ($s['is_open'] ?? 0) === 1) return false;
            if ((int) ($s['user_id'] ?? 0) <= 0) return false;
            if ($managedIds !== null && !in_array((int) ($s['store_id'] ?? 0), $managedIds, true)) return false;
            return true;
        }));
        usort($shifts, fn($a, $b) => strcmp($a['shift_date'] ?? '', $b['shift_date'] ?? ''));

        return Response::html($this->view->render('shift-claim::open-shifts-select', [
            'title'      => __('select_shift_to_publish'),
            'shifts'     => $shifts,
            'users_map'  => $this->shiftService->getUsersMap(),
            'stores_map' => $this->buildStoresMap($managedIds),
        ], 'layout.app'));
    }

    public function publishShift(Request $request): Response
    {
        $shift = $this->shifts->findById((int) $request->param('id'));
        if ($shift === null) {
            throw new NotFoundException(__('error_shift_not_found'));
        }
        $this->assertStoreAccess($request, (int) ($shift['store_id'] ?? 0));
        $old = $shift;
        $saved = $this->shifts->save(array_merge($shift, ['is_open' => 1]));
        $this->auditLogger->logUpdate($request, 'shift.published', 'shift', (int) $shift['id'], $old, $saved, [], (int) ($shift['store_id'] ?? 0) ?: null);

        $storeId = (int) ($shift['store_id'] ?? 0);
        $holderId = (int) ($shift['user_id'] ?? 0);
        $eligible = array_values(array_diff($this->memberUserIds([$storeId]), [$holderId]));
        if ($eligible !== []) {
            $this->notifs->notifyMany($eligible, 'open_shift_published', 'Un shift est disponible à la bourse aux shifts.', [], (int) $shift['id']);
        }

        return Response::redirect($this->base() . '/admin/open-shifts?success=published');
    }

    public function unpublishShift(Request $request): Response
    {
        $shift = $this->shifts->findById((int) $request->param('id'));
        if ($shift === null) {
            throw new NotFoundException(__('error_shift_not_found'));
        }
        $this->assertStoreAccess($request, (int) ($shift['store_id'] ?? 0));
        $old = $shift;
        $saved = $this->shifts->save(array_merge($shift, ['is_open' => 0]));
        $withdrawn = 0;
        foreach ($this->shiftClaims->findByShift((int) $shift['id']) as $claim) {
            if (($claim['status'] ?? '') === 'pending') {
                $this->shiftClaims->save(array_merge($claim, ['status' => 'withdrawn']));
                $withdrawn++;
                $this->notifs->notify((int) ($claim['user_id'] ?? 0), 'shift_claim_withdrawn', 'notif_shift_claim_withdrawn_body', [], (int) $shift['id']);
            }
        }
        $this->auditLogger->logUpdate($request, 'shift.unpublished', 'shift', (int) $shift['id'], $old, $saved, ['withdrawn' => $withdrawn], (int) ($shift['store_id'] ?? 0) ?: null);
        return Response::redirect($this->base() . '/admin/open-shifts?success=unpublished');
    }

    public function approveShiftClaim(Request $request): Response
    {
        $claim = $this->shiftClaims->findById((int) $request->param('id'));
        if ($claim === null) {
            throw new NotFoundException(__('error_claim_not_found'));
        }
        $oldClaim = $claim;
        // C-2 : guard statut pending
        if (($claim['status'] ?? '') !== 'pending') {
            return Response::redirect($this->base() . '/admin/open-shifts?error=already_resolved');
        }
        $shift = $this->shifts->findById((int) ($claim['shift_id'] ?? 0));
        if ($shift === null) {
            throw new NotFoundException(__('error_shift_not_found'));
        }
        // C-3 : le shift doit être encore ouvert
        if (!(int) ($shift['is_open'] ?? 0)) {
            return Response::redirect($this->base() . '/admin/open-shifts?error=already_resolved');
        }
        $this->assertStoreAccess($request, (int) ($shift['store_id'] ?? 0));

        $now      = date('Y-m-d H:i:s');
        $authUser = $request->getAttribute('auth_user');
        $resolver = (int) ($authUser['id'] ?? 0);

        // Écritures conditionnelles (WHERE status/is_open porté par l'UPDATE
        // lui-même, pas par les lectures ci-dessus) : si un autre admin a
        // approuvé une autre candidature sur ce même shift entre-temps, l'une
        // des deux écritures échoue proprement au lieu de s'écraser
        // silencieusement avec l'autre (race condition sur la bourse).
        $savedClaim = $this->shiftClaims->approveIfPending((int) $claim['id'], $now, $resolver);
        if ($savedClaim === null) {
            return Response::redirect($this->base() . '/admin/open-shifts?error=already_resolved');
        }

        // Affecter le shift au candidat retenu, fermer la bourse
        $updatedShift = $this->shifts->closeOpenShiftTo((int) $shift['id'], (int) $claim['user_id']);
        if ($updatedShift === null) {
            // Le shift a été fermé entre-temps par une autre approbation concurrente :
            // on annule la nôtre pour ne pas laisser une candidature "approved"
            // orpheline sur un shift attribué à quelqu'un d'autre.
            $this->shiftClaims->save(array_merge($savedClaim, [
                'status'      => 'pending',
                'resolved_at' => null,
                'resolved_by' => null,
            ]));
            return Response::redirect($this->base() . '/admin/open-shifts?error=already_resolved');
        }

        // Rejeter les autres candidatures encore pending
        foreach ($this->shiftClaims->findByShift((int) $shift['id']) as $other) {
            if ((int) $other['id'] !== (int) $claim['id'] && ($other['status'] ?? '') === 'pending') {
                $this->shiftClaims->save(array_merge($other, [
                    'status'      => 'rejected',
                    'resolved_at' => $now,
                    'resolved_by' => $resolver,
                ]));
                $this->notifs->notify((int) $other['user_id'], 'shift_claim_rejected', 'notif_shift_claim_rejected_body', [], (int) $shift['id']);
            }
        }

        $this->auditLogger->logUpdate($request, 'shift_claim.approved', 'shift_claim', (int) $claim['id'], $oldClaim, $savedClaim, [], (int) ($shift['store_id'] ?? 0) ?: null);

        $this->notifs->notify((int) $claim['user_id'], 'shift_claim_approved', 'notif_shift_claim_approved_body', [], (int) $shift['id']);

        return Response::redirect($this->base() . '/admin/open-shifts?success=claim_approved');
    }

    public function rejectShiftClaim(Request $request): Response
    {
        $claim = $this->shiftClaims->findById((int) $request->param('id'));
        if ($claim === null) {
            throw new NotFoundException(__('error_claim_not_found'));
        }
        $oldClaim = $claim;
        // M-3 : guard statut pending
        if (($claim['status'] ?? '') !== 'pending') {
            return Response::redirect($this->base() . '/admin/open-shifts?error=already_resolved');
        }
        $shift = $this->shifts->findById((int) ($claim['shift_id'] ?? 0));
        if ($shift === null) {
            throw new NotFoundException(__('error_shift_not_found'));
        }
        $this->assertStoreAccess($request, (int) ($shift['store_id'] ?? 0));

        $now      = date('Y-m-d H:i:s');
        $authUser = $request->getAttribute('auth_user');
        $resolver = (int) ($authUser['id'] ?? 0);

        $savedClaim = $this->shiftClaims->save(array_merge($claim, [
            'status'      => 'rejected',
            'resolved_at' => $now,
            'resolved_by' => $resolver,
        ]));
        $this->auditLogger->logUpdate($request, 'shift_claim.rejected', 'shift_claim', (int) $claim['id'], $oldClaim, $savedClaim, [], (int) ($shift['store_id'] ?? 0) ?: null);

        $this->notifs->notify((int) $claim['user_id'], 'shift_claim_rejected', 'notif_shift_claim_rejected_body', [], (int) $shift['id']);

        return Response::redirect($this->base() . '/admin/open-shifts?success=claim_rejected');
    }
}
