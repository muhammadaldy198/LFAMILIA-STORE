"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Gamepad2, Search, Ticket } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command";
import { useStoreProducts } from "@/hooks/use-store-products";
import { ProductArtwork } from "@/components/product-artwork";

export function StoreSearch({ mobile = false }: { mobile?: boolean }) {
  const [open, setOpen] = useState(false);
  const router = useRouter();
  const { products } = useStoreProducts();

  function chooseProduct(slug: string) {
    setOpen(false);
    router.push(`/checkout?product=${slug}`);
  }

  return (
    <>
      <Button
        type="button"
        variant="outline"
        onClick={() => setOpen(true)}
        className={mobile
          ? "size-10 rounded-xl border-white/10 bg-white/[0.04] p-0 text-white/65 hover:bg-white/[0.08] hover:text-white"
          : "hidden h-10 w-full max-w-sm justify-start rounded-xl border-white/10 bg-white/[0.035] px-3 text-xs font-medium text-white/35 hover:bg-white/[0.07] hover:text-white/65 lg:flex"}
        aria-label="Cari game atau voucher"
      >
        <Search className={mobile ? "size-4" : "mr-2 size-4"} />
        {!mobile && <span>Cari game atau voucher...</span>}
      </Button>

      <CommandDialog
        open={open}
        onOpenChange={setOpen}
        title="Cari produk LFAMILIA STORE"
        description="Temukan game atau voucher yang ingin kamu top up."
        className="border-white/10 bg-[#10131b] text-white shadow-2xl"
      >
        <CommandInput placeholder="Ketik nama game..." className="text-white placeholder:text-white/30" />
        <CommandList className="max-h-[420px]">
          <CommandEmpty className="py-10 text-center text-sm text-white/40">Produk tidak ditemukan.</CommandEmpty>
          <CommandGroup heading="Game & voucher" className="[&_[cmdk-group-heading]]:text-white/30">
            {products.map((product) => {
              const Icon = product.category === "game" ? Gamepad2 : Ticket;
              return (
                <CommandItem
                  key={product.slug}
                  value={`${product.name} ${product.publisher}`}
                  onSelect={() => chooseProduct(product.slug)}
                  className="my-1 cursor-pointer rounded-xl px-3 py-3 text-white/75 data-[selected=true]:bg-[#b9ff35]/10 data-[selected=true]:text-white"
                >
                  <span className="block size-10 shrink-0 overflow-hidden rounded-lg"><ProductArtwork product={product} compact /></span>
                  <span className="min-w-0 flex-1"><strong className="block truncate text-sm">{product.name}</strong><span className="text-[10px] text-white/30">{product.publisher}</span></span>
                  <Icon className="size-4 text-[#b9ff35]" />
                </CommandItem>
              );
            })}
          </CommandGroup>
        </CommandList>
      </CommandDialog>
    </>
  );
}  const router = useRouter();
  const { products } = useStoreProducts();

  function chooseProduct(slug: string) {
    setOpen(false);
    router.push(`/checkout?product=${slug}`);
  }

  return (
    <>
      <Button
        type="button"
        variant="outline"
        onClick={() => setOpen(true)}
        className={mobile
          ? "size-10 rounded-xl border-white/10 bg-white/[0.04] p-0 text-white/65 hover:bg-white/[0.08] hover:text-white"
          : "hidden h-10 w-full max-w-sm justify-start rounded-xl border-white/10 bg-white/[0.035] px-3 text-xs font-medium text-white/35 hover:bg-white/[0.07] hover:text-white/65 lg:flex"}
        aria-label="Cari game atau voucher"
      >
        <Search className={mobile ? "size-4" : "mr-2 size-4"} />
        {!mobile && <span>Cari game atau voucher...</span>}
      </Button>

      <CommandDialog
        open={open}
        onOpenChange={setOpen}
        title="Cari produk LFAMILIA STORE"
        description="Temukan game atau voucher yang ingin kamu top up."
        className="border-white/10 bg-[#10131b] text-white shadow-2xl"
      >
        <CommandInput placeholder="Ketik nama game..." className="text-white placeholder:text-white/30" />
        <CommandList className="max-h-[420px]">
          <CommandEmpty className="py-10 text-center text-sm text-white/40">Produk tidak ditemukan.</CommandEmpty>
          <CommandGroup heading="Game & voucher" className="[&_[cmdk-group-heading]]:text-white/30">
            {products.map((product) => {
              const Icon = product.category === "game" ? Gamepad2 : Ticket;
              return (
                <CommandItem
                  key={product.slug}
                  value={`${product.name} ${product.publisher}`}
                  onSelect={() => chooseProduct(product.slug)}
                  className="my-1 cursor-pointer rounded-xl px-3 py-3 text-white/75 data-[selected=true]:bg-[#b9ff35]/10 data-[selected=true]:text-white"
                >
                  <span className={`grid size-9 place-items-center rounded-lg bg-gradient-to-br ${product.accent} text-[10px] font-black text-white`}>{product.initials}</span>
                  <span className="min-w-0 flex-1"><strong className="block truncate text-sm">{product.name}</strong><span className="text-[10px] text-white/30">{product.publisher}</span></span>
                  <Icon className="size-4 text-[#b9ff35]" />
                </CommandItem>
              );
            })}
          </CommandGroup>
        </CommandList>
      </CommandDialog>
    </>
  );
}
