# Role Management UI — Design Spec

## Overview

Add a role management UI to the Chinga Games SSO platform, allowing tenant admins and platform admins to assign/remove roles via dialog modals. This addresses the current gap where roles can only be assigned programmatically.

## Decisions

- **Two scopes:** Tenant admin dialog on existing `/admin/users` page; platform admin dialog on new `/platform/users` page.
- **Dialog-based UI:** Clicking a "Manage Roles" action opens a modal — consistent with existing app patterns (voucher generation, KYC review).
- **Separate endpoints per scope:** Tenant routes in `routes/admin.php`, platform routes in `routes/platform.php` — follows existing architecture.
- **Hierarchy-based restrictions:** Users can only assign roles strictly below their own level. Prevents privilege escalation.
- **Platform admins can assign tenant-scoped roles** with a tenant picker dropdown, enabling them to bootstrap tenant admins.
- **Only `tenant_admin` and above can manage roles** — `tenant_manager` cannot, even if it theoretically gained the permission. The hierarchy check enforces this since `tenant_manager` can only assign `player`, and the `users.manage-roles` permission is only granted to `tenant_admin` and above.

## Backend

### RoleManagementService

New file: `app/Services/RoleManagementService.php`

Shared service used by both controllers. Encapsulates:

- **Role hierarchy:** `platform_super_admin` > `platform_admin` > `tenant_admin` > `tenant_manager` > `player`
- `getAssignableRoles(User $actor, ?int $tenantId): Collection` — returns roles the actor can assign, filtered by scope (platform roles have `is_platform_role=true`, tenant roles have `is_platform_role=false`). Response includes `id`, `name`, `display_name`, `description`, `is_platform_role` for each role.
- `assignRole(User $actor, User $target, string $roleName, ?int $tenantId): void` — validates hierarchy, checks for duplicates, assigns role, audit logs.
- `removeRole(User $actor, User $target, string $roleName, ?int $tenantId): void` — validates hierarchy, verifies role is actually assigned before calling `HasRoles::removeRole()` (which silently no-ops on missing roles), checks last-super-admin protection, audit logs.
- **Concurrency:** All assign/remove operations run inside `DB::transaction()` with `User::lockForUpdate()->find(...)` on the target user. This is necessary because MySQL treats NULL as distinct in unique indexes, so the `user_roles(user_id, role_id, tenant_id)` constraint does not prevent duplicate platform role assignments (where `tenant_id` is NULL).
- Throws `AuthorizationException` if hierarchy violated or self-assignment attempted.
- Throws `ValidationException` if role already assigned, not found, or tenant doesn't exist.

### Tenant Admin Endpoints

In `routes/admin.php`, behind `EnsureTenantAdmin` middleware.

Controller: `app/Http/Controllers/Admin/RoleManagementController.php`

| Method | Route | Purpose |
|--------|-------|---------|
| `GET` | `/api/v1/admin/roles` | List assignable tenant roles for current actor |
| `GET` | `/api/v1/admin/users/{uuid}/roles` | Get user's roles for current tenant |
| `POST` | `/api/v1/admin/users/{uuid}/roles` | Assign a tenant role |
| `DELETE` | `/api/v1/admin/users/{uuid}/roles/{role}` | Remove a tenant role |

All operations auto-scoped to `app('current_tenant')`.

**Permission enforcement:** Each endpoint explicitly checks `$request->user()->hasPermission('users.manage-roles')` in the controller and returns 403 if denied. No permission-checking middleware exists in the codebase — this is the first usage of the `hasPermission()` method at the HTTP layer, and the check is done inline in the controller.

Request body for POST: `{ "role": "tenant_manager" }`

### Platform Admin Endpoints

In `routes/platform.php`, behind `EnsurePlatformAdmin` middleware.

Controller: `app/Http/Controllers/Platform/PlatformRoleManagementController.php`

| Method | Route | Purpose |
|--------|-------|---------|
| `GET` | `/api/v1/platform/roles` | List all roles (platform + tenant) assignable by actor, grouped by `is_platform_role` |
| `GET` | `/api/v1/platform/users` | List all users across tenants |
| `GET` | `/api/v1/platform/users/{uuid}/roles` | Get all user roles across all scopes |
| `POST` | `/api/v1/platform/users/{uuid}/roles` | Assign any role (with optional `tenant_id`) |
| `DELETE` | `/api/v1/platform/users/{uuid}/roles/{role}` | Remove a role (`tenant_id` required in query string for tenant-scoped roles, omitted for platform roles) |

**Permission enforcement:** Same inline `hasPermission('users.manage-roles')` check as tenant controller.

**TenantScope bypass:** The `listUsers()` method uses `User::withoutGlobalScope(TenantScope::class)` to guarantee cross-tenant visibility regardless of whether a tenant context happens to be set on the request.

Request body for POST: `{ "role": "tenant_admin", "tenant_id": 5 }` (`tenant_id` null/omitted for platform roles, required for tenant-scoped roles).

Platform users list supports filters: `search`, `tenant_id`, `role`, `status`, `page`, `per_page`.

### Audit Logging

Every assign/remove operation logs via the existing `SecurityAuditService` (`app/Services/Auth/SecurityAuditService.php`) using the `logAdminAction()` method:

```php
$this->auditService->logAdminAction(
    admin: $actor,
    targetUser: $target,
    action: 'role_assigned', // or 'role_removed'
    details: [
        'role' => $roleName,
        'tenant_id' => $tenantId,
        'old_roles' => $oldRoles,
        'new_roles' => $newRoles,
    ]
);
```

### Seeder Update

Update `database/seeders/RbacSeeder.php`: add `users.manage-roles` to the `tenant_admin` role's permission list. Platform roles already have this via `users.*` (platform_admin) and `*` (platform_super_admin) wildcards.

## Frontend

### Tenant Admin — Role Dialog on `/admin/users`

Modifications to `resources/js/pages/admin/users.tsx`:

**Actions column:** Add a shield icon button (`pi pi-shield`) next to the existing eye icon. Clicking opens a `Dialog` component.

**Dialog contents:**
- Header: "Manage Roles — {user.name}"
- Body: Checkboxes for each assignable role (fetched from `GET /api/v1/admin/roles`). Each shows `display_name` and `description`. Currently assigned roles pre-checked.
- Footer: Cancel and Save buttons.

**Behavior:**
- On open: fetch user's current roles and available roles.
- On save: diff current vs. original selections. POST for new assignments, DELETE for removals. All fetch calls include the `X-CSRF-TOKEN` header (from the `<meta>` tag), following the pattern used in existing pages like `tenants/index.tsx`.
- Success: toast notification, refresh user list.
- Error: inline validation errors in dialog, server errors as toast.

### Platform Admin — New Users Page

New file: `resources/js/pages/platform/users/index.tsx`

**Layout** — uses `PageHeader`, `acu-fieldset` wrappers, and DataTable (matching the admin users page pattern, which is more polished than other platform pages):
- PageHeader: "Users" / "Manage users across all tenants"
- Filters Fieldset: Search input, Tenant dropdown, Role dropdown, Status dropdown
- Data Fieldset: DataTable with columns:
  - User (name + email stacked)
  - Tenant (name, or "Platform" if tenant_id is null)
  - Roles (comma-separated display names)
  - Status (StatusBadge)
  - Actions (shield icon for role management)

**Navigation:** Add "Users" entry to the Platform group in `resources/js/layouts/user-layout.tsx`, between "Platform" and "Tenants", icon: `pi pi-users`.

**Role Management Dialog** — similar to tenant version with additions:
- Body split into two sections:
  - **Platform Roles:** Checkboxes for platform roles assignable by actor (`platform_admin`, and `platform_super_admin` only if actor is super admin).
  - **Tenant Roles:** Dropdown to select a tenant, then checkboxes for tenant-scoped roles (`tenant_admin`, `tenant_manager`, `player`). Existing tenant role assignments shown as removable tags/chips below the dropdown.
- Save behavior: platform role changes use `tenant_id: null`, tenant role changes include the selected `tenant_id`. CSRF token included in all requests.

### Inertia Route

Add `GET /platform/users` as an Inertia page route in `routes/platform.php` (renders the React page).

## Error Handling & Edge Cases

- **Self-assignment prevention:** Users cannot modify their own roles (403).
- **Last super admin protection:** Cannot remove `platform_super_admin` from the last user who has it (422).
- **Tenant existence validation:** `tenant_id` must reference an active tenant.
- **Duplicate assignment:** Returns 422 "User already has this role".
- **Race conditions:** Handled by pessimistic locking (`DB::transaction` + `lockForUpdate`) since the unique constraint on `user_roles(user_id, role_id, tenant_id)` does not protect against duplicate NULL `tenant_id` entries in MySQL.
- **Loading states:** Dialog shows spinner while fetching, save button disabled during submission.
- **Optimistic refresh:** User list re-fetches after save to reflect changes.

## Files to Create

- `app/Services/RoleManagementService.php`
- `app/Http/Controllers/Admin/RoleManagementController.php`
- `app/Http/Controllers/Platform/PlatformRoleManagementController.php`
- `resources/js/pages/platform/users/index.tsx`

## Files to Modify

- `routes/admin.php` — add tenant role management routes
- `routes/platform.php` — add platform role management routes + Inertia page route
- `resources/js/pages/admin/users.tsx` — add shield button + role dialog
- `resources/js/layouts/user-layout.tsx` — add "Users" to platform nav group
- `database/seeders/RbacSeeder.php` — add `users.manage-roles` to `tenant_admin` role
