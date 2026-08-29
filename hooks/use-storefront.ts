"use client";

import { useEffect, useState } from "react";
import { defaultStorefrontSettings, faqs as fallbackFaqs, type StorefrontSettings } from "@/lib/store-data";
import type { FaqRecord, ProductCategoryRecord } from "@/lib/server/storefront";

export function useStorefront() {
  const [settings, setSettings] = useState<StorefrontSettings>(defaultStorefrontSettings);
  const [categories, setCategories] = useState<ProductCategoryRecord[]>([
    { id: null, slug: "game", name: "Top Up Game", icon: "gamepad", isActive: true, sortOrder: 0 },
    { id: null, slug: "voucher", name: "Voucher & Gift Card", icon: "ticket", isActive: true, sortOrder: 1 },
    { id: null, slug: "entertainment", name: "Entertainment", icon: "play", isActive: true, sortOrder: 2 },
    { id: null, slug: "pulsa", name: "Pulsa & Data", icon: "smartphone", isActive: true, sortOrder: 3 },
  ]);
  const [faqs, setFaqs] = useState<FaqRecord[]>(fallbackFaqs.map((item, index) => ({ id: null, ...item, isActive: true, sortOrder: index })));

  useEffect(() => {
    let active = true;
    void fetch("/api/storefront", { cache: "no-store" }).then((response) => response.json()).then((data: { settings?: StorefrontSettings; categories?: ProductCategoryRecord[]; faqs?: FaqRecord[] }) => {
      if (!active) return;
      if (data.settings) setSettings(data.settings);
      if (data.categories?.length) setCategories(data.categories);
      if (data.faqs?.length) setFaqs(data.faqs);
    }).catch(() => undefined);
    return () => { active = false; };
  }, []);

  return { settings, categories, faqs };
}
