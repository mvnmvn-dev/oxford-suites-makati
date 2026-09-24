# Oxford Suites Restaurant Management System --- Proposed UX & Feature Improvements

## Goal

This document lists recommended improvements for the **Oxford Suites
Restaurant Management System** to enhance both **customer experience**
and **staff operations**. These improvements are prioritized for a BSIT
Capstone Project and focus on usability, operational efficiency, and
real-world restaurant workflows.

------------------------------------------------------------------------

# Executive Summary

  ----------------------------------------------------------------------------
  Area           Current State            Proposed Enhancement
  -------------- ------------------------ ------------------------------------
  Customer       QR Ordering              Live order tracking, menu
  Ordering                                customization, waiter requests

  Kitchen        KOT Kanban               Timers, alerts, color-coded
  Operations                              priorities

  POS            Basic payments           Split bills, mixed payments,
                                          automatic discounts

  Inventory      Forecasting              Live stock availability and sold-out
                                          indicators

  Reservations   Reservation system       Interactive table selection and
                                          reminders

  Analytics      Sales dashboard          Customer satisfaction, demand
                                          forecasting, waste analytics
  ----------------------------------------------------------------------------

------------------------------------------------------------------------

# Customer Experience Improvements

## 1. Live Order Tracking

**Priority:** ⭐⭐⭐⭐⭐

Allow customers to monitor their order status in real time after placing
an order.

### Workflow

-   Order Received
-   Preparing
-   Ready to Serve
-   Served

### Benefits

-   Reduces customer uncertainty.
-   Minimizes follow-up questions to staff.
-   Integrates with the Kitchen Order Ticket (KOT).

------------------------------------------------------------------------

## 2. Call Waiter / Request Bill Button

**Priority:** ⭐⭐⭐⭐⭐

Add a floating action button inside the QR ordering page.

### Available Requests

-   Water refill
-   Extra utensils
-   Follow-up order
-   Request bill

### Benefits

-   Faster customer service.
-   Organized waiter notifications.
-   Eliminates manual signaling.

------------------------------------------------------------------------

## 3. Enhanced Digital Menu

**Priority:** ⭐⭐⭐⭐

Improve menu presentation with useful visual indicators.

### Additions

-   Best Seller badge
-   Chef's Recommendation
-   Spicy indicator
-   Vegetarian indicator
-   Limited Stock badge

### Additional Information

-   Calories
-   Preparation time
-   Ingredients
-   Allergen information

------------------------------------------------------------------------

## 4. Food Customization

**Priority:** ⭐⭐⭐⭐

Allow structured meal customization.

### Examples

-   Spice level
-   Remove ingredients
-   Extra toppings
-   Cooking preferences

### Benefits

Kitchen receives standardized customization instead of free-text notes.

------------------------------------------------------------------------

## 5. Digital Bill Preview

**Priority:** ⭐⭐⭐⭐

Show customers a live summary before requesting payment.

### Display

-   Itemized order
-   Quantity
-   Taxes
-   Service charge
-   Discounts
-   Running total

------------------------------------------------------------------------

## 6. Customer Feedback Module

**Priority:** ⭐⭐⭐⭐

Collect customer ratings after payment.

### Rating Categories

-   Food Quality
-   Service
-   Cleanliness
-   Overall Experience

### Dashboard Integration

Generate satisfaction analytics for administrators.

------------------------------------------------------------------------

# Staff Experience Improvements

## 7. Advanced Kitchen Display System (KDS)

**Priority:** ⭐⭐⭐⭐⭐

Upgrade the KOT board with operational tools.

### Features

-   Cooking timer
-   Overdue order highlighting
-   Sound notification
-   Station filtering (Grill, Drinks, Dessert)

### Benefits

-   Faster kitchen response.
-   Better workload visibility.

------------------------------------------------------------------------

## 8. Interactive Table Status Dashboard

**Priority:** ⭐⭐⭐⭐⭐

Display restaurant floor layout.

### Table Colors

  Status          Color
  --------------- --------
  Available       Green
  Occupied        Red
  Reserved Soon   Yellow
  Cleaning        Gray

### Table Information

-   Guests
-   Orders
-   Time seated
-   Current bill

------------------------------------------------------------------------

## 9. Waiter Task Queue

**Priority:** ⭐⭐⭐⭐

Assign waiter tasks automatically.

### Task Examples

-   Serve food
-   Water refill
-   Bill request
-   Cleaning request

### Benefits

-   Organized service workflow.
-   Reduced missed requests.

------------------------------------------------------------------------

## 10. Inventory-Aware Ordering

**Priority:** ⭐⭐⭐⭐⭐

Sync inventory with customer ordering.

### Menu Indicators

-   Available
-   Low Stock
-   Sold Out

### Benefits

Prevents customers from ordering unavailable meals.

------------------------------------------------------------------------

# POS Improvements

## 11. Split Billing

**Priority:** ⭐⭐⭐⭐

Support multiple billing methods.

### Split Options

-   Equal split
-   By customer
-   By item

------------------------------------------------------------------------

## 12. Mixed Payment Methods

**Priority:** ⭐⭐⭐⭐

Allow multiple payment sources.

### Supported Methods

-   Cash
-   GCash
-   Maya
-   Debit/Credit Card

Example:

  Payment Method   Amount
  ---------------- --------
  Cash             ₱300
  GCash            ₱250

------------------------------------------------------------------------

## 13. Automatic Discount Engine

**Priority:** ⭐⭐⭐⭐

Support Philippine restaurant discounts.

### Discounts

-   Senior Citizen
-   PWD
-   Employee
-   Promo Codes

Automatically compute VAT exemptions where applicable.

------------------------------------------------------------------------

# Reservation Improvements

## 14. Interactive Reservation Calendar

**Priority:** ⭐⭐⭐⭐

Allow customers to reserve specific tables.

### Reservation Details

-   Date
-   Time
-   Party Size
-   Table Location
-   Indoor / Outdoor
-   Window Seat

------------------------------------------------------------------------

## 15. Reservation Reminder System

**Priority:** ⭐⭐⭐

Send reminders before reservation time.

### Channels

-   SMS
-   Email
-   In-app notification

Allow cancellation before reservation.

------------------------------------------------------------------------

# AI-Powered Enhancements

## 16. Context-Aware Menu Recommendations

**Priority:** ⭐⭐⭐⭐

Improve recommendation engine using customer behavior.

### Recommendation Types

-   Frequently ordered together
-   Best pairings
-   Dessert suggestions
-   Drinks recommendations

------------------------------------------------------------------------

## 17. Demand Forecast Dashboard

**Priority:** ⭐⭐⭐⭐

Predict restaurant demand.

### Forecast

-   Lunch rush
-   Dinner rush
-   Weekends
-   Seasonal trends

### Benefits

Helps schedule staff and prepare inventory.

------------------------------------------------------------------------

## 18. Food Waste Prediction

**Priority:** ⭐⭐⭐⭐

Track ingredients likely to expire.

### Dashboard Insights

-   Expiring ingredients
-   High-waste items
-   Suggested promotional meals

------------------------------------------------------------------------

# Analytics Improvements

## 19. Executive Dashboard

**Priority:** ⭐⭐⭐⭐⭐

Provide a comprehensive management dashboard.

### KPIs

-   Today's Revenue
-   Orders Today
-   Average Preparation Time
-   Customer Rating
-   Peak Hours
-   Best Sellers
-   Inventory Turnover

------------------------------------------------------------------------

## 20. Customer Satisfaction Analytics

**Priority:** ⭐⭐⭐⭐

Aggregate customer feedback into reports.

### Metrics

-   Average ratings
-   Most common complaints
-   Most praised menu items
-   Service response time

------------------------------------------------------------------------

# UX Improvements

## 21. Premium Landing Page

**Priority:** ⭐⭐⭐⭐

Improve homepage presentation.

### Sections

-   Hero Banner
-   Reserve Table CTA
-   Scan QR CTA
-   Today's Specials
-   Testimonials

------------------------------------------------------------------------

## 22. Floating Shopping Cart

**Priority:** ⭐⭐⭐⭐

Persistent cart while browsing menu.

### Features

-   Running total
-   Item quantity
-   Checkout shortcut

------------------------------------------------------------------------

## 23. Skeleton Loading States

**Priority:** ⭐⭐⭐

Display loading placeholders while data loads.

### Benefits

Improves perceived performance.

------------------------------------------------------------------------

## 24. Friendly Empty States

**Priority:** ⭐⭐⭐

Replace empty screens with helpful illustrations and messages.

Example:

> 🍽️ Hungry? Start browsing our menu!

------------------------------------------------------------------------

# Accessibility Improvements

## 25. Accessibility Mode

**Priority:** ⭐⭐⭐⭐

Improve inclusivity.

### Options

-   Large text
-   High contrast
-   Screen reader labels
-   Colorblind-friendly indicators

------------------------------------------------------------------------

# Security Improvements

## 26. Audit Logs

**Priority:** ⭐⭐⭐⭐

Track important administrator actions.

### Log Examples

-   Menu edits
-   Refund approvals
-   Reservation cancellations
-   Login history

------------------------------------------------------------------------

## 27. Automatic Session Timeout

**Priority:** ⭐⭐⭐

Automatically log out inactive users, especially cashier accounts.

------------------------------------------------------------------------

# Business & Marketing Features

## 28. Loyalty Rewards Program

**Priority:** ⭐⭐⭐

Reward returning customers.

### Rewards

-   Points system
-   Free drinks
-   Free desserts
-   Discount vouchers

------------------------------------------------------------------------

## 29. Promotion Management System

**Priority:** ⭐⭐⭐⭐

Allow administrators to create promotions.

### Promotion Types

-   Buy 1 Take 1
-   Percentage discounts
-   Birthday rewards
-   Limited-time offers

------------------------------------------------------------------------

## 30. QR Digital Receipt

**Priority:** ⭐⭐⭐⭐

Generate paperless receipts.

### Customer Can Access

-   Receipt
-   Order history
-   Feedback page
-   Loyalty points

------------------------------------------------------------------------

# Priority Roadmap

## Phase 1 --- High Impact (Recommended for Capstone)

-   Live Order Tracking
-   Call Waiter / Request Bill
-   Interactive Table Dashboard
-   Kitchen Timers & Alerts
-   Inventory-Aware Menu

## Phase 2 --- Restaurant Operations

-   Split Billing
-   Mixed Payments
-   Reservation Calendar
-   Food Customization
-   Customer Feedback Analytics

## Phase 3 --- Smart Features

-   AI Menu Recommendations
-   Demand Forecasting
-   Food Waste Prediction
-   Loyalty Rewards
-   Promotion Management

------------------------------------------------------------------------

# Expected Capstone Impact

  -----------------------------------------------------------------------
  Improvement Area                    Expected Benefit
  ----------------------------------- -----------------------------------
  Customer Experience                 Faster ordering, better
                                      transparency, easier service
                                      requests

  Staff Productivity                  Organized waiter workflow, kitchen
                                      efficiency, fewer manual tasks

  Restaurant Operations               Better inventory visibility,
                                      reservation management, payment
                                      flexibility

  Management Insights                 Real-time analytics, demand
                                      forecasting, customer satisfaction
                                      reports

  Innovation                          AI-powered recommendations,
                                      forecasting, waste reduction,
                                      loyalty system
  -----------------------------------------------------------------------

------------------------------------------------------------------------

# Expected Overall Capstone Improvement

**Current Evaluation:** **88/100**

**After Implementing Priority Improvements:** **95--97/100**

The proposed features transform the Oxford Suites Restaurant Management
System from a traditional QR ordering platform into a production-style
restaurant ecosystem with customer engagement, intelligent kitchen
operations, operational analytics, and AI-assisted decision support.
