# Nginx Load Balancer — E-Commerce API

## Quick Start (Docker — Recommended)

```bash
# Build and start everything
docker compose up -d --build

# The API is now available at http://localhost
# Try it:
curl http://localhost/api/v1/health
```

## Docker Architecture

```
                         ┌──────────────┐
                         │   Nginx       │
  User ──► :80 ─────────►  port 80      │
                         │  weight-3:2:1 │
                         └──────┬───────┘
                                │
              ┌─────────────────┼─────────────────┐
              ▼                 ▼                  ▼
        ┌──────────┐    ┌──────────┐     ┌──────────┐
        │  app1    │    │  app2    │     │  app3    │
        │ weight=3 │    │ weight=2 │     │ weight=1 │
        │ 50% trf  │    │ 33% trf  │     │ 17% trf  │
        └────┬─────┘    └────┬─────┘     └────┬─────┘
             │               │                │
             └───────┬───────┴───────┬────────┘
                     ▼               ▼
              ┌──────────┐   ┌──────────┐
              │  Redis    │   │  SQLite  │
              │(queue +   │   │ (shared  │
              │ semaphore)│   │ volume)  │
              └──────────┘   └──────────┘
```

## Arch Linux — Native Setup (Without Docker)

### Install Nginx

```bash
sudo pacman -S nginx
sudo systemctl enable nginx
sudo systemctl start nginx
```

### Configure

```bash
# Copy the config
sudo cp deploy/nginx/load-balancer.conf /etc/nginx/sites-available/ecommerce-api

# Create sites-enabled directory if it doesn't exist
sudo mkdir -p /etc/nginx/sites-enabled

# Enable the site
sudo ln -sf /etc/nginx/sites-available/ecommerce-api /etc/nginx/sites-enabled/

# Remove default config if it exists
sudo rm -f /etc/nginx/sites-enabled/default

# The main nginx.conf needs to include sites-enabled/
# Check /etc/nginx/nginx.conf and add:
#   include /etc/nginx/sites-enabled/*;
# inside the http block if it's not already there.

# Test and reload
sudo nginx -t
sudo systemctl reload nginx
```

### Run Multiple App Instances Locally (for native testing)

```bash
# Install PHP and Composer
sudo pacman -S php php-pgsql php-sqlite composer redis

# Start Redis
sudo systemctl start redis

# Run 3 app instances on different ports
php artisan serve --port=8001 &
php artisan serve --port=8002 &
php artisan serve --port=8003 &

# Update load-balancer.conf to point to localhost:8001, 8002, 8003
# (edit the upstream servers section)
```

## Setup Instructions (Ubuntu/Debian)

```bash
sudo apt update
sudo apt install nginx
sudo cp deploy/nginx/load-balancer.conf /etc/nginx/sites-available/ecommerce-api
sudo ln -s /etc/nginx/sites-available/ecommerce-api /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

## Load Balancing Strategies

| Strategy              | Nginx Directive | Use Case                          |
| --------------------- | --------------- | --------------------------------- |
| **Round-Robin**       | _(default)_     | All servers have equal capacity   |
| **Weighted**          | `weight=N`      | Servers with different capacities |
| **Least Connections** | `least_conn;`   | Requests vary in processing time  |
| **IP Hash**           | `ip_hash;`      | Need session stickiness           |

## Verify Distribution

```bash
# Send 12 requests and count which upstream handled each
for i in $(seq 1 12); do
    curl -sI http://localhost/api/v1/health | grep -i "x-upstream"
done
```

Expected pattern with weights 3:2:1:

```
x-upstream: 172.18.0.2:8000  (app1 × 3)
x-upstream: 172.18.0.2:8000
x-upstream: 172.18.0.2:8000
x-upstream: 172.18.0.3:8000  (app2 × 2)
x-upstream: 172.18.0.3:8000
x-upstream: 172.18.0.4:8000  (app3 × 1)
x-upstream: 172.18.0.2:8000  (wraps around)
...
```

## Production Considerations

1. **SSL**: Add SSL certificate (Let's Encrypt with `certbot`)
2. **Shared State**: All app instances use Redis for sessions/cache
3. **File Uploads**: Use S3-compatible storage instead of local disk
4. **Database**: Consider using PostgreSQL/MySQL with replication
