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
lando drush storage_manager:import-config
lando drush cr
```

### Pantheon / Terminus scripts (site: `makehaven-website`)

The following shell snippet works for `dev`, `test`, and `live`. Replace `<ENV>` with the environment name on the first line and run the whole block in your terminal. It keeps PHP’s time limit at zero so you don’t hit the 120‑second cap, and it retries the import automatically until everything is installed.

```bash
SITE=makehaven-website
ENV=dev    # change to test or live when promoting

# dev requires SFTP mode so schema can be created
if [ "$ENV" = "dev" ]; then
  terminus connection:set $SITE.$ENV sftp
fi

DRUSH="terminus drush $SITE.$ENV -- ssh \"cd /code && php -d max_execution_time=0 /usr/local/bin/drush\""

eval $DRUSH "en storage_manager -y"

while true; do
  OUTPUT=$(eval $DRUSH "storage_manager:import-config" 2>&1)
  echo "$OUTPUT"
  if ! grep -qi 'pending' <<< "$OUTPUT"; then
    break
  fi
  sleep 5
done

eval $DRUSH "cr"

if [ "$ENV" = "dev" ]; then
  terminus connection:set $SITE.$ENV git
fi
```

If the storage configuration already lives in Pantheon’s `config/sync`, you can replace the import loop with a single `terminus drush $SITE.$ENV -- cim -y`.

### Manual fallback (only if you skip config import)

- Create vocabularies `storage_area` and `storage_type` and populate seed terms.
- Add the ECK entity types `storage_unit`, `storage_assignment`, `storage_violation` and their bundles.
- Recreate every field storage/instance from `config/optional/`.

After any approach (command or manual), review the form/view displays (e.g. expose `field_monthly_price` on the Storage Type form) to suit your editors.
