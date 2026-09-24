# Restaurant Management System — Development Guide

**Based on:** *Design and Development of a Restaurant Management System with TensorFlow API and QR Code Ordering, Online Reservation Using a Recommendation System, and Inventory Prediction* (Capstone, Bestlink College of the Philippines, Feb 2026) + provided module breakdown.

This guide translates the capstone documentation into a practical, build-ready plan: what to build, in what order, and with what technology.

---

## 1. System Overview

A web-based restaurant management platform that unifies:
- **QR Code Ordering** — customers scan a table QR code, browse the digital menu, and order from their own device.
- **Online Reservation** — customers book tables remotely; staff manage schedules.
- **AI Recommendation System** — TensorFlow-based Collaborative Filtering suggests menu items from a customer's order history.
- **Inventory Prediction** — TensorFlow-based Time Series Analysis forecasts ingredient needs from historical sales data.
- **Core back-of-house operations** — kitchen order routing, POS/billing, staff and inventory management, and admin analytics.

**Architecture style:** Microservices (chosen over monolithic — see §4) with an API Gateway front door and database-per-service persistence.

---

## 2. Module Breakdown

The system is organized into 7 functional modules. Each maps to one or more backend microservices and a set of user stories from the product backlog.

### 2.1 Customer & Table Management
- Table/seating status (available, occupied, reserved)
- QR code generation and binding per table (session scanning)
- Online reservation: create, view, update reservation status
- Customer-facing landing page reached by QR scan
- **Maps to:** Table Service, Reservation Service

### 2.2 Menu Management System
- Add, edit, disable/remove menu items (name, category, price, availability, photos)
- Category organization
- Feeds both the customer-facing digital menu and the recommendation engine
- **Maps to:** Menu Service

### 2.3 Order Management System
- Customer places order via QR-code digital menu (cart, submit)
- Staff view incoming orders and update order status
- Order data feeds the Kitchen Service (via events) and the Recommendation engine
- **Maps to:** Order Service

### 2.4 Kitchen Order System (KOT)
- Real-time kitchen order queue/display
- Order status updates flow back to customer/staff views
- Triggered by an **"Order Created" event** published by the Order Service (event-driven, not direct coupling)
- **Maps to:** Kitchen Service

### 2.5 POS & Billing System
- Payment processing and receipt/billing records
- Protected by the Circuit Breaker pattern so a Payment Service outage doesn't cascade
- **Maps to:** Payment Service

### 2.6 Inventory & Staff Management
- Record and update ingredient/stock items and quantities
- Automated stock deduction triggered by order events
- **Inventory Prediction:** TensorFlow Time Series model forecasts future ingredient demand from historical sales
- Staff accounts, roles, and permissions (RBAC: admin, kitchen staff, cashier, customer)
- **Maps to:** Inventory Service, User/Staff Service

### 2.7 Administration & Analytics
- Central dashboard (key restaurant metrics in one place)
- Reports generation
- View AI outputs: recommendation performance, inventory prediction results
- **Maps to:** Admin/Reporting layer (aggregates data via API Gateway, no dedicated database)

---

## 3. Technology Stack

| Layer | Technology | Purpose |
|---|---|---|
| IDE | Visual Studio Code | Primary development environment (backend, frontend, ML) |
| Frontend | HTML, CSS, JavaScript | Structure, styling, and client-side interactivity |
| Backend (transactional) | **PHP** | Core business logic — orders, reservations, menu, billing |
| AI/ML Service | **Python + TensorFlow API** | Recommendation engine (Collaborative Filtering) and inventory forecasting (Time Series Analysis) |
| Database | **MySQL** | Structured transactional data — accounts, billing, reservations |
| ML Data Storage | Optimized file datasets | Time-series logs and serialized TensorFlow models (polyglot persistence) |
| Ordering Access | QR Code technology | Table-side access to the digital menu/ordering flow |
| API Testing | Postman | Validate REST endpoints, payloads, and auth across services |
| Containerization | Docker | Isolated, reproducible containers per microservice |
| Version Control | Git & GitHub | Source control, branching, pull requests |
| CI/CD | GitHub Actions | Automated tests, container builds, deployment on merge |
| Runtime target | Web Browser | Web-based delivery for staff and customer interfaces |

> The capstone document notes this stack is proposed and may be adjusted during implementation. If you have latitude to modernize it (e.g., swapping PHP for Laravel/Node, or MySQL for MySQL + a document store), the architecture below still applies — only the implementation layer changes.

---

## 4. System Architecture

### 4.1 Why Microservices (not Monolithic)

| Criteria | Monolithic | Microservices |
|---|---|---|
| Deployment | Whole system together | Services deployed independently |
| Scalability | Scale everything | Scale only high-traffic services (e.g., Order Service during peak hours) |
| Fault isolation | One failure can take down the app | A failed service (e.g., Inventory) doesn't stop others (e.g., Menu browsing) |
| Maintenance | Harder as features grow | Smaller, service-specific codebases |
| Tech flexibility | Usually one stack | Each service can use what fits it (PHP for transactions, Python for ML) |

### 4.2 Services

- **User/Staff Service** — accounts, authentication, roles
- **Menu Service** — items, categories, prices, availability
- **Order Service** — orders and order status
- **Reservation Service** — table booking/scheduling
- **Table Service** — table availability/seating status
- **Inventory Service** — stock levels, deduction, forecast data
- **Payment Service** — payment processing/records
- **Kitchen Service** — food prep status
- **Notification Service** — order/reservation notifications
- **TensorFlow Inference Service** (Python) — recommendation + prediction models

### 4.3 Architectural Patterns

1. **API Gateway** — single entry point for web app, kitchen display, cashier system; handles auth, routing, rate limiting.
2. **Database-per-Service** — each service owns its data store; no cross-service DB access.
3. **Event-Driven Communication** — e.g., `Order Created` event → consumed by Kitchen Service (start prep) and Inventory Service (deduct stock).
4. **Circuit Breaker** — protects the system when a service (e.g., Payment) becomes temporarily unavailable.
5. **Repository Pattern** — isolates business logic from data-access code for testability.

### 4.4 High-Level Flow

```
Customers / Staff / Admin / Kitchen Devices
              │
         API Gateway
              │
     Restaurant Microservices
   (Order, Menu, Reservation, Table,
    Inventory, Payment, Kitchen, User,
    Notification, TF Inference)
              │
     Individual Service Databases
```

Services communicate via REST APIs (synchronous requests) and events (asynchronous, e.g., order-created → kitchen + inventory).

### 4.5 Example Event Flow

```
Customer → Order Service → "Order Created" event
                                 ├─→ Kitchen Service (starts prep, shows KOT)
                                 └─→ Inventory Service (deducts stock)
```

---

## 5. AI / Machine Learning Component

| Feature | Technique | Input | Output |
|---|---|---|---|
| Menu Recommendation | Collaborative Filtering (TensorFlow) | Customer order history, preferences | Personalized menu suggestions |
| Inventory Prediction | Time Series Analysis (TensorFlow) | Historical sales/ingredient usage | Forecasted ingredient demand, reorder triggers |

- Both models run as a **separate Python service**, isolated in its own Docker container, exposed through the API Gateway like any other microservice.
- Preference data used for recommendations should be processed **anonymously** (privacy-by-design) — no personally identifiable data feeding the model directly.
- Serialized models and training matrices are stored as **file datasets**, separate from the MySQL transactional database (polyglot persistence).

---

## 6. Data Strategy (Polyglot Persistence)

- **MySQL** — integrity-critical transactional data: logins/hashed passwords, table bookings, billing receipts, orders.
- **File-based storage** — time-series logs and serialized TensorFlow models/training matrices.
- Rationale: relational databases suit structured transactional data; flat/optimized file structures suit high-volume ML training data — keeping them separate protects performance during peak hours.

---

## 7. Security

- **Role-Based Access Control (RBAC)** — admin, kitchen staff, cashier, customer each see only what their role permits.
- **Password hashing** for authentication.
- **TLS/SSL** for all client–server communication.
- **Input validation & sanitization** at every user-facing entry point (QR order form, reservation form) to prevent injection attacks.
- **Privacy-by-design** — collect only data required for the specific service (booking, ordering, recommendations); anonymize preference vectors used by the ML engine.

---

## 8. Development Methodology — Agile Scrum

- **Sprint structure:** iterative development, one functional slice per sprint.
- **Recommended build order** (dependency-driven, matching the capstone's sprint backlog):
  1. Core system setup — DB schema, user accounts & roles
  2. Menu Management
  3. QR Code Ordering + digital menu (customer-facing)
  4. Online Reservation
  5. Order Management + Kitchen Order System (event-driven link)
  6. POS & Billing
  7. Inventory Management (recording, updating, monitoring)
  8. Recommendation System (TensorFlow — Collaborative Filtering)
  9. Inventory Prediction (TensorFlow — Time Series)
  10. Admin dashboard & reporting/analytics
  11. Integration of all services, end-to-end testing, bug fixing, UAT prep

- **Scrum events:** Sprint Planning, Daily Scrum, Sprint Review, Sprint Retrospective.
- **Scrum artifacts:** Product Backlog (full user-story list), Sprint Backlog (per-sprint tasks), Product Increment (working slice after each sprint).

---

## 9. DevOps & CI/CD Pipeline

1. **Git/GitHub** — source control, feature branches, PR review.
2. **Postman** — validate each microservice's REST endpoints (requests, responses, auth) before integration.
3. **Docker** — package each microservice (Order, Reservation, Inventory, TensorFlow Inference, etc.) into a consistent, portable container.
4. **GitHub Actions** — on merge to main: run syntax checks/unit tests → build containers → deploy.

**Testing strategy (layered):**
1. Unit Testing — individual functions/components
2. Integration Testing — services working together
3. API Testing (Postman) — endpoint-level validation across services
4. Full-system validation — functional, security, performance, usability, plus accuracy checks on the recommendation and inventory-prediction models

---

## 10. UI / Visual Design Reference

A staff-management screenshot (Oxford Suites Makati's internal workforce system) was used as a **layout reference** — sidebar navigation, top status bar, banner headers, color-coded status cards. That structure is sound for an operations tool, but its palette and type treatment read as generic hotel-HR software: flat maroon, default sans-serif, plain white cards. A restaurant system should feel like it belongs to a kitchen and a dining room, not a back office. Below is a design direction that keeps the same functional layout but gives it a food-service identity.

### 10.1 Design principles
- **Two audiences, one identity.** The customer-facing QR menu/reservation flow should feel editorial and appetite-driven (like a printed menu). The staff-facing dashboards (KOT, POS, inventory, admin) should feel fast and legible under kitchen conditions, but branded with the same palette and type — not a separate, sterile "admin theme."
- **One bold move, not five.** Spend the visual risk on the KOT board (styled as a ticket rail, see below) and the customer menu hero. Keep the rest of the dashboard calm and functional.
- **No SaaS-card sameness.** Avoid identical rounded-corner cards with the same drop shadow on every panel — that's what makes the reference screenshot feel bland. Vary card treatment by meaning (a status chip is not a menu item is not a KPI tile).

### 10.2 Color palette

| Token | Hex | Use |
|---|---|---|
| `--charcoal` | `#241E1C` | Primary text, dark surfaces (KOT board background) |
| `--ember` | `#A8342A` | Primary brand/accent — CTAs, active nav, headers (replaces flat maroon) |
| `--brass` | `#C89A4B` | Secondary accent — highlights, icons, dividers, "featured" tags |
| `--parchment` | `#F3ECE1` | App background (warm, not stark white or the generic AI cream `#F4F1EA`) |
| `--sage` | `#6B7F5B` | "Available / In stock / Served" success states |
| `--clay` | `#D9773F` | "Preparing / Pending" warning states (replaces flat orange) |

This is warmer and more saturated than the flat corporate maroon-on-white in the reference — closer to a wood-fired dining room than a hotel back office.

### 10.3 Typography

| Role | Typeface | Notes |
|---|---|---|
| Display / headings | **Fraunces** (serif, variable) | Menu-card character — use at heavier optical sizes for module titles and the customer menu hero |
| UI / body | **Public Sans** or **Inter** | High legibility for staff dashboards, order tickets, tables |

Avoid all-caps tracked-out labels and middle-dot metadata strings (`Dashboard · Today · 4 orders`) — write labels in plain sentence case instead.

### 10.4 Layout treatments by module

- **Customer QR Menu (Menu Management + Order Management, customer view):** full-width photo hero per category (not a grid of identical thumbnail cards), Fraunces headings, item cards with a circular crop for the dish photo instead of a square — reads as a plated dish, not a stock-photo card.
- **Kitchen Order System (KOT):** style as a **ticket rail** — vertical strips on a dark charcoal board, one per order, clipped like a printed kitchen chit, rather than the calendar-grid pattern from the reference. This is the one place to spend real visual boldness.
- **Reservations / Table Calendar:** the reference's calendar-grid works well here functionally — keep it, but restyle the status tags with the palette above (sage/clay/brass instead of green/orange/lavender) and swap the sans-serif banner for Fraunces.
- **POS & Billing, Inventory, Admin dashboard:** keep the sidebar + banner-header structure from the reference (it's functionally solid), but reskin with the palette/type above, and replace generic icon style with simple line icons themed to food service (ticket, tray, scale, receipt) rather than generic HR icons (clock, calendar).

### 10.5 What to drop from the reference
- Flat single-tone maroon header bands → use the ember/brass combination with slightly more contrast and texture (a subtle linen or paper-grain background on the customer-facing hero only).
- Generic rounded-rectangle status cards with uniform shadow → vary shape/treatment by content type, per §10.1.
- Corporate sans-serif throughout → reserve Fraunces for anything a customer or staff member reads as a "heading," keep the sans for dense operational data.

---

## 11. Quick-Reference Build Checklist

- [ ] Set up Git repo, project structure, Docker base configs
- [ ] Design MySQL schema (users, roles, menu, tables, reservations, orders, inventory, payments)
- [ ] Build User/Staff Service (auth, RBAC)
- [ ] Build Menu Service + admin CRUD UI
- [ ] Build Table Service + QR code generation/binding
- [ ] Build customer-facing digital menu (QR landing page)
- [ ] Build Order Service + Order Management staff view
- [ ] Build Kitchen Service (KOT) wired to `Order Created` event
- [ ] Build Reservation Service + booking UI
- [ ] Build Payment Service (POS & Billing) with Circuit Breaker
- [ ] Build Inventory Service (record/update/monitor stock, auto-deduction on order events)
- [ ] Stand up Python TensorFlow Inference Service
  - [ ] Train/deploy Collaborative Filtering recommendation model
  - [ ] Train/deploy Time Series inventory prediction model
- [ ] Wire API Gateway across all services (routing, auth, rate limiting)
- [ ] Build Admin dashboard + reporting/analytics
- [ ] Integration testing across services
- [ ] Security pass (RBAC checks, TLS, input validation)
- [ ] CI/CD pipeline live via GitHub Actions
- [ ] User acceptance testing
