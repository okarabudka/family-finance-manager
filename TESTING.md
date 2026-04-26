# Manual Testing Guide — Family Finance Manager

Run these scenarios after every deploy to verify the application is working correctly. Execute them in order — later scenarios depend on data created by earlier ones.

**Prerequisites:**
- Application is up: `docker compose ps` shows all 5 containers running (`app`, `nginx`, `db`, `redis`, `worker`)
- Migrations applied: `php bin/console doctrine:migrations:migrate`
- Telegram webhook registered: `php bin/console app:telegram:set-webhook`
- At least one admin web user exists

---

## 1. Infrastructure Health

### 1.1 Health endpoint

```
GET http://localhost:8080/health
```

**Expected:** HTTP 200, JSON body `{"status":"ok"}` (or similar). If anything is down (DB, Redis), this returns a non-OK response.

### 1.2 Worker is consuming

```bash
docker compose exec app php bin/console messenger:stats
```

**Expected:** Output lists the `async` transport with 0 or more messages in queue, no error about Redis being unreachable.

### 1.3 Database connectivity

```bash
docker compose exec db psql -U app family_finance -c '\dt'
```

**Expected:** Lists all tables — `families`, `users`, `accounts`, `transactions`, `categories`, `budgets`, `savings_goals`, `import_files`, `import_transactions`, `category_rules`, `recommendations`, `meter_readings`, `reminder_templates`.

---

## 2. Web Authentication

### 2.1 Login page loads

```
GET http://localhost:8080/login
```

**Expected:** Login form renders. No PHP errors or Twig exceptions.

### 2.2 Login with valid credentials

1. Open `http://localhost:8080/login`
2. Enter the admin email and password created via `app:user:create`
3. Click **Sign in**

**Expected:** Redirected to `/dashboard`. Navigation bar shows username in top right.

### 2.3 Login with wrong password

1. Open `http://localhost:8080/login`
2. Enter valid email, wrong password
3. Click **Sign in**

**Expected:** Stays on login page, shows error message about invalid credentials. No stack trace.

### 2.4 Authenticated-only redirect

1. Log out (`/logout`)
2. Navigate to `http://localhost:8080/dashboard`

**Expected:** Redirected back to `/login`.

---

## 3. Telegram Bot — Registration

### 3.1 First-time /start

1. Open a conversation with the bot in Telegram
2. Send `/start`

**Expected:** Bot replies asking for your name (e.g. "Please enter your name:").

### 3.2 Complete registration

1. Reply with your name (e.g. `Anna`)

**Expected:**
- Bot confirms name and creates a family
- Bot sends a message confirming two default accounts were created (e.g. main checking and cash)
- Conversation ends (no active dialog in Redis)

### 3.3 /start after registration

Send `/start` again after registration is complete.

**Expected:** Bot replies with a welcome-back message, does NOT start registration again.

### 3.4 /help

Send `/help`.

**Expected:** Bot sends a formatted list of all commands: `/start`, `/add`, `/income`, `/transfer`, `/balance`, `/report`, `/stats`, `/import`, `/pending`, `/budget`, `/meter`, `/help`.

---

## 4. Telegram Bot — Balance and Quick Input

### 4.1 /balance on fresh account

Send `/balance`.

**Expected:** Bot lists all accounts with their current balances (0.00 for new accounts) and a total line.

### 4.2 Quick expense input

Send: `50 coffee`

**Expected:**
- Bot confirms the expense was recorded
- Shows amount, category (auto-detected or uncategorized), date
- Balance updates accordingly (verify with `/balance`)

### 4.3 Quick input with decimal comma

Send: `12,50 lunch`

**Expected:** Bot records `12.50` without error.

### 4.4 Quick input — invalid text

Send: `hello`

**Expected:** Bot either ignores it gracefully or replies with a hint to use a valid format. No crash.

---

## 5. Telegram Bot — Add Expense (/add)

### 5.1 Full guided flow

1. Send `/add`

**Expected:** Bot asks for the expense amount.

2. Send `75`

**Expected:** Bot shows inline category buttons.

3. Tap a category

**Expected:** Bot shows inline account buttons.

4. Tap an account

**Expected:** Bot asks for the date.

5. Send `today`

**Expected:** Bot confirms expense recorded. Shows amount, category, account name, date.

### 5.2 Negative amount rejected

1. Send `/add`
2. Send `-10`

**Expected:** Bot rejects and asks to enter a number greater than 0.

### 5.3 Date in dd.mm.yyyy format

1. Complete /add up to the date step
2. Send `15.03.2025`

**Expected:** Bot records with date 2025-03-15.

### 5.4 Invalid date format rejected

1. Complete /add up to the date step
2. Send `march 15`

**Expected:** Bot rejects, asks again with correct format hint.

---

## 6. Telegram Bot — Add Income (/income)

### 6.1 Full flow

1. Send `/income`
2. Enter amount: `2500`
3. Select income category
4. Select account
5. Enter date: `today`

**Expected:** Bot confirms income recorded. `/balance` shows increased account balance.

---

## 7. Telegram Bot — Transfer (/transfer)

### 7.1 Transfer between accounts

1. Send `/transfer`

**Expected:** Bot shows source account selection buttons.

2. Tap source account

**Expected:** Bot shows target account buttons (source excluded or all shown).

3. Tap target account

**Expected:** Bot asks for amount.

4. Send `200`

**Expected:** Bot confirms transfer. Both account balances update correctly (verify with `/balance`).

---

## 8. Telegram Bot — Report and Stats

### 8.1 /report

Send `/report`.

**Expected:**
- Bot replies with monthly report showing income, expenses, balance
- Category breakdown listed
- Chart image attached (PNG from Quickchart.io)

If no transactions this month: bot still replies with a 0-data report, no error.

### 8.2 /stats

Send `/stats`.

**Expected:**
- Bot replies with category breakdown for current month
- Pie/bar chart image attached

---

## 9. Telegram Bot — Budget (/budget)

### 9.1 /budget with no budgets set

Send `/budget`.

**Expected:** Bot replies indicating no budgets are configured (not an error, graceful empty state).

### 9.2 /budget after creating a budget via web

1. Create a budget in the web dashboard (`/budgets/new`)
2. Send `/budget` in Telegram

**Expected:** Bot shows category name, planned amount, spent amount, and remaining.

---

## 10. Telegram Bot — Meter Readings (/meter)

### 10.1 Electricity reading without photo

1. Send `/meter`

**Expected:** Bot shows two inline buttons: **⚡ Electricity** and **💧 Water**.

2. Tap **⚡ Electricity**

**Expected:** Bot asks for the meter value. If a previous reading exists, it's shown for reference.

3. Send `12345.67`

**Expected:** Bot asks to send a photo or type `skip`.

4. Send `skip`

**Expected:** Bot confirms: "⚡ Electricity reading saved! Value: 12345.67 kWh, Date: YYYY-MM-DD".

### 10.2 Water reading with photo

1. Send `/meter`
2. Tap **💧 Water**
3. Send `234.5`
4. Send an actual photo (take a picture of anything)

**Expected:** Bot confirms: "💧 Water reading saved (photo saved)! Value: 234.50 m³, …"

### 10.3 Value with comma decimal separator

1. Send `/meter` → **⚡ Electricity**
2. Send `12346,10`

**Expected:** Bot accepts it as `12346.10` without error.

### 10.4 Invalid value rejected

1. Send `/meter` → **⚡ Electricity**
2. Send `abcdef`

**Expected:** Bot rejects and asks to enter a positive number.

### 10.5 Previous reading shown as hint

If a reading already exists for a meter type, the bot should display it when asking for the new value.

**Expected:** Message includes "Last reading: X.XX kWh on YYYY-MM-DD".

---

## 11. Telegram Bot — Bank Import (/import)

### 11.1 Import flow start

1. Send `/import`

**Expected:** Bot shows account selection buttons.

2. Tap an account

**Expected:** Bot asks to upload the file.

### 11.2 Upload valid MT940 file

1. After account selection, send a valid `.sta` file as a document attachment

**Expected:**
- Bot confirms file received and says processing in background
- Within seconds, the worker processes it
- Bot sends a follow-up notification: "Import complete. Imported: N, Skipped: M"

### 11.3 Upload Sparkasse CSV

Same as 11.2 but with a `.csv` file in Sparkasse format.

**Expected:** Same result.

### 11.4 Upload unsupported format

Upload a `.pdf` or `.txt` file.

**Expected:** Bot replies with an "unsupported format" error message. Conversation ends cleanly.

### 11.5 /pending after import with unclassified transactions

1. Complete an import that has transactions without matching category rules
2. Send `/pending`

**Expected:**
- Bot shows a pending transaction with description, amount, and inline category buttons
- Tapping a category assigns it and moves to next pending item (or confirms "no more pending")

### 11.6 Duplicate import prevention

1. Import the same file again

**Expected:**
- Import completes without error
- Notification says "Imported: 0, Skipped: N" (all duplicates skipped)
- No duplicate transactions in `/transactions` web view

---

## 12. Web Dashboard

### 12.1 Dashboard loads with data

1. Log in at `http://localhost:8080/login`
2. Navigate to `http://localhost:8080/dashboard`

**Expected:**
- Account balances shown
- Current month income/expenses/balance shown
- 6-month trend bar chart renders (Chart.js)
- Category pie chart renders

### 12.2 Dashboard with no family

Log in as a user with no family assigned.

**Expected:** Shows a "no family" page with instructions, no JS errors.

---

## 13. Web — Transactions

### 13.1 Transaction list

```
GET http://localhost:8080/transactions
```

**Expected:** Table of transactions, most recent first. Pagination controls if > page size.

### 13.2 Filtering

1. Use the filter/search fields (date range, type, category)
2. Submit the filter

**Expected:** List narrows to matching transactions. Empty state shown if none match.

### 13.3 Create transaction via web

1. Navigate to `http://localhost:8080/transactions/new`
2. Fill in: type = Expense, amount = 30, category, account, date
3. Submit

**Expected:**
- Redirected to transaction list
- New transaction appears at the top
- Account balance reflects the change

### 13.4 Edit transaction

1. Click edit on any transaction
2. Change the amount
3. Submit

**Expected:** Transaction updated, balance recalculated.

### 13.5 Delete transaction

1. Click delete on a transaction
2. Confirm

**Expected:** Transaction removed from list, balance updated.

---

## 14. Web — Accounts

### 14.1 Account list

```
GET http://localhost:8080/accounts
```

**Expected:** List of accounts with type, currency, balance.

### 14.2 Create account

1. Navigate to `http://localhost:8080/accounts/new`
2. Fill in: name = "Savings EUR", type = Savings, currency = EUR, initial balance = 1000
3. Submit

**Expected:** Account appears in list with balance 1000.00 EUR.

### 14.3 Edit account

1. Open an account's edit form
2. Change the name
3. Submit

**Expected:** Name updated in list.

---

## 15. Web — Categories

### 15.1 Category list

```
GET http://localhost:8080/categories
```

**Expected:** Hierarchical tree of categories (parent + children indented).

### 15.2 Create parent category

1. Navigate to `http://localhost:8080/categories/new`
2. Name = "Transport", type = Expense, no parent
3. Submit

**Expected:** Category appears in list.

### 15.3 Create child category

1. Create a new category
2. Set parent = "Transport"
3. Name = "Fuel"

**Expected:** "Fuel" appears nested under "Transport".

---

## 16. Web — Budgets

### 16.1 Budget list

```
GET http://localhost:8080/budgets
```

**Expected:** List of budgets with category, year, month, planned amount. Empty state if none.

### 16.2 Create budget

1. Navigate to `http://localhost:8080/budgets/new`
2. Select category, year = current year, month = current month, amount = 500
3. Submit

**Expected:** Budget appears in list.

### 16.3 Duplicate budget rejected

1. Try to create a second budget for the same category + year + month

**Expected:** Form shows a validation error (unique constraint). No duplicate created.

---

## 17. Web — Savings Goals

### 17.1 Savings goal list

```
GET http://localhost:8080/savings-goals
```

**Expected:** List with name, target amount, target date, status, and progress indicator.

### 17.2 Create savings goal

1. Navigate to `http://localhost:8080/savings-goals/new`
2. Name = "Vacation fund", target = 3000, target date = next year, account = Savings EUR
3. Submit

**Expected:** Goal appears in list with 0% progress (or actual balance % if account has funds).

### 17.3 Edit and complete goal

1. Edit the goal, change status to "Completed"
2. Submit

**Expected:** Goal shows "Completed" status in list.

---

## 18. Web — Meter Readings

### 18.1 Meters page loads

```
GET http://localhost:8080/meters
```

**Expected:**
- Two sections: ⚡ Electricity and 💧 Water
- Charts render if ≥ 2 readings exist for a type (consumption delta requires at least 2 data points)
- Tables show readings with date, value, consumption column

### 18.2 Consumption calculation

Add at least 2 electricity readings via Telegram (e.g. 12000, then 12150), then reload `/meters`.

**Expected:**
- Second row shows consumption: `+150.00`
- First row shows `—` (no previous reading to diff against)

### 18.3 View meter photo

If a reading was submitted with a photo, click **View** in the Photo column.

**Expected:**
- Photo opens in the browser (inline display, not downloaded)
- HTTP 200 with image content type
- Access is blocked for a different family's reading (try with two separate accounts)

### 18.4 Delete reading

Click **Delete** on a reading, confirm the dialog.

**Expected:** Reading removed from table. Consumption values for adjacent rows recalculate on next page load.

---

## 19. Web — Imports

### 19.1 Import file list

```
GET http://localhost:8080/imports
```

**Expected:** Table of uploaded import files with filename, account, date, status (pending/processing/done/failed), row counts.

### 19.2 Status progression

After uploading a file via `/import` in Telegram:

1. Reload `/imports` immediately

**Expected:** File appears with status `pending` or `processing`.

2. Wait a few seconds, reload again

**Expected:** Status changes to `done`, rows counts filled in.

### 19.3 Pending transactions list

```
GET http://localhost:8080/imports/pending
```

**Expected:** Table of unclassified imported transactions with description, amount, date, suggested category if any.

---

## 20. Web — Recommendations

### 20.1 Recommendations list

```
GET http://localhost:8080/recommendations
```

**Expected:** List of financial recommendations (or empty state message). Each item shows title, message, priority.

### 20.2 Mark as seen

1. Click the "Mark as seen" button on a recommendation

**Expected:** Recommendation disappears from the list (or moves to a "seen" section). Flash message confirms action.

---

## 21. Web — Settings

### 21.1 Profile update

1. Navigate to `http://localhost:8080/settings`
2. Change the display name
3. Submit the profile form

**Expected:** Flash message "Profile updated." Name in nav bar updates on next request.

### 21.2 Password change — correct current password

1. On `/settings`, fill in current password (correct), new password, confirm
2. Submit

**Expected:** Flash "Profile updated." Can log in with new password.

### 21.3 Password change — wrong current password

1. On `/settings`, enter incorrect current password
2. Submit

**Expected:** Flash error "Current password is incorrect." Password not changed.

### 21.4 Family settings update

1. On `/settings`, change the family name or currency
2. Submit the family form

**Expected:** Flash "Family settings updated."

### 21.5 Add family member

1. Navigate to `http://localhost:8080/settings/users/new`
2. Fill in name, email, password, role
3. Submit

**Expected:**
- Flash "User X created successfully."
- New user can log in at `/login` with those credentials
- New user sees the same family data (same accounts, transactions, etc.)

### 21.6 Duplicate email rejected

1. Try to add a user with an email that already exists

**Expected:** Flash error "A user with this email already exists." No duplicate user created.

---

## 22. Admin Panel

### 22.1 Admin panel access

```
GET http://localhost:8080/admin
```

**Expected:** EasyAdmin dashboard loads. Sidebar shows all sections: Users & Families, Finance, Import, Notifications.

### 22.2 Non-admin blocked

1. Log in as a regular user (no `ROLE_ADMIN`)
2. Navigate to `http://localhost:8080/admin`

**Expected:** HTTP 403 Access Denied. Not a blank page or a PHP error.

### 22.3 Reminders CRUD

1. Navigate to **Notifications → Reminders** in the admin sidebar
2. Click **Add reminder**
3. Fill in: name = "Submit electricity meter", type = `meter_electricity`, frequency = `monthly`, day of month = 25, send hour = 9, message = "Please submit your electricity meter reading!", send via Telegram = on, active = on
4. Save

**Expected:** Reminder appears in the list with correct values.

### 22.4 Edit reminder

1. Edit the reminder created in 22.3
2. Toggle active to off, save

**Expected:** Reminder shows as inactive. `app:reminders:send` will skip it.

---

## 23. Reminders — End-to-End

### 23.1 Manual trigger

```bash
docker compose exec app php bin/console app:reminders:send
```

**Expected:**
- Output: "Done. Dispatched N reminder(s)."
- If no reminders are due: "Done. Dispatched 0 reminder(s)."

### 23.2 Force-fire by manipulating lastSentAt

To test delivery without waiting for the right time/day:

```sql
UPDATE reminder_templates
SET last_sent_at = '2000-01-01',
    send_hour = EXTRACT(HOUR FROM NOW())::int,
    day_of_week = EXTRACT(ISODOW FROM NOW())::int,
    day_of_month = EXTRACT(DAY FROM NOW())::int
WHERE name = 'Submit electricity meter';
```

Then:

```bash
docker compose exec app php bin/console app:reminders:send
```

**Expected:** "Dispatched 1 reminder(s)." Message arrives in Telegram within a few seconds.

### 23.3 No duplicate sends

Run `app:reminders:send` twice in the same hour.

```bash
docker compose exec app php bin/console app:reminders:send
docker compose exec app php bin/console app:reminders:send
```

**Expected:** First run dispatches N. Second run dispatches 0. `last_sent_at` in the DB prevents re-sending.

### 23.4 Email delivery (if MAILER_DSN is configured)

1. Set `MAILER_DSN` in `.env.local` to a real SMTP or use Mailtrap/Mailpit
2. Enable **Send via Email** on a reminder
3. Force-fire as in 23.2

**Expected:** Email arrives at the user's address with subject "[Family Finance] {reminder name}" and the message text as body.

### 23.5 Email skipped when MAILER_DSN=null://null

1. Set `MAILER_DSN=null://null`
2. Enable **Send via Email** on a reminder, trigger it

**Expected:** Telegram message still arrives. No email. Worker logs a warning "Mailer not configured — skipping email reminder". No crash.

---

## 24. Weekly Report Command

```bash
docker compose exec app php bin/console app:report:weekly
```

**Expected:**
- Command runs without error
- Each family member with a linked Telegram account receives a Telegram message with the weekly financial summary
- If no transactions exist: command completes with a 0-data summary (no crash)

---

## 25. Async Worker — Resilience

### 25.1 Worker restart

```bash
docker compose restart worker
docker compose ps worker
```

**Expected:** Worker restarts cleanly, status returns to `running`.

### 25.2 Failed message inspection

```bash
docker compose exec app php bin/console messenger:failed:show
```

**Expected:** Lists any failed messages (should be empty on a clean deploy). If any exist, inspect with `messenger:failed:show --max-messages=5`.

### 25.3 Retry failed messages

```bash
docker compose exec app php bin/console messenger:failed:retry
```

Only run if 25.2 shows failed messages.

**Expected:** Messages are requeued and processed by the worker.

---

## 26. Security Spot Checks

### 26.1 Cross-family data isolation

1. Create two separate users each with their own family (via Telegram `/start` + new account)
2. Log in as User A in the web
3. Note the UUID of User A's account
4. Manually craft a URL: `http://localhost:8080/accounts/<user-b-account-uuid>`

**Expected:** HTTP 403 or 404. User A cannot see User B's account.

### 26.2 Meter photo access control

1. Submit a meter reading with a photo as User A
2. Note the reading UUID
3. Log in as User B (different family)
4. Navigate to `http://localhost:8080/meters/photo/<user-a-reading-uuid>`

**Expected:** HTTP 403 Access Denied.

### 26.3 CSRF on delete

Attempt to delete a transaction with a forged POST (missing `_token`).

**Expected:** Action rejected. No transaction deleted.

### 26.4 Webhook secret validation

```bash
curl -X POST http://localhost:8080/telegram/webhook \
  -H "Content-Type: application/json" \
  -H "X-Telegram-Bot-Api-Secret-Token: wrong_secret" \
  -d '{}'
```

**Expected:** HTTP 403.

```bash
curl -X POST http://localhost:8080/telegram/webhook \
  -H "Content-Type: application/json" \
  -d '{}'
```

**Expected:** HTTP 403 (missing header).

---

## 27. Edge Cases and Error States

### 27.1 Bot command from unregistered user

With a Telegram account that has never sent `/start`, send `/balance`.

**Expected:** Bot replies "Please register first: /start". No crash.

### 27.2 Bot command — user registered but no family

Register a user, then manually remove their `family_id` in the DB:

```sql
UPDATE users SET family_id = NULL WHERE email = 'test@example.com';
```

Then send `/balance` from that Telegram account.

**Expected:** Bot replies gracefully (register prompt or similar). No unhandled exception.

### 27.3 Abandoned conversation expiry

1. Start `/add`, enter the amount, then do nothing
2. Wait 30 minutes (or set Redis TTL manually for testing)
3. Send any message

**Expected:** Bot treats it as a fresh message (not a stuck conversation). No error.

### 27.4 Web — no family user accesses dashboard

Log in as a user with no family. Navigate to `/dashboard`.

**Expected:** Renders the "no family" template with a helpful message. No PHP error.

### 27.5 Meter reading — second type not affected

1. Submit an electricity reading
2. Submit a water reading
3. Check `/meters`

**Expected:** Electricity and water sections are independent. Each shows its own chart and table without cross-contamination.

---

## Quick Regression Checklist

Run after any code change before deploying:

| Check | Expected |
|---|---|
| `GET /health` | HTTP 200 |
| `GET /login` | Form renders |
| Login + redirect to `/dashboard` | Works |
| Bot responds to `/help` | Lists all commands |
| Bot `/balance` returns numbers | Works |
| Quick input `50 test` | Expense recorded |
| `/meter` → Electricity → value → skip | Reading saved |
| `/reports` page in web | Table loads |
| `/meters` page | Charts and table render |
| Admin `/admin` reachable | Dashboard loads |
| `app:reminders:send` runs | Exits 0, output printed |
| Worker is running | `docker compose ps worker` = running |
