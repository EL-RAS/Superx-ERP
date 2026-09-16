import { create } from "zustand";
import { AuthUser, AuthBusiness, BootstrapConfig } from "@/lib/types";
import { setAuthCookie, getAuthCookie, removeAuthCookie } from "@/lib/cookie";

const STORAGE_KEY = {
  token: "sx_token",
  user: "sx_user",
  business: "sx_business",
} as const;

interface AuthState {
  token: string | null;
  user: AuthUser | null;
  business: AuthBusiness | null;
  config: BootstrapConfig | null;
  isLoading: boolean;
  _hasHydrated: boolean;

  setAuth: (token: string, user: AuthUser, business: AuthBusiness | null) => void;
  setConfig: (config: BootstrapConfig) => void;
  setUser: (user: AuthUser) => void;
  setBusiness: (business: AuthBusiness) => void;
  setLoading: (loading: boolean) => void;
  logout: () => void;
  hydrate: () => boolean;
  isAuthenticated: () => boolean;
}

export const useAuthStore = create<AuthState>((set, get) => ({
  token: null,
  user: null,
  business: null,
  config: null,
  isLoading: true,
  _hasHydrated: false,

  setAuth: (token, user, business) => {
    if (typeof window !== "undefined") {
      localStorage.setItem(STORAGE_KEY.token, token);
      localStorage.setItem(STORAGE_KEY.user, JSON.stringify(user));
      if (business) {
        localStorage.setItem(STORAGE_KEY.business, JSON.stringify(business));
      } else {
        localStorage.removeItem(STORAGE_KEY.business);
      }
      setAuthCookie(token);
    }
    set({ token, user, business });
  },

  setConfig: (config) => set({ config, isLoading: false }),

  setUser: (user) => {
    if (typeof window !== "undefined") {
      localStorage.setItem(STORAGE_KEY.user, JSON.stringify(user));
    }
    set({ user });
  },

  setBusiness: (business) => {
    if (typeof window !== "undefined") {
      localStorage.setItem(STORAGE_KEY.business, JSON.stringify(business));
    }
    set({ business });
  },

  setLoading: (isLoading) => set({ isLoading }),

  logout: () => {
    const { token, business } = get();
    if (typeof window !== "undefined") {
      if (token && business) {
        import("@/lib/api").then(({ logoutRequest }) => {
          logoutRequest(token, business.id);
        });
      }
      localStorage.removeItem(STORAGE_KEY.token);
      localStorage.removeItem(STORAGE_KEY.user);
      localStorage.removeItem(STORAGE_KEY.business);
      removeAuthCookie();
    }
    set({ token: null, user: null, business: null, config: null, isLoading: false });
  },

  hydrate: () => {
    if (get()._hasHydrated) return !!get().token;
    if (typeof window === "undefined") return false;

    let token = localStorage.getItem(STORAGE_KEY.token);
    const userStr = localStorage.getItem(STORAGE_KEY.user);
    const businessStr = localStorage.getItem(STORAGE_KEY.business);

    if (!token) {
      const cookieToken = getAuthCookie();
      if (cookieToken) {
        token = cookieToken;
      }
    }

    if (token && userStr) {
      try {
        const user = JSON.parse(userStr) as AuthUser;
        // Platform owners authenticate without a tenant business attached.
        const business = businessStr ? (JSON.parse(businessStr) as AuthBusiness) : null;
        set({ token, user, business, _hasHydrated: true });
        setAuthCookie(token);
        return true;
      } catch {
        localStorage.removeItem(STORAGE_KEY.user);
        localStorage.removeItem(STORAGE_KEY.business);
        localStorage.removeItem(STORAGE_KEY.token);
        removeAuthCookie();
      }
    }
    set({ _hasHydrated: true });
    return false;
  },

  isAuthenticated: () => {
    const { token, business, user } = get();
    return !!token && (!!business || !!user?.is_platform_owner);
  },
}));
