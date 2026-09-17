import type { BootstrapConfig } from "./types";

export function permissionKeys(config: BootstrapConfig | null | undefined): string[] {
  return config?.user_permissions ?? [];
}

export function hasPermission(config: BootstrapConfig | null | undefined, key: string): boolean {
  return permissionKeys(config).includes(key);
}
