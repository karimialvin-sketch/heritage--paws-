# Heritage Paws

A web-based marketplace where registered dog sellers list their dogs and registered buyers contact them directly.
Built with PHP (PDO), MySQL, HTML, CSS and JavaScript.

## Roles

| Role   | What they can do |
|--------|------------------|
| Admin  | Approve or suspend seller/buyer accounts, approve or reject dog listings, manage breeds, view platform stats |
| Seller | Register (needs admin approval), list/edit/delete dogs with photos, mark dogs reserved/sold, answer buyer inquiries |
| Buyer  | Register, browse and search dogs, see seller contact details, message sellers, save favourites |

The direct link between seller and buyer is the **inquiry** system: the buyer sends a message from the dog's page,
the seller sees it (with the buyer's phone and email) and replies from their dashboard. Both can also call each other.

## Public browsing and direct contact

Anyone can browse all approved dogs and see the seller's phone number without an account.
Every dog card and detail page has **Call** and **WhatsApp** buttons. Kenyan numbers like `0712 345 678`
are converted automatically to the international format WhatsApp needs (`254712345678`).
Buyer accounts are optional and only add saved favourites and in-site messages.

## Logo and browser icon

The logo is in `assets/img/logo.png` (home page banner). The paw icon used in the header and browser tab is
`assets/img/favicon.ico` plus the PNG sizes in the same folder. To change the logo later, replace those files.

## Setup (Windows / XAMPP or WAMP)

1. **Install XAMPP** (Apache + PHP + MySQL) and start Apache and MySQL.
2. **Copy this folder** (`heritage_paws`) into `C:\xampp\htdocs\`.
3. **Create the database in MySQL Workbench**
   - Connect to your local MySQL server.
   - File > Open SQL Script > choose `database/heritage_paws.sql`.
   - Click the lightning bolt to execute. This creates the `heritage_paws` database, all tables, 13 breeds and the admin account.
4. **Check `config/db.php`**: set `DB_USER` / `DB_PASS` to match your MySQL login (XAMPP default is `root` with an empty password).
   If your folder is not called `heritage_paws`, update `BASE_URL` and `UPLOAD_URL` in the same file.
5. Open **http://localhost/heritage_paws/** in your browser.

## Default admin login

- Email: `admin@heritagepaws.com`
- Password: `Admin@123`

Change this password after your first login (see "Changing the admin password" below).

## Try it out

1. Register a seller account (it will say "awaiting approval").
2. Log in as admin > **Users** > **Approve** the seller.
3. Log in as the seller > **List a new dog** > add photos and submit.
4. Log in as admin > **Dog listings** > **Approve** the dog.
5. Register a buyer, find the dog on the home page, and send the seller a message.
6. Log back in as the seller > **Inquiries** to reply.

## Changing the admin password

Run this in a terminal to produce a hash for your new password:

    php -r "echo password_hash('YourNewPassword', PASSWORD_DEFAULT);"

Then in Workbench:

    UPDATE users SET password_hash = 'PASTE_HASH_HERE' WHERE email = 'admin@heritagepaws.com';

## Folder structure

    heritage_paws/
      index.php            public home page with search and filters
      register.php         buyer and seller registration
      login.php, logout.php
      dog.php              dog detail page, message seller, save favourite
      config/db.php        database settings
      includes/            shared header, footer and helper functions
      seller/              dashboard, add/edit dog form, inquiries
      buyer/               dashboard (saved dogs and messages)
      admin/               overview, users, dog listings, breeds
      assets/css, js, img  styling, scripts, placeholder image
      uploads/             dog photos (must be writable)
      database/heritage_paws.sql

## Security built in

- Passwords hashed with `password_hash` (bcrypt)
- All database queries use prepared statements (blocks SQL injection)
- All output is escaped (blocks XSS)
- CSRF token on every form
- Role checks on every protected page
- Photo uploads verified by real file content, size-limited, and renamed randomly

## Before going live

- Use HTTPS and a hosting provider with PHP 8+ and MySQL
- Create a dedicated MySQL user instead of `root`
- Change the default admin password
- Add a way to reset forgotten passwords by email
- Consider payment handling separately; this system only connects buyers and sellers
- Check local regulations on animal sales and breeder licensing
