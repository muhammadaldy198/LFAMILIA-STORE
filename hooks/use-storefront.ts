"use client";

import { useEffect, useState } from "react";
import { PRODUCT_CATEGORIES, normalizeProductCategorySlug } from "@/lib/product-categories";
import { defaultStorefrontSettings, faqs as fallbackFaqs, type StorefrontSettings } from "@/lib/store-data";
import type { FaqRecord, ProductCategoryRecord } from "@/lib/server/storefront";

function canonicalCategories(
  source: ProductCategoryRecord[] = [],
  authoritative = false,
): ProductCategoryRecord[] {
  return PRODUCT_CATEGORIES.map((category, index) => {
    const stored = source.find((item) => normalizeProductCategorySlug(item.slug) === category.slug);
    return {
      id: stored?.id ?? null,
      slug: category.slug,
      name: category.label,
      icon: category.icon,
      isActive: stored?.isActive ?? !authoritative,
      sortOrder: index,
    };
  });
}

export function useStorefront() {
  const [settings, setSettings] = useState<StorefrontSettings>(defaultStorefrontSettings);
  const [categories, setCategories] = useState<ProductCategoryRecord[]>(canonicalCategories());
  const [faqs, setFaqs] = useState<FaqRecord[]>(fallbackFaqs.map((item, index) => ({ id: null, ...item, isActive: true, sortOrder: index })));

  useEffect(() => {
    let active = true;
    void fetch("/api/storefront", { cache: "no-store" })
      .then(async (response) => {
        if (!response.ok) throw new Error("Storefront gagal dimuat.");
        return response.json() as Promise<{
          settings?: StorefrontSettings;
          categories?: ProductCategoryRecord[];
          faqs?: FaqRecord[];
        }>;
      })
      .then((data) => {
        if (!active) return;
        if (data.settings) setSettings(data.settings);
        if (Array.isArray(data.categories)) {
          setCategories(canonicalCategories(data.categories, true));
        }
        if (Array.isArray(data.faqs)) setFaqs(data.faqs);
      })
      .catch(() => undefined);
    return () => { active = false; };
  }, []);

  return { settings, categories, faqs };
}
