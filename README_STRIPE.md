# Storage Manager Stripe Integration

## Required Fields and Mappings

### Storage Assignment Entity

*   `field_storage_stripe_sub_id`: Text (plain) field to store the Stripe subscription ID (`sub_...`).
*   `field_storage_stripe_item_id`: Text (plain) field to store the Stripe subscription item ID (`si_...`) for the storage line item.
*   `field_storage_stripe_status`: Text (plain) field recording the latest Stripe subscription status (`active`, `canceled`, etc.).
*   `field_stripe_price_id`: Text (plain) field to store the Stripe price ID (`price_...`).

When Stripe billing is enabled in Storage Manager, assignments automatically create or update the matching subscription item after staff assignment or member self-claim. Releasing storage removes the item (and cancels storage-only subscriptions) automatically. Set a Stripe price ID on each storage type term (field `field_stripe_price_id`) or configure a module default to ensure new assignments can sync.

## UI Button Placement

### Staff-Facing "Create/Open Stripe Subscription" Button

This button is added to the `storage_assignment` entity's operations (e.g., the drop-down on the admin view) via `hook_entity_operation()`. No manual placement is required and now triggers a resync before opening the Stripe dashboard.

### Member-Facing "View Storage Invoices" Button

This button is added to the "My Storage" page (`/user/storage`) in a "Billing" section. The member portal is invoices-only via a Stripe Portal Configuration set in `settings.php`.
