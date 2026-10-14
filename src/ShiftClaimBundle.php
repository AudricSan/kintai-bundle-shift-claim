<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\ShiftClaim;

use kintai\Bundles\Installed\ShiftClaim\Providers\PendingClaimsProvider;
use kintai\Contract\Bundle;
use kintai\Contract\Container;
use kintai\Contract\Extension\NavItem;
use kintai\Contract\Extension\StoreFeature;
use kintai\Core\Repositories\DatabaseShiftClaimRepository;
use kintai\Core\Repositories\ShiftClaimRepositoryInterface;

/**
 * ShiftClaimRepositoryInterface reste enregistré par ce bundle, pas par le
 * Core — mais contrairement à TimeOff/ShiftSwap, deux composants Core en
 * lisent quand même les données (HomeController, pour le KPI "demandes en
 * attente" du dashboard ; AdminRequestsController, pour la section
 * candidatures de la page /admin/requests). Les deux résolvent
 * ShiftClaimRepositoryInterface de façon différée (jamais en dépendance de
 * constructeur), gardée par feat_bundle('open_shifts') ET
 * Container::has(ShiftClaimRepositoryInterface::class) avant tout appel —
 * donc aucune liaison Core n'est nécessaire ici : ces deux contrôleurs
 * dégradent déjà proprement (section absente) quand ce bundle est désactivé
 * ou désinstallé, au lieu de planter.
 */
final class ShiftClaimBundle extends Bundle
{
    public function getName(): string
    {
        return 'shift-claim';
    }

    public function getVersion(): string
    {
        return '1.2.0';
    }

    public function getLabel(): string
    {
        return __('bundle_shift_claim');
    }

    public function getDescription(): string
    {
        return __('bundle_shift_claim_desc');
    }

    public function register(): void
    {
        $this->registerServices();
        $this->loadViewsFrom($this->getPath() . '/Views', 'shift-claim');
        $this->loadRoutesFrom($this->getPath() . '/routes.php');
    }

    private function registerServices(): void
    {
        $container = $this->app->container();

        $container->singleton(
            ShiftClaimRepositoryInterface::class,
            fn() => new DatabaseShiftClaimRepository()
        );
    }

    /**
     * Navigation (contrat 1.1) : entrée(s) de la barre du haut et de la barre mobile.
     */
    public function navigation(): array
    {
        return [
            new NavItem('open_shifts', NavItem::REQUESTS, 'open_shifts', 'admin.open_shifts', 'employee.open_shifts',
                icon: 'plus', activePaths: ['/admin/open-shifts', '/employee/open-shifts'],
                storeFeature: 'open_shifts', order: 30, mobile: true),
        ];
    }

    /**
     * Catégories de permissions (contrat 1.2), proposées sur la page des rôles.
     */
    public function permissions(): array
    {
        return ['open_shifts' => ['view', 'publish', 'approve']];
    }

    /**
     * Fonctionnalité activable par magasin (contrat 1.3), sur la fiche magasin.
     */
    public function storeFeatures(): array
    {
        return [new StoreFeature('open_shifts', 'feature_open_shifts', 'feature_open_shifts_desc', '📋', 50)];
    }

    /**
     * Demandes en attente (contrat 1.9) : page « Demandes » et compteur du tableau de bord du Core.
     */
    public function pendingRequestsProviders(): array
    {
        return [new PendingClaimsProvider(Container::getInstance())];
    }
}
