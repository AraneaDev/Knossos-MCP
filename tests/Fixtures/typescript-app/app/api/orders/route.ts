export async function GET() {
  return Response.json([]);
}

export async function createOrder() {
  "use server";
  return { ok: true };
}
