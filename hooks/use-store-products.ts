"use client";

import { useEffect, useState } from "react";
import type { StoreProduct } from "@/lib/store-data";

export function useStoreProducts() {
  const [products, setProducts] = useState<StoreProduct[]>([]);
  const [databaseReady, setDatabaseReady] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    async function load() {
      try {
        const response = await fetch("/api/products", { cache: "no-store" });
        const data = await response.json().catch(() => ({})) as {
          products?: StoreProduct[];
          databaseReady?: boolean;
        };
        if (!active) return;
        setProducts(data.products ?? []);
        setDatabaseReady(Boolean(response.ok && data.databaseReady));
      } catch {
        if (!active) return;
        setProducts([]);
        setDatabaseReady(false);
      } finally {
        if (active) setLoading(false);
      }
    }
    void load();
    return () => { active = false; };
  }, []);

  return { products, databaseReady, loading };
}
