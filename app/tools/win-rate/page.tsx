import { Calculator } from "lucide-react";
import { WinRateCalculator } from "@/components/game-calculators";
import { ToolShell } from "@/components/tool-shell";

export default function WinRatePage() {
  return <ToolShell title="Kalkulator Win Rate" description="Hitung berapa kemenangan beruntun yang dibutuhkan untuk mencapai target win rate." icon={Calculator}><WinRateCalculator /></ToolShell>;
}
