import * as categories from "@/app/api/admin/categories/route";
import * as content from "@/app/api/admin/content/route";
import * as digiflazzPricing from "@/app/api/admin/digiflazz-pricing/route";
import * as faqs from "@/app/api/admin/faqs/route";
import * as media from "@/app/api/admin/media/route";
import * as members from "@/app/api/admin/members/route";
import * as paymentMethods from "@/app/api/admin/payment-methods/route";
import * as orders from "@/app/api/admin/orders/route";
import * as productContent from "@/app/api/admin/product-content/route";
import * as productPackageStatus from "@/app/api/admin/product-package-status/route";
import * as products from "@/app/api/admin/products/route";
import * as productSeed from "@/app/api/admin/products/seed/route";
import * as promotions from "@/app/api/admin/promotions/route";
import * as reviews from "@/app/api/admin/reviews/route";
import * as session from "@/app/api/admin/session/route";
import * as support from "@/app/api/admin/support/route";
import * as storefront from "@/app/api/admin/storefront/route";
import * as summary from "@/app/api/admin/summary/route";
import * as team from "@/app/api/admin/team/route";
import * as vouchers from "@/app/api/admin/vouchers/route";
import * as wallet from "@/app/api/admin/wallet/route";
import * as walletProof from "@/app/api/admin/wallet/proof/route";
import { getAdminSession } from "@/lib/server/admin";
import { recordAdminActivity } from "@/lib/server/security";
import { ensureLegacyDatabaseColumns } from "@/lib/server/database-repair";

type Method = "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
type Handler = (request: Request) => Response | Promise<Response>;
type RouteHandlers = Partial<Record<Method, Handler>>;
type RouteContext = { params: Promise<{ path: string[] }> };

const routes: Record<string, RouteHandlers> = {
  categories: { GET: categories.GET, POST: categories.POST, DELETE: categories.DELETE },
  content: { GET: content.GET, POST: content.POST, DELETE: content.DELETE },
  "digiflazz-pricing": { GET: digiflazzPricing.GET, POST: digiflazzPricing.POST },
  faqs: { GET: faqs.GET, POST: faqs.POST, DELETE: faqs.DELETE },
  media: { POST: media.POST },
  members: { GET: members.GET, PUT: members.PUT, PATCH: members.PATCH },
  "payment-methods": { GET: paymentMethods.GET, POST: paymentMethods.POST, DELETE: paymentMethods.DELETE },
  orders: { GET: orders.GET, PATCH: orders.PATCH },
  "product-content": { PUT: productContent.PUT },
  "product-package-status": { PATCH: productPackageStatus.PATCH },
  products: { GET: products.GET, POST: products.POST, PATCH: products.PATCH, DELETE: products.DELETE },
  "products/seed": { POST: productSeed.POST },
  promotions: { GET: promotions.GET, POST: promotions.POST, DELETE: promotions.DELETE },
  reviews: { GET: reviews.GET, PATCH: reviews.PATCH },
  session: { GET: session.GET },
  support: { GET: support.GET, PATCH: support.PATCH },
  storefront: { GET: storefront.GET, PUT: storefront.PUT },
  summary: { GET: summary.GET },
  team: { GET: team.GET, POST: team.POST, DELETE: team.DELETE },
  vouchers: { GET: vouchers.GET, POST: vouchers.POST, PATCH: vouchers.PATCH },
  wallet: { GET: wallet.GET, PUT: wallet.PUT },
  "wallet/proof": { GET: walletProof.GET },
};

async function dispatch(request: Request, context: RouteContext, method: Method) {
  await ensureLegacyDatabaseColumns();
  const { path } = await context.params;
  const handlers = routes[path.join("/")];
  if (!handlers) return Response.json({ error: "Endpoint panel tidak ditemukan." }, { status: 404 });
  const handler = handlers[method];
  if (!handler) return Response.json({ error: "Metode tidak diizinkan." }, { status: 405, headers: { Allow: Object.keys(handlers).join(", ") } });
  const response = await handler(request);
  if (!["GET", "HEAD", "OPTIONS"].includes(method) && response.ok) {
    const admin = await getAdminSession(request);
    if (admin) await recordAdminActivity(admin, method, path.join("/"));
  }
  return response;
}

export function GET(request: Request, context: RouteContext) { return dispatch(request, context, "GET"); }
export function POST(request: Request, context: RouteContext) { return dispatch(request, context, "POST"); }
export function PUT(request: Request, context: RouteContext) { return dispatch(request, context, "PUT"); }
export function PATCH(request: Request, context: RouteContext) { return dispatch(request, context, "PATCH"); }
export function DELETE(request: Request, context: RouteContext) { return dispatch(request, context, "DELETE"); }
