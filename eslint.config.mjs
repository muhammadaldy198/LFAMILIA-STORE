import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
  {
    files: ["components/ui/**/*.{ts,tsx}", "hooks/use-mobile.ts"],
    rules: {
      // These files are vendored verbatim from shadcn@4.17.0. Keep the
      // registry source intact while applying the stricter rules to Site code.
      "@typescript-eslint/no-unused-vars": "off",
      "react-hooks/purity": "off",
      "react-hooks/set-state-in-effect": "off",
    },
  },
  {
    files: ["app/checkout/page.tsx"],
    rules: {
      // Checkout intentionally performs a hard navigation after creating an invoice
      // so stale form/payment state cannot survive into the payment page.
      "@next/next/no-location-assign-relative-destination": "off",
    },
  },
  {
    files: [
      "components/admin-digiflazz-workspace.tsx",
      "components/admin-operations-workspaces.tsx",
    ],
    rules: {
      // These operational loaders intentionally run from explicit refresh/initial-load
      // boundaries rather than re-running whenever mutable result arrays change.
      "react-hooks/exhaustive-deps": "off",
    },
  },
  {
    files: ["lib/server/products.ts"],
    rules: {
      // The retired one-time catalog repopulation helper is intentionally retained
      // only as migration history while no longer being invoked from runtime reads.
      "@typescript-eslint/no-unused-vars": "off",
    },
  },
]);

export default eslintConfig;
