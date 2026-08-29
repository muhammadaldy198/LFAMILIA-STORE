export function getAdminEmail(request: Request) {
  return request.headers.get("x-lfamilia-admin-email");
}

export function unauthorizedResponse() {
  return Response.json({ error: "Akses admin diperlukan." }, { status: 401 });
}

