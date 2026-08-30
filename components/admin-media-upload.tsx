"use client";

import { useRef, useState } from "react";
import { ImageIcon, LoaderCircle, Upload } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

export function AdminMediaUpload({
  value,
  onChange,
  label,
  help,
  previewClassName = "aspect-video",
}: {
  value?: string;
  onChange(value: string): void;
  label: string;
  help: string;
  previewClassName?: string;
}) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState("");

  async function upload(file?: File) {
    if (!file) return;
    setUploading(true);
    setError("");
    try {
      const preparedFile = await optimizeImage(file);
      const form = new FormData();
      form.set("file", preparedFile);
      const response = await fetch("/api/admin/media", { method: "POST", body: form });
      const data = await response.json() as { url?: string; error?: string };
      if (!response.ok || !data.url) throw new Error(data.error || "Gambar gagal diunggah.");
      onChange(data.url);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Gambar gagal diunggah.");
    } finally {
      setUploading(false);
      if (inputRef.current) inputRef.current.value = "";
    }
  }

  return (
    <div>
      <span className="field-label">{label}</span>
      <div className={`relative mb-2 overflow-hidden rounded-xl border border-white/[0.08] bg-[#0b0f17] ${previewClassName}`}>
        {value ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={value} alt="Pratinjau media" className="size-full object-cover" />
        ) : (
          <div className="grid size-full place-items-center text-white/18"><ImageIcon className="size-6" /></div>
        )}
      </div>
      <div className="flex gap-2">
        <Input aria-label={`${label} URL`} value={value ?? ""} onChange={(event) => onChange(event.target.value)} className="admin-input min-w-0" placeholder="URL gambar atau unggah dari HP" />
        <input ref={inputRef} type="file" accept="image/jpeg,image/png,image/webp,image/gif" className="hidden" onChange={(event) => void upload(event.target.files?.[0])} />
        <Button type="button" variant="outline" disabled={uploading} onClick={() => inputRef.current?.click()} aria-label={`Unggah ${label.toLowerCase()}`} className="shrink-0 border-white/10 bg-white/[0.03] text-white hover:bg-white/[0.08] hover:text-white">
          {uploading ? <LoaderCircle className="size-4 animate-spin" /> : <Upload className="size-4" />}
          <span className="ml-2 hidden sm:inline">Unggah</span>
        </Button>
      </div>
      <span className="mt-1.5 block text-[9px] text-white/28">{help}</span>
      {error && <span className="mt-1.5 block text-[10px] text-red-300">{error}</span>}
    </div>
  );
}

export async function optimizeImage(file: File) {
  const targetBytes = 1_700_000;
  if (file.type === "image/gif" || file.size <= targetBytes) return file;

  const bitmap = await createImageBitmap(file);
  try {
    for (const plan of [{ maxSide: 1600, quality: 0.82 }, { maxSide: 1200, quality: 0.7 }]) {
      const scale = Math.min(1, plan.maxSide / Math.max(bitmap.width, bitmap.height));
      const canvas = document.createElement("canvas");
      canvas.width = Math.max(1, Math.round(bitmap.width * scale));
      canvas.height = Math.max(1, Math.round(bitmap.height * scale));
      const context = canvas.getContext("2d");
      if (!context) throw new Error("Browser tidak dapat memproses gambar ini.");
      context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/webp", plan.quality));
      if (blob && blob.size <= targetBytes) return new File([blob], `${file.name.replace(/\.[^.]+$/, "")}.webp`, { type: "image/webp" });
    }
  } finally {
    bitmap.close();
  }
  throw new Error("Gambar masih terlalu besar setelah dikompres. Pilih gambar lain.");
}
