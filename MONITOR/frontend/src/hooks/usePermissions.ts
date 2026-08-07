import { createContext, useContext } from 'react';
import { UserData } from '../types/api';

interface PermissionContextValue {
  /** The effective list from /me — role plus grants, minus denials. */
  permissions: string[];
  /** Whether the role itself is one the consolidated executive view is for. */
  isExecutiveRole: boolean;
  /**
   * Whether the role bypasses the permission map outright — Super Admin and
   * Executive. See Permissions::FULL_ACCESS_ROLES.
   */
  isFullAccess: boolean;
  user: UserData | null;
}

/**
 * The signed-in user's effective permissions, available to any panel.
 *
 * A context rather than a prop threaded through five page components: widget
 * gating happens deep inside pages — a stat card inside a panel inside a grid —
 * and passing `permissions` down to every one of them would mean every
 * intermediate component knowing about authorisation it does not otherwise care
 * about.
 *
 * The default is an empty list, which denies everything. That matters: a
 * component rendered outside the provider by mistake hides its content rather
 * than revealing it, so the failure mode of a wiring bug is a missing panel and
 * not a leaked figure.
 */
export const PermissionContext = createContext<PermissionContextValue>({
  permissions: [],
  isExecutiveRole: false,
  isFullAccess: false,
  user: null,
});

export interface PermissionApi {
  /** True when every listed permission is held. */
  can: (...permissions: string[]) => boolean;
  /** True when at least one is held. */
  canAny: (...permissions: string[]) => boolean;
  isExecutiveRole: boolean;
  /** True for the roles that hold everything regardless of the map. */
  isFullAccess: boolean;
  permissions: string[];
  user: UserData | null;
}

export const usePermissions = (): PermissionApi => {
  const { permissions, isExecutiveRole, isFullAccess, user } = useContext(PermissionContext);

  return {
    // `can` requires all, `canAny` requires one. Two functions rather than one
    // with a mode flag, because at the call site `can(A, B)` reading as "and" is
    // the whole point of having it.
    //
    // A full-access role short-circuits both. The backend already sends such a
    // role the complete list, so this is belt and braces rather than the
    // mechanism — but it is the half that covers an id which exists only in this
    // codebase and therefore could never appear in a list the server built.
    // Failing *open* here is safe only because the server still enforces every
    // route and masks every payload: this decides what is drawn, never what is
    // served.
    can: (...ids: string[]) => isFullAccess || ids.every((id) => permissions.includes(id)),
    canAny: (...ids: string[]) => isFullAccess || ids.some((id) => permissions.includes(id)),
    isExecutiveRole,
    isFullAccess,
    permissions,
    user,
  };
};
