# Frontend Integration Example

## JavaScript/TypeScript Example

```javascript
// Login function
async function login(email, password) {
    try {
        const response = await fetch('/api/login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ email, password })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Simpan token
            localStorage.setItem('access_token', data.access_token);
            
            // Redirect berdasarkan role
            window.location.href = data.dashboard_route;
            
            // Atau jika menggunakan router (React/Vue/Angular)
            // router.push(data.dashboard_route);
        } else {
            alert(data.message);
        }
    } catch (error) {
        console.error('Login error:', error);
    }
}

// Get user menu
async function getUserMenu() {
    try {
        const token = localStorage.getItem('access_token');
        const response = await fetch('/api/user-menu', {
            headers: {
                'Authorization': `Bearer ${token}`,
                'Accept': 'application/json'
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Render menu berdasarkan role
            renderMenu(data.menu_items);
        }
    } catch (error) {
        console.error('Get menu error:', error);
    }
}

// Render menu function
function renderMenu(menuItems) {
    const menuContainer = document.getElementById('menu-container');
    menuContainer.innerHTML = '';
    
    menuItems.forEach(item => {
        const menuItem = document.createElement('div');
        menuItem.className = 'menu-item';
        menuItem.innerHTML = `
            <div class="menu-icon">${item.icon}</div>
            <div class="menu-content">
                <h3>${item.name}</h3>
                <p>${item.description}</p>
            </div>
        `;
        
        menuItem.addEventListener('click', () => {
            window.location.href = item.route;
        });
        
        menuContainer.appendChild(menuItem);
    });
}
```

## React Example

```jsx
import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';

function LoginComponent() {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const navigate = useNavigate();

    const handleLogin = async (e) => {
        e.preventDefault();
        
        try {
            const response = await fetch('/api/login', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ email, password })
            });
            
            const data = await response.json();
            
            if (data.success) {
                localStorage.setItem('access_token', data.access_token);
                localStorage.setItem('user', JSON.stringify(data.user));
                
                // Redirect berdasarkan dashboard_route
                navigate(data.dashboard_route);
            } else {
                alert(data.message);
            }
        } catch (error) {
            console.error('Login error:', error);
        }
    };

    return (
        <form onSubmit={handleLogin}>
            <input 
                type="email" 
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="Email"
                required
            />
            <input 
                type="password" 
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="Password"
                required
            />
            <button type="submit">Login</button>
        </form>
    );
}

function MenuComponent() {
    const [menuItems, setMenuItems] = useState([]);
    const navigate = useNavigate();

    useEffect(() => {
        const fetchMenu = async () => {
            try {
                const token = localStorage.getItem('access_token');
                const response = await fetch('/api/user-menu', {
                    headers: {
                        'Authorization': `Bearer ${token}`,
                        'Accept': 'application/json'
                    }
                });
                
                const data = await response.json();
                
                if (data.success) {
                    setMenuItems(data.menu_items);
                }
            } catch (error) {
                console.error('Get menu error:', error);
            }
        };

        fetchMenu();
    }, []);

    return (
        <div className="menu-container">
            {menuItems.map((item, index) => (
                <div 
                    key={index}
                    className="menu-item"
                    onClick={() => navigate(item.route)}
                >
                    <div className="menu-icon">{item.icon}</div>
                    <div className="menu-content">
                        <h3>{item.name}</h3>
                        <p>{item.description}</p>
                    </div>
                </div>
            ))}
        </div>
    );
}
```

## Vue.js Example

```vue
<template>
  <div>
    <!-- Login Form -->
    <form @submit.prevent="login">
      <input v-model="email" type="email" placeholder="Email" required />
      <input v-model="password" type="password" placeholder="Password" required />
      <button type="submit">Login</button>
    </form>

    <!-- Menu -->
    <div class="menu-container">
      <div 
        v-for="item in menuItems" 
        :key="item.name"
        class="menu-item"
        @click="navigateTo(item.route)"
      >
        <div class="menu-icon">{{ item.icon }}</div>
        <div class="menu-content">
          <h3>{{ item.name }}</h3>
          <p>{{ item.description }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  data() {
    return {
      email: '',
      password: '',
      menuItems: []
    }
  },
  methods: {
    async login() {
      try {
        const response = await fetch('/api/login', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({ 
            email: this.email, 
            password: this.password 
          })
        });
        
        const data = await response.json();
        
        if (data.success) {
          localStorage.setItem('access_token', data.access_token);
          localStorage.setItem('user', JSON.stringify(data.user));
          
          // Redirect berdasarkan dashboard_route
          this.$router.push(data.dashboard_route);
        } else {
          alert(data.message);
        }
      } catch (error) {
        console.error('Login error:', error);
      }
    },
    
    async fetchMenu() {
      try {
        const token = localStorage.getItem('access_token');
        const response = await fetch('/api/user-menu', {
          headers: {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json'
          }
        });
        
        const data = await response.json();
        
        if (data.success) {
          this.menuItems = data.menu_items;
        }
      } catch (error) {
        console.error('Get menu error:', error);
      }
    },
    
    navigateTo(route) {
      this.$router.push(route);
    }
  },
  
  mounted() {
    this.fetchMenu();
  }
}
</script>
```

## CSS Example

```css
.menu-container {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 20px;
  padding: 20px;
}

.menu-item {
  display: flex;
  align-items: center;
  padding: 20px;
  border: 1px solid #e3e3e0;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.3s ease;
}

.menu-item:hover {
  border-color: #1915014a;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  transform: translateY(-2px);
}

.menu-icon {
  font-size: 24px;
  margin-right: 15px;
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #f5f5f5;
  border-radius: 50%;
}

.menu-content h3 {
  margin: 0 0 5px 0;
  font-size: 18px;
  font-weight: 600;
}

.menu-content p {
  margin: 0;
  color: #666;
  font-size: 14px;
}
```
