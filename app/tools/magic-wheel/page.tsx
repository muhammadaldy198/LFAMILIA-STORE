import { Disc3 } from "lucide-react";
import { MagicWheelCalculator } from "@/components/game-calculators";
import { ToolShell } from "@/components/tool-shell";

export default function MagicWheelPage() {
  return <ToolShell title="Kalkulator Magic Wheel" description="Hitung kombinasi draw dengan biaya paling hemat untuk mencapai 200 Magic Point." icon={Disc3}><MagicWheelCalculator /></ToolShell>;
}
