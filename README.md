# Sproutly

Sproutly is a web-based Agriculture Information System built to track farmers, farms, crops, planting cycles, and harvest records in one place. It includes a dashboard for quick farm and yield summaries, filterable data tables, and printable reports.

## Overview

Sproutly (CoreLedger) is a server-rendered PHP 8+ application styled with Bootstrap 5. It connects to a PostgreSQL database on Supabase through its REST API and is deployed on Vercel using the `vercel-php` runtime.

It manages farmers, farms, crops, planting records, and harvest records, with a dashboard and printable reports. Access is role-based: administrators have full create, edit, and delete access, while staff can view, search, and filter records.

---

## Features

- **Role-Based Access Control**
  - **Admin:** Full create, read, update, and delete (CRUD) access across all modules, plus user account management.
  - **Staff:** Read, search, sort, and filter records, view dashboards, and generate reports without modifying data.
- **Core Modules**
  - **Farmers:** Manage farmer profiles, contact details, registration dates, and Cebu city/municipality locations.
  - **Farms:** Track farm ownership, land area (in hectares), soil types, and irrigation methods.
  - **Crops:** Maintain a catalog of crop names, varieties, categories, growing periods, and recommended seasons.
  - **Planting Records:** Log planting dates, area planted, seed quantities, expected harvest dates, and current crop statuses (`Planted`, `Growing`, `Ready for Harvest`, `Harvested`, `Failed`).
  - **Harvest Records:** Record harvest dates, yields in kilograms, selling price per kg, estimated harvest value, quality grades, and remarks.
  - **Users (Admin only):** Create, edit, and remove user accounts and assign roles.
- **Dashboard & Reports**
  - Summary cards for record counts, total harvested weight (kg), and estimated harvest value.
  - Breakdown charts for planting statuses and harvest volume by crop.
  - Six printable reports with optional date range filtering (`Farmers and their farms`, `Crops planted per farm`, `Planting records by status`, `Harvest records`, `Total harvested quantity`, and `Estimated harvest value`).

---

## User Roles

| Role | Access Level | Permissions |
| :--- | :--- | :--- |
| **Admin** | Full Access | Create, read, update, and delete records across all modules; manage user accounts and roles; view dashboard and print reports. |
| **Staff** | Read-Only | View, search, sort, and filter records; view dashboard summaries; generate and print reports. |

---