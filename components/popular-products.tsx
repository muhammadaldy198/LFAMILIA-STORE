"use client";

import { ProductCard } from "@/components/product-card";
import { useStoreProducts } from "@/hooks/use-store-products";

export function PopularProducts() {
  const { products } = useStoreProducts();
  const popular = products.filter((product) => product.popular).slice(0, 6);
  const shown = popular.length ? popular : products.slice(0, 6);
  return <div className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:grid-cols-4">{shown.map((product) => <ProductCard key={product.slug} product={product} />)}</div>;
}

