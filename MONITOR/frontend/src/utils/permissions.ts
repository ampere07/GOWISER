/**
 * Client-side reading of the permission list the API returns.
 *
 * The strings are the same `section.verb` grants the backend middleware checks, and `/me` sends
 * them already expanded — so a legacy role stored as a bare `financial` arrives here as
 * `financial.view` + `financial.export` and needs no special handling.
 *
 * THIS IS PRESENTATION ONLY. Hiding a button is a courtesy to the user, not a control: every
 * endpoint behind these checks enforces the same grant server-side in EnsurePermission. Nothing
 * here should ever be the only thing standing between a user and data.
 */

export type PermissionVerb = 'view' | 'create' | 'edit' | 'delete' | 'export';

/**
 * Does the list grant this permission?
 *
 * A bare section id means "any verb on it", matching the backend's Permissions::granted().
 *
 * Fails closed on a missing list: before `/me` resolves, permissions are null and the answer is
 * no. Callers that must not flicker should wait for the list rather than defaulting to yes.
 */
export const hasPermission = (
  permissions: string[] | null | undefined,
  required: string
): boolean => {
  if (!permissions || permissions.length === 0) return false;

  const needle = required.toLowerCase().trim();

  if (!needle.includes('.')) {
    return permissions.some((grant) => grant.toLowerCase().startsWith(`${needle}.`));
  }

  return permissions.some((grant) => grant.toLowerCase() === needle);
};

/** Convenience for the common pair. */
export const canView = (permissions: string[] | null | undefined, section: string): boolean =>
  hasPermission(permissions, `${section}.view`);

export const canExport = (permissions: string[] | null | undefined, section: string): boolean =>
  hasPermission(permissions, `${section}.export`);
