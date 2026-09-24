const SENSITIVE_KEY = /^(?:authorization|api[_-]?key|token|secret|password|signature|client[_-]?secret|server[_-]?key|private[_-]?key)$/i;

export function redactLogText(value: string) {
  return value
    .replace(/Bearer\s+[A-Za-z0-9._~+\/-=]+/gi, "Bearer [REDACTED]")
    .replace(
      /\b(authorization|api[_ -]?key|token|secret|password|signature|client[_ -]?secret|server[_ -]?key|private[_ -]?key)\b\s*[:=]\s*(?:"[^"]*"|'[^']*'|[^\s,;]+)/gi,
      "$1=[REDACTED]",
    )
    .replace(/(https:\/\/)([^\s\/:@]+):([^\s\/@]+)@/gi, "$1[REDACTED]@")
    .slice(0, 1200);
}

export function safeErrorForLog(error: unknown) {
  if (error instanceof Error) {
    return {
      name: redactLogText(error.name || "Error").slice(0, 120),
      message: redactLogText(error.message || "Unhandled server error"),
    };
  }
  if (typeof error === "string") {
    return { name: "Error", message: redactLogText(error) };
  }
  if (error && typeof error === "object") {
    const candidate = error as { name?: unknown; message?: unknown; code?: unknown };
    return {
      name: typeof candidate.name === "string" ? redactLogText(candidate.name).slice(0, 120) : "Error",
      message: typeof candidate.message === "string" ? redactLogText(candidate.message) : "Unhandled server error",
      ...(typeof candidate.code === "string" || typeof candidate.code === "number"
        ? { code: String(candidate.code).slice(0, 80) }
        : {}),
    };
  }
  return { name: "Error", message: "Unhandled server error" };
}

function safeDetails(details: Record<string, string | number | boolean | null | undefined>) {
  const output: Record<string, string | number | boolean | null> = {};
  for (const [key, value] of Object.entries(details)) {
    if (value === undefined) continue;
    if (SENSITIVE_KEY.test(key)) {
      output[key] = "[REDACTED]";
    } else if (typeof value === "string") {
      output[key] = redactLogText(value).slice(0, 300);
    } else {
      output[key] = value;
    }
  }
  return output;
}

export function logServerError(
  context: string,
  error: unknown,
  details: Record<string, string | number | boolean | null | undefined> = {},
) {
  console.error(JSON.stringify({
    event: "server_error",
    context: redactLogText(context).slice(0, 180),
    error: safeErrorForLog(error),
    ...safeDetails(details),
  }));
}
