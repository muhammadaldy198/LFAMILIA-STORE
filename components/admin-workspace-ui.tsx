"use client";

import type { LucideIcon } from "lucide-react";
import { Check, Copy, X } from "lucide-react";
import { useState, type ReactNode } from "react";

export const inputClass = "h-9 w-full rounded-md border border-[#dfe5ed] bg-white px-3 text-[10px] text-[#243653] outline-none transition placeholder:text-[#9aa7ba] focus:border-[#1769e8] focus:ring-2 focus:ring-[#1769e8]/10";
export const buttonClass = "inline-flex h-9 items-center justify-center gap-2 rounded-md border border-[#dfe5ed] bg-white px-3 text-[10px] font-bold text-[#26364f] transition hover:border-[#b9c8db] hover:bg-[#f8fafc]";
export const primaryButtonClass = "inline-flex h-9 items-center justify-center gap-2 rounded-md bg-[#0769e9] px-3 text-[10px] font-bold text-white shadow-[0_5px_14px_rgba(7,105,233,0.2)] transition hover:bg-[#075dcc]";

export function WorkspaceHeader({ title, description, actions }: { title: string; description: string; actions?: ReactNode }) {
  return <div className="mb-4 flex flex-col items-start justify-between gap-3 sm:flex-row sm:gap-5">
    <div><h1 className="text-[21px] font-extrabold tracking-[-0.035em] text-[#10203d] sm:text-[24px]">{title}</h1><p className="mt-0.5 max-w-[680px] text-[11px] leading-4 text-[#718198] sm:text-[10px]">{description}</p></div>
    {actions && <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:shrink-0">{actions}</div>}
  </div>;
}

export function Panel({ title, description, action, children, className = "" }: { title?: string; description?: string; action?: ReactNode; children: ReactNode; className?: string }) {
  return <section className={`overflow-hidden rounded-lg border border-[#e1e6ed] bg-white shadow-[0_1px_3px_rgba(15,23,42,0.04)] ${className}`}>
    {(title || action) && <div className="flex min-h-[54px] flex-col items-start justify-between gap-2 border-b border-[#edf0f4] px-3 py-3 sm:flex-row sm:items-center sm:gap-3 sm:px-4 sm:py-2.5"><div>{title && <h2 className="text-[14px] font-extrabold text-[#14213a] sm:text-[13px]">{title}</h2>}{description && <p className="mt-0.5 text-[10px] leading-4 text-[#8190a5] sm:text-[9px]">{description}</p>}</div>{action}</div>}
    {children}
  </section>;
}

export function MetricCard({ icon: Icon, label, value, detail, tone = "blue" }: { icon: LucideIcon; label: string; value: string; detail: string; tone?: "blue" | "green" | "amber" | "red" | "violet" }) {
  const colors = { blue: "bg-blue-50 text-[#0769e9]", green: "bg-emerald-50 text-emerald-600", amber: "bg-amber-50 text-amber-500", red: "bg-rose-50 text-rose-500", violet: "bg-violet-50 text-violet-600" };
  return <div className="flex min-h-[88px] items-center gap-3 rounded-lg border border-[#e2e7ee] bg-white px-4 py-3 shadow-[0_1px_3px_rgba(15,23,42,0.035)]">
    <span className={`grid size-10 shrink-0 place-items-center rounded-lg ${colors[tone]}`}><Icon className="size-5" /></span>
    <div className="min-w-0"><p className="text-[9px] font-semibold text-[#607089]">{label}</p><strong className="mt-0.5 block truncate text-[17px] font-extrabold text-[#10203d]">{value}</strong><p className="mt-0.5 truncate text-[8px] text-[#8a98aa]">{detail}</p></div>
  </div>;
}

export function TabBar({ tabs, active, onChange }: { tabs: string[]; active: string; onChange(value: string): void }) {
  return <div className="mb-4 overflow-x-auto border-b border-[#dfe5ed] [-webkit-overflow-scrolling:touch]">
    <div className="flex min-w-max items-center gap-1">
      {tabs.map((tab) => <button key={tab} type="button" onClick={() => onChange(tab)} className={`border-b-2 px-3 py-2.5 text-[11px] font-bold transition sm:text-[10px] ${active === tab ? "border-[#0769e9] text-[#0769e9]" : "border-transparent text-[#718198] hover:text-[#243653]"}`}>{tab}</button>)}
    </div>
  </div>;
}

export function Toggle({ checked, onChange, label }: { checked: boolean; onChange(value: boolean): void; label?: string }) {
  return <button type="button" role="switch" aria-checked={checked} onClick={() => onChange(!checked)} className="inline-flex items-center gap-2">
    <span className={`relative h-[20px] w-[36px] rounded-full transition ${checked ? "bg-[#0769e9]" : "bg-[#cbd5e1]"}`}><span className={`absolute top-[3px] size-[14px] rounded-full bg-white shadow transition ${checked ? "left-[19px]" : "left-[3px]"}`} /></span>
    {label && <span className="text-[9px] font-semibold text-[#52627a]">{label}</span>}
  </button>;
}

export function Status({ children, tone = "green" }: { children: ReactNode; tone?: "green" | "amber" | "red" | "blue" | "gray" | "violet" }) {
  const colors = { green: "bg-emerald-50 text-emerald-600", amber: "bg-amber-50 text-amber-600", red: "bg-rose-50 text-rose-600", blue: "bg-blue-50 text-blue-600", gray: "bg-slate-100 text-slate-500", violet: "bg-violet-50 text-violet-600" };
  return <span className={`inline-flex rounded px-2 py-1 text-[8px] font-extrabold ${colors[tone]}`}>{children}</span>;
}

export function Field({ label, help, children, wide = false }: { label: string; help?: string; children: ReactNode; wide?: boolean }) {
  return <label className={wide ? "col-span-2" : ""}><span className="mb-1.5 block text-[9px] font-bold text-[#34445f]">{label}</span>{children}{help && <small className="mt-1 block text-[8px] leading-3.5 text-[#8a98aa]">{help}</small>}</label>;
}

export function CopyUrl({ label, value, note }: { label: string; value: string; note?: string }) {
  const [copied, setCopied] = useState(false);
  async function copy() { try { await navigator.clipboard.writeText(value); setCopied(true); window.setTimeout(() => setCopied(false), 1500); } catch {} }
  return <div className="rounded-md border border-[#dfe5ed] bg-[#f8fafc] p-2.5"><div className="mb-1 flex items-center justify-between gap-2"><strong className="text-[9px] text-[#34445f]">{label}</strong>{note && <span className="text-[8px] text-[#8a98aa]">{note}</span>}</div><div className="flex gap-2"><code className="flex h-8 min-w-0 flex-1 items-center truncate rounded border border-[#e5eaf1] bg-white px-2.5 text-[9px] text-[#1769e8]">{value}</code><button type="button" onClick={() => void copy()} className={buttonClass} title={`Salin ${label}`}>{copied ? <Check className="size-3.5 text-emerald-600" /> : <Copy className="size-3.5" />}{copied ? "Tersalin" : "Salin"}</button></div></div>;
}

export function Modal({ open, title, description, children, footer, onClose, width = "max-w-[620px]" }: { open: boolean; title: string; description?: string; children: ReactNode; footer?: ReactNode; onClose(): void; width?: string }) {
  if (!open) return null;
  return <div className="fixed inset-0 z-[80] grid place-items-end bg-[#0b1729]/45 p-0 sm:place-items-center sm:p-5" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
    <div className={`max-h-[92vh] w-full ${width} overflow-hidden rounded-t-xl border border-[#dfe5ed] bg-white shadow-[0_24px_80px_rgba(15,23,42,0.25)] sm:max-h-[88vh] sm:rounded-lg`}>
      <div className="flex items-start justify-between border-b border-[#edf0f4] px-4 py-3"><div><h2 className="text-sm font-extrabold text-[#14213a]">{title}</h2>{description && <p className="mt-0.5 text-[9px] text-[#8190a5]">{description}</p>}</div><button type="button" onClick={onClose} className="grid size-7 place-items-center rounded text-[#718198] hover:bg-slate-100"><X className="size-4" /></button></div>
      <div className="max-h-[66vh] overflow-y-auto p-4">{children}</div>
      {footer && <div className="flex items-center justify-end gap-2 border-t border-[#edf0f4] px-4 py-3">{footer}</div>}
    </div>
  </div>;
}
