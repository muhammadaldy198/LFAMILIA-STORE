"use client";

import { useEffect, useState } from "react";
import { PRODUCT_CATEGORIES, normalizeProductCategorySlug } from "@/lib/product-categories";
import { defaultStorefrontSettings, faqs as fallbackFaqs, type StorefrontSettings } from "@/lib/store-data";
import type { FaqRecord, ProductCategoryRecord } from "@/lib/server/storefront";

function canonicalCategories(source: ProductCategoryRecord[] = []): ProductCategoryRecord[] {
  return PRODUCT_CATEGORIES.map((category, index) => {
    const stored = source.find((item) => normalizeProductCategorySlug(item.slug) === category.slug);
    return {
      id: stored?.id ?? null,
      slug: category.slug,
      name: category.label,
      icon: category.icon,
      isActive: stored?.isActive ?? true,
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
    void fetch("/api/storefront", { cache: "no-store" }).then((response) => response.json()).then((data: { settings?: StorefrontSettings; categories?: ProductCategoryRecord[]; faqs?: FaqRecord[] }) => {
      if (!active) return;
      if (data.settings) setSettings(data.settings);
      setCategories(canonicalCategories(data.categories));
      if (data.faqs?.length) setFaqs(data.faqs);
    }).catch(() => undefined);
    return () => { active = false; };
  }, []);

  return { settings, categories, faqs };
}
