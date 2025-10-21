# Storage Manager Stripe Integration

## Required Fields and Mappings

### Storage Assignment Entity

*   `field_storage_stripe_sub_id`: Text (plain) field to store the Stripe subscription ID (`sub_...`).
*   `field_stripe_price_id`: Text (plain) field to store the Stripe price ID (`price_...`).

## UI Button Placement

### Staff-Facing "Create/Open Stripe Subscription" Button

This button is added to the `storage_assignment` entity's operations (e.g., the drop-down on the admin view) via `hook_entity_operation()`. No manual placement is required.

### Member-Facing "View Storage Invoices" Button

This button is added to the "My Storage" page (`/user/storage`) in a "Billing" section. The member portal is invoices-only via a Stripe Portal Configuration set in `settings.php`.
