# AIG Cafe API Documentation

## Table of Contents

- [Overview](#overview)
- [Authentication](#authentication)
- [Admin APIs](#admin-apis)
  - [Dashboard](#dashboard)
  - [Inventory Management](#inventory-management)
  - [Menu Management](#menu-management)
  - [Recipe Management](#recipe-management)
  - [Order Management](#order-management)
  - [Bill Management](#bill-management)
  - [Payment Management](#payment-management)
  - [User Management](#user-management)
  - [Reports & Analytics](#reports--analytics)
- [Cashier APIs](#cashier-apis)
  - [Dashboard](#dashboard-1)
  - [Order Management](#order-management-1)
  - [Bill Management](#bill-management-1)
  - [Payment Management](#payment-management-1)
  - [Cash Shift Management](#cash-shift-management)
- [Public APIs](#public-apis)
- [Data Models](#data-models)
- [Error Handling](#error-handling)
- [Rate Limiting](#rate-limiting)

---

## Overview

The AIG Cafe API provides a comprehensive RESTful interface for managing restaurant operations including inventory, menu items, orders, bills, and payments. The API follows RESTful conventions and uses JSON for data exchange.

**Base URL:** `http://your-domain.com/api`
**API Version:** v1
**Content-Type:** `application/json`

---

## Authentication

### Laravel Sanctum Authentication

All protected endpoints require authentication using Laravel Sanctum tokens.

#### Login
```http
POST /api/auth/login
Content-Type: application/json

{
    "email": "admin@aigcafe.com",
    "password": "password"
}
```

**Response:**
```json
{
    "success": true,
    "message": "Login successful",
    "token": "1|abc123...",
    "user": {
        "id": 1,
        "name": "Admin User",
        "email": "admin@aigcafe.com",
        "roles": ["General Admin"],
        "permissions": ["orders.read", "inventory.manage"]
    }
}
```

#### Using the Token
Include the token in the Authorization header for all protected requests:
```http
Authorization: Bearer 1|abc123...
```

---

## Admin APIs

### Dashboard

#### Get General Dashboard
```http
GET /api/admin/general/dashboard
Authorization: Bearer {token}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "total_orders": 1250,
        "total_revenue": 45678.90,
        "active_orders": 23,
        "low_stock_items": 5,
        "pending_payments": 8,
        "today_orders": 45,
        "today_revenue": 2345.67
    }
}
```

---

## Inventory Management

### Get Inventory Items
```http
GET /api/admin/inventory/items
Authorization: Bearer {token}
```

**Query Parameters:**
- `search` (string) - Search by name or unit
- `unit` (string) - Filter by unit (kg, pcs, ltr)
- `low_stock` (boolean) - Filter low stock items
- `category` (string) - Filter by category
- `per_page` (integer) - Items per page (default: 20)
- `page` (integer) - Page number

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "Premium Beef",
            "unit": "kg",
            "current_stock": 45.500,
            "minimum_quantity": 10.000,
            "average_purchase_price": 600.00,
            "is_active": true,
            "created_at": "2024-01-15T10:30:00.000Z",
            "updated_at": "2024-01-15T10:30:00.000Z"
        }
    ],
    "meta": {
        "current_page": 1,
        "per_page": 20,
        "total": 150,
        "last_page": 8
    }
}
```

### Create Inventory Item
```http
POST /api/admin/inventory/items
Authorization: Bearer {token}
Content-Type: application/json

{
    "name": "Fresh Vegetables",
    "unit": "kg",
    "current_stock": 50.000,
    "minimum_quantity": 5.000,
    "average_purchase_price": 25.50
}
```

**Response:**
```json
{
    "success": true,
    "message": "Inventory item created successfully",
    "data": {
        "id": 2,
        "name": "Fresh Vegetables",
        "unit": "kg",
        "current_stock": 50.000,
        "minimum_quantity": 5.000,
        "average_purchase_price": 25.50,
        "is_active": true,
        "created_at": "2024-01-15T10:35:00.000Z",
        "updated_at": "2024-01-15T10:35:00.000Z"
    }
}
```

### Update Inventory Item
```http
PUT /api/admin/inventory/items/{id}
Authorization: Bearer {token}
Content-Type: application/json

{
    "name": "Fresh Vegetables",
    "current_stock": 45.000,
    "minimum_quantity": 8.000
}
```

### Delete Inventory Item
```http
DELETE /api/admin/inventory/items/{id}
Authorization: Bearer {token}
```

### Get Low Stock Items
```http
GET /api/admin/inventory/low-stock
Authorization: Bearer {token}
```

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "Premium Beef",
            "current_stock": 8.500,
            "minimum_quantity": 10.000,
            "shortage": 1.500,
            "unit": "kg"
        }
    ]
}
```

### Batch Stock Adjustment
```http
POST /api/admin/inventory/batch-adjust
Authorization: Bearer {token}
Content-Type: application/json

{
    "adjustments": [
        {
            "inventory_item_id": 1,
            "quantity": 10.000,
            "type": "increase",
            "note": "Stock received from supplier"
        },
        {
            "inventory_item_id": 2,
            "quantity": 5.000,
            "type": "decrease",
            "note": "Stock used in kitchen"
        }
    ]
}
```

---

## Menu Management

### Get Menu Categories
```http
GET /api/admin/menu/categories
Authorization: Bearer {token}
```

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "Food",
            "is_active": true,
            "created_at": "2024-01-15T10:30:00.000Z"
        },
        {
            "id": 2,
            "name": "Drinks",
            "is_active": true,
            "created_at": "2024-01-15T10:30:00.000Z"
        }
    ]
}
```

### Create Menu Category
```http
POST /api/admin/menu/categories
Authorization: Bearer {token}
Content-Type: application/json

{
    "name": "Desserts",
    "is_active": true
}
```

### Get Menu Items
```http
GET /api/admin/menu/items
Authorization: Bearer {token}
```

**Query Parameters:**
- `category_id` (integer) - Filter by category
- `search` (string) - Search by name
- `type` (string) - Filter by type (food, drink)
- `is_available` (boolean) - Filter by availability
- `per_page` (integer) - Items per page
- `page` (integer) - Page number

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "category_id": 1,
            "name": "Classic Burger",
            "description": "Juicy beef patty with fresh lettuce",
            "type": "food",
            "price": 180.50,
            "image_path": "menu/burger.jpg",
            "is_available": true,
            "is_active": true,
            "is_featured": false,
            "menu_mode": "normal",
            "prep_minutes": 15,
            "views_count": 245,
            "created_at": "2024-01-15T10:30:00.000Z",
            "category": {
                "id": 1,
                "name": "Food"
            }
        }
    ],
    "meta": {
        "current_page": 1,
        "per_page": 20,
        "total": 50,
        "last_page": 3
    }
}
```

### Create Menu Item with Recipe
```http
POST /api/admin/menu/items/with-recipe
Authorization: Bearer {token}
Content-Type: application/json

{
    "category_id": 1,
    "name": "Deluxe Burger",
    "description": "Premium beef burger with special sauce",
    "type": "food",
    "price": 220.00,
    "prep_minutes": 20,
    "is_available": true,
    "is_active": true,
    "ingredients": [
        {
            "inventory_item_id": 1,
            "quantity": 0.200
        },
        {
            "inventory_item_id": 2,
            "quantity": 2.000
        }
    ]
}
```

### Update Menu Item
```http
PUT /api/admin/menu/items/{id}
Authorization: Bearer {token}
Content-Type: application/json

{
    "name": "Updated Burger",
    "price": 195.00,
    "is_available": false
}
```

### Toggle Menu Item Availability
```http
PATCH /api/admin/menu/items/{id}/toggle
Authorization: Bearer {token}
```

### Get Menu Item Cost Analysis
```http
GET /api/admin/menu/items/{id}/cost-analysis
Authorization: Bearer {token}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "menu_item": "Classic Burger",
        "total_cost": 85.50,
        "selling_price": 180.50,
        "cost_percentage": 47.37,
        "profit_margin": 95.00,
        "profit_percentage": 52.63,
        "ingredients": [
            {
                "name": "Premium Beef",
                "quantity": 0.200,
                "unit": "kg",
                "unit_cost": 600.00,
                "total_cost": 120.00
            }
        ]
    }
}
```

---

## Recipe Management

### Get All Recipes
```http
GET /api/admin/recipes
Authorization: Bearer {token}
```

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "menu_item_id": 1,
            "menu_item": {
                "id": 1,
                "name": "Classic Burger"
            },
            "items": [
                {
                    "id": 1,
                    "inventory_item_id": 1,
                    "inventory_item": {
                        "id": 1,
                        "name": "Premium Beef",
                        "unit": "kg"
                    },
                    "quantity": 0.200
                }
            ]
        }
    ]
}
```

### Get Recipe by Menu Item
```http
GET /api/admin/menu/items/{menuItemId}/recipe
Authorization: Bearer {token}
```

### Create Recipe
```http
POST /api/admin/recipes
Authorization: Bearer {token}
Content-Type: application/json

{
    "menu_item_id": 1,
    "note": "Standard burger recipe",
    "ingredients": [
        {
            "inventory_item_id": 1,
            "quantity": 0.200
        },
        {
            "inventory_item_id": 2,
            "quantity": 2.000
        }
    ]
}
```

### Update Recipe
```http
PUT /api/admin/recipes/{id}
Authorization: Bearer {token}
Content-Type: application/json

{
    "note": "Updated recipe",
    "ingredients": [
        {
            "inventory_item_id": 1,
            "quantity": 0.250
        }
    ]
}
```

---

## Order Management

### Get Orders
```http
GET /api/admin/orders
Authorization: Bearer {token}
```

**Query Parameters:**
- `status` (string) - Filter by status (pending, confirmed, preparing, ready, served, cancelled)
- `table_id` (integer) - Filter by table
- `date` (date) - Filter by date (YYYY-MM-DD)
- `search` (string) - Search by order number or customer name
- `per_page` (integer) - Orders per page
- `page` (integer) - Page number

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "order_number": "ORD-20240115-001",
            "table_id": 1,
            "table": {
                "id": 1,
                "table_number": "T1",
                "capacity": 4
            },
            "customer_name": "John Doe",
            "customer_phone": "+1234567890",
            "order_type": "dine_in",
            "status": "confirmed",
            "subtotal": 360.50,
            "tax": 36.05,
            "service_charge": 18.02,
            "total": 414.57,
            "notes": "Extra ketchup",
            "created_at": "2024-01-15T12:30:00.000Z",
            "items": [
                {
                    "id": 1,
                    "menu_item_id": 1,
                    "quantity": 2,
                    "unit_price": 180.50,
                    "line_total": 361.00,
                    "menu_item": {
                        "name": "Classic Burger",
                        "price": 180.50
                    }
                }
            ]
        }
    ],
    "meta": {
        "current_page": 1,
        "per_page": 20,
        "total": 100,
        "last_page": 5
    }
}
```

### Create Order
```http
POST /api/admin/orders
Authorization: Bearer {token}
Content-Type: application/json

{
    "table_id": 1,
    "customer_name": "Jane Smith",
    "customer_phone": "+1234567890",
    "order_type": "dine_in",
    "notes": "Allergic to nuts",
    "items": [
        {
            "menu_item_id": 1,
            "quantity": 2,
            "notes": "No onions"
        }
    ]
}
```

### Update Order
```http
PUT /api/admin/orders/{id}
Authorization: Bearer {token}
Content-Type: application/json

{
    "status": "confirmed",
    "notes": "Updated order notes"
}
```

### Process Order (Inventory Deduction)
```http
POST /api/admin/orders/{id}/process
Authorization: Bearer {token}
```

### Cancel Order
```http
POST /api/admin/orders/{id}/cancel
Authorization: Bearer {token}
Content-Type: application/json

{
    "reason": "Customer request"
}
```

---

## Bill Management

### Get Bills
```http
GET /api/admin/bills
Authorization: Bearer {token}
```

**Query Parameters:**
- `status` (string) - Filter by status (draft, issued, paid, void)
- `order_id` (integer) - Filter by order
- `search` (string) - Search by bill number or customer
- `per_page` (integer) - Bills per page
- `page` (integer) - Page number

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "bill_number": "BILL-20240115-001",
            "order_id": 1,
            "order": {
                "order_number": "ORD-20240115-001"
            },
            "status": "issued",
            "subtotal": 360.50,
            "tax": 36.05,
            "service_charge": 18.02,
            "total": 414.57,
            "issued_at": "2024-01-15T13:00:00.000Z",
            "payments": [
                {
                    "id": 1,
                    "amount": 414.57,
                    "method": "cash",
                    "received_by": "Cashier",
                    "received_at": "2024-01-15T13:05:00.000Z"
                }
            ]
        }
    ]
}
```

### Issue Bill
```http
POST /api/admin/bills/{orderId}/issue
Authorization: Bearer {token}
```

### Void Bill
```http
POST /api/admin/bills/{id}/void
Authorization: Bearer {token}
Content-Type: application/json

{
    "reason": "Customer complaint"
}
```

---

## Payment Management

### Get Payments
```http
GET /api/admin/payments
Authorization: Bearer {token}
```

**Query Parameters:**
- `bill_id` (integer) - Filter by bill
- `method` (string) - Filter by payment method
- `status` (string) - Filter by status
- `date_from` (date) - Filter by date range start
- `date_to` (date) - Filter by date range end

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "bill_id": 1,
            "amount": 414.57,
            "method": "cash",
            "status": "completed",
            "received_by": {
                "id": 2,
                "name": "Cashier User"
            },
            "received_at": "2024-01-15T13:05:00.000Z",
            "bill": {
                "bill_number": "BILL-20240115-001"
            }
        }
    ]
}
```

### Process Payment
```http
POST /api/admin/bills/{billId}/payments
Authorization: Bearer {token}
Content-Type: application/json

{
    "amount": 414.57,
    "method": "cash",
    "reference": "Cash payment"
}
```

### Approve Payment
```http
POST /api/admin/payments/{id}/approve
Authorization: Bearer {token}
```

### Refund Payment
```http
POST /api/admin/payments/{id}/refund
Authorization: Bearer {token}
Content-Type: application/json

{
    "amount": 100.00,
    "reason": "Customer dissatisfied"
}
```

---

## User Management

### Get Users
```http
GET /api/admin/users
Authorization: Bearer {token}
```

**Query Parameters:**
- `role` (string) - Filter by role
- `is_active` (boolean) - Filter by active status
- `search` (string) - Search by name or email

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "Admin User",
            "email": "admin@aigcafe.com",
            "phone": "+1234567890",
            "is_active": true,
            "roles": ["General Admin"],
            "created_at": "2024-01-15T10:30:00.000Z"
        }
    ]
}
```

### Create User
```http
POST /api/admin/users
Authorization: Bearer {token}
Content-Type: application/json

{
    "name": "New User",
    "email": "user@example.com",
    "phone": "+1234567890",
    "password": "password123",
    "roles": ["Cashier"]
}
```

### Update User
```http
PUT /api/admin/users/{id}
Authorization: Bearer {token}
Content-Type: application/json

{
    "name": "Updated User",
    "is_active": false
}
```

### Assign Role
```http
POST /api/admin/users/{id}/roles
Authorization: Bearer {token}
Content-Type: application/json

{
    "roles": ["Manager"]
}
```

---

## Reports & Analytics

### Get Sales Analytics
```http
GET /api/admin/reports/sales-analytics
Authorization: Bearer {token}
```

**Query Parameters:**
- `date_from` (date) - Start date
- `date_to` (date) - End date
- `group_by` (string) - Group by (day, week, month)

**Response:**
```json
{
    "success": true,
    "data": {
        "total_revenue": 15000.00,
        "total_orders": 250,
        "average_order_value": 60.00,
        "top_selling_items": [
            {
                "name": "Classic Burger",
                "quantity_sold": 150,
                "revenue": 27075.00
            }
        ],
        "revenue_by_period": [
            {
                "period": "2024-01-15",
                "revenue": 1500.00,
                "orders": 25
            }
        ]
    }
}
```

### Get Item Popularity Report
```http
GET /api/admin/reports/item-popularity
Authorization: Bearer {token}
```

### Get Payment Method Summary
```http
GET /api/admin/reports/payment-method-summary
Authorization: Bearer {token}
```

---

## Cashier APIs

### Dashboard

#### Get Cashier Dashboard
```http
GET /api/cashier/dashboard
Authorization: Bearer {token}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "active_orders": 5,
        "pending_bills": 3,
        "today_sales": 2345.67,
        "available_tables": 8,
        "low_stock_alerts": 2
    }
}
```

---

## Order Management (Cashier)

### Get Menu for POS
```http
GET /api/cashier/orders/menu
Authorization: Bearer {token}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "categories": [
            {
                "id": 1,
                "name": "Food",
                "items": [
                    {
                        "id": 1,
                        "name": "Classic Burger",
                        "price": 180.50,
                        "is_available": true,
                        "prep_minutes": 15
                    }
                ]
            }
        ]
    }
}
```

### Get Available Tables
```http
GET /api/cashier/orders/tables
Authorization: Bearer {token}
```

### Create Order (POS)
```http
POST /api/cashier/orders
Authorization: Bearer {token}
Content-Type: application/json

{
    "table_id": 1,
    "customer_name": "Walk-in Customer",
    "items": [
        {
            "menu_item_id": 1,
            "quantity": 2,
            "notes": "Extra ketchup"
        }
    ]
}
```

### Confirm Order
```http
POST /api/cashier/orders/{id}/confirm
Authorization: Bearer {token}
```

---

## Bill Management (Cashier)

### Get Bills
```http
GET /api/cashier/bills
Authorization: Bearer {token}
```

### Issue Bill
```http
POST /api/cashier/bills/{orderId}/issue
Authorization: Bearer {token}
```

### Void Bill
```http
POST /api/cashier/bills/{id}/void
Authorization: Bearer {token}
Content-Type: application/json

{
    "reason": "Customer request"
}
```

---

## Payment Management (Cashier)

### Submit Payment
```http
POST /api/cashier/bills/{billId}/payments
Authorization: Bearer {token}
Content-Type: application/json

{
    "amount": 414.57,
    "method": "cash",
    "reference": "Cash payment"
}
```

---

## Public APIs

### Get Public Menu
```http
GET /api/public/menu
```

**Query Parameters:**
- `search` (string) - Search by name
- `category_id` (integer) - Filter by category
- `type` (string) - Filter by type (food, drink)
- `menu_mode` (string) - Filter by mode (normal, spatial)

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "Classic Burger",
            "description": "Juicy beef patty",
            "price": 180.50,
            "image_url": "http://your-domain.com/storage/menu/burger.jpg",
            "is_available": true,
            "category": {
                "id": 1,
                "name": "Food"
            }
        }
    ]
}
```

### Get Menu Categories
```http
GET /api/public/menu/categories
```

### Get Tables
```http
GET /api/public/tables
```

---

## Data Models

### InventoryItem
```json
{
    "id": 1,
    "name": "string",
    "unit": "string", // kg, pcs, ltr
    "current_stock": "decimal:12,3",
    "minimum_quantity": "decimal:12,3",
    "average_purchase_price": "decimal:12,3",
    "is_active": "boolean",
    "created_at": "datetime",
    "updated_at": "datetime"
}
```

### MenuItem
```json
{
    "id": 1,
    "category_id": 1,
    "name": "string",
    "description": "string",
    "type": "food|drink",
    "price": "decimal:8,2",
    "image_path": "string",
    "is_available": "boolean",
    "is_active": "boolean",
    "is_featured": "boolean",
    "menu_mode": "normal|spatial",
    "prep_minutes": "integer",
    "views_count": "integer",
    "created_at": "datetime",
    "updated_at": "datetime"
}
```

### Order
```json
{
    "id": 1,
    "order_number": "string",
    "table_id": "integer",
    "customer_name": "string",
    "customer_phone": "string",
    "order_type": "dine_in|takeaway|delivery",
    "status": "pending|confirmed|preparing|ready|served|cancelled",
    "subtotal": "decimal:8,2",
    "tax": "decimal:8,2",
    "service_charge": "decimal:8,2",
    "total": "decimal:8,2",
    "notes": "string",
    "created_at": "datetime",
    "updated_at": "datetime"
}
```

### Bill
```json
{
    "id": 1,
    "bill_number": "string",
    "order_id": "integer",
    "status": "draft|issued|paid|void",
    "subtotal": "decimal:8,2",
    "tax": "decimal:8,2",
    "service_charge": "decimal:8,2",
    "total": "decimal:8,2",
    "issued_at": "datetime",
    "created_at": "datetime",
    "updated_at": "datetime"
}
```

### Payment
```json
{
    "id": 1,
    "bill_id": "integer",
    "amount": "decimal:8,2",
    "method": "cash|card|mobile|online",
    "status": "pending|completed|failed|refunded",
    "reference": "string",
    "received_by": "integer",
    "received_at": "datetime",
    "created_at": "datetime",
    "updated_at": "datetime"
}
```

---

## Error Handling

### Standard Error Response Format
```json
{
    "success": false,
    "message": "Error description",
    "errors": {
        "field_name": ["Error message"]
    }
}
```

### Common HTTP Status Codes
- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `422` - Validation Error
- `429` - Too Many Requests
- `500` - Internal Server Error

### Validation Errors
```json
{
    "success": false,
    "message": "Validation failed",
    "errors": {
        "name": ["The name field is required."],
        "email": ["The email must be a valid email address."]
    }
}
```

---

## Rate Limiting

API endpoints are rate-limited to prevent abuse:

- **Public endpoints:** 60 requests per minute
- **Authenticated endpoints:** 300 requests per minute
- **Admin endpoints:** 600 requests per minute

Rate limit headers are included in responses:
```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 59
X-RateLimit-Reset: 1642699200
```

---

## API Testing

### Example cURL Commands

#### Authentication
```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@aigcafe.com","password":"password"}'
```

#### Get Inventory Items
```bash
curl -X GET http://localhost:8000/api/admin/inventory/items \
  -H "Authorization: Bearer YOUR_TOKEN"
```

#### Create Order
```bash
curl -X POST http://localhost:8000/api/cashier/orders \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"table_id":1,"items":[{"menu_item_id":1,"quantity":2}]}'
```

---

## WebSocket Events

### Real-time Updates

The API supports WebSocket connections for real-time updates:

#### Order Status Updates
```javascript
// Connect to WebSocket
const ws = new WebSocket('ws://localhost:8000/ws/orders');

// Listen for order updates
ws.onmessage = function(event) {
    const data = JSON.parse(event.data);
    console.log('Order update:', data);
};
```

#### Stock Alerts
```javascript
const ws = new WebSocket('ws://localhost:8000/ws/inventory');
```

---

## SDK Examples

### JavaScript/TypeScript

```typescript
class AIGCafeAPI {
    private baseURL: string;
    private token: string;

    constructor(baseURL: string, token: string) {
        this.baseURL = baseURL;
        this.token = token;
    }

    async getInventoryItems(): Promise<any> {
        const response = await fetch(`${this.baseURL}/api/admin/inventory/items`, {
            headers: {
                'Authorization': `Bearer ${this.token}`,
                'Content-Type': 'application/json'
            }
        });
        return response.json();
    }

    async createOrder(orderData: any): Promise<any> {
        const response = await fetch(`${this.baseURL}/api/cashier/orders`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${this.token}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(orderData)
        });
        return response.json();
    }
}

// Usage
const api = new AIGCafeAPI('http://localhost:8000', 'your-token');
const items = await api.getInventoryItems();
```

### Python

```python
import requests

class AIGCafeAPI:
    def __init__(self, base_url: str, token: str):
        self.base_url = base_url
        self.token = token
        self.headers = {
            'Authorization': f'Bearer {token}',
            'Content-Type': 'application/json'
        }

    def get_inventory_items(self):
        response = requests.get(
            f'{self.base_url}/api/admin/inventory/items',
            headers=self.headers
        )
        return response.json()

    def create_order(self, order_data):
        response = requests.post(
            f'{self.base_url}/api/cashier/orders',
            headers=self.headers,
            json=order_data
        )
        return response.json()

# Usage
api = AIGCafeAPI('http://localhost:8000', 'your-token')
items = api.get_inventory_items()
```

---

## Changelog

### v1.0.0 (2024-01-15)
- Initial API release
- Core functionality for inventory, menu, orders, bills, and payments
- Authentication with Laravel Sanctum
- Comprehensive admin and cashier endpoints
- Public API for customer access

---

## Support

For API support and documentation updates, please contact the development team or create an issue in the project repository.
