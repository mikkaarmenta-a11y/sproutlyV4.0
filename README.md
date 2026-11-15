# Sproutly – Agriculture Information System

Sproutly is a simple website for keeping track of farming records in one place: who the farmers are, what farms they own, which crops they plant, and how much they harvest. It also shows a dashboard with quick summaries and can print reports.

All data is saved in an online database (Supabase), so it is always up to date.

---

## What You Can Do With Sproutly

- **Farmers** – keep each farmer's name, contact number, address and registration date.
- **Farms** – record who owns each farm, where it is, its size, soil type and how it is watered.
- **Crops** – keep a list of crops, their varieties, category, how long they take to grow and the best season.
- **Planting Records** – log what was planted on which farm, when, how much, when it should be ready, and its current status (Planted, Growing, Ready for Harvest, Harvested or Failed).
- **Harvest Records** – log how many kilograms were harvested, the selling price, the quality grade and remarks. The estimated value is calculated for you.
- **Dashboard** – see totals for farmers, farms, crops, plantings and harvests, plus total kilograms harvested, estimated value, recent records and charts.
- **Reports** – six printable reports that can be limited to a date range:
  1. Farmers and their farms
  2. Crops planted per farm
  3. Planting records by status
  4. Harvest records
  5. Total harvested quantity
  6. Estimated harvest value

### Finding things easily
Every page has a search box and filters (for example, farms by irrigation type or harvests by quality grade). Click a column heading to sort. Long lists are split into pages of 10.

### Helpful touches
- **Dropdown lists** for fields with fixed choices (such as harvest remarks), so no one has to type them by hand.
- **A warning before deleting**, which also tells you what else will be removed along with the record.
- **Clear messages** after you save, edit or delete something, and friendly error messages when something is wrong.
- **Works on phones, tablets and computers.**

---

## Who Can Do What

| Role | What they can do |
| :--- | :--- |
| **Super Admin** | Everything, including approving new records and new account requests, and managing all users. |
| **Administrator** | View, add, edit and delete records, view reports, and manage Staff accounts. |
| **Staff** | View, search, add and edit records, and view the dashboard and reports. Staff cannot delete records. |

Good to know:
- New records added by an Administrator or Staff member wait for the Super Admin's approval before they show up in the lists.
- Anyone can ask for an account using **Create Account** on the login page, but they can sign in only after the Super Admin approves them.
- Passwords are stored safely and are never shown.
