# Changelog

Tous les changements notables de ce bundle sont documentés dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/).
Le schéma de version (X.Y.Z, canaux alpha/beta/main) est décrit dans
`.github/workflows/release.yml`.

## [Unreleased]

## [1.1.0] - 2026-09-29

### Fixed

- `publishShift()` passait une phrase française codée en dur comme clé de traduction du corps de la notification `open_shift_published`, au lieu d'une vraie clé — jamais traduite en/ja. Utilise désormais `notif_open_shift_published_body`, ajoutée côté Kintai Core.

### Changed

- Aucun changement fonctionnel — bump de version pour aligner ce bundle sur la ligne 1.1.0 commune à tous les bundles officiels.
- Les six notifications de la bourse aux shifts (publication, retrait, candidature soumise/approuvée/rejetée/retirée) ne disaient rien du shift concerné et ne menaient nulle part au clic. Le corps précise désormais la date, l'horaire et le magasin (candidature soumise ajoute aussi le nom du candidat) — `notif_open_shift_published_body`/`notif_shift_claim_*_body` (Kintai Core) gagnent les placeholders `:date`/`:start`/`:end`/`:store`/`:author`. Le clic renvoie vers `/employee/open-shifts` (candidats) ou `/admin/open-shifts` (managers, nouvelle candidature à traiter). **Nécessite** la version de Kintai Core introduisant le paramètre `$link` sur `notify()`/`notifyMany()`.

## [1.0.0] - 2026-09-19

### Added

- Extraction initiale depuis Kintai (`src/Bundles/ShiftClaim`).
