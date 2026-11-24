# BAHAGI A – ANG PROC SYSTEM (Supplier & Procurement, Member 2)

## A1. Ang sitwasyon

- Ikaw ang may-ari ng **suppliers** (SUP-0001) at ng lahat ng procurement records: purchase requests (PR-), purchase orders (PO-), goods receipts (GRN-), supplier returns (SR-) at supplier issues (SI-).
- Hindi pa alam kung gaano na kalayo ang system. Aalamin muna ito ni Claude sa unang sagot (framework, database, Docker).
- Target na stack: Laravel 12 API + React (Vite) frontend + MySQL, naka-Docker. Gamitin ang Docker files sa `CRM-docker-template.zip` galing kay Ralph.
- Mga module:
  - Suppliers
  - Supplier products (price list at preferred supplier ng bawat SKU)
  - Purchase requests
  - Purchase orders
  - Goods receipts (pag-receive)
  - Supplier returns
  - Supplier issues (mga reklamo galing CRM)
  - Integrations page
- Mga role: **Admin**, **Manager** (nag-a-approve), at **Purchasing Officer**.
- Mga kopya (read-only, hindi mo ito ine-edit):
  - Galing HR: employees, branches, departments.
  - Galing INV: products at warehouses.

## A2. Mga patakaran sa kaligtasan

- Gumamit ng Git mula ngayon. Mag-commit pagkatapos ng bawat hakbang na gumagana. Huwag i-commit ang `.env`.
- Ang ID ng database ay hindi kailanman ipinapasa sa ibang system. Code lang (B4).
- Ang mga kopya (products, warehouses, employees, branches, departments) ay hindi puwedeng i-edit sa PROC. Ang MasterDataSeeder at ang `integration:sync-masterdata` lang ang nagpapalit nito.
- **Kapag LIVE na sa AWS:**
  - Lahat ng database change ay bagong migration at nullable (o may default).
  - Huwag patakbuhin ang buong `db:seed`. Ang `php artisan db:seed --class=MasterDataSeeder` lang (upsert).
  - Mag-backup muna gamit ang `mysqldump` bago mag-migrate.

## A3. Mga table

Lahat ng `*_number` at `*_code` ay unique. Ang mga numero ay `PREFIX-YYYYMMDD-0001`, at nagsisimula ulit sa 0001 bawat araw. Gumawa ng numero sa loob ng database transaction para walang doble.

**Sariling data (ikaw ang may-ari):**

| Table | Mga column |
|---|---|
| suppliers | supplier_code, name, contact_person, email, phone, status (Active/Inactive), timestamps |
| supplier_products | supplier_code, sku, unit_cost, lead_time_days, is_preferred. Unique: (supplier_code, sku). **Isa lang** ang preferred bawat sku |
| purchase_requests | pr_number, source_system (INV o PROC), reference_number, branch_code, requested_by nullable (employee_no), needed_by, status (Pending / Approved / Rejected / Converted), approved_by nullable, approved_at nullable, remarks. Unique: (source_system, reference_number) |
| purchase_request_items | purchase_request_id, sku, quantity, reason |
| purchase_orders | po_number, pr_number nullable, supplier_code, branch_code, warehouse_code (saan ide-deliver), status (Draft / Approved / Sent / Partially Received / Received / Cancelled), ordered_by, ordered_at |
| purchase_order_items | purchase_order_id, sku, quantity, unit_cost, received_quantity (default 0) |
| goods_receipts | grn_number, po_number, warehouse_code, branch_code, received_by (employee_no), received_at, stock_sync_status (not_sent / pending / synced / failed, default not_sent), movement_number nullable |
| goods_receipt_items | goods_receipt_id, sku, quantity |
| supplier_returns | return_number, supplier_code, grn_number nullable, warehouse_code, branch_code, reason, returned_by, status, stock_sync_status, movement_number nullable |
| supplier_return_items | supplier_return_id, sku, quantity |
| supplier_issues | issue_number, source_system, reference_number, sku, supplier_code nullable, issue_type, description, quantity_affected, branch_code, reported_at, status (Open / In Review / Resolved / Closed), resolution nullable. Unique: (source_system, reference_number) |

**Mga kopya:**

| Table | Mga column |
|---|---|
| employees | employee_no, first_name, last_name, email, branch_code, department_code, position, employment_status, can_login, source_updated_at |
| branches | branch_code, name, city, status, source_updated_at |
| departments | department_code, name, status, source_updated_at |
| products | sku, name, category, unit, price, cost, reorder_level, reorder_quantity, status, source_updated_at |
| warehouses | warehouse_code, name, branch_code, is_default, status, source_updated_at |

**Sa users at integration:**
- Sa `users`, idagdag ang `employee_no` (unique, nullable), `employment_status` (nullable) at `can_login` (boolean, default true).
- Gumawa rin ng `integration_logs` at `integration_outbox` (B7).

**Sample data (MasterDataSeeder):**
- Gamitin ang mga CSV na ito mula sa Bahagi C: `suppliers.csv`, `supplier_products.csv`, `branches.csv`, `departments.csv`, `employees.csv`, `products.csv` at `warehouses.csv`.
- Gumawa ng user account para sa EMP-0001 (Admin), EMP-0002 (Manager) at EMP-0008 (Purchasing Officer), na may default na password para sa local.

## A4. Mga feature

**Purchase requests**
- Listahan ng PR na may filter na status, at nakikita kung galing INV (low-stock) o ginawa sa PROC.
- Puwedeng gumawa ng PR nang manual. Sa ganoon, ang `source_system` ay `PROC` at ang `reference_number` ay ang sariling `pr_number`.
- Ang Manager ang nag-a-approve o nagre-reject.

**PR → PO**
- Mula sa approved na PR, gumawa ng PO. Ang default na supplier ay ang preferred supplier ng SKU (`supplier_products.is_preferred`), at ang default na `unit_cost` ay galing sa `supplier_products`.
- Kapag iba-iba ang supplier ng mga SKU, hatiin sa ilang PO.
- Ang default na `warehouse_code` ay ang default warehouse ng `branch_code`.
- Ang PR ay magiging `Converted`.

**F5 – Pag-receive → INV**
- Kapag na-save ang GRN, gawin ang mga ito sa **parehong database transaction**:
  - Dagdagan ang `received_quantity` ng PO items.
  - Gawing `Partially Received` o `Received` ang PO.
  - Tawagin ang `Outbox::push('INV','POST','/stock-movements', ...)`:
    ```json
    {"movement_type":"PURCHASE_IN","source_system":"PROC","reference_number":"GRN-20261123-0001",
     "branch_code":"BR-001","warehouse_code":"WH-001","performed_by":"EMP-0008",
     "items":[{"sku":"SKU-0050","quantity":20}],"remarks":"Receipt for PO-20261123-0001"}
    ```
  - Gawing `pending` ang `stock_sync_status`.
- Kapag 2xx ang sagot ng INV, gawin itong `synced` at i-save ang `movement_number` na sinagot ng INV. Kapag failed ang outbox, gawin itong `failed`.
- Bawat GRN ay hiwalay na `reference_number`, kaya puwede ang partial na pag-receive.

**F5 – Supplier return → INV**
- Pareho ang proseso, pero `SUPPLIER_RETURN_OUT` ang movement type, at ang `reference_number` ay ang `return_number`.
- Kapag 422 ang sagot ng INV (kulang ang stock), `failed` ang status at ipakita ang error sa user.

**F7 – Supplier issues (galing CRM)**
- Listahan na may filter na status at supplier.
- Puwedeng palitan ang status at isulat ang resolution.
- Makikita rin ang galing sa CRM: ang `reference_number` (FB-...) at ang `branch_code`.

**F6 – Suppliers at supplier products**
- Normal na listahan at edit.
- Kapag pinalitan ang preferred supplier ng isang SKU, siguraduhing isa lang ang `is_preferred = true` para sa SKU na iyon.

**Pag-login at HR status (F1)**
- Sa login, tanggihan ang user kung `can_login = false`.
- Kapag na-sync ang employees at naging false ang `can_login`, burahin ang mga Sanctum token ng user na iyon.
- Huwag gumawa ng bagong user nang awtomatiko. Ang Admin ang gagawa ng account, at pipiliin niya ang `employee_no` mula sa kopya ng employees.

**Integrations page (Settings, Admin lang)**
- Health ng bawat system (green/red).
- Outbox na may filter na status at "Retry" button.
- Ang huling 100 na integration logs.

**Pag-save ng sagot ng ibang system**
- Sa `ProcessOutbox`, kapag 2xx ang sagot, tawagin ang isang maliit na handler na nag-u-update ng sariling record gamit ang `reference_number` (hal. ang `goods_receipts.movement_number`).

**Tests (PHPUnit / Pest)**
- API key (401) at `/health`.
- `POST /purchase-requests`:
  - gumagawa ng PR;
  - 200 at walang doble kapag inulit;
  - 422 kapag walang ganoong sku.
- `POST /supplier-issues`: hinahanap ang preferred supplier kapag null ang `supplier_code`.
- Ang GRN ay naglalagay ng `PURCHASE_IN` sa outbox, at ang return ay `SUPPLIER_RETURN_OUT`.
- `ProcessOutbox` gamit ang `Http::fake()`:
  - 2xx → sent;
  - 500 → retry na may 1/2/4/8 minuto;
  - 422 → failed agad.
- `GET /suppliers`: format ng listahan at `updated_since`.

**docs/INTEGRATION.md**: listahan ng endpoints mo, may sample na request at response.

## A5. Mga endpoint na ibibigay ng PROC (/api/integration/v1)

| Method & path | Laman |
|---|---|
| GET /health | `{"success":true,"system":"PROC","version":"v1","time":"..."}` (walang key) |
| GET /suppliers | Listahan (B5 format, may `updated_since` at `per_page`). Fields sa B9 |
| GET /supplier-products | Listahan. Fields sa B9: supplier_code, sku, unit_cost, lead_time_days, is_preferred (boolean), updated_at |
| POST /purchase-requests | Galing INV. Body at sagot sa B9. **201** kapag bago, **200** kapag inulit (parehong source_system + reference_number). 422 kapag hindi kilala ang branch_code o sku, o kapag ang quantity ay hindi integer na ≥ 1. Puwedeng `null` ang `requested_by` |
| POST /supplier-issues | Galing CRM. Body at sagot sa B9. `issue_type` ay isa lang sa: Defective, Expired, Wrong Item, Damaged Packaging, Other (iba → 422). Kapag null ang `supplier_code`, gamitin ang preferred supplier ng SKU. Kapag wala ring preferred, tanggapin pa rin na null ang `supplier_code` para hindi mawala ang reklamo. Status ay `Open`. 201 / 200 kapag inulit |

## A6. Mga kopya na kukunin ng PROC (Yugto 2, gamit ang integration:sync-masterdata)

- **Galing HR:**
  - `GET /employees`, `GET /branches` at `GET /departments`, i-upsert gamit ang code.
  - I-update ang `users` na may parehong `employee_no` (status, can_login). Kapag naging false, burahin ang tokens.
- **Galing INV:** `GET /products` at `GET /warehouses`, i-upsert gamit ang sku at warehouse_code.
- Gamitin ang `updated_since` = ang pinakabagong `source_updated_at` na hawak mo na. Basahin ang lahat ng page (`meta.last_page`).
- Puwede ring ipakita ang INV stock (`GET /stock?sku=`) sa PO page bilang impormasyon lang.

## A7. Pagkakasunod-sunod ng gagawin (isa-isa)

1. Kunin kay Ralph ang `CRM-docker-template.zip`. Kung hindi Laravel ang system mo, ilipat muna ang logic at database design sa Laravel 12.
2. Siguraduhing naka-Git ang project. Ilagay ang Docker files at patakbuhin sa local (`docker compose up -d --build`). Dapat 4 na service ang `Up`.
3. Idagdag ang mga variable sa B6 sa `.env` at `.env.example` (`SYSTEM_CODE=PROC`), at gawin ang `config/integration.php`.
4. Migrations ng `integration_logs` at `integration_outbox`.
5. Middleware, response helper at ang `/health` route. I-test gamit ang curl.
6. Migrations ng sariling tables at mga kopya (A3), tapos ang MasterDataSeeder at mga CSV.
7. Mga screen: suppliers, supplier products, PR, PO, GRN, supplier returns, supplier issues.
8. Endpoints: `/suppliers`, `/supplier-products`, `/purchase-requests`, `/supplier-issues`. I-test gamit ang Postman na may `X-API-KEY`, kasama ang pag-ulit ng parehong POST (dapat 200 at walang doble).
9. IntegrationClient, Outbox, ProcessOutbox at ang scheduler service.
10. F5: GRN → `PURCHASE_IN` at return → `SUPPLIER_RETURN_OUT` papunta sa outbox. I-check na `pending` ang laman ng outbox kapag `INTEGRATION_ENABLED=false`.
11. Login check gamit ang `can_login`.
12. Integrations page sa Settings.
13. Tests at `docs/INTEGRATION.md`.
14. Deploy sa AWS EC2 na may Elastic IP:
    - Ilagay ang `PUBLIC_HOST=<Elastic IP>` sa `~/app/.env` bago mag-build.
    - Buksan ang port 5173 at 8000 sa security group.
    - Sa unang deploy, patakbuhin ang `php artisan db:seed --class=MasterDataSeeder`.
    - Kung maliit ang server (t2/t3.micro), maglagay muna ng 2 GB swap.
    - Ibigay sa grupo ang `PROC_API_URL`. Ang key ay ibigay sa pribadong mensahe.

## A8. Sa end-to-end test (B10), ito ang bahagi mo

- **Hakbang 4:** may lalabas na PR galing INV para sa SKU-0050.
- **Hakbang 5:** i-approve → gumawa ng PO → i-receive ang 20 → dapat maging 29 ang stock sa INV.
- **Hakbang 6:** may lalabas na Supplier Issue galing CRM.
