# Source + audit

- **Skill:** laravel-verification (author affaan-m)
- **Ditemukan di:** SkillsMP — https://skillsmp.com/creators/affaan-m/ecc/pi-core-skills-laravel-verification
- **Diambil dari:** https://github.com/affaan-m/ECC/tree/main/pi/core/skills/laravel-verification (raw SKILL.md, 181 baris)
- **Diambil:** 16 Okt 2026 (oleh subagent atas perintah user "install semua yang lu butuhin biar overpower")
- **Audit keamanan (subagent, baca penuh 181 baris):** LULUS. Laravel verification loop (env -> composer -> pint/phpstan -> tests/coverage -> composer audit -> migrations -> cache/queue). Contains standard artisan/composer commands; Phase 7 staging-only job dispatch is explicitly guarded ('staging only', 'non-production'). No malicious content. Catatan: BETOL must apply its own rules (no production changes without explicit order).
- **SKILL.md disalin apa adanya** (verbatim, tidak diubah — diverifikasi byte-identical dengan file yang di-fetch).
