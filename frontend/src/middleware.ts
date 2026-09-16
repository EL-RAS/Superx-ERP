import { NextRequest, NextResponse } from "next/server";

const AUTH_COOKIE = "sx_auth";

export function middleware(request: NextRequest) {
  const token = request.cookies.get(AUTH_COOKIE)?.value;
  const { pathname } = request.nextUrl;

  const isLandingOrLogin = pathname === "/" || pathname === "/login" || pathname === "/register";

  if (token && isLandingOrLogin) {
    return NextResponse.redirect(new URL("/dashboard", request.url));
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/", "/login", "/register"],
};
