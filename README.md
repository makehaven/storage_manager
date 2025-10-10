# Storage Manager

Drupal 10 custom module for managing member storage units and assignments at MakeHaven.
Provides entity types, admin UI, and hooks for Stripe subscription billing.

---

## Features

- **Entities (via ECK)**
  - `storage_unit` – represents a physical storage space (locker, shelf, floor spot, etc.).
  - `storage_assignment` – links a member (user) to a storage unit with start/end dates.

- **Taxonomies**
  - `storage_type` – categorizes units; holds monthly price and Stripe price ID.
  - `storage_area` – identifies rooms/areas in the facility.

- **UI**
  - **Dashboard** at `/admin/storage/dashboard` with unit status and assign/release actions.
  - **Assign form**: allocate a vacant unit to a member, with optional issue flag.
  - **Release form**: end an active assignment and mark the unit vacant.
  - **Block:** optional "Storage Map" overlay (color-coded dots placed by X/Y coords).

- **Business rules**
  - Prevents more than one active assignment per unit.
  - Tracks price snapshot on assignment.
  - Supports flagging issues (boolean + note).
  - Stripe integration is stubbed for future automation.

---

## Installation

### Common first step

1. Place this module in `web/modules/custom/storage_manager/`.
2. Enable it (this step alone does not create any config):
   ```bash
   drush en storage_manager -y
   ```

Until the configuration is imported you will see watchdog notices about missing field storage bundles—those disappear as soon as the config is in place.

### Local (Lando) example

```
lando drush en storage_manager -y
lando drush config:import --partial --source=/app/web/modules/custom/storage_manager/config/install -y
lando drush config:import --partial --source=/app/web/modules/custom/storage_manager/config/optional -y
lando drush cr
```

### Pantheon / Terminus cheat sheet (site: `makehaven-website`)

Copy the following block and replace the environment (`dev`, `test`, or `live`) as needed:

```
terminus drush makehaven-website.dev -- en storage_manager -y
terminus drush makehaven-website.dev -- config:import --partial --source=$(terminus drush makehaven-website.dev -- php:eval 'echo DRUPAL_ROOT;')/modules/custom/storage_manager/config/install -y
terminus drush makehaven-website.dev -- config:import --partial --source=$(terminus drush makehaven-website.dev -- php:eval 'echo DRUPAL_ROOT;')/modules/custom/storage_manager/config/optional -y
terminus drush makehaven-website.dev -- cr
```

Repeat with `.test` or `.live` when promoting. If the storage configuration already lives in Pantheon’s `config/sync`, you can replace the two partial imports with a single `terminus drush makehaven-website.<env> -- cim -y`.

### Manual fallback (only if you skip config import)

- Create vocabularies `storage_area` and `storage_type` and populate seed terms.
- Add the ECK entity types `storage_unit`, `storage_assignment`, `storage_violation` and their bundles.
- Recreate every field storage/instance from `config/optional/`.

After any approach, review the form/view displays (e.g. expose `field_monthly_price` on the Storage Type form) to suit your editors.
