# 🚀 Mstore API Guide - Products & Authentication

Welcome! This guide is specifically designed for the **Pearl XP** team to integrate with the Mstore backend. It focuses on the core functionalities: **Product Management**, **Categories**, **Attributes**, and **Authentication**.

---

## 🔐 Authentication

All authenticated requests must include the `Authorization` header with a valid Bearer token.

**Base URL:** `https://mstore.primeads.ai/api`

### 1. Login
Authenticate and receive a token.
- **Endpoint:** `POST /login`
- **Body:**
  ```json
  {
    "email": "admin@example.com",
    "password": "yourpassword"
  }
  ```
- **Response:**
  ```json
  {
    "access_token": "1|abc123xyz...",
    "permissions": ["product.create", "category.index"],
    "success": true
  }
  ```

### 2. Logout
Revoke the current access token.
- **Endpoint:** `POST /logout`
- **Headers:** `Authorization: Bearer <token>`

---

## 📦 Product Management

Manage the catalog, including both simple and variable (classified) products.

### 1. List Products
- **Endpoint:** `GET /product`
- **Filters:** `category`, `min_price`, `max_price`, `status`

### 2. Create Product (Simple)
- **Endpoint:** `POST /product`
- **Body:**
  ```json
  {
    "name": "Classic T-Shirt",
    "type": "simple",
    "price": 25.00,
    "sale_price": 22.00,
    "cost": 15.50,
    "discount": 10,
    "quantity": 100,
    "sku": "TSHIRT-001",
    "categories": [1, 5],
    "status": 1,
    "product_thumbnail_id": 123
  }
  ```

### 3. Update Product
- **Endpoint:** `PUT /product/{id}`
- **Body:** (Partial updates supported, including `cost`)

### 4. Toggle Status
- **Endpoint:** `PUT /product/{id}/{status}`
  - Example: `/api/product/15/0` to deactivate product ID 15.

---

## 🛠 Supporting Data

Use these to populate dropdowns or relate to products.

### 1. Categories
- `GET /category`: List all categories.
- `POST /category`: Create a category.

### 2. Attributes
- `GET /attribute`: List attributes (e.g., Color, Size).
- `GET /attribute-value`: List values for attributes.

---

## ⚠️ Response Codes

| Code | Description |
| :--- | :--- |
| `200` | Success |
| `201` | Created successfully |
| `400` | Bad Request (Check parameters) |
| `401` | Unauthenticated (Missing or invalid token) |
| `403` | Forbidden (Insufficient permissions) |
| `422` | Validation Error (Check `errors` field in response) |

---

**Built by DMSG Team.**
For support, contact the system administrator.

---

## Pearl XP Integration

This section is all the Pearl XP team needs. Read it top to bottom once.

### The one-minute mental model

You send product updates keyed by **barcode**. The backend treats the three fields differently:

| You send | What happens | Needs admin approval? |
| :--- | :--- | :--- |
| `stock` | Written **immediately** to `products.quantity` (and `stock_status` flips to `in_stock` / `out_of_stock`). | No |
| `mrp` | Staged as `new_mrp`. Goes live (`products.price`) only after approval. | **Yes** |
| `price` | Staged as `new_price`. Goes live (`products.sale_price`) only after approval. | **Yes** |

So: stock is fire-and-forget. Prices wait in a review queue.

### 1. Authentication (master token)

Every request needs this header:

```
Authorization: Bearer <master-token>
Content-Type: application/json
```

About the token:

- It never expires (until someone resets it).
- It acts as a full admin.
- The plaintext is shown **only once** when created — it cannot be recovered later, only replaced.

**Getting / rotating the token (server admin only):**

```bash
cd /home/ubuntu/mstore
php artisan master-token:reset
# copy the printed `id|plaintext` value and share it securely
```

Resetting **revokes the previous token instantly** — update all senders at the same time.

**If you get `401 {"message": "Unauthenticated."}`**, check in this order:

1. `Authorization` header missing or not in `Bearer <token>` form (no `X-API-Key`, no query param).
2. Token is old/revoked (a reset was done after you copied it) — ask for the current one.
3. You used a normal login token (`POST /login`) older than 5 days — those expire; the master token does not.
4. A proxy stripped the header — confirm the header reaches the server.

### 2. Submit product updates

**Endpoint:** `POST /api/pearl-xp/product-updates`

**Base URL:** `https://mstore.primeads.ai/api`

**cURL example:**

```bash
curl -X POST https://mstore.primeads.ai/api/pearl-xp/product-updates \
  -H "Authorization: Bearer <master-token>" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"items": [{"barcode": "8901234567890", "mrp": 120, "price": 99, "stock": 45}]}'
```

**Body shapes (all three accepted):**

Single object:

```json
{ "barcode": "8901234567890", "mrp": 120.00, "price": 99.00, "stock": 45 }
```

Batch object (preferred for sync jobs):

```json
{
  "items": [
    { "barcode": "8901234567890", "mrp": 120.00, "price": 99.00, "stock": 45 },
    { "barcode": "8901234567891", "stock": 10 },
    { "barcode": "8901234567892", "mrp": 200.00, "price": 150.00 }
  ]
}
```

Bare array (also accepted):

```json
[{ "barcode": "8901234567890", "stock": 45 }]
```

**Common payloads and what each does:**

```json
// Stock only -> applied instantly, nothing to approve, no update_id
{ "barcode": "8901234567890", "stock": 45 }

// Price only -> staged for review, products table untouched until approved
{ "barcode": "8901234567890", "mrp": 120.00, "price": 99.00 }

// Both -> stock applied instantly AND prices staged for review
{ "barcode": "8901234567890", "mrp": 120.00, "price": 99.00, "stock": 45 }
```

**Validation rules:**

| Field | Rule |
| :--- | :--- |
| `barcode` | Required string. Must match an existing `products.barcode`. |
| `mrp` | Optional number, `>= 0`. Becomes staged `new_mrp`. |
| `price` | Optional number, `>= 0`. Becomes staged `new_price`. |
| `stock` | Optional integer, `>= 0`. Applied directly to `quantity`. |
| Overall | At least one of `mrp`, `price`, `stock` is required per item. |

### 3. Reading the response

**This endpoint always returns HTTP 200 when your token is valid — check the `success` boolean, not the status code.** (`401` means auth failed; `200` with `"success": false` means the request was received but an item failed.)

All-success batch:

```json
{
  "success": true,
  "accepted": 2,
  "failed": 0,
  "results": [
    { "barcode": "8901234567890", "success": true, "stock_applied": true, "update_id": 1, "status": "pending" },
    { "barcode": "8901234567891", "success": true, "stock_applied": true }
  ]
}
```

Partial failure (one bad barcode — the others still applied):

```json
{
  "success": false,
  "accepted": 1,
  "failed": 1,
  "results": [
    { "barcode": "8901234567890", "success": true, "stock_applied": true, "update_id": 1, "status": "pending" },
    { "barcode": "NOPE", "success": false, "error": "No product found with barcode: NOPE" }
  ]
}
```

**Response fields:**

| Field | Meaning |
| :--- | :--- |
| `success` (top) | `true` only if **every** item succeeded. |
| `accepted` / `failed` | Counts of per-item outcomes. |
| `results[].success` | Per-item outcome — act on this in batch jobs. |
| `results[].error` | Present only when that item failed. Human-readable reason. |
| `results[].stock_applied` | `true` = `products.quantity` was updated right now. |
| `results[].update_id` | Present **only** when `mrp`/`price` was staged. Quote this id to the admin team when asking about a price review. |
| `results[].status` | Always `"pending"` for newly staged prices. |

### 4. What happens after you send (end to end)

1. You `POST` items. Stock hits `products.quantity` (+ `stock_status`) in the same request.
2. `mrp`/`price` land in `pearl_xp_product_updates` as one `pending` row per item (with `new_stock = NULL` so stock is never applied twice). Nothing in `products.price` / `products.sale_price` changes yet.
3. An admin reviews (`GET /api/pearl-xp/product-updates?status=pending`, details at `GET .../{id}`) and either approves (`POST .../{id}/approve` — prices go live) or rejects (`POST .../{id}/reject` — prices discarded, stock stays as you set it).
4. The staging row is kept as an audit trail (`approved` / `rejected`).

Field mapping for reviewers: `mrp` → `products.price`, `price` → `products.sale_price`, `stock` → `products.quantity`.

### 5. Troubleshooting

| Symptom | Cause | Fix |
| :--- | :--- | :--- |
| `401 {"message":"Unauthenticated."}` | Missing/invalid/revoked token | See section 1 checklist. |
| `200` with `"error": "No product found with barcode: X"` | Barcode doesn't exist in `products` | Create the product first or fix the barcode. Matching is exact (products only, not variations). |
| `200` with `"error": "Failed to store update."` | Server-side write failed (seen once when the `pearl_xp_product_updates` table didn't exist: `1146 Table ... doesn't exist`) | Server admin: `php artisan migrate --force`, then check `storage/logs/laravel.log` (`Pearl XP update failed to store` → `reason`). Retry with a fresh request. |
| `200` with validation message (`At least one of mrp, price or stock...`, `must be...`) | Payload shape wrong | Send `{"items": [...]}` or a single object containing `barcode` plus at least one value field. |
| Stock still shows "awaiting approval" | You are looking at a row created before the direct-stock change, or the server hasn't pulled the fix | Rows created earlier legitimately still carry `new_stock`. Only **new** rows have `new_stock = NULL`. Server: confirm `grep -n "stock_applied" app/Http/Controllers/PearlXpController.php` hits, then retest fresh. |
| Prices not live after your POST | Expected — they wait for approval | Ask an admin to approve the `update_id`, or confirm via `GET /api/pearl-xp/product-updates?barcode=<code>`. |

**When asking for help, always share:** the exact JSON you sent, the full JSON response (including `update_id`s), and the barcode. Never share the master token itself — it must be rotated if exposed.

---

**Built by DMSG Team.**
For support, contact the system administrator.
