# Password Policy - Fleksibel Tanpa Batasan 6 Karakter

## Masalah yang Diperbaiki
Password validation sekarang lebih fleksibel dan tidak memaksa minimal 6 karakter. User bisa menggunakan password pendek seperti "admin" atau password lainnya sesuai kebutuhan.

## Konfigurasi Password Policy

### File: `config/password_policy.php`
```php
return [
    'min_length' => env('PASSWORD_MIN_LENGTH', 3), // Minimum 3 karakter (bisa disesuaikan)
    'max_length' => env('PASSWORD_MAX_LENGTH', 255), // Maximum 255 karakter
    'require_uppercase' => env('PASSWORD_REQUIRE_UPPERCASE', false), // Tidak wajib huruf besar
    'require_lowercase' => env('PASSWORD_REQUIRE_LOWERCASE', false), // Tidak wajib huruf kecil
    'require_numbers' => env('PASSWORD_REQUIRE_NUMBERS', false), // Tidak wajib angka
    'require_symbols' => env('PASSWORD_REQUIRE_SYMBOLS', false), // Tidak wajib simbol
    'allow_common_passwords' => env('PASSWORD_ALLOW_COMMON', true), // Boleh password umum seperti "admin"
];
```

## Environment Variables (.env)

Anda bisa menyesuaikan password policy melalui file `.env`:

```env
# Password Policy Configuration
PASSWORD_MIN_LENGTH=3
PASSWORD_MAX_LENGTH=255
PASSWORD_REQUIRE_UPPERCASE=false
PASSWORD_REQUIRE_LOWERCASE=false
PASSWORD_REQUIRE_NUMBERS=false
PASSWORD_REQUIRE_SYMBOLS=false
PASSWORD_ALLOW_COMMON=true
```

## Custom Password Validation Rule

### File: `app/Rules/FlexiblePassword.php`
- **Fleksibel**: Minimum 3 karakter (bisa disesuaikan)
- **Konfigurasi**: Menggunakan config file untuk pengaturan
- **Customizable**: Bisa diaktifkan/nonaktifkan berbagai requirement

## API Endpoints

### Get Password Policy
```
GET /api/password-policy
```

Response:
```json
{
  "success": true,
  "policy": {
    "min_length": 3,
    "max_length": 255,
    "require_uppercase": false,
    "require_lowercase": false,
    "require_numbers": false,
    "require_symbols": false,
    "allow_common_passwords": true
  }
}
```

### Create User (Updated)
```
POST /api/users
Authorization: Bearer {token}
Content-Type: application/json

{
  "name": "Test User",
  "email": "test@example.com",
  "password": "admin", // Sekarang bisa password pendek
  "role": "admin"
}
```

## Contoh Password yang Sekarang Diizinkan

✅ **Password Pendek**:
- `admin`
- `123`
- `abc`
- `test`

✅ **Password Umum**:
- `password`
- `123456`
- `qwerty`
- `admin123`

✅ **Password Kustom**:
- `myPass`
- `secret`
- `user123`
- `admin@2024`

## Frontend Integration

### JavaScript Example
```javascript
// Get password policy
async function getPasswordPolicy() {
    try {
        const response = await fetch('/api/password-policy');
        const data = await response.json();
        
        if (data.success) {
            const policy = data.policy;
            console.log('Min length:', policy.min_length); // 3
            console.log('Allow common passwords:', policy.allow_common_passwords); // true
        }
    } catch (error) {
        console.error('Error getting password policy:', error);
    }
}

// Create user with flexible password
async function createUser(userData) {
    try {
        const response = await fetch('/api/users', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`
            },
            body: JSON.stringify({
                name: userData.name,
                email: userData.email,
                password: userData.password, // Bisa password pendek seperti "admin"
                role: userData.role
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            console.log('User created successfully');
        } else {
            console.error('Validation errors:', data.errors);
        }
    } catch (error) {
        console.error('Error creating user:', error);
    }
}
```

### React Example
```jsx
import { useState, useEffect } from 'react';

function CreateUserForm() {
    const [passwordPolicy, setPasswordPolicy] = useState(null);
    const [formData, setFormData] = useState({
        name: '',
        email: '',
        password: '',
        role: 'admin'
    });

    useEffect(() => {
        // Get password policy
        fetch('/api/password-policy')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    setPasswordPolicy(data.policy);
                }
            });
    }, []);

    const handleSubmit = async (e) => {
        e.preventDefault();
        
        try {
            const response = await fetch('/api/users', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${token}`
                },
                body: JSON.stringify(formData)
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert('User created successfully!');
            } else {
                alert('Error: ' + JSON.stringify(data.errors));
            }
        } catch (error) {
            console.error('Error:', error);
        }
    };

    return (
        <form onSubmit={handleSubmit}>
            <input 
                type="text" 
                placeholder="Name"
                value={formData.name}
                onChange={(e) => setFormData({...formData, name: e.target.value})}
                required
            />
            <input 
                type="email" 
                placeholder="Email"
                value={formData.email}
                onChange={(e) => setFormData({...formData, email: e.target.value})}
                required
            />
            <input 
                type="password" 
                placeholder="Password (min 3 chars)"
                value={formData.password}
                onChange={(e) => setFormData({...formData, password: e.target.value})}
                minLength={passwordPolicy?.min_length || 3}
                required
            />
            <select 
                value={formData.role}
                onChange={(e) => setFormData({...formData, role: e.target.value})}
            >
                <option value="admin">Admin</option>
                <option value="user">User</option>
            </select>
            <button type="submit">Create User</button>
        </form>
    );
}
```

## Testing

### Test Password Pendek
```bash
curl -X POST http://localhost:8000/api/users \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "name": "Test User",
    "email": "test@example.com", 
    "password": "admin",
    "role": "admin"
  }'
```

### Test Password Policy Endpoint
```bash
curl http://localhost:8000/api/password-policy
```

## Keuntungan

1. **Fleksibilitas**: Password bisa pendek sesuai kebutuhan
2. **User Friendly**: Tidak memaksa password kompleks
3. **Konfigurasi**: Mudah disesuaikan melalui environment variables
4. **Backward Compatible**: Tetap mendukung password lama
5. **API Ready**: Frontend bisa mendapatkan policy secara dinamis

## Security Note

Meskipun password policy lebih fleksibel, tetap disarankan untuk:
- Menggunakan password yang unik untuk setiap akun
- Mengganti password default setelah setup
- Mengaktifkan 2FA jika diperlukan
- Monitoring login attempts (sudah ada rate limiting)

