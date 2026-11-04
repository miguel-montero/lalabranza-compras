# La Labranza — Kitchen Price Tracker (Phase 1) — Design

Date: 2026-11-04
Source requirements: `Kitchen_Tracker_Requirements_v1.docx` (SRS v1.0, 2 Nov 2026). This document supersedes the SRS where they differ.

## 1. Intent

La Labranza (restaurant at Viña La Quirinca) tracks supplier purchases in a spreadsheet ("CONTROL DE PRECIOS.xlsx", one line per purchase). It is hard to answer "am I paying more than before, and who is cheapest?" because units, pack sizes and tax treatment differ between purchases.

This app replaces the spreadsheet. One person enters each receipt (header + item lines). The app computes a **real cost per base unit** (per kg, per L, per unit) that is comparable across suppliers and across boleta/factura, and it flags meaningful price changes.

Success looks like this: the buyer enters a receipt on a phone in a couple of minutes, immediately sees which lines went up or down compared with last time, and can later look up any product's price history and best recent supplier.

## 2. Goals, non-goals, anti-goals

**Goals (Phase 1)**
- Fast receipt entry on phone and desktop, with live per-line calculations and price-change warnings.
- A correct, comparable real cost per base unit, including IVA handling for boleta vs factura.
- Price history per product, supplier comparison, flagged changes, spend over a period.
- A quick **price lookup** screen for checking "what did I pay last time, and where?" (for example, while at the market).
- One-time import of the existing spreadsheet, with manual review.
- CSV export.
- A data model ready for Phase 2 inventory without building any of it.

**Non-goals (Phase 1)**
- Stock on hand, locations, consumption, waste, counts, reorder alerts (Phase 2).
- Recipes or menu costing, OCR, electronic invoicing (DTE/SII), accounting integration.
- Multiple users, roles, permissions, approval flows.
- Receipt photo attachments (P2 in the SRS; deferred).
- Special taxes (ILA, specific taxes, IVA withholding on meat/flour).

**Anti-goals (a failure even if it "works")**
- Comparing incompatible things: different units, unknown pack sizes, or boleta vs factura without IVA adjustment. These produce false alerts.
- Silently "fixing" ambiguous imported data.
- Half-saved receipts or duplicate receipts from double-submits.
- Being heavier to use than the spreadsheet it replaces.

## 3. Decisions made with the owner

| Topic | Decision |
|---|---|
| Users | Exactly **one person** uses it. A single login; no roles, no approvals. |
| Amounts | Prices are recorded as actually paid (CLP). **Refined (my call, owner to confirm on review):** boleta lines are typed as printed (IVA included). Factura lines are typed as the **net amount printed on the factura**, and the app adds IVA to show the amount paid. This matches what each document prints. |
| Document type | Each receipt is marked **Boleta** or **Factura**. |
| IVA comparison | Prices are compared across boleta and factura by deriving real cost (rule in §5). |
| Platform | **Custom build**, not Grocy. |
| UI stack | **React + shadcn/ui**, matching the stack of the main site. |
| Hosting | On the existing **lalabranza.cl** HostGator account. |

Confirmed by inspection: lalabranza.cl runs on HostGator Chile (ns20/ns21.hostgator.cl, Apache, HTTPS with HSTS). The main site is a Next.js + Tailwind static export, so no Node runtime is used in production.

## 4. Architecture and hosting

- **Subdomain:** `compras.lalabranza.cl` (my call), separate from the public site. Add `noindex` and `robots.txt` disallow. HTTPS required.
- **Frontend:** a React + TypeScript single-page app built with **Vite** (my call; Next static export handles dynamic routes like `/productos/:id` poorly), using shadcn/ui + Tailwind, with shadcn charts (Recharts) for graphs. Built locally to static files and uploaded. Apache `.htaccess` sends unknown paths to `index.html`.
- **Backend:** a small PHP JSON API under `/api/` on the same subdomain, so it is same-origin. Use PDO prepared statements. A framework is optional (plain PHP with a tiny router, or Slim), builder's choice. Target the PHP 8.x version available on the plan, which must be checked in cPanel before starting.
- **Database:** MySQL/MariaDB from the HostGator plan (InnoDB, utf8mb4).
- **Branding (my call):** reuse the main site's palette and fonts (piedra volcánica, fibra cruda, Fraunces / Space Grotesk / Lora) and the La Labranza name and logo. Keep it a clean working tool, not a marketing page.
- **Language:** Spanish UI (my call; products, suppliers and source data are in Spanish). CLP formatting: `$1.300` style, no decimals for paid amounts. Dates shown as `dd-mm-yyyy`. Time zone America/Santiago.

## 5. Calculations (the core)

### 5.1 Units
- A product has a **base unit**: `kg`, `L`, or `unidad`.
- Allowed entry units: `kg`, `g` → kg (÷1000); `L`, `mL` → L (÷1000); `unidad` → unidad.
- A line can be entered as **quantity + unit** ("2 kg") or as **packages × net content** ("3 bolsas × 500 g" → 1.5 kg). Store both the original entry and the computed **base quantity**.
- If a mass or volume product is entered in `unidad` without net content, there is **no normalized price** for that line. It is saved but marked "no comparable" and is never used as a comparison baseline.
- `unidad` products are only comparable to the same product. Different pack variants (for example "Aceite 1 L botella" vs "Aceite bidón 5 L" when sold per unit) must be **different products**. Never auto-merge them.

### 5.2 IVA → real cost
- IVA rate is a setting, default **19%**. The rate in effect is **copied onto each receipt** when it is saved, so changing the setting later does not rewrite history.
- Each line has an "exento / sin IVA" flag, default off.
- **Boleta:** the user types the amount printed (IVA included). `real_cost = amount_paid`. The IVA is not recoverable, so it is part of the cost.
- **Factura, line not exempt:** the user types the **net** line amount. `real_cost = net`, and `amount_paid = round(net × (1 + iva_rate))`.
- **Factura, exempt line:** `real_cost = amount_paid = net`.
- The receipt footer shows the computed Neto / IVA / Total so the user can check it against the printed total. If they differ, show a non-blocking warning.
- Show both amount paid and real cost wherever prices are shown.

### 5.2b Receipt-level adjustments (my call)
- **Descuento / ajuste:** one signed CLP amount per receipt. Use it for a global discount or cash rounding, with negative meaning a discount. It is spread across the lines **in proportion to their amounts** (on the same basis as the line amounts: as printed for boleta, net for factura) before real cost is calculated. Store the share given to each line. Rounding remainders go to the largest line, so the shares add up exactly.
- **Despacho / flete:** a separate CLP amount per receipt (net on facturas, with IVA added the same way). It is **not** spread into product prices. It is included in spend totals and shown as its own category ("Despacho") on the dashboard. All comparisons, alerts, the "best supplier" view and price lookup use **real cost per base unit**.

### 5.3 Entry modes
- Default: the user enters the **line total paid** and quantity. Unit price is derived.
- Optional per line: the user enters the **unit price paid** instead. Line total = unit price × quantity. The UI clearly shows which field is the input and which is calculated.
- Validation: quantity > 0, amount > 0, product required, unit compatible with the product's base unit.

### 5.4 Comparison and alerts
- `normalized_price = real_cost / base_quantity` (CLP per kg/L/unidad).
- **Baseline:** the most recent **earlier** active purchase of the same product with a normalized price, from any supplier, ordered by purchase date and then by entry order. Voided receipts and lines with an unknown date are excluded.
- Show the CLP difference per base unit, the % change `(new − ref) / ref × 100`, and the baseline's supplier and date.
- Flag when |% change| ≥ the threshold setting (default **10%**). Show "Sin comparación" for first purchases or non-comparable lines. Never show a false alert.
- **"Recent"** (lowest recent price, best supplier, supplier comparison) means the last **90 days** by default, editable in Ajustes (my call). If there is no purchase in the window, show the latest known price labelled with its date.
- **Undated imported rows** (my call) appear in Historial and the product chart in a "sin fecha" group. They are excluded from baselines, alerts, "latest", "lowest" and "recent" figures.
- Comparisons are calculated when they are displayed, not frozen. After an edit or void, history and alerts stay consistent (SRS AT-06).
- Example check (from SRS AT-02, both boleta): 1,000 g for $1.300, then 1 kg for $1.500 → +$200/kg, +15,38%.
- Example check (IVA): factura 1 kg typed as net $1.000 (paid $1.190) → real $1.000/kg. A later boleta 1 kg for $1.100 → +10,0%, flagged.

## 6. Screens

1. **Login:** a single account.
2. **Nueva compra (receipt entry):**
   - Header: date (defaults to today), supplier (searchable, with create inline), Boleta/Factura, receipt number (optional; text, keeps leading zeros), notes.
   - Lines: product (searchable by name or alias, with create inline), quantity + unit or packages × content, amount paid (or unit price), exempt flag, notes.
   - As each line is filled in, it shows the real cost per base unit and the comparison chip (↑ / ↓ / = / sin comparación). The header shows the receipt total.
   - "Add line" is quick and keyboard-friendly. Phone layout comes first.
   - Footer fields: Descuento/ajuste, Despacho, and computed Neto/IVA/Total for facturas or Total for boletas.
   - Connectivity (my call): the app is **online-only**. An unsaved receipt is autosaved to the device (localStorage) and restored after a reload or lost signal. Full offline mode is out of scope.
   - Saving is atomic and protected against double submission (idempotency key). If the same supplier + receipt number already exists, it warns but allows saving.
   - A receipt without a number is a normal case with no warning.
3. **Consultar precio (price lookup):** search a product → latest real cost per unit, date, supplier; latest price per supplier; lowest recent price. Phone-first and fast.
4. **Panel (dashboard):** recent receipts; flagged price changes over a period; spend over a period (total paid and total real cost; by category and by supplier); a quick link to product history.
5. **Historial:** a filterable table of purchase lines (date range, product, supplier, category, receipt number, boleta/factura, change direction). Export CSV of the current filter, with original and normalized fields.
6. **Producto (detail):** aliases, category, base unit, price history chart (real cost/unit over time, coloured by supplier), latest and lowest prices, purchase count.
7. **Proveedores / Productos / Categorías:** simple CRUD, with an active/inactive toggle instead of deletion when records are in use.
8. **Recibo (detail):** view, edit or void. Edits and voids are saved to an audit history (before/after, timestamp, optional reason) that can be viewed on the receipt.
9. **Ajustes:** IVA rate, alert threshold, change password, download a database backup.
10. **Importar planilla:** see §8.

## 7. Data model (conceptual; builder finalises)

- `suppliers`: id, name, notes, active, timestamps.
- `categories`: id, name.
- `products`: id, name, category_id, base_unit (kg|L|unidad), active, notes, timestamps.
- `product_aliases`: id, product_id, alias (unique).
- `receipts`: id, supplier_id, purchase_date (DATE, nullable only for imported rows), date_unknown flag, doc_type (boleta|factura), receipt_number (VARCHAR, nullable), iva_rate (snapshot), notes, status (active|void), void_reason, idempotency_key (unique), source (manual|import), timestamps.
- `receipt_items`: id, receipt_id, line_order, product_id, entry_mode (total|unit_price), quantity, entry_unit, package_count, package_content, package_unit, base_quantity (nullable if not comparable), amount_paid (integer CLP), iva_exempt, real_cost, normalized_price (nullable), notes.
- `audit_log`: id, entity, entity_id, action, before_json, after_json, reason, created_at.
- `settings`: key/value (iva_rate, alert_threshold_pct, timezone).
- `user`: a single row with password_hash; `login_attempts` for throttling.
- **Phase 2 readiness:** `receipt_items.base_quantity` + `product_id` are enough to post stock-in movements later. Add nothing else for inventory now. No on-hand stock is ever inferred from purchases.

## 8. Spreadsheet import (one-time)

The source is "CONTROL DE PRECIOS.xlsx", sheet "Hoja 1", with about 19 rows. Columns: Fecha, Lugar / Proveedor, Pruducto, Categoria, Cantidad, Unidad, Precio unitario, Total de compra, Numero de boleta.

- Owner's file is the input; builder must obtain it (not in the project folder yet).
- My call: because the data is small, this is a **review screen for this known column layout**, not a generic column-mapping wizard. Accept CSV (export from Excel) or XLSX.
- Every row appears in an editable preview with flags: missing date, missing unit, missing total, missing receipt number, likely swapped supplier/product (e.g. "Pimenton" / "Vega central"), ambiguous price (unit vs total, e.g. qty 3 and 920), unknown product/supplier name (map it to an existing one, create it, or add it as an alias).
- The preview lets the user select several rows and set **one date** for all of them (approximate backdating).
- Spreadsheet amounts are treated as IVA-included, as paid (my call).
- Nothing is imported until each flagged row is resolved or explicitly accepted. Rows with an unknown date are imported with `date_unknown` set and excluded from comparison baselines. Boleta/factura must be chosen per row (default boleta, my call).
- Rows are grouped into receipts by supplier + receipt number + date where all are present. Otherwise each row becomes its own receipt.

## 9. Security, reliability, backups

- One account: `password_hash` (bcrypt/argon2), login throttling, secure HttpOnly SameSite=Strict session cookie, CSRF token on mutating API calls, all queries prepared, HTTPS only.
- Saving a receipt happens in a single DB transaction.
- **Backups:** a daily cPanel cron runs `mysqldump` into a directory outside `public_html`, keeping 30 days (my call), plus a manual download in Ajustes. Restore steps are written in a README and tested once before launch.
- No recurring paid licences. All dependencies have MIT-style licences.

## 10. Acceptance tests (adapted from SRS §11)

- AT-01: Save a receipt with 3 products; find it by supplier and receipt number; every line is stored once.
- AT-02: 1,000 g at $1.300 then 1 kg at $1.500 (both boleta) → +$200/kg, +15,38%.
- AT-02b: Factura 1 kg net $1.000 (paid $1.190), then boleta 1 kg $1.100 → ref $1.000/kg, +10,0%, flagged.
- AT-03: The first purchase of a new product shows no alert.
- AT-04: A bottle with unknown capacity vs a product in L → marked not comparable.
- AT-05: A negative quantity, missing supplier, or double-click save is rejected or deduplicated.
- AT-06: Editing a posted line is audited and comparisons stay consistent; a voided receipt disappears from baselines.
- AT-07: Importing the real spreadsheet flags missing dates, totals, units and swapped columns.
- AT-08: The CSV export matches the visible filter and includes normalized price, unit, doc type and real cost.

## 11. Left to the builder

- PHP structure (plain vs micro-framework), API shape, migrations approach, frontend state and data fetching, testing setup.
- Exact screen layouts within shadcn conventions; chart details.
- Deployment script (e.g. build, then upload via FTP/SSH/cPanel); the SPA rewrite rules.
- How aliases are suggested during product search.

## 12. To verify before building

- In cPanel: PHP version (needs 8.x), MySQL availability and database quota, cron availability, and whether a subdomain can be created with its own document root and SSL (AutoSSL).
- The owner holds the HostGator, cPanel and DNS access and does the verification or grants access.
- Fallbacks (my call): if no subdomain is possible, serve from `lalabranza.cl/compras/` (configure the Vite `base` and API path for it, and make sure the main site's deploy doesn't overwrite that folder). If there is no cron, run the daily backup from a secret-token URL hit by a free external scheduler, or on the first login of the day. If only PHP 7.4 is available, stop and raise it with the owner (HostGator's PHP selector normally offers 8.x).

## 13. Calls to confirm on review
1. Factura lines are typed as net, and the app adds IVA.
2. Discounts and rounding are spread proportionally across lines. Despacho is kept separate from product prices.
3. "Recent" = 90 days, editable.
4. Undated imported rows appear in history but not in price figures.
5. Online-only, with the unsaved receipt kept on the device.
6. Vite (not Next) for the app. Subdomain `compras.lalabranza.cl`. Spanish UI. Main-site branding.
7. A one-time import screen for the known spreadsheet layout (not a generic mapper).
