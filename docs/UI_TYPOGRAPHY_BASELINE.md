# Customer typography baseline

Approved customer-facing baseline from 2026-10-02. Nilai ini tetap menjadi referensi visual customer ketika sesuai dengan CSS/komponen aktual; ia bukan kontrak untuk memaksa setiap komponen ke ukuran yang sama.

Admin panel sekarang mempunyai styling/shadcn workspace sendiri dan **bukan “future Admin pass”**. Untuk status Admin gunakan `rebuild/ADMIN.md` dan komponen/CSS aktual.

| Role | Mobile | Desktop |
| --- | --- | --- |
| Page title | 24px | 28px |
| Product title | 20px | 24px |
| Section heading | 16px | 18px |
| Body | 13px | 14px |
| Field label | 12px | 13px |
| Input/select | 16px | 16px |
| Button/menu | 12px | 13px |
| Helper/metadata | 11px | 12px |

Customer controls menggunakan target 36px pada mobile dan 40px pada desktop ketika komponen mengikuti baseline global. Textarea dimulai lebih tinggi; nominal name/price dapat mempunyai scale khusus.

Footer desktop mempunyai typography sendiri untuk readability. Banner/media dimensions dan component-specific responsive rules tetap mengikuti CSS/komponen aktual.

Jangan memakai dokumen ini untuk menimpa business behavior atau memaksa seluruh Admin shadcn control mengikuti ukuran customer.
