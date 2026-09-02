"use client";

import { useEffect, useState } from "react";
import { products as fallbackProducts, type StoreProduct } from "@/lib/store-data";

export function useStoreProducts() {
  const [products, setProducts] = useState<StoreProduct[]>(fallbackProducts);
  const [databaseReady, setDatabaseReady] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    async function load() {
      try {
        const response = await fetch("/api/products", { cache: "no-store" });
        const data = await response.json() as { products?: StoreProduct[]; databaseReady?: boolean };
        if (active && data.products?.length) {
          setProducts(data.products);
          setDatabaseReady(Boolean(data.databaseReady));
        }
      } finally {
        if (active) setLoading(false);
      }
    }
    void load();
    return () => { active = false; };
  }, []);

  return { products, databaseReady, loading };
}
