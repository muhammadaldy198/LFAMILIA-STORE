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
      const form = new FormData();
      form.set("file", file);
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
