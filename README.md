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

1. Place this module in `web/modules/custom/storage_manager/`.
2. Create two taxonomy vocabularies (Structure → Taxonomy) **before** enabling the module:
   - `storage_area`
   - `storage_type`
   Optionally add seed terms such as "Metalshop", "Studio", "CNC Room" (areas) and "Small Bin", "Large Bin" (types).
3. Enable the module:
   ```bash
   drush en storage_manager -y
   ```
   Installation will halt with an explanatory error if the required vocabularies are missing.
