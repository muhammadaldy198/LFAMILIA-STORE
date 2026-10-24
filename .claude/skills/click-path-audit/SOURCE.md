# Source + audit

- **Skill:** click-path-audit (author affaan-m)
- **Ditemukan di:** SkillsMP — https://skillsmp.com/creators/affaan-m/ecc/pi-core-skills-click-path-audit
- **Diambil dari:** https://github.com/affaan-m/ECC/tree/main/pi/core/skills/click-path-audit (raw SKILL.md, 246 baris)
- **Diambil:** 16 Okt 2026 (oleh subagent atas perintah user "install semua yang lu butuhin biar overpower")
- **Audit keamanan (subagent, baca penuh 246 baris):** LULUS. Behavioural flow audit: map state stores (side-effect map) -> trace each touchpoint handler in order -> report Sequential Undo / Async Race / Stale Closure / Missing Transition / Dead Path / useEffect Interference. Workflow-only, no malicious content. Catatan: examples use React/Zustand, but the trace method transfers to Vue/Pinia/Inertia.
- **SKILL.md disalin apa adanya** (verbatim, tidak diubah — diverifikasi byte-identical dengan file yang di-fetch).
