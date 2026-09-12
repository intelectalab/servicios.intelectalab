import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";
import { TOKEN_COOKIE } from "@/lib/auth";

const AUTH_ROUTES = ["/", "/login", "/forgot-password", "/reset-password"];

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const hasToken = Boolean(request.cookies.get(TOKEN_COOKIE)?.value);
  const isAuthRoute = AUTH_ROUTES.some((route) => pathname === route || pathname.startsWith(`${route}/`));

  if (!hasToken && pathname.startsWith("/customers")) {
    return NextResponse.redirect(new URL("/login", request.url));
  }

  if (hasToken && isAuthRoute) {
    return NextResponse.redirect(new URL("/customers", request.url));
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/", "/login/:path*", "/forgot-password/:path*", "/reset-password/:path*", "/customers/:path*"],
};
