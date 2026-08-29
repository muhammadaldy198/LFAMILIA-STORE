import { Sparkles } from "lucide-react";
import { ZodiacCalculator } from "@/components/game-calculators";
import { ToolShell } from "@/components/tool-shell";

export default function ZodiacPage() {
  return <ToolShell title="Kalkulator Zodiac" description="Perkirakan jumlah summon dan diamond yang dibutuhkan untuk mencapai 100 poin Zodiac." icon={Sparkles}><ZodiacCalculator /></ToolShell>;
}
