# Cinius Muhasebe Web

PHP 8.1 and Tailwind CSS desktop-style administration interface for the legacy accounting database.

Invoice headers and invoice-linked rows open a composite invoice window containing the joined header summary and its line items, with print/PDF and Excel-compatible export controls.

## Run locally

1. Copy `.env.example` to `.env`, configure the database connection, and replace `APP_LOGIN_USERNAME` and `APP_LOGIN_PASSWORD` with private administrator credentials.
2. Install and build the CSS: `npm install` then `npm run build`.
3. Start PHP: `php -S 127.0.0.1:8080 -t public`.
4. Open `http://127.0.0.1:8080` and sign in using the credentials configured in `.env`.

The environment-backed administrator is used as the default login. Existing administrators stored as password hashes in `storage/users.json` remain valid for backward compatibility. Database writes are recorded in `storage/audit.log`; both storage files are excluded from Git.

Administrators can create and edit accounts under **Kullanıcılar → Kullanıcı Yönetimi**. Passwords require at least 12 characters; leave the password blank when editing to preserve it. The `.env` account and the original local administrator (ID 1) cannot be demoted. The environment account's credentials are edited in `.env`.

For regular users, select both a main menu and its permitted items. Administrators always see all menus. Account roles and permissions are reread on every request, and API access is checked on the server. Related dropdown labels remain available when needed by an authorized editor. Refresh an already open desktop after changing its permissions to update its navigation; restored windows are filtered by the current permissions. The PHP process needs write access to `storage/users.json` and its directory.

**WebCin** is the first menu. **Pencere → Tümünü Kapat** closes all windows, including minimized ones, after a single confirmation if there are unsaved edits. **Raporlar → E-postaları Dışa Aktar** downloads all Cari or WebCup project email addresses as UTF-8 CSV (`Name,Email`). Exports split multiple addresses, deduplicate case-insensitively, skip invalid addresses and the application's known placeholder address, and protect spreadsheet formula-like names.

## Safety notes

- Legacy databases may use MyISAM. Cleanup tab 9 previews and converts remaining MyISAM base tables to InnoDB; it does not create foreign keys automatically.
- Normal data windows do not expose record deletion. The administrator-only **Pencere → Veri Temizleme** screen provides a narrowly scoped, audited cleanup workflow with separate preview and apply modes.
- Use a cloned database for initial testing and compare accounting totals with the Delphi application before enabling production writes.

## Cleanup workflow

1. Create and verify a full database backup, then stop the Delphi application.
2. Open **Pencere → Veri Temizleme** and review the read-only product, linked-author, address, and legacy-field tabs.
3. Switch to apply mode only after reviewing the counts. A page can display and select up to 5,000 rows per destructive operation. Pressing the delete button executes immediately; eligibility is revalidated on the server and the request remains protected by administrator authentication and CSRF validation.
4. Run product cleanup batches first, address cleanup second, and the legacy-field phase last.

Cleanup DROP/ALTER operations cannot be rolled back. Results are appended to `storage/audit.log`; selected IDs are represented by a SHA-256 digest rather than being logged in full.
