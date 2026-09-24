# Oxford Suites — Restaurant Management System (RMS)

Web-based restaurant platform for **Oxford Suites Makati** (*Restaurant of Suite*), built from `RMS-Development-Guide.md`.

## Stack

| Layer | Technology |
|---|---|
| Frontend | HTML, CSS, JavaScript |
| Backend | PHP microservices + API Gateway |
| Database | MySQL |
| AI/ML | Python + TensorFlow (Flask inference service) |
| Containers | Docker Compose (MySQL + ML) |
| CI | GitHub Actions |

## Features

- QR table ordering (customer digital menu)
- **Guest portal** — shared header across Home / Menu / Suites / Reserve / Orders; account modals (profile, password, deactivate); promo strip; suite information page
- Online reservations + staff calendar
- Order management with event-driven Kitchen KOT + inventory deduction
- POS & billing with circuit breaker
- Inventory + TensorFlow time-series forecast
- Collaborative filtering menu recommendations
- Admin analytics dashboard
- RBAC: admin, kitchen, cashier, customer

## Design

Layout inspired by the Oxford Suites workforce UI (sidebar + banner modules), restyled per the guide and built with **Tailwind CSS** (precompiled `public/assets/css/tailwind.css` + custom theme tokens in `tailwind.config.js`):

- Palette: charcoal / ember / brass / parchment / sage / clay (`public/assets/js/tailwind-theme.js`)
- Type: Fraunces (display) + Public Sans (UI)
- **Light / dark mode** — sun/moon toggle in the header (persists in `localStorage` as `oxford-rms-theme`)
- KOT uses the same suite shell as other modules (kanban columns: New / In progress / Ready)
- Logo: `public/assets/img/oxford-logo.jpg`  

## Quick start (XAMPP)

1. Start **Apache** and **MySQL** in XAMPP.
2. Import the schema:

```bash
C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\oxford-rms\database\init.sql
```

3. Open: [http://localhost/oxford-rms/](http://localhost/oxford-rms/)

### Demo logins

Password for all: `password123`

| Role | Email |
|---|---|
| Admin | admin@oxfordsuites.ph |
| Kitchen | kitchen@oxfordsuites.ph |
| Cashier | cashier@oxfordsuites.ph |
| Guest (customer) | guest@example.com |

### Guest portal

[http://localhost/oxford-rms/public/customer/](http://localhost/oxford-rms/public/customer/) — sign in / register, reserve, my orders

### Sample QR menu

[http://localhost/oxford-rms/public/customer/menu.html?token=qr_t3_oxford_suite](http://localhost/oxford-rms/public/customer/menu.html?token=qr_t3_oxford_suite)

## Optional: ML service

```bash
cd ml-service
pip install flask flask-cors numpy
# Optional: pip install tensorflow  (Python < 3.13)
python app.py
```

Runs on `http://127.0.0.1:5001`. The API Gateway falls back to PHP heuristics if ML is offline.

## Optional: Docker

```bash
docker compose up -d mysql ml-service
```

Point `config/app.php` / env vars at MySQL port `3307` if using Compose MySQL.

## API Gateway

Base: `/oxford-rms/api/`

| Path | Service |
|---|---|
| `/auth/login` | User auth |
| `/auth/register` | Guest account signup |
| `/menu/*` | Menu CRUD |
| `/orders` | Orders + `OrderCreated` events (`?mine=1` for guest history) |
| `/reservations` | Reservations (`?mine=1` for guest bookings) |
| `/tables` | Floor + QR tokens |
| `/reservations` | Bookings / calendar |
| `/kitchen/tickets` | KOT |
| `/payments` | POS (circuit breaker) |
| `/inventory` | Stock |
| `/ml/recommend` | CF recommendations |
| `/ml/forecast` | Inventory forecast |
| `/analytics` | Admin KPIs |

## Project layout

```
oxford-rms/
├── gateway/           # API Gateway
├── services/          # PHP microservices
├── shared/            # DB, Auth, EventBus, CircuitBreaker
├── ml-service/        # Python TensorFlow inference
├── database/init.sql  # Schema + seed
├── public/            # Staff + customer UI
└── RMS-Development-Guide.md
```

## Architecture notes

- **API Gateway** routes all client traffic.
- **Event bus** publishes `OrderCreated` → Kitchen ticket + inventory deduction.
- **Circuit breaker** wraps Payment Service failures.
- **Polyglot persistence**: MySQL for transactions; file datasets under `ml-service/models` & `data` for ML.
