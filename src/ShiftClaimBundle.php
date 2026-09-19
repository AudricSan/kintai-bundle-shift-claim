<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\ShiftClaim;

use kintai\Core\BundleContract\Bundle;
use kintai\Core\Repositories\ShiftClaimRepositoryInterface;
use kintai\Core\Repositories\DatabaseShiftClaimRepository;

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
        return '1.0.0';
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
}
