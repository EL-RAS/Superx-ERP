import type { BootstrapConfig } from "./types";

export function permissionKeys(config: BootstrapConfig | null | undefined): string[] {
  return config?.user_permissions ?? [];
}

export function hasPermission(config: BootstrapConfig | null | undefined, key: string): boolean {
  return permissionKeys(config).includes(key);
}

export function hasAnyPermission(config: BootstrapConfig | null | undefined, keys: string[]): boolean {
  return keys.some((key) => hasPermission(config, key));
}

export function canAccessModule(config: BootstrapConfig | null | undefined, module: string): boolean {
  return hasPermission(config, `${module}.view`);
}
