# PROC – Integration API (v1)

PROC (Supplier & Procurement, Member 2). Para sa CRM, INV at HR.
Sinusunod nito ang shared standard (Bahagi B). Ang mga halimbawa sa ibaba ay galing sa tunay na sagot ng PROC.

## Sa madaling salita

| Kailangan mo… | Tawagin sa PROC | Sino |
|---|---|---|
| Listahan ng suppliers | `GET /suppliers` | INV, CRM (F6) |
| Preferred supplier at presyo bawat SKU | `GET /supplier-products` | INV, CRM (F6) |
| Gumawa ng purchase request (low-stock) | `POST /purchase-requests` | INV (F4) |
| Ipasa ang reklamo sa produkto | `POST /supplier-issues` | CRM (F7) |

| Ipinapadala ng PROC… | Saan | Kailan |
|---|---|---|
| `PURCHASE_IN` | `POST {INV}/stock-movements` | Kapag na-save ang goods receipt (GRN) |
| `SUPPLIER_RETURN_OUT` | `POST {INV}/stock-movements` | Kapag na-save ang supplier return (SR) |

## Koneksyon

- **Base URL:** `PROC_API_URL`, hal. `http://<Elastic IP ng PROC>:8000/api/integration/v1`.
  Lokal: `http://localhost:8000/api/integration/v1`.
- **API key:** header na `X-API-KEY: <PROC_API_KEY>`. Ibinibigay ito sa **pribadong mensahe**. Huwag itong isulat sa code o docs.
  Mali o walang key → **HTTP 401**.
- Laging ipadala ang `Accept: application/json`, at sa POST ang `Content-Type: application/json`.
- **Petsa:** ISO 8601 na may `+08:00`. **Pera:** string na may 2 decimals (`"270.00"`). **Quantity:** integer.
- Walang ibinabalik na database ID. **Code lang** (`SUP-0001`, `PR-20261125-0003`…).

### Format ng sagot (B5)

```json
// OK
{"success": true, "message": "...", "data": ...}

// Error (401, 404, 422)
{"success": false, "message": "...", "errors": {...}}
```

### Walang doble (B5)

Bawat POST ay may `source_system` at `reference_number`. Kapag natanggap ulit ng PROC ang **parehong pares**,
ibinabalik lang nito ang dating record na may **HTTP 200** (hindi 201) at walang nagagawang bago.
Kaya ligtas na ulitin ng outbox mo ang padala.

### Listahan (GET)

| Query | Ibig sabihin |
|---|---|
| `updated_since` | ISO 8601 na petsa. Ibinabalik lang ang nagbago mula noon (`updated_at >= updated_since`). Kahit anong timezone, hal. `2026-11-23T00:00:00+08:00` o `2026-11-22T16:00:00Z`. |
| `per_page` | Default 100, hanggang 1000. |
| `page` | Default 1. Basahin hanggang `meta.last_page`. |

---

## GET /health

Walang API key.

```bash
curl http://localhost:8000/api/integration/v1/health
```
```json
{"success":true,"system":"PROC","version":"v1","time":"2026-11-25T01:00:07+08:00"}
```

---

## GET /suppliers (F6)

```bash
curl "$PROC_API_URL/suppliers?per_page=2" \
  -H "Accept: application/json" -H "X-API-KEY: $PROC_API_KEY"
```
```json
{
  "success": true,
  "message": "OK",
  "data": {
    "items": [
      {"supplier_code":"SUP-0001","name":"Golden Harvest Trading","contact_person":"Ramil Bautista",
       "email":"sales@goldenharvest.example.com","phone":"0917-000-0001","status":"Active",
       "updated_at":"2026-11-25T01:04:30+08:00"},
      {"supplier_code":"SUP-0002","name":"Pacific Canned Goods Inc.","contact_person":"Joy Navarro",
       "email":"orders@pacificcanned.example.com","phone":"0917-000-0002","status":"Active",
       "updated_at":"2026-11-25T01:04:30+08:00"}
    ],
    "meta": {"current_page":1,"last_page":3,"total":5}
  }
}
```

- `status`: `Active` o `Inactive`.
- Hindi nagbabago ang `supplier_code`. Iyon ang gamitin bilang unique sa kopya mo.

---

## GET /supplier-products (F6)

Price list ng bawat supplier, at kung sino ang **preferred supplier** ng bawat SKU.

```bash
curl "$PROC_API_URL/supplier-products?updated_since=2026-11-23T00:00:00%2B08:00" \
  -H "Accept: application/json" -H "X-API-KEY: $PROC_API_KEY"
```
```json
{
  "success": true,
  "message": "OK",
  "data": {
    "items": [
      {"supplier_code":"SUP-0001","sku":"SKU-0001","unit_cost":"270.00","lead_time_days":3,
       "is_preferred":true,"updated_at":"2026-11-25T01:04:30+08:00"}
    ],
    "meta": {"current_page":1,"last_page":1,"total":15}
  }
}
```

- Unique: (`supplier_code`, `sku`).
- **Isa lang ang `is_preferred: true` bawat SKU.** Kapag pinalitan ang preferred, parehong nagbabago ang `updated_at`
  ng luma (naging `false`) at ng bago (naging `true`), kaya makukuha mo ang dalawa gamit ang `updated_since`.
- Puwedeng may SKU na walang preferred supplier.

---

## POST /purchase-requests (F4, galing INV)

Kapag ang stock ay ≤ reorder level.

```bash
curl -X POST "$PROC_API_URL/purchase-requests" \
  -H "Accept: application/json" -H "Content-Type: application/json" -H "X-API-KEY: $PROC_API_KEY" \
  -d '{
    "source_system": "INV",
    "reference_number": "LSA-20261123-0004",
    "branch_code": "BR-001",
    "requested_by": "EMP-0007",
    "needed_by": "2026-11-30",
    "items": [{"sku": "SKU-0001", "quantity": 50, "reason": "Below reorder level"}]
  }'
```

**201** (bago):
```json
{"success":true,"message":"Purchase request created","data":{"pr_number":"PR-20261125-0003","status":"Pending"}}
```

**200** (inulit ang parehong `source_system` + `reference_number`, walang doble):
```json
{"success":true,"message":"Already received","data":{"pr_number":"PR-20261125-0003","status":"Pending"}}
```

**422** (hindi kilala ang SKU o branch, o mali ang quantity):
```json
{"success":false,"message":"Validation failed","errors":{"items.0.sku":["The selected items.0.sku is invalid."]}}
```
```json
{"success":false,"message":"Validation failed","errors":{
  "branch_code":["The selected branch code is invalid."],
  "items.0.quantity":["The items.0.quantity field must be at least 1."]}}
```

| Field | Patakaran |
|---|---|
| `source_system` | Required. `INV` (puwede rin `CRM`, `HR`, `PROC`) |
| `reference_number` | Required. Hal. ang `alert_number` (LSA-…) |
| `branch_code` | Required. Dapat nasa kopya ng branches sa PROC |
| `requested_by` | `employee_no` o `null` |
| `needed_by` | `YYYY-MM-DD` o `null` |
| `items[].sku` | Required. **Dapat nasa kopya ng products sa PROC**, kung hindi ay 422 |
| `items[].quantity` | Integer na ≥ 1 |
| `items[].reason` | Opsyonal |

> ⚠️ **Para sa INV:** Ang 422 ay hindi na inuulit ng outbox (B7). Kaya dapat **naka-sync na sa PROC ang bagong SKU**
> bago ka magpadala ng PR para dito (hal. ang SKU-0050 sa end-to-end test). Pag-usapan natin ang pagkakasunod-sunod sa Yugto 2.

Ang bagong PR ay `Pending`. Ang Manager ng PROC ang mag-a-approve, at gagawing PO.

---

## POST /supplier-issues (F7, galing CRM)

Kapag "Forward to Procurement" ang reklamo sa produkto.

```bash
curl -X POST "$PROC_API_URL/supplier-issues" \
  -H "Accept: application/json" -H "Content-Type: application/json" -H "X-API-KEY: $PROC_API_KEY" \
  -d '{
    "source_system": "CRM",
    "reference_number": "FB-20261123-0012",
    "sku": "SKU-0001",
    "supplier_code": null,
    "issue_type": "Defective",
    "description": "Broken on opening",
    "quantity_affected": 1,
    "branch_code": "BR-001",
    "reported_at": "2026-11-23T10:15:00+08:00"
  }'
```

**201** (bago) / **200** (inulit, walang doble):
```json
{"success":true,"message":"Supplier issue created","data":{"issue_number":"SI-20261125-0001","supplier_code":"SUP-0001","status":"Open"}}
```

| Field | Patakaran |
|---|---|
| `source_system`, `reference_number` | Required (hal. `CRM`, `FB-…`) |
| `sku` | Required. **Tinatanggap kahit wala pa sa kopya ng PROC**, para hindi mawala ang reklamo |
| `supplier_code` | `null` → gagamitin ng PROC ang **preferred supplier** ng SKU. Kapag wala ring preferred, `null` pa rin ang sagot pero **tanggap ang reklamo** |
| `issue_type` | `Defective`, `Expired`, `Wrong Item`, `Damaged Packaging` o `Other` (iba → 422) |
| `quantity_affected` | Integer na ≥ 1 |
| `branch_code`, `description` | Opsyonal |
| `reported_at` | ISO 8601. Kapag wala, ang oras ng pagtanggap |

---

## Ipinapadala ng PROC: POST {INV}/stock-movements (F5)

Ipinapadala ito sa pamamagitan ng **outbox**, kaya tuloy pa rin ang trabaho sa PROC kahit down ang INV.

**Pag-receive (GRN):**
```json
{"movement_type":"PURCHASE_IN","source_system":"PROC","reference_number":"GRN-20261125-0002",
 "branch_code":"BR-001","warehouse_code":"WH-001","performed_by":"EMP-0008",
 "items":[{"sku":"SKU-0001","quantity":30}],"remarks":"Receipt for PO-20261125-0001"}
```

**Supplier return (SR):**
```json
{"movement_type":"SUPPLIER_RETURN_OUT","source_system":"PROC","reference_number":"SR-20261125-0002",
 "branch_code":"BR-001","warehouse_code":"WH-001","performed_by":"EMP-0008",
 "items":[{"sku":"SKU-0001","quantity":2}],"remarks":"Return to SUP-0001: Basa ang 2 sako"}
```

- Bawat GRN ay **hiwalay na `reference_number`**, kaya ang partial na delivery ay ilang movement.
- Laging may `warehouse_code` (ang warehouse ng PO).

**Ang inaasahan ng PROC na sagot ng INV (B9):**

| Sagot ng INV | Ginagawa ng PROC |
|---|---|
| **201/200** `{"success":true,"data":{"movement_number":"SM-…","status":"posted"}}` | `sent`. Sine-save ang `movement_number` sa GRN o SR, at `synced` ang stock sync status. Ang SR ay nagiging `Returned`. |
| **422** (hal. `Insufficient stock`) | `failed` agad. Makikita ng user ang mensahe at `errors` ng INV sa detail ng GRN o SR. |
| 500, timeout, down | Uulitin pagkalipas ng 1, 2, 4, 8 minuto. Pagdating ng 5 attempts → `failed`. May **Retry** button ang Admin. |

> **Para sa INV:** Kailangang nasa `data.movement_number` ang numero, para ma-save ito ng PROC.
> Sa pag-ulit ng parehong `reference_number`, ibalik ang dati (200), ayon sa B5.

---

## Kukunin ng PROC (Yugto 2, `integration:sync-masterdata`)

| Galing | Endpoint | Para saan sa PROC |
|---|---|---|
| HR | `GET /employees`, `/branches`, `/departments` | Kopya (read-only). Ang `can_login = false` → hindi makaka-login ang user, at buburahin ang tokens niya |
| INV | `GET /products`, `/warehouses` | Kopya (read-only). Pang-validate ng SKU at default warehouse ng branch |
| INV | `GET /stock?sku=` | Impormasyon lang sa PO page (opsyonal) |

Gagamit ang PROC ng `updated_since` (ang pinakabagong `source_updated_at` na hawak nito) at babasahin ang lahat ng page.

---

## Pag-debug

- Sa PROC: **Settings → Integrations** (Admin). Nandoon ang health ng bawat system, ang outbox (pending/sent/failed + Retry), at ang huling 100 log na may request at response body.
- Bawat tawag papasok (`in`) at palabas (`out`) ay nasa table na `integration_logs`.
- Postman collection: [`docs/postman/PROC-integration.postman_collection.json`](postman/PROC-integration.postman_collection.json). Ilagay ang `api_key` sa Variables.
