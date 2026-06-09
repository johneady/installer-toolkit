# Installation Guide

Thank you for your purchase! This guide will walk you through installing the application on your web server.

---

## Requirements

- **PHP** 8.3 or higher
- **MySQL** 5.7+ or **MariaDB** 10.3+
- **PHP Extensions:** zip, pdo_mysql, mbstring, openssl, fileinfo, bcmath
- **Apache** with mod_rewrite enabled

---

## Installation Steps

### 1. Create a Database

Create a MySQL database using your hosting control panel (e.g. cPanel, Plesk, DirectAdmin). Note down the database name, username, and password — you'll need them in step 4.

### 2. Upload the Files

Upload both the `.zip` file and `install.php` to your web server's **document root** folder:

| Host | Document Root |
|------|---------------|
| cPanel | `public_html` |
| Plesk | `httpdocs` |
| XAMPP / Apache | `htdocs` |
| Other | `www` or `wwwroot` |

### 3. Launch the Installer

Open your web browser and navigate to:

```
https://yourdomain.com/install.php
```

### 4. Follow the Wizard

The installer will guide you through each step:

1. **License Agreement** — Accept the EULA to continue
2. **Requirements** — Your server is checked for compatibility
3. **Database** — Enter your MySQL credentials (test the connection before proceeding)
4. **Application** — Set your app name, URL, and timezone
5. **Email** — Configure SMTP or use the log driver
6. **Admin Account** — Create your administrator login
7. **Install** — Sit back while the application is installed
8. **Cron Job** — Set up the scheduled task (required for emails and background tasks)
9. **Complete** — Done!

### 5. Clean Up

The installer will attempt to delete itself automatically. If it cannot, manually remove `install.php` and the `.zip` file from your server.

### 6. Log In

Visit your admin panel at:

```
https://yourdomain.com/admin
```

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Blank page | Ensure PHP 8.3+ is installed and all required extensions are enabled |
| 500 error after install | Verify that `mod_rewrite` is enabled in your Apache configuration |
| File uploads not working | Set the `storage/` directory permissions to 755 or 775 |

Need more help? Contact support.
