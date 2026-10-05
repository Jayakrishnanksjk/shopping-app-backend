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

### Master Token Authentication

Pearl XP uses a **master token** to authenticate. This token:
- Never expires (until manually reset)
- Authenticates as a full admin user
- Works across all API endpoints

**How to generate/reset the token:**

Run this command on the server terminal:

```bash
php artisan master-token:reset
```

This will:
1. Revoke any previous master token
2. Print a new plaintext token to the terminal (shown only once)
3. Copy and share it with the Pearl XP team

**Usage:**

Include the token in every request:

```
Authorization: Bearer 4|YK1uY2J3MU4ch5AmFgGPk5eF9Qc96pCAlbtNg535153ca7ec
```

---

### Submit Product Updates

Update product MRP, price, and stock by matching barcode.

**Endpoint:** `POST /api/pearl-xp/product-updates`

**Headers:**
- `Authorization: Bearer <master-token>`
- `Content-Type: application/json`

**Body (single item):**
```json
{
  "barcode": "8901234567890",
  "mrp": 120.00,
  "price": 99.00,
  "stock": 45
}
```

**Body (batch):**
```json
{
  "items": [
    { "barcode": "8901234567890", "mrp": 120.00, "price": 99.00, "stock": 45 },
    { "barcode": "8901234567891", "mrp": 200.00, "price": 150.00 }
  ]
}
```

**Validation rules:**
- `barcode` — required, must match an existing product
- At least one of `mrp`, `price`, or `stock` must be provided
- All values must be numeric and >= 0

**Response:**
```json
{
  "success": true,
  "accepted": 2,
  "failed": 0,
  "results": [
    { "barcode": "8901234567890", "success": true, "update_id": 1, "status": "pending" },
    { "barcode": "8901234567891", "success": true, "update_id": 2, "status": "pending" }
  ]
}
```

- `success` at the top level = all items succeeded
- Each item returns its own `success` flag and `error` message if failed
- HTTP 200 is returned either way — check the `success` boolean

**What happens:**
1. Updates are stored in a staging table with status `pending`
2. An admin must approve them before they apply to the real products table
3. The staging row is kept as an audit trail

---

### Error Handling

- If a barcode doesn't match any product, that item gets `success: false` with an error message
- All errors are logged server-side with the full payload and reason
- The API always returns HTTP 200 — check the `success` field in the response

---

**Built by DMSG Team.**
For support, contact the system administrator.
