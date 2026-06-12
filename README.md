# Sales Salary Inventory Tracker

A lightweight PHP + MySQL dashboard for tracking sales, inventory (shared accounts/slots), staff commissions, and weekly salary releases.

## Features

- **Dashboard** — daily/weekly/monthly/all-time revenue, cost, profit, and sales count with charts.
- **Products** — manage product catalog with variants, categories, and Active/Sold views.
- **Inventory** — track stock, account credentials, and per-slot (name + PIN) availability with Active/History views.
- **Sales** — record sales with auto profit/commission calculation and stock deduction; click-to-copy account email/password/slot fields.
- **Salary** (Main Admin only) — weekly Released/Unreleased commission tracker per staff member.
- **Users** — manage admin/staff accounts, profile pictures, and commission rates.
- **Settings** — company branding, theme, and commission configuration.

## Local Setup (Laragon)

1. Import `database.sql` into MySQL.
2. Put this folder in your Laragon `www` directory.
3. Make sure MySQL is running.
4. Open the site in your browser.

Default config (`config.php`):

- Database name: `reseller_tracker`
- Host: `127.0.0.1`
- User: `root`
- Password: empty by default

## Default Login

- Admin: `Main Admin`
- Password: `admin123`

Use **Add Admin** on the login page to create more admin profiles. The new admin card appears right after saving.

## Notes

- Inventory items support separate slot records (name + PIN) per slot.
- Use **+ Add Category** in Inventory to create new groups like Spotify or Disney.
- Only the Main Admin account can view the Salary tab and mark salaries as released.

## Deployment

Edit `config.php` with your production database host/name/user/password (e.g. for InfinityFree, get these from your hosting MySQL panel). Do not commit real production credentials.
