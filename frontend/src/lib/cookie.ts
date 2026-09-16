import Cookies from "js-cookie";

const COOKIE_NAME = "sx_auth";
const COOKIE_DAYS = 30;

export function setAuthCookie(token: string) {
  Cookies.set(COOKIE_NAME, token, {
    expires: COOKIE_DAYS,
    path: "/",
    sameSite: "lax",
  });
}

export function getAuthCookie(): string | undefined {
  return Cookies.get(COOKIE_NAME);
}

export function removeAuthCookie() {
  Cookies.remove(COOKIE_NAME, { path: "/" });
}
