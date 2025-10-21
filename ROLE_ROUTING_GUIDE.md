# Role-Based Routing Implementation

## Masalah yang Diperbaiki
User `admin@sbc.com` dengan role `super-admin` sekarang akan diarahkan ke menu Role Management (`/admin/roles`) setelah login, bukan ke dashboard biasa.

## Perubahan yang Dilakukan

### 1. AuthController Enhancement
- **Dashboard Route Logic**: Menambahkan method `getDashboardRouteByRole()` untuk menentukan route berdasarkan role
- **Login Response**: Response login sekarang menyertakan `dashboard_route` yang menunjukkan kemana user harus diarahkan

### 2. Role-Based Middleware
- **CheckRolePermission Middleware**: Middleware baru untuk memvalidasi akses berdasarkan role
- **Protection**: Routes sekarang dilindungi dengan permission checking

### 3. API Routes Update
- **Role-Based Access Control**: 
  - `/roles` endpoints hanya bisa diakses oleh `super-admin`
  - `/users` endpoints bisa diakses oleh `admin` dan `super-admin`
- **New Endpoints**:
  - `GET /api/dashboard-route`: Mendapatkan route dashboard berdasarkan role
  - `GET /api/user-menu`: Mendapatkan menu yang bisa diakses berdasarkan role

## Role Hierarchy

### Super Admin (`super-admin`)
- **Dashboard Route**: `/admin/roles`
- **Access**: 
  - Role Management
  - User Management  
  - Dashboard
- **Permissions**: Full access to all features

### Admin (`admin`)
- **Dashboard Route**: `/admin/users`
- **Access**:
  - User Management
  - Dashboard
- **Permissions**: Can manage users but not roles

### Manager (`manager`)
- **Dashboard Route**: `/manager/dashboard`
- **Access**:
  - Dashboard only
- **Permissions**: Limited access

### Other Roles
- **Dashboard Route**: `/dashboard`
- **Access**: Basic dashboard only

## API Endpoints

### Login Response (Updated)
```json
{
  "success": true,
  "message": "Login successful",
  "access_token": "token_here",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "admin",
    "email": "admin@sbc.com",
    "roles": ["super-admin"]
  },
  "dashboard_route": "/admin/roles"
}
```

### Get Dashboard Route
```
GET /api/dashboard-route
Authorization: Bearer {token}
```

Response:
```json
{
  "success": true,
  "dashboard_route": "/admin/roles",
  "user_roles": ["super-admin"]
}
```

### Get User Menu
```
GET /api/user-menu
Authorization: Bearer {token}
```

Response:
```json
{
  "success": true,
  "menu_items": [
    {
      "name": "Role Management",
      "route": "/admin/roles",
      "icon": "shield",
      "description": "Manage user roles and permissions"
    },
    {
      "name": "User Management",
      "route": "/admin/users", 
      "icon": "users",
      "description": "Manage system users"
    }
  ],
  "user_roles": ["super-admin"]
}
```

## Frontend Integration

Untuk frontend, setelah login berhasil:

1. **Check `dashboard_route`** dari response login
2. **Redirect user** ke route yang sesuai
3. **Use `/api/user-menu`** untuk menampilkan menu yang sesuai dengan role
4. **Handle 403 errors** jika user tidak memiliki permission

## Testing

Test dengan user `admin@sbc.com`:
- Login akan mengembalikan `dashboard_route: "/admin/roles"`
- User akan diarahkan ke menu Role Management
- Menu yang tersedia: Role Management, User Management, Dashboard

## Security

- **Middleware Protection**: Semua admin routes dilindungi dengan role checking
- **Permission Validation**: Setiap request dicek permission-nya
- **Role Hierarchy**: Super admin > Admin > Manager > Others
